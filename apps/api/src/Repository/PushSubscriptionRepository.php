<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findOneByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    /**
     * @return list<PushSubscription>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<PushSubscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<User> $users
     *
     * @return list<PushSubscription>
     */
    public function findActiveForUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        /** @var list<PushSubscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.user IN (:users)')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
