<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return list<AuditLog>
     */
    public function findRecentForUser(User $user, ?string $category = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)));

        if ($category !== null && $category !== '') {
            $qb->andWhere('a.category = :category')->setParameter('category', $category);
        }

        /** @var list<AuditLog> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
