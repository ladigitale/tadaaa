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
