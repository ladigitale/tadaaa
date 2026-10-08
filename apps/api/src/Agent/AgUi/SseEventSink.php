<?php

declare(strict_types=1);

namespace App\Agent\AgUi;

/**
 * Écrit chaque événement AG-UI en Server-Sent Events (`data: {json}` + ligne vide)
 * et vide les tampons pour que le navigateur le reçoive tout de suite.
 */
final class SseEventSink implements EventSink
{
    /** @var \Closure(string): void */
    private \Closure $write;

    /** @param (\Closure(string): void)|null $write sortie (echo + flush par défaut) */
    public function __construct(?\Closure $write = null)
    {
        $this->write = $write ?? static function (string $chunk): void {
            echo $chunk;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };
    }

    public function emit(array $event): void
    {
        $event['timestamp'] ??= (int) (microtime(true) * 1000);
        ($this->write)('data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n");
    }
}
