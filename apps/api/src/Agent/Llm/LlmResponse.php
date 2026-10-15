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
     * @param list<string>               $invalidToolInputs ids des tool_use dont les arguments ne sont pas un
     *                                                      JSON objet valide (flux coupé, modèle approximatif)
     */
    public function __construct(
        public readonly array $content,
        public readonly string $stopReason,
        public readonly array $rawToolInputs = [],
        public readonly ?array $rawContent = null,
        public readonly array $invalidToolInputs = [],
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

    /** @return list<array{id: string, name: string, input: array<string, mixed>, raw: ?\stdClass, invalid: bool}> */
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
                    'invalid' => \in_array((string) $block['id'], $this->invalidToolInputs, true),
                ];
            }
        }

        return $out;
    }
}
