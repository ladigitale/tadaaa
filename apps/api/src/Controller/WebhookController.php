<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\WebhookService;
use App\Webhook\WebhookEventType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/webhooks')]
#[IsGranted('ROLE_USER')]
final class WebhookController extends AbstractController
{
    public function __construct(private readonly WebhookService $webhooks)
    {
    }

    #[Route('/events', name: 'api_webhooks_events', methods: ['GET'])]
    public function events(): JsonResponse
    {
        return $this->json(['member' => WebhookEventType::catalogue()]);
    }

    #[Route('', name: 'api_webhooks_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'member' => array_map($this->webhooks->serialize(...), $this->webhooks->listForUser($user)),
        ]);
    }

    #[Route('', name: 'api_webhooks_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array{url?: string, events?: list<string>, datasetId?: string|null} $payload */
        $payload = json_decode($request->getContent(), true) ?? [];
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : '';
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : null;
        $datasetId = array_key_exists('datasetId', $payload) && is_string($payload['datasetId'])
            ? $payload['datasetId']
            : null;

        $created = $this->webhooks->create($user, $url, $events, $datasetId);

        return $this->json([
            'webhook' => $this->webhooks->serialize($created['endpoint']),
            'plainSecret' => $created['plainSecret'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_webhooks_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['webhook' => $this->webhooks->serialize($this->webhooks->getForUser($user, $id))]);
    }

    #[Route('/{id}', name: 'api_webhooks_update', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array{url?: string, events?: list<string>, active?: bool, datasetId?: string|null} $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json([
            'webhook' => $this->webhooks->serialize($this->webhooks->update($user, $id, $payload)),
        ]);
    }

    #[Route('/{id}', name: 'api_webhooks_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->webhooks->delete($user, $id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/deliveries', name: 'api_webhooks_deliveries', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function deliveries(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));

        return $this->json([
            'member' => array_map(
                $this->webhooks->serializeDelivery(...),
                $this->webhooks->listDeliveries($user, $id, $limit),
            ),
        ]);
    }

    #[Route('/{id}/ping', name: 'api_webhooks_ping', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function ping(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $delivery = $this->webhooks->ping($user, $id);

        return $this->json(['delivery' => $this->webhooks->serializeDelivery($delivery)]);
    }
}
