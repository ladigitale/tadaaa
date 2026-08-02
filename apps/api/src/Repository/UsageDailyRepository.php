<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\UsageDaily;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UsageDaily>
 */
class UsageDailyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageDaily::class);
    }

    public function findOneFor(User $owner, \DateTimeImmutable $day, ?Dataset $dataset): ?UsageDaily
    {
        $key = $dataset?->getId()->toRfc4122() ?? '_';

        return $this->createQueryBuilder('u')
            ->andWhere('u.owner = :owner')
            ->andWhere('u.day = :day')
            ->andWhere('u.datasetKey = :key')
            ->setParameter('owner', $owner)
            ->setParameter('day', $day->setTime(0, 0))
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<UsageDaily>
     */
    public function findRange(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<UsageDaily> $rows */
        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.owner = :owner')
            ->andWhere('u.day >= :from')
            ->andWhere('u.day <= :to')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(0, 0))
            ->orderBy('u.day', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
