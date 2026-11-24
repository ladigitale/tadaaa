<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModerationCaseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Dossier de modération (tous artefacts) : un contenu signalé par les visiteurs — balise (lieu), message de
 * discussion, blaze (avatar), parcours. Ouvert au premier signalement, rouvert par un signalement après décision ;
 * le super admin le règle depuis la page Modération du site Artefacts.
 */
#[ORM\Entity(repositoryClass: ModerationCaseRepository::class)]
#[ORM\Table(name: 'moderation_cases')]
#[ORM\UniqueConstraint(name: 'uniq_moderation_case_target', columns: ['kind', 'target_id'])]
#[ORM\Index(name: 'idx_moderation_case_queue', columns: ['status', 'last_reported_at'])]
class ModerationCase
{
    public const KINDS = ['place', 'message', 'visitor', 'route'];
    /** open : à traiter ; kept : laissé visible ; hidden : masqué ; deleted : retiré. */
    public const STATUSES = ['open', 'kept', 'hidden', 'deleted'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    #[ORM\Column]
    private int $reports = 0;

    #[ORM\Column(length: 16)]
    private string $status = 'open';

    #[ORM\Column]
    private \DateTimeImmutable $firstReportedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastReportedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    public function __construct(Artifact $artifact, string $kind, Uuid $targetId)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->kind = $kind;
        $this->targetId = $targetId;
        $this->firstReportedAt = new \DateTimeImmutable();
        $this->lastReportedAt = $this->firstReportedAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getArtifact(): Artifact { return $this->artifact; }
    public function getKind(): string { return $this->kind; }
    public function getTargetId(): Uuid { return $this->targetId; }
    public function getReports(): int { return $this->reports; }
    public function getStatus(): string { return $this->status; }
    public function getFirstReportedAt(): \DateTimeImmutable { return $this->firstReportedAt; }
    public function getLastReportedAt(): \DateTimeImmutable { return $this->lastReportedAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }

    /** Nouveau signalement : compté, et le dossier repasse « à traiter » s'il avait été laissé visible. */
    public function reported(int $reports): void
    {
        $this->reports = max($this->reports + 1, $reports);
        $this->lastReportedAt = new \DateTimeImmutable();
        if ($this->status === 'kept') {
            $this->status = 'open';
        }
    }

    public function decide(string $status, ?User $by): void
    {
        $this->status = $status;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decidedBy = $by;
        if ($status === 'kept') {
            $this->reports = 0;
        }
    }
}
