<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactWorkflow;
use App\Entity\ArtifactWorkflowRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactWorkflowRun>
 */
class ArtifactWorkflowRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactWorkflowRun::class);
    }

    /** @return list<ArtifactWorkflowRun> */
    public function findRecent(ArtifactWorkflow $workflow, int $limit = 20): array
    {
        /** @var list<ArtifactWorkflowRun> */
        return $this->findBy(['workflow' => $workflow], ['createdAt' => 'DESC'], max(1, min($limit, 100)));
    }

    /** Supprime les runs de workflow terminés créés avant la date donnée (rétention). */
    public function purgeFinishedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.createdAt < :before')
            ->andWhere('r.status IN (:done)')
            ->setParameter('before', $before)
            ->setParameter('done', [ArtifactWorkflowRun::STATUS_COMPLETED, ArtifactWorkflowRun::STATUS_FAILED, ArtifactWorkflowRun::STATUS_CANCELLED])
            ->getQuery()
            ->execute();
    }
}
