<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactRunLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactRunLink>
 */
class ArtifactRunLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRunLink::class);
    }

    /** @return list<ArtifactRunLink> */
    public function findActiveFrom(ArtifactRun $run): array
    {
        /** @var list<ArtifactRunLink> */
        return $this->findBy(['fromRun' => $run, 'active' => true], ['createdAt' => 'ASC']);
    }

    /** @return list<ArtifactRunLink> liens où le run est source ou cible */
    public function findForRun(ArtifactRun $run): array
    {
        /** @var list<ArtifactRunLink> */
        return $this->createQueryBuilder('l')
            ->where('l.fromRun = :r OR l.toRun = :r')
            ->setParameter('r', $run)
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveFrom(ArtifactRun $run): int
    {
        return $this->count(['fromRun' => $run, 'active' => true]);
    }
}
