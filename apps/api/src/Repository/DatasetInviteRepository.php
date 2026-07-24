<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DatasetInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasetInvite>
 */
class DatasetInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetInvite::class);
    }

    public function findOneByToken(string $token): ?DatasetInvite
    {
        return $this->findOneBy(['token' => $token]);
    }
}
