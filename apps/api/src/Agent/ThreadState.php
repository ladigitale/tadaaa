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

    public const DRAFT_PLACEHOLDER = '[document retiré de la mémoire : le brouillon courant est sur le serveur, lis-le avec read_preview]';

    /**
     * Les documents complets envoyés à preview_artifact ne sont pas gardés : le brouillon
     * courant est dans le workspace, et les anciennes versions ne servent plus. Sans ça,
     * chaque retouche ajoutait un document entier relu à chaque étape suivante.
     * Les longues lectures (read_preview) sont aussi raccourcies : elles sont périmées.
     */
    public function withoutDrafts(int $maxReadChars = 1500): self
    {
        $readIds = [];
        $messages = $this->messages;
        foreach ($messages as $i => $message) {
            if (!\is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $j => $block) {
                $type = \is_array($block) ? ($block['type'] ?? null) : ($block instanceof \stdClass ? ($block->type ?? null) : null);
                if ($type === 'tool_use') {
                    $name = \is_array($block) ? ($block['name'] ?? null) : ($block->name ?? null);
                    $id = (string) (\is_array($block) ? ($block['id'] ?? '') : ($block->id ?? ''));
                    if ($name === 'read_preview') {
                        $readIds[$id] = true;
                    }
                    if ($name !== 'preview_artifact') {
                        continue;
                    }
                    if (\is_array($block)) {
                        $messages[$i]['content'][$j]['input'] = ['document' => self::DRAFT_PLACEHOLDER];
                    } else {
                        $copy = clone $block;
                        $copy->input = (object) ['document' => self::DRAFT_PLACEHOLDER];
                        $messages[$i]['content'][$j] = $copy;
                    }
                } elseif ($type === 'tool_result' && \is_array($block) && isset($readIds[(string) ($block['tool_use_id'] ?? '')])
                    && \is_string($block['content'] ?? null) && mb_strlen($block['content']) > $maxReadChars) {
                    $messages[$i]['content'][$j]['content'] = '[lecture retirée de la mémoire : relis avec read_preview si besoin]';
                }
            }
        }

        return new self($messages, $this->messageCount, $this->workspace);
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
