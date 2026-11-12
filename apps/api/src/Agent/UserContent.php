<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Message utilisateur AG-UI multimodal (`content: string | InputContent[]`) → contenu du modèle :
 * texte, images et PDF en blocs (format Anthropic, converti pour OpenAI par le client), textes
 * joints ou collés en bloc provisoire `pasted_text` (repris par {@see PastedTextCompactor} : tels
 * quels s'ils sont courts, résumés sinon).
 *
 * Fragments acceptés : `{type:"text", text}`, `{type:"image"|"document", source:{type:"data", value,
 * mimeType}, metadata?:{filename}}` et l'ancienne forme `{type:"binary", mimeType, data, filename}`.
 * Les sources par URL ne sont pas suivies (aucune requête sortante).
 */
final class UserContent
{
    public const MAX_TEXT = 20000;
    public const MAX_PARTS = 8;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;
    public const MAX_PASTED_CHARS = 400000;
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * @return array{content: string|list<array<string, mixed>>, text: string, attachments: list<array{kind: string, name: string, mimeType: string, size: int}>}|null
     *                                                                                                                                                                       null : message vide
     */
    public static function parse(mixed $content): ?array
    {
        if (\is_string($content)) {
            $text = trim($content);
            if ($text === '') {
                return null;
            }
            if (mb_strlen($text) <= self::MAX_TEXT) {
                return ['content' => $text, 'text' => $text, 'attachments' => []];
            }
            // Message très long tapé ou collé sans pièce jointe : traité comme un texte collé.
            return [
                'content' => [self::pasted('Message long', mb_substr($text, 0, self::MAX_PASTED_CHARS), true)],
                'text' => mb_substr($text, 0, 200).'…',
                'attachments' => [['kind' => 'text', 'name' => 'Message long', 'mimeType' => 'text/plain', 'size' => \strlen($text)]],
            ];
        }
        if (!\is_array($content) || !array_is_list($content)) {
            return null;
        }
        $blocks = [];
        $texts = [];
        $attachments = [];
        $total = 0;
        $files = 0;
        foreach ($content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            $type = $part['type'] ?? null;
            if ($type === 'text') {
                $t = \is_string($part['text'] ?? null) ? trim($part['text']) : '';
                if ($t !== '') {
                    $texts[] = mb_substr($t, 0, self::MAX_TEXT);
                    $blocks[] = ['type' => 'text', 'text' => mb_substr($t, 0, self::MAX_TEXT)];
                }
                continue;
            }
            [$mime, $data, $name] = self::binary($part);
            if ($mime === null) {
                continue;
            }
            if (++$files > self::MAX_PARTS) {
                $blocks[] = ['type' => 'text', 'text' => sprintf('[pièce jointe « %s » ignorée : %d au plus par message]', $name, self::MAX_PARTS)];
                continue;
            }
            if ($data === null) {
                $blocks[] = ['type' => 'text', 'text' => sprintf('[pièce jointe « %s » ignorée : seules les pièces jointes envoyées avec le message sont lues, pas les liens]', $name)];
                continue;
            }
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                $blocks[] = ['type' => 'text', 'text' => sprintf('[pièce jointe « %s » illisible]', $name)];
                continue;
            }
            $size = \strlen($bytes);
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                $blocks[] = ['type' => 'text', 'text' => sprintf('[pièce jointe « %s » ignorée : plus de %d Mo de pièces jointes dans ce message]', $name, self::MAX_TOTAL_BYTES / 1048576)];
                continue;
            }
            if (\in_array($mime, self::IMAGE_TYPES, true)) {
                if ($size > self::MAX_IMAGE_BYTES || !self::looksLikeImage($mime, $bytes)) {
                    $blocks[] = ['type' => 'text', 'text' => sprintf('[image « %s » refusée : %s]', $name, $size > self::MAX_IMAGE_BYTES ? 'plus de 5 Mo' : 'contenu invalide')];
                    continue;
                }
                $blocks[] = ['type' => 'text', 'text' => sprintf('[image jointe : %s]', $name)];
                $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];
                $attachments[] = ['kind' => 'image', 'name' => $name, 'mimeType' => $mime, 'size' => $size];
            } elseif ($mime === 'application/pdf') {
                if ($size > self::MAX_PDF_BYTES || !str_starts_with($bytes, '%PDF')) {
                    $blocks[] = ['type' => 'text', 'text' => sprintf('[PDF « %s » refusé : %s]', $name, $size > self::MAX_PDF_BYTES ? 'plus de 10 Mo' : 'contenu invalide')];
                    continue;
                }
                $blocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data], 'title' => mb_substr($name, 0, 200)];
                $attachments[] = ['kind' => 'document', 'name' => $name, 'mimeType' => $mime, 'size' => $size];
            } elseif (self::isText($mime)) {
                if (!mb_check_encoding($bytes, 'UTF-8')) {
                    $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
                }
                $pasted = ($part['metadata']['pasted'] ?? false) === true;
                $blocks[] = self::pasted($name, mb_substr($bytes, 0, self::MAX_PASTED_CHARS), $pasted);
                $attachments[] = ['kind' => 'text', 'name' => $name, 'mimeType' => $mime, 'size' => $size];
            } else {
                $blocks[] = ['type' => 'text', 'text' => sprintf('[pièce jointe « %s » ignorée : format %s non pris en charge]', $name, $mime)];
            }
        }
        if ($blocks === []) {
            return null;
        }
        $text = implode("\n\n", $texts);
        $onlyText = array_filter($blocks, static fn (array $b): bool => $b['type'] !== 'text') === [];

        return [
            'content' => $onlyText ? implode("\n\n", array_column($blocks, 'text')) : $blocks,
            'text' => $text,
            'attachments' => $attachments,
        ];
    }

    /** @return array{type: 'pasted_text', name: string, text: string, pasted: bool} */
    public static function pasted(string $name, string $text, bool $pasted): array
    {
        return ['type' => 'pasted_text', 'name' => $name, 'text' => $text, 'pasted' => $pasted];
    }

    /**
     * Retire les images et PDF d'une transcription (mémoire du fil) : remplacés par une mention.
     *
     * @param list<array{role: string, content: mixed}> $messages
     *
     * @return list<array{role: string, content: mixed}>
     */
    public static function withoutBinaries(array $messages): array
    {
        foreach ($messages as $i => $message) {
            if (!\is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $j => $block) {
                $type = \is_array($block) ? ($block['type'] ?? null) : null;
                if ($type === 'image') {
                    $messages[$i]['content'][$j] = ['type' => 'text', 'text' => '[image vue plus tôt dans la conversation, plus disponible]'];
                } elseif ($type === 'document') {
                    $messages[$i]['content'][$j] = ['type' => 'text', 'text' => sprintf('[PDF « %s » lu plus tôt dans la conversation, plus disponible]', (string) ($block['title'] ?? 'document'))];
                }
            }
        }

        return $messages;
    }

    /** @return array{0: ?string, 1: ?string, 2: string} type MIME, base64 (null : URL), nom */
    private static function binary(array $part): array
    {
        $type = $part['type'] ?? null;
        $meta = \is_array($part['metadata'] ?? null) ? $part['metadata'] : [];
        $name = \is_string($meta['filename'] ?? null) ? $meta['filename'] : (\is_string($part['filename'] ?? null) ? $part['filename'] : 'pièce jointe');
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F]/u', '', $name) ?? ''), 0, 120) ?: 'pièce jointe';
        if ($type === 'binary') {
            $mime = \is_string($part['mimeType'] ?? null) ? strtolower($part['mimeType']) : null;

            return [$mime, \is_string($part['data'] ?? null) ? $part['data'] : null, $name];
        }
        if (\in_array($type, ['image', 'document', 'audio', 'video'], true) && \is_array($part['source'] ?? null)) {
            $source = $part['source'];
            $mime = \is_string($source['mimeType'] ?? null) ? strtolower($source['mimeType']) : null;
            if (($source['type'] ?? null) === 'data' && \is_string($source['value'] ?? null)) {
                return [$mime, $source['value'], $name];
            }

            return [$mime ?? 'application/octet-stream', null, $name];
        }

        return [null, null, $name];
    }

    private static function isText(string $mime): bool
    {
        return str_starts_with($mime, 'text/') || \in_array($mime, ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml', 'application/csv'], true);
    }

    private static function looksLikeImage(string $mime, string $b): bool
    {
        return match ($mime) {
            'image/png' => str_starts_with($b, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($b, "\xFF\xD8\xFF"),
            'image/gif' => str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a'),
            'image/webp' => str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP',
            default => false,
        };
    }
}
