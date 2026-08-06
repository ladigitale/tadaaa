<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LegalConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/legal')]
final class LegalController extends AbstractController
{
    public function __construct(
        private readonly LegalConfig $legal,
    ) {
    }

    #[Route('', name: 'api_legal', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json($this->legal->toPublicArray());
    }
}
