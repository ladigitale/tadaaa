<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessToken>
 */
class AccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessToken::class);
    }

    public function findActiveByHash(string $hash): ?AccessToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tokenHash = :hash')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('hash', $hash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<AccessToken>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<AccessToken> $tokens */
        $tokens = $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('owner', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $tokens;
    }
}
