<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactSave;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactSave>
 */
class ArtifactSaveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactSave::class);
    }

    public function findByKeyHash(Artifact $artifact, string $keyHash): ?ArtifactSave
    {
        return $this->findOneBy(['artifact' => $artifact, 'keyHash' => $keyHash]);
    }

    /** La sauvegarde la plus récente rattachée au compte. */
    public function findLatestForOwner(Artifact $artifact, User $owner): ?ArtifactSave
    {
        return $this->findOneBy(['artifact' => $artifact, 'owner' => $owner], ['updatedAt' => 'DESC']);
    }
}
