<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/**
 * Lit le flux SSE de la Messages API (`stream: true`) et reconstruit la réponse
 * complète : blocs texte et tool_use (arguments recollés depuis `input_json_delta`).
 *
 * Un tool_use interrompu (max_tokens) garde des arguments incomplets : son `input`
 * vaut `[]` et la réponse porte `stop_reason: max_tokens`.
 */
final class AnthropicStreamParser
{
    private string $buffer = '';

    /** @var array<int, array{type: string, text?: string, id?: string, name?: string, json?: string}> */
    private array $blocks = [];

    private string $stopReason = 'end_turn';

    public function __construct(private readonly ?LlmStream $stream = null)
    {
    }

    public function push(string $chunk): void
    {
        $this->buffer .= str_replace("\r\n", "\n", $chunk);
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 2);
            $this->frame($frame);
        }
    }

    public function finish(): LlmResponse
    {
        if (trim($this->buffer) !== '') {
            $this->frame($this->buffer);
            $this->buffer = '';
        }
        ksort($this->blocks);
        $content = [];
        $rawContent = [];
        $rawInputs = [];
        foreach ($this->blocks as $block) {
            if ($block['type'] === 'text') {
                if (($block['text'] ?? '') === '') {
                    continue;
                }
                $content[] = ['type' => 'text', 'text' => $block['text']];
                $rawContent[] = (object) ['type' => 'text', 'text' => $block['text']];
            } elseif ($block['type'] === 'tool_use') {
                $json = trim($block['json'] ?? '');
                $json = $json === '' ? '{}' : $json;
                $raw = json_decode($json, false);
                $input = json_decode($json, true);
                $raw = $raw instanceof \stdClass ? $raw : new \stdClass();
                $content[] = [
                    'type' => 'tool_use',
                    'id' => (string) $block['id'],
                    'name' => (string) $block['name'],
                    'input' => \is_array($input) ? $input : [],
                ];
                $rawContent[] = (object) ['type' => 'tool_use', 'id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => $raw];
                $rawInputs[(string) $block['id']] = $raw;
            }
        }

        return new LlmResponse($content, $this->stopReason, $rawInputs, $rawContent);
    }

    private function frame(string $frame): void
    {
        $data = '';
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }
        if ($data === '' || $data === '[DONE]') {
            return;
        }
        $event = json_decode($data, true);
        if (!\is_array($event)) {
            return;
        }
        $index = (int) ($event['index'] ?? 0);
        switch ($event['type'] ?? null) {
            case 'content_block_start':
                $block = \is_array($event['content_block'] ?? null) ? $event['content_block'] : [];
                if (($block['type'] ?? null) === 'tool_use') {
                    $this->blocks[$index] = ['type' => 'tool_use', 'id' => (string) ($block['id'] ?? ''), 'name' => (string) ($block['name'] ?? ''), 'json' => ''];
                    $this->stream?->toolStart((string) ($block['id'] ?? ''), (string) ($block['name'] ?? ''));
                } elseif (($block['type'] ?? null) === 'text') {
                    $this->blocks[$index] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                    $this->stream?->text((string) ($block['text'] ?? ''));
                }
                break;
            case 'content_block_delta':
                $delta = \is_array($event['delta'] ?? null) ? $event['delta'] : [];
                if (($delta['type'] ?? null) === 'text_delta' && isset($this->blocks[$index]) && $this->blocks[$index]['type'] === 'text') {
                    $text = (string) ($delta['text'] ?? '');
                    $this->blocks[$index]['text'] = ($this->blocks[$index]['text'] ?? '').$text;
                    $this->stream?->text($text);
                } elseif (($delta['type'] ?? null) === 'input_json_delta' && isset($this->blocks[$index]) && $this->blocks[$index]['type'] === 'tool_use') {
                    $this->blocks[$index]['json'] = ($this->blocks[$index]['json'] ?? '').(string) ($delta['partial_json'] ?? '');
                }
                break;
            case 'message_delta':
                $reason = $event['delta']['stop_reason'] ?? null;
                if (\is_string($reason) && $reason !== '') {
                    $this->stopReason = $reason;
                }
                break;
            case 'error':
                $message = (string) ($event['error']['message'] ?? 'erreur inconnue');
                throw new LlmUnavailable('Le fournisseur a interrompu la réponse : '.LlmHttp::short($message));
        }
    }
}
