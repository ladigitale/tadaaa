<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
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
    public function __construct(private readonly SyncService $sync)
    {
    }

    #[Route('/{baseId}/sync', name: 'api_dataset_sync_pull', methods: ['GET'])]
    public function pull(string $baseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->sync->pull(
            $user,
            $baseId,
            $request->query->getString('since') ?: null,
        ));
    }

    #[Route('/{baseId}/sync/push', name: 'api_dataset_sync_push', methods: ['POST'])]
    public function push(string $baseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json($this->sync->push($user, $baseId, $payload));
    }

    #[Route('/{baseId}/sync/bootstrap', name: 'api_dataset_sync_bootstrap', methods: ['POST'])]
    public function bootstrap(string $baseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json(
            $this->sync->bootstrap($user, $baseId, $payload),
            Response::HTTP_CREATED,
        );
    }
}
