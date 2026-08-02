<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookEndpointRepository;
use App\Webhook\WebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class WebhookService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebhookEndpointRepository $endpoints,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly DatasetAccessService $access,
        private readonly WebhookDispatcher $dispatcher,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<WebhookEndpoint>
     */
    public function listForUser(User $user): array
    {
        return $this->endpoints->findActiveForUser($user);
    }

    public function getForUser(User $user, string $id): WebhookEndpoint
    {
        $endpoint = $this->endpoints->find($id);
        if (!$endpoint instanceof WebhookEndpoint) {
            throw new NotFoundHttpException('Webhook introuvable.');
        }
        if (!$endpoint->getOwner()->getId()->equals($user->getId())) {
            throw new NotFoundHttpException('Webhook introuvable.');
        }

        return $endpoint;
    }

    /**
     * @param list<string>|null $events
     *
     * @return array{endpoint: WebhookEndpoint, plainSecret: string}
     */
    public function create(
        User $user,
        string $url,
        ?array $events = null,
        ?string $datasetId = null,
    ): array {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'http')) {
            throw new BadRequestHttpException('URL webhook invalide (http/https requis).');
        }

        $dataset = $this->resolveDataset($user, $datasetId);
        $normalizedEvents = WebhookEventType::normalizeList($events ?? []);
        $plain = 'whsec_'.bin2hex(random_bytes(24));
        $prefix = substr($plain, 0, 12);

        $endpoint = new WebhookEndpoint($user, $url, $plain, $prefix, $normalizedEvents, $dataset);
        $this->entityManager->persist($endpoint);
        $this->entityManager->flush();

        $this->audit->log($user, AuditLog::CATEGORY_WEBHOOK, 'webhook.subscribed', [
            'endpointId' => $endpoint->getId()->toRfc4122(),
            'url' => $url,
            'datasetId' => $dataset?->getId()->toRfc4122(),
            'events' => $normalizedEvents,
        ]);

        return ['endpoint' => $endpoint, 'plainSecret' => $plain];
    }

    /**
     * @param array{
     *   url?: string,
     *   events?: list<string>,
     *   active?: bool,
     *   datasetId?: string|null
     * } $patch
     */
    public function update(User $user, string $id, array $patch): WebhookEndpoint
    {
        $endpoint = $this->getForUser($user, $id);

        if (array_key_exists('url', $patch) && is_string($patch['url'])) {
            $url = trim($patch['url']);
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'http')) {
                throw new BadRequestHttpException('URL webhook invalide (http/https requis).');
            }
            $endpoint->setUrl($url);
        }

        if (array_key_exists('events', $patch) && is_array($patch['events'])) {
            $endpoint->setEvents(WebhookEventType::normalizeList(
                array_values(array_map('strval', $patch['events'])),
            ));
        }

        if (array_key_exists('active', $patch)) {
            $endpoint->setActive((bool) $patch['active']);
        }

        if (array_key_exists('datasetId', $patch)) {
            $datasetId = $patch['datasetId'];
            if ($datasetId === null || $datasetId === '') {
                $endpoint->setDataset(null);
            } elseif (is_string($datasetId)) {
                $endpoint->setDataset($this->resolveDataset($user, $datasetId));
            }
        }

        $this->entityManager->flush();
        $this->audit->log($user, AuditLog::CATEGORY_WEBHOOK, 'webhook.updated', [
            'endpointId' => $endpoint->getId()->toRfc4122(),
            'active' => $endpoint->isActive(),
            'events' => $endpoint->getEvents(),
            'datasetId' => $endpoint->getDataset()?->getId()->toRfc4122(),
        ]);

        return $endpoint;
    }

    public function delete(User $user, string $id): void
    {
        $endpoint = $this->getForUser($user, $id);
        $endpointId = $endpoint->getId()->toRfc4122();
        $this->entityManager->remove($endpoint);
        $this->entityManager->flush();
        $this->audit->log($user, AuditLog::CATEGORY_WEBHOOK, 'webhook.unsubscribed', [
            'endpointId' => $endpointId,
        ]);
    }

    /**
     * @return list<WebhookDelivery>
     */
    public function listDeliveries(User $user, string $id, int $limit = 50): array
    {
        $endpoint = $this->getForUser($user, $id);

        return $this->deliveries->findRecentForEndpoint($endpoint, $limit);
    }

    public function ping(User $user, string $id): WebhookDelivery
    {
        $endpoint = $this->getForUser($user, $id);

        return $this->dispatcher->dispatchToEndpoint(
            $endpoint,
            WebhookEventType::WEBHOOK_PING,
            ['ok' => true],
            $user,
            $endpoint->getDataset(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->getId()->toRfc4122(),
            'url' => $endpoint->getUrl(),
            'secretPrefix' => $endpoint->getSecretPrefix(),
            'events' => $endpoint->getEvents(),
            'datasetId' => $endpoint->getDataset()?->getId()->toRfc4122(),
            'active' => $endpoint->isActive(),
            'createdAt' => $endpoint->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastDeliveryAt' => $endpoint->getLastDeliveryAt()?->format(\DateTimeInterface::ATOM),
            'failureCount' => $endpoint->getFailureCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getId()->toRfc4122(),
            'eventId' => $delivery->getEventId(),
            'eventType' => $delivery->getEventType(),
            'status' => $delivery->getStatus(),
            'httpStatus' => $delivery->getHttpStatus(),
            'responseMs' => $delivery->getResponseMs(),
            'error' => $delivery->getError(),
            'requestBytes' => $delivery->getRequestBytes(),
            'createdAt' => $delivery->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveDataset(User $user, ?string $datasetId): ?Dataset
    {
        if ($datasetId === null || trim($datasetId) === '') {
            return null;
        }

        try {
            $uuid = Uuid::fromString(trim($datasetId));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('datasetId invalide.');
        }

        $dataset = $this->access->requireAccessibleById($user, $uuid);
        $this->access->assertCanWrite($user, $dataset);

        return $dataset;
    }
}
