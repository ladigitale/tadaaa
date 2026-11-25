<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Image de page d'un artefact (aperçu des liens partagés : `og:image`, captures du manifeste d'installation).
 *
 * Le document la règle dans `image` :
 * - `{"src": "@asset:apercu.png", "alt": "…"}` : image fournie (png, jpg, webp ; 1200 × 630 conseillé) ;
 * - `{"view": "jeu", "wait": 1500}` : capture de cette vue du viewer, après `wait` ms (défaut : vue par défaut) ;
 * - `{"mode": "carte"}` : carte icône + titre + description, sans capture.
 * Sans `image` : capture de la vue par défaut ; une capture ratée ou vide retombe sur la carte.
 *
 * Les images rendues sont gardées sur disque par artefact, version et réglage : une version n'est rendue qu'une fois.
 */
final class ArtifactPageImage
{
    public const WIDE = ['width' => 1200, 'height' => 630];
    public const NARROW = ['width' => 540, 'height' => 1170];
    private const KEYS = ['src', 'alt', 'view', 'wait', 'mode'];
    /** Une capture plus petite est une page unie (rien d'affiché) : on prend la carte. */
    private const MIN_CAPTURE_BYTES = 6000;

    public function __construct(
        private readonly ArtifactRenderer $renderer,
        #[Autowire('%kernel.project_dir%/var/artifact-page-images')]
        private readonly string $cacheDir,
    ) {
    }

    /**
     * @return array{src: ?string, alt: ?string, view: ?string, wait: int, mode: string}
     */
    public static function fromDocument(mixed $document): array
    {
        $img = \is_array($document) && \is_array($document['image'] ?? null) ? $document['image'] : [];
        $src = \is_string($img['src'] ?? null) && str_starts_with($img['src'], 'https://') && mb_strlen($img['src']) <= 600 ? $img['src'] : null;
        $alt = \is_string($img['alt'] ?? null) && trim($img['alt']) !== '' ? mb_substr(trim($img['alt']), 0, 200) : null;
        $view = \is_string($img['view'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $img['view']) === 1 ? $img['view'] : null;
        $wait = \is_int($img['wait'] ?? null) ? max(0, min(5000, $img['wait'])) : 1500;
        $mode = ($img['mode'] ?? null) === 'carte' ? 'carte' : 'capture';

        return ['src' => $src, 'alt' => $alt, 'view' => $view, 'wait' => $wait, 'mode' => $mode];
    }

    /**
     * Erreurs de validation de `document.image`.
     *
     * @return list<array{path: string, message: string}>
     */
    public static function validate(mixed $image): array
    {
        if (!\is_array($image) || array_is_list($image) && $image !== []) {
            return [['path' => '/image', 'message' => 'image doit être un objet {src, alt} ou {view, wait} ou {mode: "carte"}.']];
        }
        $errors = [];
        foreach (array_keys($image) as $k) {
            if (!\in_array($k, self::KEYS, true)) {
                $errors[] = ['path' => '/image/'.$k, 'message' => 'Clé non autorisée ('.implode(', ', self::KEYS).').'];
            }
        }
        if (isset($image['src']) && (!\is_string($image['src'])
            || !(preg_match('/^@asset:[a-z0-9][a-z0-9._-]{0,63}\.(png|jpe?g|webp)$/', $image['src']) === 1 || (str_starts_with($image['src'], 'https://') && mb_strlen($image['src']) <= 600)))) {
            $errors[] = ['path' => '/image/src', 'message' => 'src : "@asset:apercu.png" (png, jpg ou webp de l\'artefact, 1200 × 630 conseillé) ou URL https.'];
        }
        if (isset($image['alt']) && (!\is_string($image['alt']) || mb_strlen($image['alt']) > 200)) {
            $errors[] = ['path' => '/image/alt', 'message' => 'alt : texte de 200 caractères au plus.'];
        }
        if (isset($image['view']) && (!\is_string($image['view']) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $image['view']) !== 1)) {
            $errors[] = ['path' => '/image/view', 'message' => 'view : id d\'une vue du document.'];
        }
        if (isset($image['wait']) && (!\is_int($image['wait']) || $image['wait'] < 0 || $image['wait'] > 5000)) {
            $errors[] = ['path' => '/image/wait', 'message' => 'wait : attente avant la capture, 0 à 5000 ms.'];
        }
        if (isset($image['mode']) && !\in_array($image['mode'], ['capture', 'carte'], true)) {
            $errors[] = ['path' => '/image/mode', 'message' => 'mode : "capture" (défaut) ou "carte".'];
        }

        return $errors;
    }

