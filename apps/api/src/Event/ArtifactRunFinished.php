<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ArtifactRun;

/**
 * Un run d'artefact en attente vient de se terminer (sortie publiée, annulé, expiré) :
 * un workflow qui l'attendait peut reprendre.
 */
final class ArtifactRunFinished
{
    public function __construct(public readonly ArtifactRun $run)
    {
    }
}
