<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactPlaceVisitorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Visiteur anonyme des lieux d'un artefact : un appareil (empreinte d'une clé aléatoire gardée par l'appareil,
 * en-tête X-Place-Device), avec un pseudo et un avatar dessiné (SVG) : son « blaze », affiché sur ses messages.
 * Un compte Tadaaa connecté peut le revendiquer : il le retrouve alors sur ses autres appareils.
 */
#[ORM\Entity(repositoryClass: ArtifactPlaceVisitorRepository::class)]
#[ORM\Table(name: 'artifact_place_visitors')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_place_visitor_device', columns: ['artifact_id', 'collection', 'device_hash'])]
class ArtifactPlaceVisitor
{
    public const MAX_PSEUDO = 40;
    /** Avatar SVG (data: URL, source des traits de sonic-sketch comprise), en octets. */
    public const MAX_AVATAR = 300_000;
    public const HIDE_AT_REPORTS = 3;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column(length: 64)]
    private string $collection;

    #[ORM\Column(length: 64)]
    private string $deviceHash;

    /** Compte Tadaaa qui a revendiqué ce blaze (facultatif). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $pseudo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $avatar = null;

    #[ORM\Column]
    private int $reports = 0;

    #[ORM\Column]
    private bool $hidden = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Artifact $artifact, string $collection, string $deviceHash)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->collection = $collection;
        $this->deviceHash = $deviceHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getArtifact(): Artifact { return $this->artifact; }
    public function getCollection(): string { return $this->collection; }
    public function getPseudo(): ?string { return $this->pseudo; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): void { $this->user = $user; }
    public function getDeviceHash(): string { return $this->deviceHash; }
    public function getAvatar(): ?string { return $this->avatar; }
    public function isHidden(): bool { return $this->hidden; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function update(?string $pseudo, ?string $avatar): void
    {
        $this->pseudo = $pseudo;
        $this->avatar = $avatar;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function report(): void
    {
        ++$this->reports;
        if ($this->reports >= self::HIDE_AT_REPORTS) {
            $this->hidden = true;
        }
    }
}
