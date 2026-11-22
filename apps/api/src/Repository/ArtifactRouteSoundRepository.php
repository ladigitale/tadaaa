<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactRoute;
use App\Entity\ArtifactRouteSound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactRouteSound> */
final class ArtifactRouteSoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRouteSound::class);
    }

    public function forStep(ArtifactRoute $route, int $step): ?ArtifactRouteSound
    {
        return $this->findOneBy(['route' => $route, 'step' => $step]);
    }
}
