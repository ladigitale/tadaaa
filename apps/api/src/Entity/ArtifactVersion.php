<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactVersionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactVersionRepository::class)]
#[ORM\Table(name: 'artifact_versions')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_version', columns: ['artifact_id', 'version'])]
class ArtifactVersion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column]
    private int $version;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $document = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** @param array<string, mixed> $document */
    public function __construct(Artifact $artifact, int $version, array $document, User $createdBy, ?string $note = null)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->version = $version;
        $this->document = $document;
        $this->createdBy = $createdBy;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArtifact(): Artifact
    {
        return $this->artifact;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function getDocument(): array
    {
        return $this->document;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
