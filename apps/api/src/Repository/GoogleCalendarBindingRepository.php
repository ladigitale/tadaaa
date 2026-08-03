<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\GoogleCalendarBinding;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoogleCalendarBinding>
 */
class GoogleCalendarBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleCalendarBinding::class);
    }

    /**
     * @return list<GoogleCalendarBinding>
     */
    public function findForUser(User $user): array
    {
        /** @var list<GoogleCalendarBinding> $rows */
        $rows = $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.priority', 'DESC')
            ->addOrderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<GoogleCalendarBinding>
     */
    public function findForUserAndDataset(User $user, Dataset $dataset): array
    {
        /** @var list<GoogleCalendarBinding> $rows */
        $rows = $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.dataset = :dataset')
            ->setParameter('user', $user)
            ->setParameter('dataset', $dataset)
            ->orderBy('b.priority', 'DESC')
            ->addOrderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<GoogleCalendarBinding>
     */
    public function findExportEnabledForDataset(Dataset $dataset): array
    {
        /** @var list<GoogleCalendarBinding> $rows */
        $rows = $this->createQueryBuilder('b')
            ->andWhere('b.dataset = :dataset')
            ->andWhere('b.exportEnabled = true')
            ->setParameter('dataset', $dataset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneByWatchChannelId(string $channelId): ?GoogleCalendarBinding
    {
        return $this->findOneBy(['watchChannelId' => $channelId]);
    }
}
