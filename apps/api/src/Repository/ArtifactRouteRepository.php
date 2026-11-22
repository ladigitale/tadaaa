<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactRoute> */
final class ArtifactRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRoute::class);
    }

    /**
     * Parcours visibles qui touchent la zone (ouest, sud, est, nord), les plus récents d'abord.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return list<ArtifactRoute>
     */
    public function inBox(Artifact $artifact, string $collection, array $bbox, int $limit): array
    {
        [$w, $s, $e, $n] = $bbox;
        /** @var list<ArtifactRoute> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.artifact = :a')->setParameter('a', $artifact)
            ->andWhere('r.collection = :c')->setParameter('c', $collection)
            ->andWhere('r.hidden = false')
            ->andWhere('r.maxLat >= :s AND r.minLat <= :n')->setParameter('s', $s)->setParameter('n', $n)
            ->andWhere('r.maxLon >= :w AND r.minLon <= :e')->setParameter('w', $w)->setParameter('e', $e)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }

    public function countFor(Artifact $artifact, string $collection): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')
            ->andWhere('r.artifact = :a')->setParameter('a', $artifact)
            ->andWhere('r.collection = :c')->setParameter('c', $collection)
            ->getQuery()->getSingleScalarResult();
    }

    public function countRecentByIp(Artifact $artifact, string $ipHash, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')
            ->andWhere('r.artifact = :a')->setParameter('a', $artifact)
            ->andWhere('r.ipHash = :ip')->setParameter('ip', $ipHash)
            ->andWhere('r.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
