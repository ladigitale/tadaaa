<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\GoogleCalendarBinding;
use App\Entity\GoogleCalendarConnection;
use App\Entity\User;
use App\Repository\GoogleCalendarBindingRepository;
use App\Repository\GoogleCalendarConnectionRepository;
use App\Repository\TagRepository;
use App\Repository\TodoGoogleEventLinkRepository;
use App\Util\BaseIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class GoogleCalendarService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleOAuthClient $oauth,
        private readonly GoogleCalendarApi $api,
        private readonly GoogleCalendarSyncService $sync,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly GoogleCalendarBindingRepository $bindings,
        private readonly TodoGoogleEventLinkRepository $links,
        private readonly TagRepository $tags,
        private readonly DatasetAccessService $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $connection = $this->connections->findOneByUser($user);

        return [
            'configured' => $this->oauth->isConfigured(),
            'connected' => $connection !== null && $connection->isActive(),
            'connection' => $connection !== null ? $this->serializeConnection($connection) : null,
            'bindings' => array_map($this->serializeBinding(...), $this->bindings->findForUser($user)),
        ];
    }

    /**
     * @return array{authorizeUrl: string}
     */
    public function connect(User $user): array
    {
        $built = $this->oauth->buildAuthorizeUrl($user);

        return ['authorizeUrl' => $built['authorizeUrl']];
    }

    public function disconnect(User $user): void
    {
        $connection = $this->connections->findOneByUser($user);
        if ($connection === null) {
            return;
        }

        foreach ($this->bindings->findForUser($user) as $binding) {
            $this->sync->stopWatch($binding);
            $this->entityManager->remove($binding);
        }
        foreach ($this->links->findAllForUser($user) as $link) {
            try {
                $this->api->deleteEvent($connection, $link->getGoogleCalendarId(), $link->getGoogleEventId());
            } catch (\Throwable) {
                // best-effort
            }
            $this->entityManager->remove($link);
        }

        $this->oauth->revoke($connection);
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
    }

    /**
     * @return list<array{id: string, summary: string, primary: bool, accessRole: string}>
     */
    public function listCalendars(User $user): array
    {
        $connection = $this->requireActiveConnection($user);

        return $this->api->listCalendars($connection);
    }

    /**
     * @param array{
     *   datasetId?: string,
     *   googleCalendarId?: string,
     *   googleCalendarSummary?: string,
     *   tagIds?: list<string>,
     *   isDefault?: bool,
     *   exportEnabled?: bool,
     *   importEnabled?: bool,
     *   priority?: int
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function createBinding(User $user, array $payload): array
    {
        $this->requireActiveConnection($user);
        $dataset = $this->resolveDataset($user, $payload['datasetId'] ?? null);
        $calendarId = isset($payload['googleCalendarId']) && is_string($payload['googleCalendarId'])
            ? trim($payload['googleCalendarId'])
            : '';
        if ($calendarId === '') {
            throw new BadRequestHttpException('googleCalendarId is required.');
        }
        $summary = isset($payload['googleCalendarSummary']) && is_string($payload['googleCalendarSummary'])
            ? trim($payload['googleCalendarSummary'])
            : $calendarId;
        $tagIds = $this->normalizeTagIds($dataset, $payload['tagIds'] ?? []);
        $isDefault = !empty($payload['isDefault']);
        $priority = isset($payload['priority']) ? (int) $payload['priority'] : 0;

        if ($isDefault) {
            $this->clearDefault($user, $dataset);
        }

        $binding = new GoogleCalendarBinding($user, $dataset, $calendarId, $summary, $tagIds, $isDefault, $priority);
        if (array_key_exists('exportEnabled', $payload)) {
            $binding->setExportEnabled((bool) $payload['exportEnabled']);
        }
        if (array_key_exists('importEnabled', $payload)) {
            $binding->setImportEnabled((bool) $payload['importEnabled']);
        }

        $this->entityManager->persist($binding);
        $this->entityManager->flush();
        $this->sync->ensureWatch($binding);

        return $this->serializeBinding($binding);
    }

    /**
     * @param array{
     *   googleCalendarId?: string,
     *   googleCalendarSummary?: string,
     *   tagIds?: list<string>,
     *   isDefault?: bool,
     *   exportEnabled?: bool,
     *   importEnabled?: bool,
     *   priority?: int
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function updateBinding(User $user, string $id, array $payload): array
    {
        $binding = $this->requireBinding($user, $id);

        if (isset($payload['googleCalendarId']) && is_string($payload['googleCalendarId'])) {
            $binding->setGoogleCalendarId(trim($payload['googleCalendarId']));
        }
        if (isset($payload['googleCalendarSummary']) && is_string($payload['googleCalendarSummary'])) {
            $binding->setGoogleCalendarSummary(trim($payload['googleCalendarSummary']));
        }
        if (array_key_exists('tagIds', $payload)) {
            $binding->setTagIds($this->normalizeTagIds($binding->getDataset(), $payload['tagIds'] ?? []));
        }
        if (array_key_exists('isDefault', $payload)) {
            $isDefault = (bool) $payload['isDefault'];
            if ($isDefault) {
                $this->clearDefault($user, $binding->getDataset(), $binding);
            }
            $binding->setIsDefault($isDefault);
        }
        if (array_key_exists('exportEnabled', $payload)) {
            $binding->setExportEnabled((bool) $payload['exportEnabled']);
        }
        if (array_key_exists('importEnabled', $payload)) {
            $binding->setImportEnabled((bool) $payload['importEnabled']);
        }
        if (array_key_exists('priority', $payload)) {
            $binding->setPriority((int) $payload['priority']);
        }

        $this->entityManager->flush();
        if ($binding->isImportEnabled()) {
            $this->sync->ensureWatch($binding);
        } else {
            $this->sync->stopWatch($binding);
            $this->entityManager->flush();
        }

        return $this->serializeBinding($binding);
    }

    public function deleteBinding(User $user, string $id): void
    {
        $binding = $this->requireBinding($user, $id);
        $this->sync->stopWatch($binding);
        $this->entityManager->remove($binding);
        $this->entityManager->flush();
    }

    /**
     * @return array{changed: int}
     */
    public function syncNow(User $user, ?string $bindingId = null): array
    {
        $this->requireActiveConnection($user);
        $targets = $bindingId !== null
            ? [$this->requireBinding($user, $bindingId)]
            : array_values(array_filter(
                $this->bindings->findForUser($user),
                static fn (GoogleCalendarBinding $b) => $b->isImportEnabled(),
            ));

        $changed = 0;
        foreach ($targets as $binding) {
            $changed += $this->sync->pullBinding($binding);
            $this->sync->ensureWatch($binding);
        }

        return ['changed' => $changed];
    }

    public function handlePush(string $channelId, string $channelToken): void
    {
        $binding = $this->bindings->findOneByWatchChannelId($channelId);
        if ($binding === null) {
            return;
        }
        if ($binding->getWatchToken() !== null && !hash_equals($binding->getWatchToken(), $channelToken)) {
            return;
        }
        if (!$binding->isImportEnabled()) {
            return;
        }
        $this->sync->pullBinding($binding);
    }

    private function requireActiveConnection(User $user): GoogleCalendarConnection
    {
        $connection = $this->connections->findOneByUser($user);
        if ($connection === null || !$connection->isActive()) {
            throw new BadRequestHttpException('Connect Google Calendar first.');
        }

        return $connection;
    }

    private function requireBinding(User $user, string $id): GoogleCalendarBinding
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Binding not found.');
        }
        $binding = $this->bindings->find($uuid);
        if (!$binding instanceof GoogleCalendarBinding || !$binding->getUser()->getId()->equals($user->getId())) {
            throw new NotFoundHttpException('Binding not found.');
        }

        return $binding;
    }

    private function resolveDataset(User $user, mixed $datasetId): Dataset
    {
        if (!is_string($datasetId) || trim($datasetId) === '') {
            throw new BadRequestHttpException('datasetId is required.');
        }
        try {
            $uuid = Uuid::fromString(trim($datasetId));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid datasetId.');
        }

        return $this->access->requireAccessibleById($user, $uuid);
    }

    /**
     * @param mixed $raw
     *
     * @return list<string>
     */
    private function normalizeTagIds(Dataset $dataset, mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $tag = $this->tags->findOneForDataset($dataset, $id);
            if ($tag !== null && !$tag->isDeleted()) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    private function clearDefault(User $user, Dataset $dataset, ?GoogleCalendarBinding $except = null): void
    {
        foreach ($this->bindings->findForUserAndDataset($user, $dataset) as $binding) {
            if ($except !== null && $binding->getId()->equals($except->getId())) {
                continue;
            }
            if ($binding->isDefault()) {
                $binding->setIsDefault(false);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConnection(GoogleCalendarConnection $connection): array
    {
        return [
            'id' => $connection->getId()->toRfc4122(),
            'email' => $connection->getGoogleAccountEmail(),
            'status' => $connection->getStatus(),
            'updatedAt' => $connection->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeBinding(GoogleCalendarBinding $binding): array
    {
        $dataset = $binding->getDataset();

        return [
            'id' => $binding->getId()->toRfc4122(),
            'datasetId' => $dataset->getId()->toRfc4122(),
            'baseId' => BaseIdParser::format($dataset->getBaseId()),
            'datasetName' => $dataset->getName(),
            'googleCalendarId' => $binding->getGoogleCalendarId(),
            'googleCalendarSummary' => $binding->getGoogleCalendarSummary(),
            'tagIds' => $binding->getTagIds(),
            'isDefault' => $binding->isDefault(),
            'exportEnabled' => $binding->isExportEnabled(),
            'importEnabled' => $binding->isImportEnabled(),
            'priority' => $binding->getPriority(),
            'watchExpiresAt' => $binding->getWatchExpiresAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $binding->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
