<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ArtifactRun;

/**
 * Le viewer d'un run a publié des éléments de sa file d'envoi (`io.stream.emit`) :
 * les liens entre runs les renvoient dans d'autres flux.
 */
final class ArtifactRunEmitted
{
    /** @param list<\stdClass> $items éléments {id, data} */
    public function __construct(public readonly ArtifactRun $run, public readonly array $items)
    {
    }
}
