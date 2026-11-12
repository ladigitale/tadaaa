<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Llm\LlmClient;

/**
 * Textes joints ou collés (`pasted_text`, voir {@see UserContent}) → blocs texte du modèle :
 * tels quels jusqu'à {@see self::INLINE_CHARS} caractères, résumés au-delà (un appel au modèle,
 * consignes : garder chiffres, noms, structure, extraits de code utiles). Si le résumé échoue, le
 * texte est compressé (début et fin gardés, milieu retiré) plutôt que perdu.
 */
final class PastedTextCompactor
{
    public const INLINE_CHARS = 30000;
    /** Au-delà, le milieu est retiré avant même le résumé (contexte du modèle). */
    public const SUMMARY_INPUT_CHARS = 150000;

    private const SYSTEM = 'Tu résumes un texte collé par une personne dans une conversation avec un assistant qui crée des '
        .'artefacts (pages, jeux, outils, visualisations). Le résumé remplace le texte : garde fidèlement tout ce qui peut '
        .'servir (faits, chiffres, dates, noms propres, listes, structure des sections, contraintes, données tabulaires '
        .'résumées avec leurs colonnes et quelques lignes d’exemple, extraits de code ou de configuration indispensables). '
        .'Pas d’avis ni d’introduction. Même langue que le texte. 1500 mots au plus.';

    /** @var array<string, string> empreinte → résumé (même texte renvoyé à chaque run d'un fil) */
    private array $cache = [];

    public function __construct(private readonly LlmClient $llm)
    {
    }

    /** Vrai si au moins un message contient un texte collé à reprendre. */
    public static function has(array $messages): bool
    {
        foreach ($messages as $m) {
            foreach (\is_array($m['content'] ?? null) ? $m['content'] : [] as $b) {
                if (\is_array($b) && ($b['type'] ?? null) === 'pasted_text') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @param callable(string): void|null               $status   libellé d'état pendant un résumé
     *
     * @return list<array{role: string, content: mixed}>
     */
    public function resolve(array $messages, ?callable $status = null): array
    {
        foreach ($messages as $i => $message) {
            if (!\is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $j => $block) {
                if (!\is_array($block) || ($block['type'] ?? null) !== 'pasted_text') {
                    continue;
                }
                $messages[$i]['content'][$j] = ['type' => 'text', 'text' => $this->render((string) $block['name'], (string) $block['text'], (bool) ($block['pasted'] ?? false), $status)];
            }
        }

        return $messages;
    }

    private function render(string $name, string $text, bool $pasted, ?callable $status): string
    {
        $chars = mb_strlen($text);
        $lines = substr_count($text, "\n") + 1;
        $label = $pasted ? 'Texte collé' : 'Fichier joint';
        if ($chars <= self::INLINE_CHARS) {
            return sprintf("[%s « %s » — %d lignes]\n```\n%s\n```", $label, $name, $lines, $text);
        }
        $key = hash('sha256', $text);
        if (!isset($this->cache[$key])) {
            if ($status !== null) {
                $status(sprintf('Résumé de « %s » (%s caractères)…', $name, number_format($chars, 0, ',', ' ')));
            }
            $this->cache[$key] = $this->summarize($text) ?? self::squeeze($text, self::INLINE_CHARS);
        }

        return sprintf(
            "[%s « %s » — %d lignes, %d caractères : trop long pour être lu en entier, voici %s. Demande à la personne l’extrait précis s’il te manque un détail.]\n%s",
            $label,
            $name,
            $lines,
            $chars,
            str_contains($this->cache[$key], '[… ') ? 'son début et sa fin' : 'un résumé fidèle',
            $this->cache[$key],
        );
    }

    private function summarize(string $text): ?string
    {
        try {
            $response = $this->llm->complete(self::SYSTEM, [['role' => 'user', 'content' => "Texte à résumer :\n\n".self::squeeze($text, self::SUMMARY_INPUT_CHARS)]], []);
            $summary = trim($response->text());

            return $summary !== '' ? $summary : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Garde le début (3/4) et la fin (1/4) d'un texte trop long. */
    public static function squeeze(string $text, int $max): string
    {
        $chars = mb_strlen($text);
        if ($chars <= $max) {
            return $text;
        }
        $head = (int) floor($max * 0.75);
        $tail = $max - $head;

        return mb_substr($text, 0, $head)
            .sprintf("\n[… %d caractères retirés …]\n", $chars - $max)
            .mb_substr($text, -$tail);
    }
}
