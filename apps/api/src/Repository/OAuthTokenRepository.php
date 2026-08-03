<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthToken>
 */
class OAuthTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthToken::class);
    }

    public function findActiveAccessByHash(string $hash): ?OAuthToken
    {
        $token = $this->findOneBy(['accessTokenHash' => $hash]);
        if (!$token instanceof OAuthToken || $token->isRevoked() || $token->isAccessExpired()) {
            return null;
        }

        return $token;
    }

    public function findActiveRefreshByHash(string $hash): ?OAuthToken
    {
        $token = $this->findOneBy(['refreshTokenHash' => $hash]);
        if (!$token instanceof OAuthToken || $token->isRevoked() || $token->isRefreshExpired()) {
            return null;
        }

        return $token;
    }
}
