<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactPlaceVisitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactPlaceVisitor> */
final class ArtifactPlaceVisitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactPlaceVisitor::class);
    }

    public function findByDevice(Artifact $artifact, string $collection, string $deviceHash): ?ArtifactPlaceVisitor
    {
        return $this->findOneBy(['artifact' => $artifact, 'collection' => $collection, 'deviceHash' => $deviceHash]);
    }
}
