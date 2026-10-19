<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\ArtifactRoom;
use App\Entity\ArtifactRoomMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ArtifactRoom>
 */
class ArtifactRoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRoom::class);
    }

    public function findOneForArtifact(Artifact $artifact, Uuid $id): ?ArtifactRoom
    {
        return $this->findOneBy(['artifact' => $artifact, 'id' => $id]);
    }

    public function findOneByCode(Artifact $artifact, string $code): ?ArtifactRoom
    {
        return $this->findOneBy(['artifact' => $artifact, 'code' => $code]);
    }

    /** @return list<ArtifactRoom> */
    public function findListed(Artifact $artifact, int $limit = 50): array
    {
        /** @var list<ArtifactRoom> $rows */
        $rows = $this->findBy(['artifact' => $artifact, 'listed' => true], ['createdAt' => 'DESC'], $limit);

        return $rows;
    }

    public function countForArtifact(Artifact $artifact): int
    {
        return $this->count(['artifact' => $artifact]);
    }

    /**
     * Supprime ce qui est mort : salons expirés, participants sans signe de vie depuis
     * `$staleBefore` (le client renouvelle son jeton toutes les ~4 min), puis salons sans hôte.
     * Appelé à chaque lecture / création : peu de lignes, pas besoin de tâche planifiée.
     */
    public function purge(\DateTimeImmutable $now, \DateTimeImmutable $staleBefore): void
    {
        $em = $this->getEntityManager();

        $em->createQuery('DELETE FROM '.ArtifactRoom::class.' r WHERE r.expiresAt < :now')
            ->setParameter('now', $now)
            ->execute();

        $em->createQuery('DELETE FROM '.ArtifactRoomMember::class.' m WHERE m.lastSeenAt < :stale')
            ->setParameter('stale', $staleBefore)
            ->execute();

        $em->createQuery(
            'DELETE FROM '.ArtifactRoom::class.' r WHERE NOT EXISTS ('
            .'SELECT 1 FROM '.ArtifactRoomMember::class.' m WHERE m.room = r AND m.host = true)'
        )->execute();
    }
}
