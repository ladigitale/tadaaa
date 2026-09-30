<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactCollection;
use App\Entity\ArtifactRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactRecord>
 */
class ArtifactRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRecord::class);
    }

    /**
     * @return list<ArtifactRecord>
     */
    public function findForCollection(ArtifactCollection $collection, ?User $ownerFilter = null): array
    {
        $criteria = ['collection' => $collection];
        if ($ownerFilter !== null) {
            $criteria['createdBy'] = $ownerFilter;
        }

        /** @var list<ArtifactRecord> $rows */
        $rows = $this->findBy($criteria, ['createdAt' => 'DESC']);

        return $rows;
    }
}
