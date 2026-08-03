<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\BandwidthQuota;
use App\Service\StorageQuota;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/quotas')]
#[IsGranted('ROLE_USER')]
final class QuotaController extends AbstractController
{
    public function __construct(
        private readonly StorageQuota $storageQuota,
        private readonly BandwidthQuota $bandwidthQuota,
    ) {
    }

    #[Route('', name: 'api_quotas', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'storage' => $this->storageQuota->report($user),
            'bandwidth' => $this->bandwidthQuota->report($user),
        ]);
    }
}
