<?php

declare(strict_types=1);

namespace App\Agent\Llm;

final class LlmResponse
{
    /**
     * @param list<array<string, mixed>> $content blocs `{type: text, text}` / `{type: tool_use, id, name, input}`
     * @param string                     $stopReason `end_turn`, `tool_use`, `max_tokens`…
     */
    public function __construct(
        public readonly array $content,
        public readonly string $stopReason,
    ) {
    }

    public function text(): string
    {
        $parts = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'text' && \is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /** @return list<array{id: string, name: string, input: array<string, mixed>}> */
    public function toolUses(): array
    {
        $out = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $out[] = [
                    'id' => (string) $block['id'],
                    'name' => (string) $block['name'],
                    'input' => \is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        return $out;
    }
}
