<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactRepository::class)]
#[ORM\Table(name: 'artifacts')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_artifact_dataset', columns: ['dataset_id'])]
class Artifact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16, enumType: ArtifactVisibility::class)]
    private ArtifactVisibility $visibility = ArtifactVisibility::Private;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $linkToken = null;

    #[ORM\Column]
    private int $currentVersion = 0;

    #[ORM\Column(length: 32)]
    private string $concordeVersion = '5.1.0';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ArtifactVersion> */
    #[ORM\OneToMany(mappedBy: 'artifact', targetEntity: ArtifactVersion::class, orphanRemoval: true)]
    #[ORM\OrderBy(['version' => 'ASC'])]
    private Collection $versions;

    /** @var Collection<int, ArtifactCollection> */
    #[ORM\OneToMany(mappedBy: 'artifact', targetEntity: ArtifactCollection::class, orphanRemoval: true)]
    private Collection $collections;

    public function __construct(Dataset $dataset, User $createdBy, string $slug, string $title)
    {
        $this->id = Uuid::v7();
        $this->dataset = $dataset;
        $this->createdBy = $createdBy;
        $this->slug = $slug;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->versions = new ArrayCollection();
        $this->collections = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function getVisibility(): ArtifactVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(ArtifactVisibility $visibility): static
    {
        $this->visibility = $visibility;
        $this->touch();

        return $this;
    }

    public function getLinkToken(): ?string
    {
        return $this->linkToken;
    }

    public function setLinkToken(?string $linkToken): static
    {
        $this->linkToken = $linkToken;
        $this->touch();

        return $this;
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(int $currentVersion): static
    {
        $this->currentVersion = $currentVersion;
        $this->touch();

        return $this;
    }

    public function getConcordeVersion(): string
    {
        return $this->concordeVersion;
    }

    public function setConcordeVersion(string $concordeVersion): static
    {
        $this->concordeVersion = $concordeVersion;

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, ArtifactVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    /** @return Collection<int, ArtifactCollection> */
    public function getCollections(): Collection
    {
        return $this->collections;
    }
}
