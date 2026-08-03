<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\DatasetMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasetMember>
 */
class DatasetMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetMember::class);
    }

    public function findOneForUser(Dataset $dataset, User $user): ?DatasetMember
    {
        return $this->findOneBy(['dataset' => $dataset, 'user' => $user]);
    }

    /**
     * @return list<DatasetMember>
     */
    public function findAllForDataset(Dataset $dataset): array
    {
        /** @var list<DatasetMember> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.dataset = :dataset')
            ->setParameter('dataset', $dataset)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
