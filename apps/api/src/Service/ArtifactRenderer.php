<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rend un document dans le vrai viewer (Chromium headless via tools/render-artifact) et
 * rapporte erreurs console / page, requêtes en échec, fps et capture (outil MCP `render_artifact`).
 *
 * Config : RENDER_VIEWER_DIST = dossier dist/ du viewer construit (ladigitale/artifacts) ;
 * RENDER_CHROME = exécutable Chromium (défaut : celui de Playwright, ou /usr/bin/chromium).
 */
final class ArtifactRenderer
{
    public const TIMEOUT_S = 45;
    public const MAX_OUTPUT = 262144;
    public const MAX_REQUEST = 2097152;
    private const MAX_SHOT_BYTES = 1500000;

    public function __construct(
        #[Autowire('%kernel.project_dir%/tools/render-artifact')]
        private readonly string $toolDir,
        #[Autowire('%env(default::RENDER_VIEWER_DIST)%')]
        private readonly ?string $viewerDist = null,
        #[Autowire('%env(default::RENDER_CHROME)%')]
        private readonly ?string $chrome = null,
        #[Autowire('%env(default::SIMULATE_STORE_NODE)%')]
        private readonly ?string $nodeBinary = null,
    ) {
    }

    public function available(): ?string
    {
        if (!is_file($this->toolDir.'/render-artifact.mjs') || !is_dir($this->toolDir.'/node_modules/playwright-core')) {
            return 'Rendu indisponible (dépendances Node non installées sur ce serveur).';
        }
        if ($this->viewerDist === null || $this->viewerDist === '' || !is_file($this->viewerDist.'/index.html')) {
            return 'Rendu indisponible (RENDER_VIEWER_DIST : viewer construit introuvable).';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request {document, view?, viewport?, steps?, settleMs?, fps?}
     *
     * @return array{result: array<string, mixed>, png: ?string} png = capture finale en base64
     */
    public function render(array $request): array
    {
        if (($why = $this->available()) !== null) {
            return ['result' => ['ok' => false, 'errors' => [$why]], 'png' => null];
        }
        $dir = sys_get_temp_dir().'/render-'.bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true)) {
            return ['result' => ['ok' => false, 'errors' => ['Dossier temporaire indisponible.']], 'png' => null];
        }
        try {
            $request['outDir'] = $dir;
            $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false || \strlen($payload) > self::MAX_REQUEST) {
                return ['result' => ['ok' => false, 'errors' => ['Requête illisible ou trop volumineuse.']], 'png' => null];
            }
            $env = ['PATH' => (string) getenv('PATH'), 'HOME' => $dir, 'RENDER_VIEWER_DIST' => (string) $this->viewerDist];
            $chrome = $this->chrome ?: (is_file('/usr/bin/chromium') ? '/usr/bin/chromium' : '');
            if ($chrome !== '') {
                $env['CHROME_PATH'] = $chrome;
            }
            $run = SubprocessRunner::run(
                [$this->nodeBinary ?: 'node', '--max-old-space-size=256', $this->toolDir.'/render-artifact.mjs'],
                $this->toolDir,
                $payload,
                self::TIMEOUT_S,
                self::MAX_OUTPUT,
                $env,
            );
            if ($run['status'] === 'spawn') {
                return ['result' => ['ok' => false, 'errors' => ['Node introuvable sur ce serveur.']], 'png' => null];
            }
            if ($run['status'] === 'timeout') {
                return ['result' => ['ok' => false, 'errors' => [sprintf('Délai dépassé (%d s) : page trop lente ou bloquée.', self::TIMEOUT_S)]], 'png' => null];
            }
            $decoded = json_decode($run['stdout'], true);
            if (!\is_array($decoded)) {
                return ['result' => ['ok' => false, 'errors' => ['Rendu : sortie invalide.'.($run['stderr'] !== '' ? ' '.mb_substr(trim($run['stderr']), 0, 300) : '')]], 'png' => null];
            }
            $png = null;
            $final = $dir.'/final.png';
            if (is_file($final) && filesize($final) <= self::MAX_SHOT_BYTES) {
                $png = base64_encode((string) file_get_contents($final));
            }
            // Les chemins de captures sont locaux au serveur : on ne les expose pas.
            $decoded['screenshots'] = \count($decoded['screenshots'] ?? []);
            $decoded['screenshotIncluded'] = $png !== null;

            return ['result' => $decoded, 'png' => $png];
        } finally {
            self::removeTree($dir);
        }
    }

    private static function removeTree(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
