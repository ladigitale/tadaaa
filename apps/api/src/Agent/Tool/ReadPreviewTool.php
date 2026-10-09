<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Service\JsonPatch;

/**
 * `read_preview` (atelier Artefacts) : lit une partie du brouillon courant. Sans chemin,
 * un plan du document (clés, ids des vues, tailles) ; avec un chemin, la valeur, ou son
 * plan si elle est trop longue. Évite de relire tout un artefact pour en changer un détail.
 */
final class ReadPreviewTool implements AgentTool
{
    public const MAX_CHARS = 6000;

    public function __construct(private readonly DraftStore $drafts)
    {
    }

    public function name(): string
    {
        return 'read_preview';
    }

    public function description(): string
    {
        return 'Lit le brouillon courant (dernier aperçu valide, ou artefact en cours d’édition). Sans path : '
            .'plan du document. Avec path (JSON Pointer, ex. "/views/0/a2ui/1") : la valeur, ou son plan si elle '
            .'est longue. À utiliser avant edit_preview au lieu de relire tout l’artefact.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'JSON Pointer (vide : plan du document)']],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $draft = $this->drafts->current($context);
        if ($draft === null) {
            return ToolResult::json(['error' => 'Pas encore de brouillon dans cette conversation.'], true);
        }
        $path = \is_string($input['path'] ?? null) ? $input['path'] : '';
        try {
            $value = JsonPatch::get($draft, $path);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::json(['error' => $e->getMessage(), 'outline' => self::outline($draft, 2)], true);
        }
        $json = (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($path !== '' && mb_strlen($json) <= self::MAX_CHARS) {
            return new ToolResult($json, false);
        }
        for ($depth = 4; $depth >= 1; --$depth) {
            $outline = (string) json_encode(['path' => $path, 'outline' => self::outline($value, $depth)], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (mb_strlen($outline) <= self::MAX_CHARS) {
                break;
            }
        }

        return new ToolResult(mb_substr($outline, 0, self::MAX_CHARS), false);
    }

    /** Plan d'une valeur : structure jusqu'à `$depth`, puis `{…n clés}` / `[…n]`, textes abrégés. */
    public static function outline(mixed $value, int $depth): mixed
    {
        if (\is_string($value)) {
            return mb_strlen($value) > 60 ? mb_substr($value, 0, 57).'…' : $value;
        }
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            if ($depth <= 0) {
                return sprintf('{…%d clés}', \count($props));
            }
            $out = new \stdClass();
            foreach ($props as $key => $child) {
                $out->{$key} = self::outline($child, $depth - 1);
            }

            return $out;
        }
        if (\is_array($value)) {
            if ($depth <= 0) {
                return sprintf('[…%d]', \count($value));
            }
            $items = array_map(static fn (mixed $v): mixed => self::outline($v, $depth - 1), \array_slice($value, 0, 20));
            if (\count($value) > 20) {
                $items[] = sprintf('…+%d', \count($value) - 20);
            }

            return $items;
        }

        return $value;
    }
}
