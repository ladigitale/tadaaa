<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Icône d'application d'un artefact (`document.icon`) : `{emoji | text, background, color}` ou
 * `{src: "@asset:icone.png"}` (URL https résolue). Sert au manifeste d'installation, au favicon et à l'icône SVG.
 */
final class ArtifactAppIcon
{
    public const DEFAULT_BACKGROUND = '#111111';
    public const DEFAULT_COLOR = '#ffffff';

    /** Couleur CSS simple (pas de var(), pas d'url()). */
    private const COLOR = '/^(#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg+-]{1,60}\)|[a-z]{3,24})$/i';

    /**
     * @return array{emoji: ?string, text: ?string, background: string, color: string, src: ?string}
     */
    public static function fromDocument(mixed $document): array
    {
        $icon = \is_array($document) && \is_array($document['icon'] ?? null) ? $document['icon'] : [];
        $str = static fn (string $k, int $max): ?string => \is_string($icon[$k] ?? null) && trim($icon[$k]) !== '' && mb_strlen($icon[$k]) <= $max ? trim($icon[$k]) : null;
        $bg = $str('background', 80);
        $fg = $str('color', 80);
        $src = $str('src', 600);

        return [
            'emoji' => $str('emoji', 16),
            'text' => $str('text', 3),
            'background' => $bg !== null && preg_match(self::COLOR, $bg) === 1 ? $bg : self::DEFAULT_BACKGROUND,
            'color' => $fg !== null && preg_match(self::COLOR, $fg) === 1 ? $fg : self::DEFAULT_COLOR,
            'src' => $src !== null && str_starts_with($src, 'https://') ? $src : null,
        ];
    }

    /**
     * Erreurs de validation de `document.icon`.
     *
     * @return list<array{path: string, message: string}>
     */
    public static function validate(mixed $icon): array
    {
        if (!\is_array($icon) || array_is_list($icon) && $icon !== []) {
            return [['path' => '/icon', 'message' => 'icon doit être un objet {emoji | text, background, color} ou {src}.']];
        }
        $errors = [];
        foreach (array_keys($icon) as $k) {
            if (!\in_array($k, ['emoji', 'text', 'background', 'color', 'src'], true)) {
                $errors[] = ['path' => '/icon/'.$k, 'message' => 'Clé non autorisée (emoji, text, background, color, src).'];
            }
        }
        if (isset($icon['emoji']) && (!\is_string($icon['emoji']) || mb_strlen($icon['emoji']) > 16 || trim($icon['emoji']) === '')) {
            $errors[] = ['path' => '/icon/emoji', 'message' => 'emoji : un emoji (16 caractères au plus).'];
        }
        if (isset($icon['text']) && (!\is_string($icon['text']) || mb_strlen($icon['text']) > 3 || trim($icon['text']) === '')) {
            $errors[] = ['path' => '/icon/text', 'message' => 'text : 1 à 3 caractères.'];
        }
        foreach (['background', 'color'] as $k) {
            if (isset($icon[$k]) && (!\is_string($icon[$k]) || preg_match(self::COLOR, trim($icon[$k])) !== 1)) {
                $errors[] = ['path' => '/icon/'.$k, 'message' => $k.' : couleur CSS (#hex, rgb(), hsl() ou nom).'];
            }
        }
        if (isset($icon['src']) && (!\is_string($icon['src']) || !(str_starts_with($icon['src'], '@asset:') || str_starts_with($icon['src'], 'https://')))) {
            $errors[] = ['path' => '/icon/src', 'message' => 'src : "@asset:icone.png" (asset carré de l\'artefact, 512 px conseillé) ou URL https.'];
        }

        return $errors;
    }

    /** SVG 512 × 512 : emoji, ou lettres (text, sinon initiale du titre), centré sur le fond. */
    public static function svg(array $icon, string $title): string
    {
        $glyph = $icon['emoji'] ?? $icon['text'] ?? mb_strtoupper(mb_substr(trim($title) !== '' ? trim($title) : '?', 0, 1));
        $isEmoji = $icon['emoji'] !== null;
        $size = $isEmoji ? 300 : (mb_strlen($glyph) > 2 ? 190 : (mb_strlen($glyph) > 1 ? 250 : 320));
        $esc = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_XML1, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
            .'<rect width="512" height="512" rx="112" fill="'.$esc($icon['background']).'"/>'
            .'<text x="256" y="268" font-size="'.$size.'" text-anchor="middle" dominant-baseline="central" fill="'.$esc($icon['color']).'"'
            .' font-family="\'Apple Color Emoji\', \'Segoe UI Emoji\', \'Noto Color Emoji\', system-ui, Helvetica, Arial, sans-serif" font-weight="700">'
            .$esc($glyph).'</text></svg>';
    }
}
