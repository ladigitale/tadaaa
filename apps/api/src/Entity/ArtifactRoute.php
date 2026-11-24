<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours : une suite de lieux d'une collection (`data.places.<nom>`) reliés entre eux, avec pour chaque étape
 * un rayon de déclenchement et un son (celui de la balise, un son propre au parcours ou aucun).
 */
/** Sons propres des étapes : ArtifactRouteSound (chargés à part). */
#[ORM\Entity(repositoryClass: ArtifactRouteRepository::class)]
#[ORM\Table(name: 'artifact_routes')]
#[ORM\Index(name: 'idx_artifact_route_box', columns: ['artifact_id', 'collection', 'min_lat', 'max_lat', 'min_lon', 'max_lon'])]
class ArtifactRoute
{
    public const MAX_TITLE = 80;
    public const MAX_DESCRIPTION = 500;
    public const MIN_STEPS = 2;
    public const MAX_STEPS = 30;
    public const MIN_RADIUS = 10;
    public const MAX_RADIUS = 300;
    /** Son propre d'une étape (data:audio) et total des sons d'un parcours. */
    public const MAX_SOUND = 450_000;
    public const MAX_SOUNDS = 3_000_000;
    public const MODES = ['etape', 'ambiance'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column(length: 64)]
    private string $collection;

    #[ORM\Column(length: 80)]
    private string $title;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description;

    /** Étapes dans l'ordre imposé. */
    #[ORM\Column]
    private bool $ordered;

    #[ORM\Column(length: 10)]
    private string $mode;

    /** @var list<array{place: string, radius: int, sound: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps;

    #[ORM\Column]
    private float $minLat;
    #[ORM\Column]
    private float $maxLat;
    #[ORM\Column]
    private float $minLon;
    #[ORM\Column]
    private float $maxLon;

    /** Longueur à vol d'oiseau d'étape en étape (m). */
    #[ORM\Column]
    private int $lengthM;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $pseudo;

    #[ORM\Column(length: 64)]
    private string $ipHash;

    #[ORM\Column(length: 64)]
    private string $deleteTokenHash;

    #[ORM\Column]
    private int $reports = 0;

    #[ORM\Column]
    private bool $hidden = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{place: string, radius: int, sound: string}> $steps
     * @param array{0: float, 1: float, 2: float, 3: float}            $box    minLat, maxLat, minLon, maxLon
     */
    public function __construct(Artifact $artifact, string $collection, string $title, ?string $description, bool $ordered, string $mode, array $steps, array $box, int $lengthM, ?string $pseudo, string $ipHash, string $deleteTokenHash)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->collection = $collection;
        $this->title = $title;
        $this->description = $description;
        $this->ordered = $ordered;
        $this->mode = \in_array($mode, self::MODES, true) ? $mode : 'etape';
        $this->steps = $steps;
        [$this->minLat, $this->maxLat, $this->minLon, $this->maxLon] = $box;
        $this->lengthM = $lengthM;
        $this->pseudo = $pseudo;
        $this->ipHash = $ipHash;
        $this->deleteTokenHash = $deleteTokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getArtifact(): Artifact { return $this->artifact; }
    public function getCollection(): string { return $this->collection; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function isOrdered(): bool { return $this->ordered; }
    public function getMode(): string { return $this->mode; }
    /** @return list<array{place: string, radius: int, sound: string}> */
    public function getSteps(): array { return $this->steps; }
    public function getLengthM(): int { return $this->lengthM; }
    public function getPseudo(): ?string { return $this->pseudo; }
    public function getIpHash(): string { return $this->ipHash; }
    public function getDeleteTokenHash(): string { return $this->deleteTokenHash; }
    public function getReports(): int { return $this->reports; }
    public function isHidden(): bool { return $this->hidden; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function report(int $hideAt): void
    {
        ++$this->reports;
        if ($this->reports >= $hideAt) {
            $this->hidden = true;
        }
    }

    public function setHidden(bool $hidden): void { $this->hidden = $hidden; }

    /** Décision de modération : masqué ou rétabli (rétabli : signalements remis à zéro). */
    public function moderate(bool $hidden): void
    {
        $this->hidden = $hidden;
        if (!$hidden) {
            $this->reports = 0;
        }
    }
}
