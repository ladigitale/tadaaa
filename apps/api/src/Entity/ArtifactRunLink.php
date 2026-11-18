<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRunLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Lien entre deux runs en flux : chaque élément publié par la file d'envoi (`io.stream.emit`) du
 * run source est transformé (JSONata `map`, facultatif) puis poussé dans le flux du run cible,
 * comme par un émetteur. Aucun jeton ne passe dans le navigateur. Le lien s'arrête quand l'un des
 * deux runs se termine.
 */
#[ORM\Entity(repositoryClass: ArtifactRunLinkRepository::class)]
#[ORM\Table(name: 'artifact_run_links')]
#[ORM\Index(name: 'idx_artifact_run_links_from', columns: ['from_run_id'])]
class ArtifactRunLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactRun::class)]
    #[ORM\JoinColumn(name: 'from_run_id', nullable: false, onDelete: 'CASCADE')]
    private ArtifactRun $fromRun;

    #[ORM\ManyToOne(targetEntity: ArtifactRun::class)]
    #[ORM\JoinColumn(name: 'to_run_id', nullable: false, onDelete: 'CASCADE')]
    private ArtifactRun $toRun;

    /** Expression JSONata évaluée sur chaque élément {id, data, from: {slug, runId}} ; null = data tel quel. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $map;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $forwarded = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $failed = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAt = null;

    public function __construct(ArtifactRun $fromRun, ArtifactRun $toRun, ?string $map, ?User $createdBy)
    {
        $this->id = Uuid::v7();
        $this->fromRun = $fromRun;
        $this->toRun = $toRun;
        $this->map = $map;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFromRun(): ArtifactRun
    {
        return $this->fromRun;
    }

    public function getToRun(): ArtifactRun
    {
        return $this->toRun;
    }

    public function getMap(): ?string
    {
        return $this->map;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function recordSuccess(int $messages): void
    {
        $this->forwarded += $messages;
        $this->lastAt = new \DateTimeImmutable();
    }

    public function recordFailure(string $error): void
    {
        ++$this->failed;
        $this->lastError = mb_substr($error, 0, 500);
        $this->lastAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'linkId' => $this->id->toRfc4122(),
            'from' => ['runId' => $this->fromRun->getId()->toRfc4122(), 'slug' => $this->fromRun->getArtifact()->getSlug()],
            'to' => ['runId' => $this->toRun->getId()->toRfc4122(), 'slug' => $this->toRun->getArtifact()->getSlug()],
            'map' => $this->map,
            'active' => $this->active,
            'forwarded' => $this->forwarded,
            'failed' => $this->failed,
            'lastError' => $this->lastError,
            'lastAt' => $this->lastAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
