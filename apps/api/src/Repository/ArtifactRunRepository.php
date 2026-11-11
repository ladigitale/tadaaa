<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactRun>
 */
class ArtifactRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRun::class);
    }

    /**
     * @return list<ArtifactRun>
     */
    public function findRecentForArtifact(Artifact $artifact, int $limit = 20): array
    {
        /** @var list<ArtifactRun> */
        return $this->findBy(['artifact' => $artifact], ['createdAt' => 'DESC'], max(1, min($limit, 100)));
    }
}
