<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ModerationCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ModerationCase> */
class ModerationCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationCase::class);
    }

    public function findTarget(string $kind, Uuid $targetId): ?ModerationCase
    {
        return $this->findOneBy(['kind' => $kind, 'targetId' => $targetId]);
    }

    /**
     * File de modération : à traiter d'abord (du plus récent signalement au plus ancien), puis les décisions récentes.
     *
     * @return list<ModerationCase>
     */
    public function queue(?string $status, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')->join('c.artifact', 'a')->addSelect('a');
        if ($status !== null) {
            $qb->andWhere('c.status = :s')->setParameter('s', $status);
        }

        /** @var list<ModerationCase> */
        return $qb->orderBy('c.lastReportedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countOpen(): int
    {
        return (int) $this->count(['status' => 'open']);
    }
}
