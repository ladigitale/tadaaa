<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactPlace;
use App\Entity\ArtifactPlaceMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactPlaceMessage> */
final class ArtifactPlaceMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactPlaceMessage::class);
    }

    /** @return list<ArtifactPlaceMessage> les $limit plus récents visibles, du plus ancien au plus récent */
    public function latestFor(ArtifactPlace $place, int $limit): array
    {
        /** @var list<ArtifactPlaceMessage> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.place = :p')->setParameter('p', $place)
            ->andWhere('m.hidden = false')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return array_reverse($rows);
    }

    public function countRecentByIp(ArtifactPlace $place, string $ipHash, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->join('m.place', 'p')
            ->andWhere('p.artifact = :a')->setParameter('a', $place->getArtifact())
            ->andWhere('m.ipHash = :ip')->setParameter('ip', $ipHash)
            ->andWhere('m.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
