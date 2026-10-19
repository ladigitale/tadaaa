<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRoomMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Participant d'un salon : invité éphémère (pseudo + clé aléatoire), sans compte.
 * La clé n'est renvoyée qu'une fois au participant ; seule son empreinte est stockée.
 */
#[ORM\Entity(repositoryClass: ArtifactRoomMemberRepository::class)]
#[ORM\Table(name: 'artifact_room_members')]
#[ORM\Index(name: 'idx_artifact_room_member_room', columns: ['room_id'])]
#[ORM\Index(name: 'idx_artifact_room_member_seen', columns: ['last_seen_at'])]
class ArtifactRoomMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactRoom::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactRoom $room;

    #[ORM\Column(length: 32)]
    private string $pseudo;

    #[ORM\Column]
    private bool $host;

    #[ORM\Column(length: 16, enumType: ArtifactRoomMemberStatus::class)]
    private ArtifactRoomMemberStatus $status;

    /** sha256 hex de la clé de participant. */
    #[ORM\Column(length: 64)]
    private string $keyHash;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(
        ArtifactRoom $room,
        string $pseudo,
        bool $host,
        ArtifactRoomMemberStatus $status,
        string $keyHash,
    ) {
        $this->id = Uuid::v7();
        $this->room = $room;
        $this->pseudo = $pseudo;
        $this->host = $host;
        $this->status = $status;
        $this->keyHash = $keyHash;
        $this->joinedAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->joinedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): ArtifactRoom
    {
        return $this->room;
    }

    public function getPseudo(): string
    {
        return $this->pseudo;
    }

    public function isHost(): bool
    {
        return $this->host;
    }

    public function getStatus(): ArtifactRoomMemberStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === ArtifactRoomMemberStatus::Active;
    }

    public function activate(): static
    {
        $this->status = ArtifactRoomMemberStatus::Active;

        return $this;
    }

    public function matchesKey(string $key): bool
    {
        return $key !== '' && hash_equals($this->keyHash, hash('sha256', $key));
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(): static
    {
        $this->lastSeenAt = new \DateTimeImmutable();

        return $this;
    }
}
