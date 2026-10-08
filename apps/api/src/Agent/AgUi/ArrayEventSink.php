<?php

declare(strict_types=1);

namespace App\Agent\AgUi;

/** Collecte les événements (tests, rejeu). */
final class ArrayEventSink implements EventSink
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function emit(array $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(static fn (array $e): string => (string) $e['type'], $this->events);
    }
}
