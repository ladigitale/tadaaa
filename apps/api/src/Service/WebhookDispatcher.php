<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Dataset;
use App\Entity\DatasetMemberRole;
use App\Entity\User;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Repository\DatasetMemberRepository;
use App\Repository\WebhookEndpointRepository;
use App\Util\BaseIdParser;
use App\Webhook\WebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dispatches domain events to matching webhook endpoints (best-effort).
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebhookEndpointRepository $endpoints,
        private readonly DatasetMemberRepository $members,
        private readonly HttpClientInterface $httpClient,
        private readonly AuditLogger $audit,
        private readonly UsageMeter $usage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function dispatch(
        Dataset $dataset,
        string $eventType,
        array $data,
        ?User $actor = null,
    ): void {
        if (!WebhookEventType::isValid($eventType)) {
            return;
        }

        try {
            $candidates = $this->candidateOwners($dataset);
            $targets = $this->endpoints->findActiveForDatasetEvent($dataset, $candidates, $eventType);
            if ($targets === []) {
                return;
            }

            $eventId = 'evt_'.bin2hex(random_bytes(12));
            $payload = [
                'id' => $eventId,
                'type' => $eventType,
                'time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'datasetId' => $dataset->getId()->toRfc4122(),
                'baseId' => BaseIdParser::format($dataset->getBaseId()),
                'actor' => $actor !== null
                    ? [
                        'userId' => $actor->getId()->toRfc4122(),
                        'email' => $actor->getEmail(),
                    ]
                    : null,
                'data' => $data,
            ];
            $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $bytes = \strlen($body);

            foreach ($targets as $endpoint) {
                $this->deliver($endpoint, $eventId, $eventType, $body, $bytes, $dataset);
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook dispatch failed: {message}', [
                'message' => $e->getMessage(),
                'type' => $eventType,
            ]);
        }
    }

    /**
     * Deliver a ping (or arbitrary payload) to a single endpoint owned by the user.
     *
     * @param array<string, mixed> $data
     */
    public function dispatchToEndpoint(
        WebhookEndpoint $endpoint,
        string $eventType,
        array $data,
        ?User $actor = null,
        ?Dataset $dataset = null,
    ): WebhookDelivery {
        $eventId = 'evt_'.bin2hex(random_bytes(12));
        $resolvedDataset = $dataset ?? $endpoint->getDataset();
        $payload = [
            'id' => $eventId,
            'type' => $eventType,
            'time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'datasetId' => $resolvedDataset?->getId()->toRfc4122(),
            'baseId' => $resolvedDataset !== null
                ? BaseIdParser::format($resolvedDataset->getBaseId())
                : null,
            'actor' => $actor !== null
                ? [
                    'userId' => $actor->getId()->toRfc4122(),
                    'email' => $actor->getEmail(),
                ]
                : null,
            'data' => $data,
        ];
        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $bytes = \strlen($body);
        $delivery = $this->deliver($endpoint, $eventId, $eventType, $body, $bytes, $resolvedDataset);
        $this->entityManager->flush();

        return $delivery;
    }

    private function deliver(
        WebhookEndpoint $endpoint,
        string $eventId,
        string $eventType,
        string $body,
        int $bytes,
        ?Dataset $dataset,
    ): WebhookDelivery {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->getSigningSecret());
        $started = hrtime(true);
        $httpStatus = null;
        $error = null;
        $ok = false;

        try {
            $response = $this->httpClient->request('POST', $endpoint->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Tadaaa-Webhooks/1.0',
                    'X-Tadaaa-Event' => $eventType,
                    'X-Tadaaa-Delivery' => $eventId,
                    'X-Tadaaa-Signature' => 't='.$timestamp.',v1='.$signature,
                ],
                'body' => $body,
                'timeout' => 5,
                'max_redirects' => 0,
            ]);
            $httpStatus = $response->getStatusCode();
            $ok = $httpStatus >= 200 && $httpStatus < 300;
            if (!$ok) {
                $error = 'HTTP '.$httpStatus;
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $status = $ok ? WebhookDelivery::STATUS_SUCCESS : WebhookDelivery::STATUS_FAILED;
        $delivery = new WebhookDelivery(
            $endpoint,
            $eventId,
            $eventType,
            $status,
            $bytes,
            $httpStatus,
            $elapsedMs,
            $error,
        );
        $this->entityManager->persist($delivery);

        if ($ok) {
            $endpoint->markDeliverySuccess();
        } else {
            $endpoint->markDeliveryFailure();
            $this->audit->queue($endpoint->getOwner(), AuditLog::CATEGORY_WEBHOOK, 'webhook.delivery_failed', [
                'endpointId' => $endpoint->getId()->toRfc4122(),
                'eventType' => $eventType,
                'eventId' => $eventId,
                'httpStatus' => $httpStatus,
                'error' => $error,
            ]);
        }

        $owner = $endpoint->getOwner();
        $this->usage->increment($owner, $dataset, UsageMeter::WEBHOOK_DELIVERIES, 1, flush: false);
        $this->usage->increment($owner, $dataset, UsageMeter::WEBHOOK_BYTES, $bytes, flush: false);
        if (!$ok) {
            $this->usage->increment($owner, $dataset, UsageMeter::WEBHOOK_FAILURES, 1, flush: false);
        }

        return $delivery;
    }

    /**
     * @return list<User>
     */
    private function candidateOwners(Dataset $dataset): array
    {
        $owners = [$dataset->getOwner()];
        foreach ($this->members->findAllForDataset($dataset) as $member) {
            if ($member->getRole() === DatasetMemberRole::Writer) {
                $owners[] = $member->getUser();
            }
        }

        return $owners;
    }
}
