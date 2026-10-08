<?php

declare(strict_types=1);

namespace App\Agent\Llm;

final class LlmResponse
{
    /**
     * @param list<array<string, mixed>> $content blocs `{type: text, text}` / `{type: tool_use, id, name, input}`
     * @param string                     $stopReason `end_turn`, `tool_use`, `max_tokens`…
     * @param array<string, \stdClass>   $rawToolInputs arguments des tool_use décodés en objets, par id
     *                                                  (`{}` distinct de `[]`, voir JsonShape)
     * @param list<mixed>|null           $rawContent    `content` décodé en objets, renvoyé tel quel au
     *                                                  fournisseur dans l'historique
     */
    public function __construct(
        public readonly array $content,
        public readonly string $stopReason,
        public readonly array $rawToolInputs = [],
        public readonly ?array $rawContent = null,
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

    /** @return list<array{id: string, name: string, input: array<string, mixed>, raw: ?\stdClass}> */
    public function toolUses(): array
    {
        $out = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $out[] = [
                    'id' => (string) $block['id'],
                    'name' => (string) $block['name'],
                    'input' => \is_array($block['input'] ?? null) ? $block['input'] : [],
                    'raw' => $this->rawToolInputs[(string) $block['id']] ?? null,
                ];
            }
        }

        return $out;
    }
}
