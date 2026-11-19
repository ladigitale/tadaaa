<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ArtifactRun;

/** Le viewer d'un run en flux a donné signe de vie (alive) ou est parti (bye) : présence à revoir. */
final class ArtifactRunSeen
{
    public function __construct(public readonly ArtifactRun $run)
    {
    }
}
