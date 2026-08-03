<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessToken;
use App\Entity\User;
use App\Service\AccessTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/access-tokens')]
#[IsGranted('ROLE_USER')]
final class AccessTokenController extends AbstractController
{
    public function __construct(private readonly AccessTokenService $tokens)
    {
    }

    #[Route('', name: 'api_access_tokens_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'member' => array_map($this->serialize(...), $this->tokens->listForUser($user)),
        ]);
    }

    #[Route('', name: 'api_access_tokens_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array{name?: string} $payload */
        $payload = json_decode($request->getContent(), true) ?? [];
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : 'MCP';

        $created = $this->tokens->create($user, $name);

        return $this->json([
            'token' => $this->serialize($created['token']),
            // Affiché une seule fois — à copier pour Cursor / agents
            'plainToken' => $created['plainToken'],
            'mcpUrl' => $this->mcpUrl($request),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_access_tokens_revoke', methods: ['DELETE'])]
    public function revoke(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->tokens->revoke($user, $id)) {
            return $this->json(['error' => 'Token introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array{id: string, name: string, tokenPrefix: string, createdAt: string, lastUsedAt: ?string} */
    private function serialize(AccessToken $token): array
    {
        return [
            'id' => $token->getId()->toRfc4122(),
            'name' => $token->getName(),
            'tokenPrefix' => $token->getTokenPrefix(),
            'createdAt' => $token->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $token->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function mcpUrl(Request $request): string
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        return $base.'/mcp';
    }
}
