<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\AgUi\EventSink;

/** Ce qu'un outil peut faire pendant un run : émettre des événements AG-UI. */
final class ToolContext
{
    private int $counter = 0;

    public function __construct(
        public readonly EventSink $sink,
        public readonly string $runId,
    ) {
    }

    /** Identifiant court et unique dans le run (surfaces, messages). */
    public function nextId(string $prefix): string
    {
        return sprintf('%s-%s-%d', $prefix, substr(preg_replace('/[^A-Za-z0-9]/', '', $this->runId) ?: 'run', 0, 8), ++$this->counter);
    }
}
