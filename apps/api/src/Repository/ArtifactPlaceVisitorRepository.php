<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactPlaceVisitor;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactPlaceVisitor> (non final : doublé dans les tests) */
class ArtifactPlaceVisitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactPlaceVisitor::class);
    }

    public function findByDevice(Artifact $artifact, string $collection, string $deviceHash): ?ArtifactPlaceVisitor
    {
        return $this->findOneBy(['artifact' => $artifact, 'collection' => $collection, 'deviceHash' => $deviceHash]);
    }

    /** Blaze revendiqué par ce compte (le plus récemment modifié s'il en a plusieurs). */
    public function findByUser(Artifact $artifact, string $collection, User $user): ?ArtifactPlaceVisitor
    {
        return $this->findOneBy(['artifact' => $artifact, 'collection' => $collection, 'user' => $user], ['updatedAt' => 'DESC']);
    }
}
