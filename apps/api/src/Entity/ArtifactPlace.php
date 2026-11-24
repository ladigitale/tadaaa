<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactPlaceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Lieu partagé d'un artefact (`data.places.<nom>`) : un objet JSON posé par un visiteur à une position GPS,
 * visible par tous les visiteurs qui passent à proximité (balises, notes de terrain, sons de lieu…).
 * Le poseur garde un jeton de suppression (seule son empreinte est stockée) ; les signalements masquent le lieu.
 */
#[ORM\Entity(repositoryClass: ArtifactPlaceRepository::class)]
#[ORM\Table(name: 'artifact_places')]
#[ORM\Index(name: 'idx_artifact_place_geo', columns: ['artifact_id', 'collection', 'lat', 'lon'])]
#[ORM\Index(name: 'idx_artifact_place_ip', columns: ['artifact_id', 'ip_hash', 'created_at'])]
class ArtifactPlace
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column(length: 64)]
    private string $collection;

    #[ORM\Column(type: 'float')]
    private float $lat;

    #[ORM\Column(type: 'float')]
    private float $lon;

    /** Champs courts du corps (texte ≤ 200, nombres, booléens), servis dans les listes. @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $summary = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $body = [];

    #[ORM\Column]
    private int $sizeBytes = 0;

    /** sha256 du jeton de suppression remis au poseur. */
    #[ORM\Column(length: 64)]
    private string $deleteTokenHash;

    #[ORM\Column(length: 64)]
    private string $ipHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Blaze de qui a posé le lieu (affiché sur ce qui ouvre la discussion). */
    #[ORM\ManyToOne(targetEntity: ArtifactPlaceVisitor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ArtifactPlaceVisitor $visitor = null;

    #[ORM\Column]
    private int $reports = 0;

    /** Messages visibles de la discussion du lieu (ArtifactPlaceMessage). */
    #[ORM\Column(options: ['default' => 0])]
    private int $messageCount = 0;

    #[ORM\Column]
    private bool $hidden = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $body @param array<string, mixed> $summary */
    public function __construct(Artifact $artifact, string $collection, float $lat, float $lon, array $body, array $summary, int $sizeBytes, string $deleteTokenHash, string $ipHash)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
        $this->collection = $collection;
        $this->lat = $lat;
        $this->lon = $lon;
        $this->body = $body;
        $this->summary = $summary;
        $this->sizeBytes = $sizeBytes;
        $this->deleteTokenHash = $deleteTokenHash;
        $this->ipHash = $ipHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getArtifact(): Artifact { return $this->artifact; }
    public function getCollection(): string { return $this->collection; }
    public function getLat(): float { return $this->lat; }
    public function getLon(): float { return $this->lon; }
    /** @return array<string, mixed> */
    public function getSummary(): array { return $this->summary; }
    /** @return array<string, mixed> */
    public function getBody(): array { return $this->body; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getDeleteTokenHash(): string { return $this->deleteTokenHash; }
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): void { $this->author = $author; }
    public function getVisitor(): ?ArtifactPlaceVisitor { return $this->visitor; }
    public function setVisitor(?ArtifactPlaceVisitor $visitor): void { $this->visitor = $visitor; }
    public function getReports(): int { return $this->reports; }
    public function getMessageCount(): int { return $this->messageCount; }
    public function addMessages(int $n): void { $this->messageCount = max(0, $this->messageCount + $n); }
    public function isHidden(): bool { return $this->hidden; }
    public function setHidden(bool $hidden): void { $this->hidden = $hidden; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Ajoute une surface de remplacement (`body.refs`, les $maxExtra plus récentes) ; retire les plus anciennes
     * tant que le corps dépasse $maxBytes. Retourne la taille finale, ou null si la nouvelle seule ne tient pas.
     *
     * @param array<string, mixed> $ref
     */
    public function addRef(array $ref, int $maxExtra, int $maxBytes): ?int
    {
        $body = $this->body;
        $refs = \is_array($body['refs'] ?? null) && array_is_list($body['refs']) ? $body['refs'] : [];
        $refs[] = $ref;
        $refs = \array_slice($refs, -$maxExtra);
        while (true) {
            $body['refs'] = $refs;
            $size = \strlen((string) json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
            if ($size <= $maxBytes) {
                break;
            }
            if (\count($refs) <= 1) {
                return null;
            }
            array_shift($refs);
        }
        $this->body = $body;
        $this->sizeBytes = $size;

        return $size;
    }

    /**
     * Corps retouché par le poseur (texte, dessin, vocal) : résumé et taille recalculés.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $summary
     */
    public function replaceBody(array $body, array $summary, int $sizeBytes): void
    {
        $this->body = $body;
        $this->summary = $summary;
        $this->sizeBytes = $sizeBytes;
    }

    public function report(int $hideAt): void
    {
        ++$this->reports;
        if ($this->reports >= $hideAt) {
            $this->hidden = true;
        }
    }
}
