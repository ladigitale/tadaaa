<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\AppearanceConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public appearance API for sister SPAs (Belts fills --sc-* from this).
 * Authenticated PUT stores the user's preferred themeId.
 */
#[Route('/api/appearance')]
final class AppearanceController extends AbstractController
{
    public function __construct(
        private readonly AppearanceConfig $appearance,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Theme CSS variables + shell.
     * Query `?theme=` optional — if omitted and user is logged in, uses account themeId.
     */
    #[Route('', name: 'api_appearance', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $themeId = $request->query->getString('theme');
        if ($themeId === '') {
            $user = $this->getUser();
            if ($user instanceof User) {
                $themeId = $user->getThemeId();
            }
        }

        $resolved = $this->appearance->resolve($themeId !== '' ? $themeId : null);
        if ($resolved === null) {
            return $this->json(['error' => 'Unknown theme.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($resolved);
    }

    /** Persist theme preference on the account (sister apps poll / receive via handoff). */
    #[Route('', name: 'api_appearance_update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function update(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $themeId = \is_string($body['themeId'] ?? null) ? $body['themeId'] : '';
        $resolved = $this->appearance->resolve($themeId !== '' ? $themeId : null);
        if ($resolved === null) {
            return $this->json(['error' => 'Unknown theme.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setThemeId($resolved['themeId']);
        $this->em->flush();

        return $this->json($resolved);
    }

    /** Catalog without CSS maps (picker UI). */
    #[Route('/themes', name: 'api_appearance_themes', methods: ['GET'])]
    public function themes(): JsonResponse
    {
        return $this->json([
            'member' => $this->appearance->listThemes(),
        ]);
    }
}
