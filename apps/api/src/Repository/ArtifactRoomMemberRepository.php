<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactRoom;
use App\Entity\ArtifactRoomMember;
use App\Entity\ArtifactRoomMemberStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ArtifactRoomMember>
 */
class ArtifactRoomMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactRoomMember::class);
    }

    public function findOneInRoom(ArtifactRoom $room, Uuid $id): ?ArtifactRoomMember
    {
        return $this->findOneBy(['room' => $room, 'id' => $id]);
    }

    public function findHost(ArtifactRoom $room): ?ArtifactRoomMember
    {
        return $this->findOneBy(['room' => $room, 'host' => true]);
    }

    /** @return list<ArtifactRoomMember> */
    public function findActive(ArtifactRoom $room): array
    {
        /** @var list<ArtifactRoomMember> $rows */
        $rows = $this->findBy(['room' => $room, 'status' => ArtifactRoomMemberStatus::Active], ['joinedAt' => 'ASC']);

        return $rows;
    }

    public function countActive(ArtifactRoom $room): int
    {
        return $this->count(['room' => $room, 'status' => ArtifactRoomMemberStatus::Active]);
    }

    public function countPending(ArtifactRoom $room): int
    {
        return $this->count(['room' => $room, 'status' => ArtifactRoomMemberStatus::Pending]);
    }

    /** Nombre de participants actifs par salon (pour la liste), indexé par id RFC 4122. @param list<ArtifactRoom> $rooms @return array<string, int> */
    public function countActiveByRoom(array $rooms): array
    {
        if ($rooms === []) {
            return [];
        }

        /** @var list<array{rid: Uuid, n: numeric-string|int}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.room) AS rid, COUNT(m.id) AS n')
            ->andWhere('m.room IN (:rooms)')
            ->andWhere('m.status = :active')
            ->setParameter('rooms', $rooms)
            ->setParameter('active', ArtifactRoomMemberStatus::Active)
            ->groupBy('m.room')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['rid']] = (int) $row['n'];
        }

        return $out;
    }
}
