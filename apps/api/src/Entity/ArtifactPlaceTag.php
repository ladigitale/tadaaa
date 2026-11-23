<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactPlaceTagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Passage d'un visiteur sur un lieu (« passés par là ») : un seul par visiteur et par lieu, daté du dernier passage. */
#[ORM\Entity(repositoryClass: ArtifactPlaceTagRepository::class)]
#[ORM\Table(name: 'artifact_place_tags')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_place_tag', columns: ['place_id', 'visitor_id'])]
#[ORM\Index(name: 'idx_artifact_place_tag_place', columns: ['place_id', 'last_at'])]
#[ORM\Index(name: 'idx_artifact_place_tag_visitor', columns: ['visitor_id', 'last_at'])]
class ArtifactPlaceTag
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactPlace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactPlace $place;

    #[ORM\ManyToOne(targetEntity: ArtifactPlaceVisitor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactPlaceVisitor $visitor;

    #[ORM\Column]
    private \DateTimeImmutable $firstAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastAt;

    public function __construct(ArtifactPlace $place, ArtifactPlaceVisitor $visitor)
    {
        $this->id = Uuid::v7();
        $this->place = $place;
        $this->visitor = $visitor;
        $this->firstAt = new \DateTimeImmutable();
        $this->lastAt = $this->firstAt;
    }

    public function getVisitor(): ArtifactPlaceVisitor { return $this->visitor; }
    public function getFirstAt(): \DateTimeImmutable { return $this->firstAt; }
    public function getLastAt(): \DateTimeImmutable { return $this->lastAt; }

    public function touch(): void
    {
        $this->lastAt = new \DateTimeImmutable();
    }
}
