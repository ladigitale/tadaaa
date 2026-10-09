<?php

declare(strict_types=1);

namespace App\Agent;

/** Ce que {@see ThreadStore} garde d'une conversation. */
final class ThreadState
{
    /**
     * @param list<array{role: string, content: mixed}> $messages     transcription complète envoyée au modèle
     * @param int                                       $messageCount messages texte reçus du navigateur pour ce run
     * @param array<string, mixed>                      $workspace    état des outils (aperçu courant, artefact publié…)
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $messageCount,
        public readonly array $workspace = [],
    ) {
    }

    /** Résultats d'outils longs (catalogue, document relu) remplacés par un rappel. */
    public function compacted(int $maxChars = 2000): self
    {
        $messages = $this->messages;
        foreach ($messages as $i => $message) {
            if (!\is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $j => $block) {
                if (\is_array($block) && ($block['type'] ?? null) === 'tool_result'
                    && \is_string($block['content'] ?? null) && mb_strlen($block['content']) > $maxChars) {
                    $messages[$i]['content'][$j]['content'] = sprintf(
                        '[résultat de %d caractères retiré de la mémoire : rappelle l’outil si tu en as encore besoin]',
                        mb_strlen($block['content']),
                    );
                }
            }
        }

        return new self($messages, $this->messageCount, $this->workspace);
    }
}
