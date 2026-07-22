<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Dataset>
 */
class DatasetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dataset::class);
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByBaseIdForUser(User $user, Uuid $baseId): ?Dataset
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.owner = :owner')
            ->andWhere('d.baseId = :baseId')
            ->setParameter('owner', $user)
            ->setParameter('baseId', $baseId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByNameForUser(User $user, string $name): ?Dataset
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.owner = :owner')
            ->andWhere('d.name = :name')
            ->setParameter('owner', $user)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
