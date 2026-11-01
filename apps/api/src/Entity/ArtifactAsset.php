<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Asset binaire d'un artefact (image, son, modèle 3D, JSON…). Cette entité ne sert qu'à décrire la
 * table au mapping : les lectures et écritures passent par {@see \App\Service\ArtifactAssets} (DBAL),
 * pour ne jamais hydrater le contenu (`data`) avec les métadonnées.
 */
#[ORM\Entity]
#[ORM\Table(name: 'artifact_assets')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_asset_name', columns: ['artifact_id', 'name'])]
class ArtifactAsset
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $contentType;

    #[ORM\Column(type: 'bigint')]
    private string $sizeBytes = '0';

    #[ORM\Column(length: 64)]
    private string $sha256;

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private $data;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Artifact $artifact, string $name, string $contentType, string $sha256)
    {
        $this->id = Uuid::v7();
        $this->artifact = $artifact;
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

    public function getName(): string
    {
        return $this->name;
    }
}
