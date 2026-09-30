<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactVersion>
 */
class ArtifactVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactVersion::class);
    }

    public function findOneForArtifactVersion(Artifact $artifact, int $version): ?ArtifactVersion
    {
        return $this->findOneBy(['artifact' => $artifact, 'version' => $version]);
    }

    /** @return list<ArtifactVersion> */
    public function findAllForArtifact(Artifact $artifact): array
    {
        /** @var list<ArtifactVersion> $rows */
        $rows = $this->findBy(['artifact' => $artifact], ['version' => 'DESC']);

        return $rows;
    }
}
