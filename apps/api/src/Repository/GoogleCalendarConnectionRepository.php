<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GoogleCalendarConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoogleCalendarConnection>
 */
class GoogleCalendarConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleCalendarConnection::class);
    }

    public function findOneByUser(User $user): ?GoogleCalendarConnection
    {
        return $this->findOneBy(['user' => $user]);
    }
}
