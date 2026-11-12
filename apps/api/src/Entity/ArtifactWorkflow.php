<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactWorkflowRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Enchaînement d'artefacts-outils (contrat io) : la sortie d'une étape alimente la suivante
 * (définition validée par {@see \App\Service\ArtifactWorkflowDefinition}).
 */
#[ORM\Entity(repositoryClass: ArtifactWorkflowRepository::class)]
#[ORM\Table(name: 'artifact_workflows')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_workflow_slug', columns: ['dataset_id', 'slug'])]
class ArtifactWorkflow
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

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $definition;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $definition */
    public function __construct(Dataset $dataset, string $slug, string $title, array $definition, ?User $createdBy)
    {
        $this->id = Uuid::v7();
        $this->dataset = $dataset;
        $this->slug = $slug;
        $this->title = $title;
        $this->definition = $definition;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return array<string, mixed> */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getCreatedBy(): ?User
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

    /** @param array<string, mixed> $definition */
    public function update(string $title, array $definition): void
    {
        $this->title = $title;
        $this->definition = $definition;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
