<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Exécution d'un artefact utilisé comme outil (contrat `io` du document) : entrée reçue, sortie
 * produite, statut. Un run headless est terminé dès sa création ; les autres modes restent
 * `pending` jusqu'à ce que le viewer publie la sortie.
 */
#[ORM\Entity(repositoryClass: ArtifactRunRepository::class)]
#[ORM\Table(name: 'artifact_runs')]
#[ORM\Index(name: 'idx_artifact_run_artifact', columns: ['artifact_id', 'created_at'])]
#[ORM\Index(name: 'idx_artifact_run_user', columns: ['created_by_id'])]
class ArtifactRun
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column]
    private int $artifactVersion;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(length: 16)]
    private string $mode;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $input;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $output = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $errors = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metrics = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Artifact $artifact, int $artifactVersion, ?User $createdBy, string $mode, mixed $input)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->artifactVersion = $artifactVersion;
        $this->createdBy = $createdBy;
        $this->mode = $mode;
        $this->input = $input;
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

    public function getArtifactVersion(): int
    {
        return $this->artifactVersion;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInput(): mixed
    {
        return $this->input;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $metrics
     */
    public function finish(string $status, mixed $output, array $errors = [], array $metrics = []): void
    {
        $this->status = $status;
        $this->output = $output;
        $this->errors = $errors;
        $this->metrics = $metrics;
        $this->completedAt = new \DateTimeImmutable();
    }
}
