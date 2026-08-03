<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\BandwidthQuota;
use App\Service\StorageQuota;
use App\Service\SyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/datasets')]
#[IsGranted('ROLE_USER')]
final class SyncController extends AbstractController
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly StorageQuota $storageQuota,
        private readonly BandwidthQuota $bandwidthQuota,
    ) {
    }

    #[Route('/{baseId}/sync', name: 'api_dataset_sync_pull', methods: ['GET'])]
    public function pull(string $baseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->bandwidthQuota->assertCanTransfer($user);

        $payload = $this->sync->pull(
            $user,
            $baseId,
            $request->query->getString('since') ?: null,
        );
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        $this->bandwidthQuota->recordTransfer($user, null, \strlen($encoded) + \strlen($request->getContent()));

        return $this->json($payload);
    }

    #[Route('/{baseId}/sync/push', name: 'api_dataset_sync_push', methods: ['POST'])]
    public function push(string $baseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $raw = $request->getContent();
        $this->bandwidthQuota->assertCanTransfer($user, \strlen($raw));
        $this->storageQuota->assertCanGrow($user);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?? [];

        $result = $this->sync->push($user, $baseId, $payload);
        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);
        $this->bandwidthQuota->recordTransfer($user, null, \strlen($raw) + \strlen($encoded));

        return $this->json($result);
    }

    #[Route('/{baseId}/sync/bootstrap', name: 'api_dataset_sync_bootstrap', methods: ['POST'])]
    public function bootstrap(string $baseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $raw = $request->getContent();
        $this->bandwidthQuota->assertCanTransfer($user, \strlen($raw));
        $this->storageQuota->assertCanGrow($user, \strlen($raw));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?? [];

        $result = $this->sync->bootstrap($user, $baseId, $payload);
        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);
        $this->bandwidthQuota->recordTransfer($user, null, \strlen($raw) + \strlen($encoded));

        return $this->json($result, Response::HTTP_CREATED);
    }
}
