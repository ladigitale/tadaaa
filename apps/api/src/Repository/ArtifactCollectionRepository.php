<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactCollection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactCollection>
 */
class ArtifactCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactCollection::class);
    }

    public function findOneByArtifactName(Artifact $artifact, string $name): ?ArtifactCollection
    {
        return $this->findOneBy(['artifact' => $artifact, 'name' => $name]);
    }
}
