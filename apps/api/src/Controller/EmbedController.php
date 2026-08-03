<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\EmbedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/embeds')]
#[IsGranted('ROLE_USER')]
final class EmbedController extends AbstractController
{
    public function __construct(private readonly EmbedService $embeds)
    {
    }

    #[Route('', name: 'api_embeds_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'member' => array_map($this->embeds->serialize(...), $this->embeds->listForUser($user)),
        ]);
    }

    #[Route('', name: 'api_embeds_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        $name = is_string($payload['name'] ?? null) ? $payload['name'] : '';
        $datasetId = is_string($payload['datasetId'] ?? null) ? $payload['datasetId'] : '';
        $allowedOrigins = is_array($payload['allowedOrigins'] ?? null)
            ? array_values(array_map('strval', $payload['allowedOrigins']))
            : [];
        $tagIds = is_array($payload['tagIds'] ?? null)
            ? array_values(array_map('strval', $payload['tagIds']))
            : [];
        $includeDone = (bool) ($payload['includeDone'] ?? false);
        $includeDescription = (bool) ($payload['includeDescription'] ?? false);
        $rateLimit = isset($payload['rateLimitPerMinute']) ? (int) $payload['rateLimitPerMinute'] : 60;

        $created = $this->embeds->create(
            $user,
            $name,
            $datasetId,
            $allowedOrigins,
            $tagIds,
            $includeDone,
            $includeDescription,
            $rateLimit,
        );

        return $this->json([
            'embed' => $this->embeds->serialize($created['key']),
            'plainToken' => $created['plainToken'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_embeds_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['embed' => $this->embeds->serialize($this->embeds->getForUser($user, $id))]);
    }

    #[Route('/{id}', name: 'api_embeds_update', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json([
            'embed' => $this->embeds->serialize($this->embeds->update($user, $id, $payload)),
        ]);
    }

    #[Route('/{id}/rotate', name: 'api_embeds_rotate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function rotate(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $rotated = $this->embeds->rotate($user, $id);

        return $this->json([
            'embed' => $this->embeds->serialize($rotated['key']),
            'plainToken' => $rotated['plainToken'],
        ]);
    }

    #[Route('/{id}', name: 'api_embeds_revoke', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function revoke(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->embeds->revoke($user, $id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
