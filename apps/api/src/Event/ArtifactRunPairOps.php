<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ArtifactRun;

/**
 * Le viewer d'un run hôte a publié des commandes d'appairage dans sa file d'envoi
 * (`{op: "kick", runId}` : retirer un appareil).
 */
final class ArtifactRunPairOps
{
    /** @param list<\stdClass> $ops éléments {id, op, runId?} */
    public function __construct(public readonly ArtifactRun $run, public readonly array $ops)
    {
    }
}
