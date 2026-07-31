<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PushFeature;
use App\Service\PushSubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/push')]
#[IsGranted('ROLE_USER')]
final class PushController extends AbstractController
{
    public function __construct(
        private readonly PushFeature $push,
        private readonly PushSubscriptionService $subscriptions,
    ) {
    }

    #[Route('/vapid-public-key', name: 'api_push_vapid_public_key', methods: ['GET'])]
    public function vapidPublicKey(): JsonResponse
    {
        if (!$this->push->isEnabled()) {
            return $this->json([
                'publicKey' => null,
                'enabled' => false,
            ]);
        }

        return $this->json([
            'publicKey' => $this->push->getPublicKey(),
            'enabled' => true,
        ]);
    }

    #[Route('/subscriptions', name: 'api_push_subscriptions_upsert', methods: ['POST'])]
    public function upsertSubscription(Request $request): JsonResponse
    {
        if (!$this->push->isEnabled()) {
            return $this->json(['error' => 'Web Push is not configured on this server.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $result = $this->subscriptions->upsert($this->user(), $this->decodeJson($request));

        return $this->json($result, Response::HTTP_CREATED);
    }

    #[Route('/subscriptions', name: 'api_push_subscriptions_revoke', methods: ['DELETE'])]
    public function revokeSubscription(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $endpoint = is_string($payload['endpoint'] ?? null) ? $payload['endpoint'] : '';
        $this->subscriptions->revoke($this->user(), $endpoint);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array<string, mixed> */
    private function decodeJson(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
