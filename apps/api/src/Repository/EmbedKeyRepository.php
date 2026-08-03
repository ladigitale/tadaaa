<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmbedKey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmbedKey>
 */
class EmbedKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmbedKey::class);
    }

    public function findActiveByHash(string $hash): ?EmbedKey
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.tokenHash = :hash')
            ->andWhere('e.revokedAt IS NULL')
            ->setParameter('hash', $hash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUsableByHash(string $hash): ?EmbedKey
    {
        $key = $this->findActiveByHash($hash);
        if ($key === null || !$key->isActive()) {
            return null;
        }

        return $key;
    }

    /**
     * @return list<EmbedKey>
     */
    public function findForUser(User $user): array
    {
        /** @var list<EmbedKey> $keys */
        $keys = $this->createQueryBuilder('e')
            ->andWhere('e.owner = :owner')
            ->andWhere('e.revokedAt IS NULL')
            ->setParameter('owner', $user)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $keys;
    }
}
