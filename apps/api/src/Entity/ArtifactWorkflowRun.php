<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactWorkflowRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Exécution d'un workflow d'artefacts : étapes jouées dans l'ordre, état de chacune
 * (`steps` : id → {status, runId, output, files, url?, errors?}), sortie finale.
 */
#[ORM\Entity(repositoryClass: ArtifactWorkflowRunRepository::class)]
#[ORM\Table(name: 'artifact_workflow_runs')]
#[ORM\Index(name: 'idx_artifact_workflow_run_wf', columns: ['workflow_id', 'created_at'])]
class ArtifactWorkflowRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactWorkflow::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactWorkflow $workflow;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $input;

    /**
     * Définition figée au lancement (une modification du workflow n'affecte pas les runs en cours).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $definition;

    /** @var array<string, array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $steps = [];

    #[ORM\Column(name: 'step_cursor')]
    private int $cursor = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $output = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $errors = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @param array<string, mixed> $definition */
    public function __construct(ArtifactWorkflow $workflow, array $definition, ?User $createdBy, mixed $input)
    {
        $this->id = Uuid::v7();
        $this->workflow = $workflow;
        $this->definition = $definition;
        $this->createdBy = $createdBy;
        $this->input = $input;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWorkflow(): ArtifactWorkflow
    {
        return $this->workflow;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getInput(): mixed
    {
        return $this->input;
    }

    /** @return array<string, mixed> */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    /** @return array<string, array<string, mixed>> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @param array<string, mixed> $state */
    public function setStep(string $id, array $state): void
    {
        $this->steps[$id] = $state;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCursor(): int
    {
        return $this->cursor;
    }

    public function setCursor(int $cursor): void
    {
        $this->cursor = $cursor;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isFinished(): bool
    {
        return \in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    /** @param list<string> $errors */
    public function finish(string $status, mixed $output, array $errors = []): void
    {
        $this->status = $status;
        $this->output = $output;
        $this->errors = $errors;
        $this->completedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->completedAt;
    }
}
