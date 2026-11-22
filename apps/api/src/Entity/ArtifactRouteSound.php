<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRouteSoundRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Son propre d'une étape de parcours (data:audio), gardé à part pour ne pas alourdir les listes. */
#[ORM\Entity(repositoryClass: ArtifactRouteSoundRepository::class)]
#[ORM\Table(name: 'artifact_route_sounds')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_route_sound', columns: ['route_id', 'step'])]
class ArtifactRouteSound
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactRoute::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactRoute $route;

    #[ORM\Column]
    private int $step;

    #[ORM\Column(type: Types::TEXT)]
    private string $media;

    public function __construct(ArtifactRoute $route, int $step, string $media)
    {
        $this->id = Uuid::v7();
        $this->route = $route;
        $this->step = $step;
        $this->media = $media;
    }

    public function getStep(): int { return $this->step; }
    public function getMedia(): string { return $this->media; }
}
