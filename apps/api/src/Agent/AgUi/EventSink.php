<?php

declare(strict_types=1);

namespace App\Agent\AgUi;

/**
 * Destination des événements AG-UI d'un run (flux SSE en production, tableau en test).
 * Les événements sont des tableaux déjà au format du protocole (`type` en SCREAMING_SNAKE).
 */
interface EventSink
{
    /** @param array<string, mixed> $event */
    public function emit(array $event): void;
}
