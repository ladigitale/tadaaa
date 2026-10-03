<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactCollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactCollectionRepository::class)]
#[ORM\Table(name: 'artifact_collections')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_collection_name', columns: ['artifact_id', 'name'])]
class ArtifactCollection
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class, inversedBy: 'collections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column]
    private bool $publicRead = false;

    #[ORM\Column(length: 20, enumType: ArtifactWriteMode::class)]
    private ArtifactWriteMode $writeMode = ArtifactWriteMode::None;

    #[ORM\Column(length: 16, enumType: ArtifactCollectionScope::class)]
    private ArtifactCollectionScope $scope = ArtifactCollectionScope::Shared;

    /**
     * Collecte contrôlée (writeMode = intake) : schéma normalisé, voir ArtifactIntakeSchema.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $intakeSchema = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $intakeOpenedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $intakeOpenUntil = null;

    #[ORM\Column(nullable: true)]
    private ?int $intakeMaxRecords = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $intakeCode = null;

    /** Jeton de lecture « lien secret » (collections non publiques). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $readToken = null;

    /** @var Collection<int, ArtifactRecord> */
    #[ORM\OneToMany(mappedBy: 'collection', targetEntity: ArtifactRecord::class, orphanRemoval: true)]
    private Collection $records;

    public function __construct(Artifact $artifact, string $name)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->name = $name;
        $this->records = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArtifact(): Artifact
    {
        return $this->artifact;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isPublicRead(): bool
    {
        return $this->publicRead;
    }

    public function setPublicRead(bool $publicRead): static
    {
        $this->publicRead = $publicRead;

        return $this;
    }

    public function getWriteMode(): ArtifactWriteMode
    {
        return $this->writeMode;
    }

    public function setWriteMode(ArtifactWriteMode $writeMode): static
    {
        $this->writeMode = $writeMode;

        return $this;
    }

    public function getScope(): ArtifactCollectionScope
    {
        return $this->scope;
    }

    public function setScope(ArtifactCollectionScope $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    /** @return Collection<int, ArtifactRecord> */
    public function getRecords(): Collection
    {
        return $this->records;
    }

    /** @return array<string, mixed>|null */
    public function getIntakeSchema(): ?array
    {
        return $this->intakeSchema;
    }

    /** @param array<string, mixed>|null $schema */
    public function setIntakeSchema(?array $schema): static
    {
        $this->intakeSchema = $schema;

        return $this;
    }

    public function getIntakeOpenedAt(): ?\DateTimeImmutable
    {
        return $this->intakeOpenedAt;
    }

    public function getIntakeOpenUntil(): ?\DateTimeImmutable
    {
        return $this->intakeOpenUntil;
    }

    public function getIntakeMaxRecords(): ?int
    {
        return $this->intakeMaxRecords;
    }

    public function getIntakeCode(): ?string
    {
        return $this->intakeCode;
    }

    public function isIntakeOpen(\DateTimeImmutable $now): bool
    {
        return $this->writeMode === ArtifactWriteMode::Intake
            && $this->intakeSchema !== null
            && $this->intakeOpenUntil !== null
            && $now < $this->intakeOpenUntil;
    }

    public function openIntake(\DateTimeImmutable $now, \DateTimeImmutable $until, int $maxRecords, ?string $code): static
    {
        $this->intakeOpenedAt = $now;
        $this->intakeOpenUntil = $until;
        $this->intakeMaxRecords = $maxRecords;
        $this->intakeCode = $code;

        return $this;
    }

    public function closeIntake(): static
    {
        $this->intakeOpenUntil = null;
        $this->intakeCode = null;

        return $this;
    }

    public function getReadToken(): ?string
    {
        return $this->readToken;
    }

    public function setReadToken(?string $token): static
    {
        $this->readToken = $token;

        return $this;
    }
}
