<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Catalogue de libs JS/CSS pinnées (CDN jsDelivr) pour Artefacts.
 * Le document ne contient que des IDs — jamais d’URL libre.
 */
final class ArtifactScriptsCatalog
{
    /** @var array{version?: int, cdnAllowlist?: list<string>, maxScriptsPerDocument?: int, categories?: array<string, string>, libraries?: array<string, array<string, mixed>>} */
    private array $data;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/scripts-catalog.json')]
        private readonly string $path,
    ) {
        $raw = file_get_contents($this->path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $this->data = \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    public function maxScriptsPerDocument(): int
    {
        $max = $this->data['maxScriptsPerDocument'] ?? 16;

        return \is_int($max) && $max > 0 ? $max : 16;
    }

    /** @return array<string, true> */
    public function allowedIds(): array
    {
        $libs = $this->data['libraries'] ?? [];
        if (!\is_array($libs)) {
            return [];
        }

        return array_fill_keys(array_keys($libs), true);
    }

    /**
     * Résumé pour MCP / agents (sans tout le contenu binaire).
     *
     * @return array{categories: array<string, string>, libraries: list<array{id: string, category: string, label: string, global?: string, depends?: list<string>}>}
     */
    public function mcpSummary(): array
    {
        $categories = $this->data['categories'] ?? [];
        if (!\is_array($categories)) {
            $categories = [];
        }
        $out = [];
        foreach ($this->data['libraries'] ?? [] as $id => $lib) {
            if (!\is_string($id) || !\is_array($lib)) {
                continue;
            }
            $entry = [
                'id' => $id,
                'category' => (string) ($lib['category'] ?? 'other'),
                'label' => (string) ($lib['label'] ?? $id),
            ];
            if (isset($lib['global']) && \is_string($lib['global'])) {
                $entry['global'] = $lib['global'];
            }
            if (isset($lib['depends']) && \is_array($lib['depends'])) {
                $entry['depends'] = array_values(array_filter($lib['depends'], 'is_string'));
            }
            $out[] = $entry;
        }

        return [
            'categories' => $categories,
            'libraries' => $out,
            'maxScriptsPerDocument' => $this->maxScriptsPerDocument(),
            'usage' => 'Dans le document: "scripts": ["chartjs","leaflet"]. IDs uniquement. Pas d’URL, pas de JS inline.',
        ];
    }

    /**
     * @param list<string>|mixed $ids
     *
     * @return array{assets: list<array{id: string, src: string, integrity?: string, css?: list<string>, global?: string}>, errors: list<array{path: string, message: string}>}
     */
    public function resolve(mixed $ids): array
    {
        $errors = [];
        if ($ids === null) {
            return ['assets' => [], 'errors' => []];
        }
        if (!\is_array($ids)) {
            return ['assets' => [], 'errors' => [['path' => '/scripts', 'message' => 'scripts doit être un tableau d’IDs.']]];
        }

        $max = $this->maxScriptsPerDocument();
        if (\count($ids) > $max) {
            $errors[] = ['path' => '/scripts', 'message' => sprintf('Trop de scripts (max %d).', $max)];
        }

        $ordered = [];
        $seen = [];
        foreach (array_values($ids) as $i => $id) {
            $path = '/scripts/'.$i;
            if (!\is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)) {
                $errors[] = ['path' => $path, 'message' => 'ID de script invalide.'];
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->appendWithDepends($id, $path, $ordered, $seen, $errors);
        }

        $assets = [];
        foreach ($ordered as $id) {
            $lib = $this->data['libraries'][$id] ?? null;
            if (!\is_array($lib) || !isset($lib['src']) || !\is_string($lib['src'])) {
                continue;
            }
            if (!$this->isAllowedCdnUrl($lib['src'])) {
                $errors[] = ['path' => '/scripts', 'message' => sprintf('URL CDN non autorisée pour "%s".', $id)];
                continue;
            }
            $asset = ['id' => $id, 'src' => $lib['src']];
            if (isset($lib['integrity']) && \is_string($lib['integrity']) && $lib['integrity'] !== '') {
                $asset['integrity'] = $lib['integrity'];
            }
            if (isset($lib['global']) && \is_string($lib['global'])) {
                $asset['global'] = $lib['global'];
            }
            if (isset($lib['css']) && \is_array($lib['css'])) {
                $css = [];
                foreach ($lib['css'] as $href) {
                    if (\is_string($href) && $this->isAllowedCdnUrl($href)) {
                        $css[] = $href;
                    }
                }
                if ($css !== []) {
                    $asset['css'] = $css;
                }
            }
            $assets[] = $asset;
        }

        return ['assets' => $assets, 'errors' => $errors];
    }

    /**
     * @param list<string> $ordered
     * @param array<string, true> $seen
     * @param list<array{path: string, message: string}> $errors
     */
    private function appendWithDepends(string $id, string $path, array &$ordered, array &$seen, array &$errors): void
    {
        $libs = $this->data['libraries'] ?? [];
        if (!isset($libs[$id]) || !\is_array($libs[$id])) {
            $errors[] = ['path' => $path, 'message' => sprintf('Script "%s" hors catalogue.', $id)];

            return;
        }
        $depends = $libs[$id]['depends'] ?? [];
        if (\is_array($depends)) {
            foreach ($depends as $dep) {
                if (!\is_string($dep)) {
                    continue;
                }
                if (!isset($seen[$dep])) {
                    $seen[$dep] = true;
                    $this->appendWithDepends($dep, $path, $ordered, $seen, $errors);
                }
            }
        }
        if (!\in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }

    private function isAllowedCdnUrl(string $url): bool
    {
        $allow = $this->data['cdnAllowlist'] ?? ['https://cdn.jsdelivr.net/'];
        if (!\is_array($allow)) {
            return false;
        }
        foreach ($allow as $prefix) {
            if (\is_string($prefix) && str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
