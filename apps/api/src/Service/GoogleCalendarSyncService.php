<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\GoogleCalendarBinding;
use App\Entity\GoogleCalendarConnection;
use App\Entity\Todo;
use App\Entity\TodoGoogleEventLink;
use App\Entity\User;
use App\Repository\GoogleCalendarBindingRepository;
use App\Repository\GoogleCalendarConnectionRepository;
use App\Repository\TagRepository;
use App\Repository\TodoGoogleEventLinkRepository;
use App\Repository\TodoRepository;
use App\Util\TodoDate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bidirectional Google Calendar sync for one user's bindings on a dataset.
 *
 * Rules:
 * - Export: OR tag match → calendar; else default binding; else delete linked event.
 * - Import: event in bound calendar → todo + binding tags; leave unbound → unlink only.
 */
final class GoogleCalendarSyncService
{
    /** @var array<string, true> */
    private array $importGuard = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleOAuthClient $oauth,
        private readonly GoogleCalendarApi $api,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly GoogleCalendarBindingRepository $bindings,
        private readonly TodoGoogleEventLinkRepository $links,
        private readonly TodoRepository $todos,
        private readonly TagRepository $tags,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:DEFAULT_URI)%')]
        private readonly string $apiPublicUrl,
        #[Autowire('%env(string:APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
    ) {
    }

    public function beginImportGuard(string $key): void
    {
        $this->importGuard[$key] = true;
    }

    public function endImportGuard(string $key): void
    {
        unset($this->importGuard[$key]);
    }

    private function isImportGuarded(User $user, Dataset $dataset, string $todoId): bool
    {
        return isset($this->importGuard[$user->getId()->toRfc4122().'|'.$dataset->getId()->toRfc4122().'|'.$todoId]);
    }

    public function syncTodoForUser(User $user, Dataset $dataset, Todo $todo): void
    {
        if ($this->isImportGuarded($user, $dataset, $todo->getId())) {
            return;
        }

        $connection = $this->connections->findOneByUser($user);
        if ($connection === null || !$connection->isActive()) {
            return;
        }

        $userBindings = $this->bindings->findForUserAndDataset($user, $dataset);
        $exportBindings = array_values(array_filter(
            $userBindings,
            static fn (GoogleCalendarBinding $b) => $b->isExportEnabled(),
        ));
        if ($exportBindings === []) {
            return;
        }

        try {
            $this->doExport($connection, $user, $dataset, $todo, $exportBindings);
        } catch (\Throwable $e) {
            $this->logger->warning('Google Calendar export failed: {message}', [
                'message' => $e->getMessage(),
                'todoId' => $todo->getId(),
                'userId' => $user->getId()->toRfc4122(),
            ]);
        }
    }

    /**
     * @param list<GoogleCalendarBinding> $exportBindings
     */
    private function doExport(
        GoogleCalendarConnection $connection,
        User $user,
        Dataset $dataset,
        Todo $todo,
        array $exportBindings,
    ): void {
        $link = $this->links->findOneForUserTodo($user, $dataset, $todo->getId());
        $shouldExist = !$todo->isDeleted()
            && !$todo->isArchived()
            && ($todo->getStartAt() !== null || $todo->getEndAt() !== null);

        $target = $shouldExist ? $this->resolveBinding($todo->getTagIds(), $exportBindings) : null;

        if ($target === null) {
            if ($link !== null) {
                $this->api->deleteEvent($connection, $link->getGoogleCalendarId(), $link->getGoogleEventId());
                $this->entityManager->remove($link);
                $this->entityManager->flush();
            }

            return;
        }

        $hash = $this->contentHash($todo, $target->getGoogleCalendarId());
        if ($link !== null && $link->getContentHash() === $hash && $link->getGoogleCalendarId() === $target->getGoogleCalendarId()) {
            return;
        }

        $body = $this->todoToEventBody($todo, $dataset, $user);

        if ($link !== null && $link->getGoogleCalendarId() !== $target->getGoogleCalendarId()) {
            $this->api->deleteEvent($connection, $link->getGoogleCalendarId(), $link->getGoogleEventId());
            $this->entityManager->remove($link);
            $this->entityManager->flush();
            $link = null;
        }

        if ($link === null) {
            $created = $this->api->insertEvent($connection, $target->getGoogleCalendarId(), $body);
            $eventId = isset($created['id']) && is_string($created['id']) ? $created['id'] : null;
            if ($eventId === null) {
                throw new \RuntimeException('Google insertEvent returned no id.');
            }
            $link = new TodoGoogleEventLink($user, $dataset, $todo->getId(), $target->getGoogleCalendarId(), $eventId);
            $this->entityManager->persist($link);
            if (isset($created['etag']) && is_string($created['etag'])) {
                $link->setEtag($created['etag']);
            }
        } else {
            $patched = $this->api->patchEvent($connection, $link->getGoogleCalendarId(), $link->getGoogleEventId(), $body);
            if (isset($patched['id']) && is_string($patched['id'])) {
                $link->setGoogleEventId($patched['id']);
            }
            if (isset($patched['etag']) && is_string($patched['etag'])) {
                $link->setEtag($patched['etag']);
            }
        }

        $link->setContentHash($hash);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $todoTagIds
     * @param list<GoogleCalendarBinding> $bindings
     */
    public function resolveBinding(array $todoTagIds, array $bindings): ?GoogleCalendarBinding
    {
        $matched = [];
        foreach ($bindings as $binding) {
            if ($binding->matchesTodoTags($todoTagIds)) {
                $matched[] = $binding;
            }
        }
        if ($matched !== []) {
            usort($matched, static fn (GoogleCalendarBinding $a, GoogleCalendarBinding $b) => $b->getPriority() <=> $a->getPriority());

            return $matched[0];
        }

        foreach ($bindings as $binding) {
            if ($binding->isDefault()) {
                return $binding;
            }
        }

        return null;
    }

    public function pullBinding(GoogleCalendarBinding $binding): int
    {
        if (!$binding->isImportEnabled()) {
            return 0;
        }
        $user = $binding->getUser();
        $connection = $this->connections->findOneByUser($user);
        if ($connection === null || !$connection->isActive()) {
            return 0;
        }

        $changed = 0;
        $pageToken = null;
        $syncToken = $binding->getSyncToken();
        do {
            try {
                $page = $this->api->listEvents($connection, $binding->getGoogleCalendarId(), $syncToken, $pageToken);
            } catch (\Throwable $e) {
                if ($syncToken !== null && str_contains($e->getMessage(), '410')) {
                    $binding->setSyncToken(null);
                    $this->entityManager->flush();
                    $syncToken = null;
                    $page = $this->api->listEvents($connection, $binding->getGoogleCalendarId(), null, null);
                } else {
                    throw $e;
                }
            }

            foreach ($page['items'] as $event) {
                if ($this->applyImportedEvent($binding, $connection, $event)) {
                    ++$changed;
                }
            }
            $pageToken = $page['nextPageToken'];
            if ($page['nextSyncToken'] !== null) {
                $binding->setSyncToken($page['nextSyncToken']);
            }
        } while ($pageToken !== null && $pageToken !== '');

        $this->entityManager->flush();

        return $changed;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function applyImportedEvent(
        GoogleCalendarBinding $binding,
        GoogleCalendarConnection $connection,
        array $event,
    ): bool {
        $user = $binding->getUser();
        $dataset = $binding->getDataset();
        $eventId = isset($event['id']) && is_string($event['id']) ? $event['id'] : null;
        if ($eventId === null) {
            return false;
        }

        $status = isset($event['status']) && is_string($event['status']) ? $event['status'] : 'confirmed';
        $link = $this->links->findOneByGoogleEvent($user, $binding->getGoogleCalendarId(), $eventId);

        $extended = $event['extendedProperties']['private'] ?? [];
        $tadaaaTodoId = is_array($extended) && isset($extended['tadaaaTodoId']) && is_string($extended['tadaaaTodoId'])
            ? $extended['tadaaaTodoId']
            : null;
        $tadaaaUserId = is_array($extended) && isset($extended['tadaaaUserId']) && is_string($extended['tadaaaUserId'])
            ? $extended['tadaaaUserId']
            : null;

        // Event from another member's mirror — do not import as a new todo.
        if ($tadaaaUserId !== null && $tadaaaUserId !== $user->getId()->toRfc4122()) {
            return false;
        }

        if ($status === 'cancelled') {
            if ($link !== null) {
                $todo = $this->todos->findOneForDataset($dataset, $link->getTodoId());
                if ($todo !== null && !$todo->isDeleted()) {
                    $guard = $user->getId()->toRfc4122().'|'.$dataset->getId()->toRfc4122().'|'.$todo->getId();
                    $this->beginImportGuard($guard);
                    try {
                        $todo->setDeletedAt(new \DateTimeImmutable());
                        $dataset->touch();
                    } finally {
                        $this->endImportGuard($guard);
                    }
                }
                $this->entityManager->remove($link);

                return true;
            }

            return false;
        }

        [$startAt, $endAt] = $this->eventToDates($event);
        if ($startAt === null && $endAt === null) {
            return false;
        }

        $summary = isset($event['summary']) && is_string($event['summary']) ? trim($event['summary']) : '';
        if ($summary === '') {
            $summary = 'Google event';
        }
        $description = isset($event['description']) && is_string($event['description']) ? $event['description'] : null;

        $todoId = $tadaaaTodoId ?? ($link?->getTodoId());
        $todo = $todoId !== null ? $this->todos->findOneForDataset($dataset, $todoId) : null;

        $tagIds = $this->canonicalTagIds($binding, $dataset);

        if ($todo === null) {
            $todoId = $todoId ?? ('gcal_'.bin2hex(random_bytes(8)));
            $todo = new Todo($todoId, $dataset);
            $todo->setText($summary);
            $todo->setDescription($description);
            $todo->setStartAt($startAt);
            $todo->setEndAt($endAt);
            $todo->setTagIds($tagIds);
            $this->entityManager->persist($todo);
            $link = new TodoGoogleEventLink($user, $dataset, $todo->getId(), $binding->getGoogleCalendarId(), $eventId);
            $this->entityManager->persist($link);
        } else {
            $guard = $user->getId()->toRfc4122().'|'.$dataset->getId()->toRfc4122().'|'.$todo->getId();
            $this->beginImportGuard($guard);
            try {
                $todo->setText($summary);
                $todo->setDescription($description);
                $todo->setStartAt($startAt);
                $todo->setEndAt($endAt);
                $mergedTags = array_values(array_unique([...$todo->getTagIds(), ...$tagIds]));
                $todo->setTagIds($mergedTags);
                if ($todo->isDeleted()) {
                    $todo->setDeletedAt(null);
                }
            } finally {
                $this->endImportGuard($guard);
            }
            if ($link === null) {
                $link = new TodoGoogleEventLink($user, $dataset, $todo->getId(), $binding->getGoogleCalendarId(), $eventId);
                $this->entityManager->persist($link);
            }
        }

        if (isset($event['etag']) && is_string($event['etag'])) {
            $link->setEtag($event['etag']);
        }
        $link->setContentHash($this->contentHash($todo, $binding->getGoogleCalendarId()));
        $dataset->touch();

        // Ensure extended props point back (best-effort, skip echo).
        try {
            $this->api->patchEvent($connection, $binding->getGoogleCalendarId(), $eventId, [
                'extendedProperties' => [
                    'private' => [
                        'tadaaaTodoId' => $todo->getId(),
                        'tadaaaDatasetId' => $dataset->getId()->toRfc4122(),
                        'tadaaaUserId' => $user->getId()->toRfc4122(),
                    ],
                ],
            ]);
        } catch (\Throwable) {
            // ignore
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function canonicalTagIds(GoogleCalendarBinding $binding, Dataset $dataset): array
    {
        $ids = $binding->getTagIds();
        if ($ids === []) {
            return [];
        }
        $valid = [];
        foreach ($ids as $id) {
            $tag = $this->tags->findOneForDataset($dataset, $id);
            if ($tag !== null && !$tag->isDeleted()) {
                $valid[] = $id;
            }
        }

        return $valid === [] ? [] : [$valid[0]];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function eventToDates(array $event): array
    {
        $start = $this->parseGoogleDate($event['start'] ?? null);
        $end = $this->parseGoogleDate($event['end'] ?? null);

        return [$start, $end];
    }

    private function parseGoogleDate(mixed $node): ?\DateTimeImmutable
    {
        if (!is_array($node)) {
            return null;
        }
        if (isset($node['date']) && is_string($node['date'])) {
            return TodoDate::parse($node['date']);
        }
        if (isset($node['dateTime']) && is_string($node['dateTime'])) {
            return TodoDate::parse($node['dateTime']);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function todoToEventBody(Todo $todo, Dataset $dataset, User $user): array
    {
        $startAt = $todo->getStartAt();
        $endAt = $todo->getEndAt() ?? $startAt;
        if ($startAt === null && $endAt !== null) {
            $startAt = $endAt;
        }
        \assert($startAt instanceof \DateTimeImmutable);
        \assert($endAt instanceof \DateTimeImmutable);

        $allDay = $startAt->format('H:i:s') === '00:00:00'
            && $endAt->format('H:i:s') === '00:00:00';

        if ($allDay) {
            $start = ['date' => $startAt->format('Y-m-d')];
            // Google all-day end is exclusive
            $endExclusive = $endAt->modify('+1 day');
            if ($endAt->format('Y-m-d') === $startAt->format('Y-m-d')) {
                $endExclusive = $startAt->modify('+1 day');
            }
            $end = ['date' => $endExclusive->format('Y-m-d')];
        } else {
            $start = ['dateTime' => $startAt->format(\DateTimeInterface::ATOM), 'timeZone' => 'UTC'];
            $end = ['dateTime' => $endAt->format(\DateTimeInterface::ATOM), 'timeZone' => 'UTC'];
        }

        $desc = $todo->getDescription() ?? '';
        $appLink = rtrim($this->appPublicUrl !== '' ? $this->appPublicUrl : '', '/');
        if ($appLink !== '') {
            $desc = trim($desc."\n\n".$appLink);
        }

        return [
            'summary' => $todo->getText(),
            'description' => $desc !== '' ? $desc : null,
            'start' => $start,
            'end' => $end,
            'status' => $todo->isDone() ? 'tentative' : 'confirmed',
            'extendedProperties' => [
                'private' => [
                    'tadaaaTodoId' => $todo->getId(),
                    'tadaaaDatasetId' => $dataset->getId()->toRfc4122(),
                    'tadaaaUserId' => $user->getId()->toRfc4122(),
                ],
            ],
        ];
    }

    private function contentHash(Todo $todo, string $calendarId): string
    {
        $tags = $todo->getTagIds();
        sort($tags);
        $payload = json_encode([
            'cal' => $calendarId,
            'text' => $todo->getText(),
            'description' => $todo->getDescription(),
            'done' => $todo->isDone(),
            'archived' => $todo->isArchived(),
            'deleted' => $todo->isDeleted(),
            'startAt' => TodoDate::format($todo->getStartAt()),
            'endAt' => TodoDate::format($todo->getEndAt()),
            'tagIds' => $tags,
        ], \JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    public function ensureWatch(GoogleCalendarBinding $binding): void
    {
        if (!$binding->isImportEnabled()) {
            return;
        }
        $connection = $this->connections->findOneByUser($binding->getUser());
        if ($connection === null || !$connection->isActive()) {
            return;
        }

        $expires = $binding->getWatchExpiresAt();
        if (
            $binding->getWatchChannelId() !== null
            && $expires !== null
            && $expires > new \DateTimeImmutable('+1 day')
        ) {
            return;
        }

        if ($binding->getWatchChannelId() !== null && $binding->getWatchResourceId() !== null) {
            $this->api->stopWatch($connection, $binding->getWatchChannelId(), $binding->getWatchResourceId());
        }

        $channelId = 'tadaaa-'.bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(16));
        $webhookUrl = rtrim($this->apiPublicUrl !== '' ? $this->apiPublicUrl : 'https://localhost:8443', '/').'/api/google-calendar/push';

        try {
            $watch = $this->api->watchEvents(
                $connection,
                $binding->getGoogleCalendarId(),
                $channelId,
                $webhookUrl,
                $token,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Google watch failed: {message}', ['message' => $e->getMessage()]);

            return;
        }

        $binding->setWatchChannelId($watch['id']);
        $binding->setWatchResourceId($watch['resourceId']);
        $binding->setWatchToken($token);
        if ($watch['expiration'] !== null) {
            // Google returns ms epoch as string
            $ms = (int) $watch['expiration'];
            if ($ms > 0) {
                $binding->setWatchExpiresAt((new \DateTimeImmutable())->setTimestamp((int) floor($ms / 1000)));
            }
        }
        $this->entityManager->flush();
    }

    public function stopWatch(GoogleCalendarBinding $binding): void
    {
        $connection = $this->connections->findOneByUser($binding->getUser());
        if ($connection === null) {
            return;
        }
        if ($binding->getWatchChannelId() !== null && $binding->getWatchResourceId() !== null) {
            $this->api->stopWatch($connection, $binding->getWatchChannelId(), $binding->getWatchResourceId());
        }
        $binding->setWatchChannelId(null);
        $binding->setWatchResourceId(null);
        $binding->setWatchExpiresAt(null);
        $binding->setWatchToken(null);
    }
}
