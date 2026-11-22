<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactPlaceMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Message laissé sur un lieu partagé (discussion du lieu) : texte, vocal ou dessin, avec pseudo facultatif,
 * posé par n'importe quel visiteur ; masqué au 3e signalement.
 */
#[ORM\Entity(repositoryClass: ArtifactPlaceMessageRepository::class)]
#[ORM\Table(name: 'artifact_place_messages')]
#[ORM\Index(name: 'idx_artifact_place_message_place', columns: ['place_id', 'created_at'])]
class ArtifactPlaceMessage
{
    public const MAX_TEXT = 500;
    public const MAX_PSEUDO = 40;
    /** Vocal ou dessin joint (data: URL), en octets. */
    public const MAX_MEDIA = 900_000;
    public const KINDS = ['text', 'voice', 'drawing'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactPlace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactPlace $place;

    /** text | voice | drawing */
    #[ORM\Column(length: 16, options: ['default' => 'text'])]
    private string $kind;

    #[ORM\Column(length: 2000)]
    private string $text;

    /** Vocal (data:audio/…) ou dessin (data:image/…) ; servi à part, pas dans la liste. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $media;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $pseudo;

    #[ORM\Column(length: 64)]
    private string $ipHash;

    /** sha256 du jeton de l'appareil qui l'a posé (retrait par l'auteur). */
    #[ORM\Column(length: 64)]
    private string $deleteTokenHash;

    #[ORM\Column]
    private int $reports = 0;

    #[ORM\Column]
    private bool $hidden = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Posé sur place (position vérifiée par le serveur et mur reconnu) ; sinon commentaire à distance. */
    #[ORM\Column(options: ['default' => true])]
    private bool $onSite;

    /** Dessin : son cadre en fois la zone (1 = la zone, 3 = la zone et autant tout autour). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $frame;

    public function __construct(ArtifactPlace $place, string $kind, string $text, ?string $media, ?string $pseudo, string $ipHash, string $deleteTokenHash, bool $onSite = true, ?int $frame = null)
    {
        $this->onSite = $onSite;
        $this->frame = $kind === 'drawing' && $frame !== null ? max(1, min(5, $frame)) : null;
        $this->id = Uuid::v7();
        $this->place = $place;
        $this->kind = $kind;
        $this->text = $text;
        $this->media = $media;
        $this->pseudo = $pseudo;
        $this->ipHash = $ipHash;
        $this->deleteTokenHash = $deleteTokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPlace(): ArtifactPlace { return $this->place; }
    public function getKind(): string { return $this->kind; }
    public function getText(): string { return $this->text; }
    public function getMedia(): ?string { return $this->media; }
    public function getPseudo(): ?string { return $this->pseudo; }
    public function getDeleteTokenHash(): string { return $this->deleteTokenHash; }
    public function isHidden(): bool { return $this->hidden; }
    public function isOnSite(): bool { return $this->onSite; }
    public function getFrame(): ?int { return $this->frame; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function report(int $hideAt): void
    {
        ++$this->reports;
        if ($this->reports >= $hideAt) {
            $this->hidden = true;
        }
    }
}
