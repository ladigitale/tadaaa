<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactSaveRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Sauvegarde en ligne de l'état d'un artefact pour un visiteur (`data.persist.online`) : retrouvée sur un autre
 * appareil par sa clé secrète (seule son empreinte est gardée) ou par le compte qui l'a rattachée.
 */
#[ORM\Entity(repositoryClass: ArtifactSaveRepository::class)]
#[ORM\Table(name: 'artifact_saves')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_save_key', columns: ['artifact_id', 'key_hash'])]
#[ORM\Index(name: 'idx_artifact_save_owner', columns: ['artifact_id', 'owner_id'])]
class ArtifactSave
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    /** sha256 de la clé secrète (hexadécimal). */
    #[ORM\Column(length: 64)]
    private string $keyHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column]
    private int $sizeBytes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Artifact $artifact, string $keyHash)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->keyHash = $keyHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArtifact(): Artifact
    {
        return $this->artifact;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data, int $sizeBytes): static
    {
        $this->data = $data;
        $this->sizeBytes = $sizeBytes;
        ++$this->revision;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
