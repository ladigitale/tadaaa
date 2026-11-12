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

    /**
     * Runs `pending` dont le jeton a expiré (les plus anciens d'abord).
     *
     * @return list<ArtifactRun>
     */
    public function findExpiredPending(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<ArtifactRun> */
        return $this->createQueryBuilder('r')
            ->where('r.status = :pending')
            ->andWhere('r.expiresAt IS NOT NULL')
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('pending', ArtifactRun::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('r.expiresAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /** Supprime les runs terminés créés avant la date donnée (rétention). */
    public function purgeFinishedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.createdAt < :before')
            ->andWhere('r.status <> :pending')
            ->setParameter('before', $before)
            ->setParameter('pending', ArtifactRun::STATUS_PENDING)
            ->getQuery()
            ->execute();
    }
}
