<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRoomRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Salon éphémère rattaché à un artefact (partage d'état de jeu, tableau blanc…).
 * Les données elles-mêmes ne passent jamais par la base : elles circulent sur le hub Mercure.
 */
#[ORM\Entity(repositoryClass: ArtifactRoomRepository::class)]
#[ORM\Table(name: 'artifact_rooms')]
#[ORM\Index(name: 'idx_artifact_room_artifact', columns: ['artifact_id'])]
#[ORM\Index(name: 'idx_artifact_room_expires', columns: ['expires_at'])]
class ArtifactRoom
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    /**
     * Composante secrète des topics Mercure du salon (jamais dans la liste publique) :
     * connaître l'id d'un salon listé ne suffit pas à viser ses topics.
     */
    #[ORM\Column(length: 32)]
    private string $topicKey;

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column(length: 16, enumType: ArtifactRoomAccess::class)]
    private ArtifactRoomAccess $access;

    /** Code de rendez-vous normalisé (minuscules, a-z0-9). Non secret : stocké en clair. */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $code;

    /** Visible dans la liste publique des salons de l'artefact. */
    #[ORM\Column]
    private bool $listed;

    #[ORM\Column]
    private int $maxMembers;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        Artifact $artifact,
        string $name,
        ArtifactRoomAccess $access,
        ?string $code,
        bool $listed,
        int $maxMembers,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->topicKey = bin2hex(random_bytes(16));
        $this->artifact = $artifact;
        $this->name = $name;
        $this->access = $access;
        $this->code = $code;
        $this->listed = $listed;
        $this->maxMembers = $maxMembers;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTopicKey(): string
    {
        return $this->topicKey;
    }

    public function getArtifact(): Artifact
    {
        return $this->artifact;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAccess(): ArtifactRoomAccess
    {
        return $this->access;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function isListed(): bool
    {
        return $this->listed;
    }

    public function getMaxMembers(): int
    {
        return $this->maxMembers;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function extendUntil(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
