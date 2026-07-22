<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessToken;
use App\Entity\User;
use App\Repository\AccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AccessTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccessTokenRepository $tokens,
    ) {
    }

    /**
     * @return array{token: AccessToken, plainToken: string}
     */
    public function create(User $user, string $name): array
    {
        $plain = 'tada_'.bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, 12);

        $token = new AccessToken($user, $name !== '' ? $name : 'MCP', $hash, $prefix);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return ['token' => $token, 'plainToken' => $plain];
    }

    public function revoke(User $user, string $id): bool
    {
        $token = $this->tokens->find($id);
        if (!$token instanceof AccessToken) {
            return false;
        }
        if ($token->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            return false;
        }
        if ($token->isRevoked()) {
            return true;
        }

        $token->revoke();
        $this->entityManager->flush();

        return true;
    }

    /**
     * @return list<AccessToken>
     */
    public function listForUser(User $user): array
    {
        return $this->tokens->findActiveForUser($user);
    }

    public function authenticate(string $plainToken): ?User
    {
        if (!str_starts_with($plainToken, 'tada_')) {
            return null;
        }

        $token = $this->tokens->findActiveByHash(hash('sha256', $plainToken));
        if ($token === null) {
            return null;
        }

        $token->touch();
        $this->entityManager->flush();

        return $token->getOwner();
    }
}
