<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactPlace;
use App\Entity\ArtifactPlaceTag;
use App\Entity\ArtifactPlaceVisitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactPlaceTag> */
final class ArtifactPlaceTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactPlaceTag::class);
    }

    public function findOne(ArtifactPlace $place, ArtifactPlaceVisitor $visitor): ?ArtifactPlaceTag
    {
        return $this->findOneBy(['place' => $place, 'visitor' => $visitor]);
    }

    /** @return list<ArtifactPlaceTag> les plus récents d'abord, visiteurs masqués exclus */
    public function latestFor(ArtifactPlace $place, int $limit): array
    {
        /** @var list<ArtifactPlaceTag> $rows */
        $rows = $this->createQueryBuilder('t')
            ->join('t.visitor', 'v')->addSelect('v')
            ->andWhere('t.place = :p')->setParameter('p', $place)
            ->andWhere('v.hidden = false')
            ->orderBy('t.lastAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }

    public function countFor(ArtifactPlace $place): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->join('t.visitor', 'v')
            ->andWhere('t.place = :p')->setParameter('p', $place)
            ->andWhere('v.hidden = false')
            ->getQuery()->getSingleScalarResult();
    }

    public function countRecentByVisitor(ArtifactPlaceVisitor $visitor, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->andWhere('t.visitor = :v')->setParameter('v', $visitor)
            ->andWhere('t.lastAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
