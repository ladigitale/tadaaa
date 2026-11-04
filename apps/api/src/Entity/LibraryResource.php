<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Ressource réutilisable du compte (shader, modèle 3D, données, image, son). Cette entité ne sert qu'à
 * décrire la table au mapping : lectures et écritures passent par {@see \App\Service\Resources} (DBAL),
 * pour ne jamais hydrater le contenu (`data`) avec les métadonnées.
 *
 * `version` est le numéro de la version courante ; les précédentes sont dans `resource_versions`.
 * `base_*` : ressource de base (Creative-stack) dont celle-ci est la copie, pour signaler une mise à jour.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resources')]
#[ORM\UniqueConstraint(name: 'uniq_resource_user_slug', columns: ['user_id', 'slug'])]
#[ORM\Index(name: 'idx_resource_user_kind', columns: ['user_id', 'kind'])]
class LibraryResource
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    #[ORM\Column(type: 'text')]
    private string $prompt = '';

    #[ORM\Column(length: 100)]
    private string $contentType;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column(type: 'bigint')]
    private string $sizeBytes = '0';

    #[ORM\Column(length: 64)]
    private string $sha256;

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private $data;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $baseId = null;

    #[ORM\Column(nullable: true)]
    private ?int $baseVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $baseSha256 = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $kind, string $slug, string $name, string $contentType, string $sha256)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->kind = $kind;
        $this->slug = $slug;
        $this->name = $name;
        $this->contentType = $contentType;
        $this->sha256 = $sha256;
        $this->data = '';
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
