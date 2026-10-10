<?php

declare(strict_types=1);

namespace App\Agent\AgUi;

use App\Agent\Tool\PreviewArtifactTool;
use App\Agent\Tool\PublishPreviewTool;

/**
 * Relaie les événements d'un run vers un autre sink et en garde une version compacte,
 * de quoi réafficher la conversation plus tard (historique de l'atelier) :
 * - le texte de l'utilisateur (`note`) et les messages de l'agent, une entrée par message ;
 * - les appels d'outils (sans leurs arguments, qui peuvent contenir des documents entiers) ;
 * - les interfaces A2UI / SDUI et les erreurs, telles quelles ;
 * - à part : le dernier aperçu et la dernière publication.
 *
 * Entrées du journal : `{role: 'user'|'assistant', text}` ou `{event: {…}}` (événement AG-UI à rejouer).
 */
final class RecordingEventSink implements EventSink
{
    /** Taille maximale du journal sérialisé ; au-delà on garde la fin. */
    public const MAX_BYTES = 600_000;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /** @var array<string, int> messageId => index dans $entries */
    private array $texts = [];

    private mixed $preview = null;
    private mixed $published = null;

    public function __construct(private readonly EventSink $inner)
    {
    }

    public function emit(array $event): void
    {
        $this->inner->emit($event);
        $this->record($event);
    }

    public function user(string $text): void
    {
        $this->entries[] = ['role' => 'user', 'text' => $text];
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        $entries = $this->entries;
        while ($entries !== [] && \strlen((string) json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_BYTES) {
            array_splice($entries, 0, max(1, intdiv(\count($entries), 4)));
        }

        return $entries;
    }

    public function preview(): mixed
    {
        return $this->preview;
    }

    public function published(): mixed
    {
        return $this->published;
    }

    /** @param array<string, mixed> $event */
    private function record(array $event): void
    {
        $type = $event['type'] ?? '';
        switch ($type) {
            case 'TEXT_MESSAGE_START':
                $this->texts[(string) ($event['messageId'] ?? '')] = \count($this->entries);
                $this->entries[] = ['role' => 'assistant', 'id' => (string) ($event['messageId'] ?? ''), 'text' => ''];
                break;
            case 'TEXT_MESSAGE_CONTENT':
                $id = (string) ($event['messageId'] ?? '');
                if (isset($this->texts[$id])) {
                    $this->entries[$this->texts[$id]]['text'] .= (string) ($event['delta'] ?? '');
                }
                break;
            case 'TOOL_CALL_START':
                $this->entries[] = ['event' => ['type' => 'TOOL_CALL_START', 'toolCallId' => $event['toolCallId'] ?? '', 'toolCallName' => $event['toolCallName'] ?? '']];
                break;
            case 'TOOL_CALL_END':
                $this->entries[] = ['event' => ['type' => 'TOOL_CALL_END', 'toolCallId' => $event['toolCallId'] ?? '']];
                break;
            case 'RUN_ERROR':
                $this->entries[] = ['event' => ['type' => 'RUN_ERROR', 'message' => (string) ($event['message'] ?? '')]];
                break;
            case 'CUSTOM':
                $name = $event['name'] ?? '';
                if ($name === PreviewArtifactTool::EVENT) {
                    $this->preview = $event['value'] ?? null;
                } elseif ($name === PublishPreviewTool::EVENT) {
                    $this->published = $event['value'] ?? null;
                } elseif ($name === 'a2ui' || $name === 'sdui') {
                    $this->entries[] = ['event' => ['type' => 'CUSTOM', 'name' => $name, 'value' => $event['value'] ?? null]];
                }
                break;
        }
    }
}