    /**
     * PNG de l'image de page (chemin d'un fichier du cache), rendu au besoin ; `null` si le rendu est indisponible.
     *
     * @param array<string, mixed> $body        corps de `ArtifactService::publicRead` (document aux assets résolus)
     * @param ?string              $assetPrefix URL https des assets de l'artefact, chargés pendant la capture
     */
    public function png(array $body, bool $narrow = false, ?string $assetPrefix = null): ?string
    {
        $document = \is_array($body['document'] ?? null) ? $body['document'] : [];
        $image = self::fromDocument($document);
        $title = trim((string) ($body['title'] ?? '')) ?: (string) ($body['slug'] ?? '');
        $description = trim((string) ($body['description'] ?? ''));
        $icon = ArtifactAppIcon::fromDocument($document);
        $key = sha1((string) json_encode([2, $body['id'] ?? '', $body['version'] ?? 0, $narrow, $image, $title, $description, $icon]));
        $file = $this->cacheDir.'/'.substr($key, 0, 2).'/'.$key.'.png';
        if (is_file($file)) {
            return $file;
        }
        if (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0775, true) && !is_dir(\dirname($file))) {
            return null;
        }
        // Un seul rendu à la fois pour une même image : les suivants attendent puis lisent le cache.
        $lock = @fopen($file.'.lock', 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, \LOCK_EX);
            if (is_file($file)) {
                return $file;
            }
            $viewport = $narrow ? self::NARROW : self::WIDE;
            $png = null;
            if ($image['mode'] === 'capture') {
                $png = $this->capture($document, $image, $viewport, $assetPrefix);
            }
            $png ??= $this->capture(self::cardDocument($title, $description, $icon, $narrow), ['view' => null, 'wait' => 300], $viewport, $assetPrefix, 0);
            if ($png === null) {
                return null;
            }
            $tmp = $file.'.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, $png);
            rename($tmp, $file);

            return $file;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
            @unlink($file.'.lock');
        }
    }

    /**
     * @param array<string, mixed>            $document
     * @param array{view: ?string, wait: int} $image
     * @param array{width: int, height: int}  $viewport
     */
    private function capture(array $document, array $image, array $viewport, ?string $assetPrefix, int $minBytes = self::MIN_CAPTURE_BYTES): ?string
    {
        $request = ['document' => $document, 'viewport' => $viewport, 'settleMs' => $image['wait']];
        if ($assetPrefix !== null) {
            $request['assetPrefix'] = $assetPrefix;
        }
        if ($image['view'] !== null) {
            $request['view'] = $image['view'];
        }
        $out = $this->renderer->render($request);
        $png = $out['png'] !== null ? base64_decode($out['png'], true) : false;

        return \is_string($png) && \strlen($png) >= max(1, $minBytes) ? $png : null;
    }

    /**
     * Document SDUI de la carte : fond et icône de `document.icon`, titre et description.
     *
     * @param array{emoji: ?string, text: ?string, background: string, color: string, src: ?string} $icon
     *
     * @return array<string, mixed>
     */
    public static function cardDocument(string $title, string $description, array $icon, bool $narrow = false): array
    {
        $glyph = $icon['emoji'] ?? $icon['text'] ?? mb_strtoupper(mb_substr($title !== '' ? $title : '?', 0, 1));
        $iconNode = $icon['src'] !== null
            ? ['tagName' => 'img', 'attributes' => ['src' => $icon['src'], 'alt' => '', 'style' => 'width:100%;height:100%;object-fit:cover;border-radius:22%']]
            : ['tagName' => 'span', 'attributes' => ['style' => 'font-size:'.($icon['emoji'] !== null ? '150px' : '120px').';font-weight:700;line-height:1'], 'textContent' => $glyph];
        $fit = static fn (string $s, int $max): string => mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)).'…' : $s;
        $titleSize = mb_strlen($title) > 40 ? 58 : 76;
        $text = [
            ['tagName' => 'h1', 'attributes' => ['style' => 'margin:0;font-size:'.$titleSize.'px;line-height:1.05;font-weight:800;letter-spacing:-0.02em'], 'textContent' => $fit($title, 80)],
        ];
        if ($description !== '') {
            $text[] = ['tagName' => 'p', 'attributes' => ['style' => 'margin:28px 0 0;font-size:32px;line-height:1.3;opacity:.82'], 'textContent' => $fit($description, $narrow ? 200 : 160)];
        }
        $text[] = ['tagName' => 'p', 'attributes' => ['style' => 'margin:36px 0 0;font-size:24px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.6'], 'textContent' => 'Artefacts'];
        $layout = $narrow
            ? 'flex-direction:column;justify-content:center;align-items:flex-start;padding:80px 56px;gap:56px'
            : 'flex-direction:row;align-items:center;padding:72px 88px;gap:72px';

        return [
            'schema' => 'artifacts/1',
            'title' => $title,
            'page' => ['nav' => 'none', 'padding' => 'none'],
            'defaultView' => 'carte',
            'views' => [[
                'id' => 'carte',
                'title' => 'Carte',
                'root' => ['nodes' => [[
                    'tagName' => 'div',
                    'attributes' => ['style' => 'position:fixed;inset:0;z-index:10;display:flex;box-sizing:border-box;'.$layout
                        .';background:#faf7f0;color:#141414;font-family:\'Liberation Sans\',Arial,Helvetica,system-ui,\'Noto Color Emoji\',sans-serif'],
                    'nodes' => [
                        [
                            'tagName' => 'div',
                            'attributes' => ['style' => 'flex:none;width:260px;height:260px;border-radius:58px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:'.$icon['background'].';color:'.$icon['color']],
                            'nodes' => [$iconNode],
                        ],
                        ['tagName' => 'div', 'attributes' => ['style' => 'min-width:0'], 'nodes' => $text],
                    ],
                ]]],
            ]],
        ];
    }
}
