<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactPlace>
 */
class ArtifactPlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactPlace::class);
    }

    /**
     * Lieux dans un rectangle de latitude / longitude (filtre fin par distance côté service).
     *
     * @return list<ArtifactPlace>
     */
    public function findInBox(Artifact $artifact, string $collection, float $latMin, float $latMax, float $lonMin, float $lonMax, bool $withHidden, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.artifact = :a')->setParameter('a', $artifact)
            ->andWhere('p.collection = :c')->setParameter('c', $collection)
            ->andWhere('p.lat BETWEEN :la0 AND :la1')->setParameter('la0', $latMin)->setParameter('la1', $latMax)
            ->andWhere('p.lon BETWEEN :lo0 AND :lo1')->setParameter('lo0', $lonMin)->setParameter('lo1', $lonMax)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);
        if (!$withHidden) {
            $qb->andWhere('p.hidden = false');
        }

        /** @var list<ArtifactPlace> */
        return $qb->getQuery()->getResult();
    }

    public function countInBox(Artifact $artifact, string $collection, float $latMin, float $latMax, float $lonMin, float $lonMax): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('p.artifact = :a')->setParameter('a', $artifact)
            ->andWhere('p.collection = :c')->setParameter('c', $collection)
            ->andWhere('p.hidden = false')
            ->andWhere('p.lat BETWEEN :la0 AND :la1')->setParameter('la0', $latMin)->setParameter('la1', $latMax)
            ->andWhere('p.lon BETWEEN :lo0 AND :lo1')->setParameter('lo0', $lonMin)->setParameter('lo1', $lonMax)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Regroupe les lieux visibles d'une zone par cases de grille (carte dézoomée) : centre moyen et nombre par case.
     *
     * @return list<array{lat: float, lon: float, count: int}>
     */
    public function clustersInBox(Artifact $artifact, string $collection, float $latMin, float $latMax, float $lonMin, float $lonMax, float $cellLat, float $cellLon, int $limit): array
    {
        $sql = 'SELECT AVG(lat) AS lat, AVG(lon) AS lon, COUNT(*) AS n FROM artifact_places
                WHERE artifact_id = :a AND collection = :c AND hidden = false
                  AND lat BETWEEN :la0 AND :la1 AND lon BETWEEN :lo0 AND :lo1
                GROUP BY FLOOR(lat / :cl), FLOOR(lon / :co)
                ORDER BY n DESC
                LIMIT '.max(1, min(1000, $limit));
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'a' => $artifact->getId()->toRfc4122(), 'c' => $collection,
            'la0' => $latMin, 'la1' => $latMax, 'lo0' => $lonMin, 'lo1' => $lonMax,
            'cl' => $cellLat, 'co' => $cellLon,
        ]);

        return array_map(static fn (array $r): array => ['lat' => round((float) $r['lat'], 6), 'lon' => round((float) $r['lon'], 6), 'count' => (int) $r['n']], $rows);
    }

    /** @return list<ArtifactPlace> */
    public function findLatest(Artifact $artifact, string $collection, bool $withHidden, int $limit): array
    {
        $criteria = ['artifact' => $artifact, 'collection' => $collection];
        if (!$withHidden) {
            $criteria['hidden'] = false;
        }

        /** @var list<ArtifactPlace> */
        return $this->findBy($criteria, ['createdAt' => 'DESC'], $limit);
    }

    public function countFor(Artifact $artifact, string $collection): int
    {
        return $this->count(['artifact' => $artifact, 'collection' => $collection]);
    }

    public function countRecentByIp(Artifact $artifact, string $ipHash, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('p.artifact = :a')->setParameter('a', $artifact)
            ->andWhere('p.ipHash = :h')->setParameter('h', $ipHash)
            ->andWhere('p.createdAt >= :s')->setParameter('s', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
