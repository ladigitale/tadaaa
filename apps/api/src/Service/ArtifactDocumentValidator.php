<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validates Artefacts SDUI envelopes against schema limits + component whitelist.
 * Rejects js/css/markup/innerHTML and unsafe URLs.
 */
final class ArtifactDocumentValidator
{
    public const MAX_BYTES = 512 * 1024;
    public const MAX_DEPTH = 32;
    public const MAX_NODES = 5000;
    public const MAX_JSONATA = 2048;

    private const FORBIDDEN_NODE_KEYS = ['markup', 'innerHTML', 'prefix', 'suffix', 'js', 'css'];
    private const FORBIDDEN_DESCRIPTOR_KEYS = ['js', 'css'];

    /** @var list<string> */
    private array $allowedTags;

    /** @var array<string, true> */
    private array $allowedTagSet;

    /**
     * @param array{components?: list<array{name: string}>, safeHtmlTags?: list<string>}|null $catalog
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/catalog.json')]
        private readonly string $catalogPath,
        #[Autowire('%kernel.project_dir%/config/artifacts/sdui.schema.json')]
        private readonly string $schemaPath,
        ?array $catalog = null,
    ) {
        $data = $catalog ?? $this->loadJson($this->catalogPath);
        $tags = $data['safeHtmlTags'] ?? [];
        foreach ($data['components'] ?? [] as $component) {
            if (isset($component['name']) && \is_string($component['name'])) {
                $tags[] = $component['name'];
            }
        }
        $this->allowedTags = array_values(array_unique($tags));
        $this->allowedTagSet = array_fill_keys($this->allowedTags, true);
    }

    /** @return list<string> */
    public function allowedTags(): array
    {
        return $this->allowedTags;
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return $this->loadJson($this->catalogPath);
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return $this->loadJson($this->schemaPath);
    }

    /**
     * Payload pour l’outil MCP get_artifact_catalog.
     *
     * @return array<string, mixed>
     */
    public function mcpCatalogPayload(): array
    {
        $examplesDir = \dirname($this->catalogPath).'/examples';
        $examples = [];
        if (is_dir($examplesDir)) {
            foreach (glob($examplesDir.'/*.json') ?: [] as $file) {
                $decoded = $this->loadJson($file);
                if ($decoded !== []) {
                    $examples[] = $decoded;
                }
            }
        }

        return [
            'catalog' => $this->catalog(),
            'envelope' => [
                'schema' => 'artifacts/1',
                'title' => 'Titre affiché',
                'theme' => 'default',
                'defaultView' => 'home',
                'views' => [
                    [
                        'id' => 'home',
                        'title' => 'Accueil',
                        'root' => ['nodes' => [['tagName' => 'div', 'attributes' => ['class' => 'p-4']]]],
                    ],
                ],
                'data' => [
                    'sources' => ['votes' => ['collection' => 'votes']],
                    'transforms' => ['total' => ['jsonata' => '$count(votes)']],
                ],
            ],
            'rules' => [
                'interdit' => ['markup', 'innerHTML', 'js', 'css', 'prefix', 'suffix', 'javascript:'],
                'tagName' => 'Uniquement composants Concorde (sonic-*) ou balises HTML sûres du catalogue.',
                'navigation' => 'views[].id = hash URL (#stats). defaultView si hash absent.',
            ],
            'examples' => array_slice($examples, 0, 3),
        ];
    }

    /**
     * @param array<string, mixed>|mixed $document
     *
     * @return array{valid: bool, errors: list<array{path: string, message: string}>}
     */
    public function validate(mixed $document): array
    {
        $errors = [];
        if (!\is_array($document)) {
            return ['valid' => false, 'errors' => [['path' => '', 'message' => 'Le document doit être un objet JSON.']]];
        }

        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['valid' => false, 'errors' => [['path' => '', 'message' => 'JSON invalide.']]];
        }
        if (\strlen($encoded) > self::MAX_BYTES) {
            $errors[] = ['path' => '', 'message' => sprintf('Document trop volumineux (max %d Ko).', self::MAX_BYTES / 1024)];
        }

        if (($document['schema'] ?? null) !== 'artifacts/1') {
            $errors[] = ['path' => '/schema', 'message' => 'schema doit être "artifacts/1".'];
        }

        $title = $document['title'] ?? null;
        if (!\is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
            $errors[] = ['path' => '/title', 'message' => 'title requis (1–200 caractères).'];
        }

        $views = $document['views'] ?? null;
        if (!\is_array($views) || $views === []) {
            $errors[] = ['path' => '/views', 'message' => 'Au moins une vue est requise.'];
        } else {
            $viewIds = [];
            $nodeCount = 0;
            foreach (array_values($views) as $i => $view) {
                $base = '/views/'.$i;
                if (!\is_array($view)) {
                    $errors[] = ['path' => $base, 'message' => 'Vue invalide.'];
                    continue;
                }
                $id = $view['id'] ?? null;
                if (!\is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)) {
                    $errors[] = ['path' => $base.'/id', 'message' => 'id de vue invalide.'];
                } elseif (isset($viewIds[$id])) {
                    $errors[] = ['path' => $base.'/id', 'message' => 'id de vue dupliqué.'];
                } else {
                    $viewIds[$id] = true;
                }
                $vTitle = $view['title'] ?? null;
                if (!\is_string($vTitle) || trim($vTitle) === '') {
                    $errors[] = ['path' => $base.'/title', 'message' => 'title de vue requis.'];
                }
                $root = $view['root'] ?? null;
                if (!\is_array($root)) {
                    $errors[] = ['path' => $base.'/root', 'message' => 'root (descripteur SDUI) requis.'];
                } else {
                    $this->validateDescriptor($root, $base.'/root', $errors, $nodeCount, 0);
                }
            }

            $defaultView = $document['defaultView'] ?? null;
            if (!\is_string($defaultView) || !isset($viewIds[$defaultView])) {
                $errors[] = ['path' => '/defaultView', 'message' => 'defaultView doit référencer une vue existante.'];
            }

            if ($nodeCount > self::MAX_NODES) {
                $errors[] = ['path' => '/views', 'message' => sprintf('Trop de nœuds (max %d).', self::MAX_NODES)];
            }
        }

        if (isset($document['data'])) {
            $this->validateDataSection($document['data'], '/data', $errors);
        }

        // Reject unknown top-level keys beyond envelope
        $allowedTop = ['schema' => true, 'title' => true, 'theme' => true, 'views' => true, 'defaultView' => true, 'data' => true];
        foreach (array_keys($document) as $key) {
            if (!isset($allowedTop[$key])) {
                $errors[] = ['path' => '/'.$key, 'message' => 'Clé non autorisée.'];
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $descriptor
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateDescriptor(array $descriptor, string $path, array &$errors, int &$nodeCount, int $depth): void
    {
        foreach (self::FORBIDDEN_DESCRIPTOR_KEYS as $forbidden) {
            if (\array_key_exists($forbidden, $descriptor)) {
                $errors[] = ['path' => $path.'/'.$forbidden, 'message' => sprintf('"%s" est interdit (sécurité).', $forbidden)];
            }
        }

        if (isset($descriptor['library'])) {
            if (!\is_array($descriptor['library'])) {
                $errors[] = ['path' => $path.'/library', 'message' => 'library doit être un objet.'];
            } else {
                foreach ($descriptor['library'] as $key => $node) {
                    if (!\is_array($node)) {
                        $errors[] = ['path' => $path.'/library/'.$key, 'message' => 'Nœud library invalide.'];
                        continue;
                    }
                    $this->validateNode($node, $path.'/library/'.$key, $errors, $nodeCount, $depth + 1);
                }
            }
        }

        if (isset($descriptor['nodes'])) {
            if (!\is_array($descriptor['nodes'])) {
                $errors[] = ['path' => $path.'/nodes', 'message' => 'nodes doit être un tableau.'];
            } else {
                foreach (array_values($descriptor['nodes']) as $i => $node) {
                    if (!\is_array($node)) {
                        $errors[] = ['path' => $path.'/nodes/'.$i, 'message' => 'Nœud invalide.'];
                        continue;
                    }
                    $this->validateNode($node, $path.'/nodes/'.$i, $errors, $nodeCount, $depth + 1);
                }
            }
        }

        foreach (array_keys($descriptor) as $key) {
            if (!\in_array($key, ['library', 'nodes'], true) && !\in_array($key, self::FORBIDDEN_DESCRIPTOR_KEYS, true)) {
                $errors[] = ['path' => $path.'/'.$key, 'message' => 'Clé descripteur non autorisée.'];
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateNode(array $node, string $path, array &$errors, int &$nodeCount, int $depth): void
    {
        ++$nodeCount;
        if ($depth > self::MAX_DEPTH) {
            $errors[] = ['path' => $path, 'message' => sprintf('Profondeur max %d dépassée.', self::MAX_DEPTH)];

            return;
        }

        foreach (self::FORBIDDEN_NODE_KEYS as $forbidden) {
            if (\array_key_exists($forbidden, $node)) {
                $errors[] = ['path' => $path.'/'.$forbidden, 'message' => sprintf('"%s" est interdit (sécurité).', $forbidden)];
            }
        }

        $tagName = $node['tagName'] ?? 'div';
        if (!\is_string($tagName) || $tagName === '') {
            $errors[] = ['path' => $path.'/tagName', 'message' => 'tagName invalide.'];
        } elseif (!isset($this->allowedTagSet[$tagName])) {
            $errors[] = ['path' => $path.'/tagName', 'message' => sprintf('Composant hors liste blanche : %s', $tagName)];
        } elseif (preg_match('/^script$/i', $tagName) || str_contains(strtolower($tagName), 'script')) {
            $errors[] = ['path' => $path.'/tagName', 'message' => 'Balise script interdite.'];
        }

        if (isset($node['attributes'])) {
            if (!\is_array($node['attributes'])) {
                $errors[] = ['path' => $path.'/attributes', 'message' => 'attributes doit être un objet.'];
            } else {
                foreach ($node['attributes'] as $attr => $value) {
                    if (!\is_string($attr) || $attr === '') {
                        $errors[] = ['path' => $path.'/attributes', 'message' => 'Nom d’attribut invalide.'];
                        continue;
                    }
                    if (preg_match('/^on/i', $attr) || strcasecmp($attr, 'srcdoc') === 0) {
                        $errors[] = ['path' => $path.'/attributes/'.$attr, 'message' => 'Attribut événement / srcdoc interdit.'];
                        continue;
                    }
                    if (\is_string($value) && $this->isUnsafeUrl($attr, $value)) {
                        $errors[] = ['path' => $path.'/attributes/'.$attr, 'message' => 'URL non autorisée (https uniquement ; pas de javascript:/data: hors image).'];
                    }
                }
            }
        }

        if (isset($node['nodes'])) {
            if (!\is_array($node['nodes'])) {
                $errors[] = ['path' => $path.'/nodes', 'message' => 'nodes doit être un tableau.'];
            } else {
                foreach (array_values($node['nodes']) as $i => $child) {
                    if (!\is_array($child)) {
                        $errors[] = ['path' => $path.'/nodes/'.$i, 'message' => 'Nœud enfant invalide.'];
                        continue;
                    }
                    $this->validateNode($child, $path.'/nodes/'.$i, $errors, $nodeCount, $depth + 1);
                }
            }
        }

        $allowedKeys = ['tagName' => true, 'attributes' => true, 'nodes' => true, 'libraryKey' => true, 'contentElementSelector' => true, 'parentElementSelector' => true];
        foreach (array_keys($node) as $key) {
            if (!isset($allowedKeys[$key]) && !\in_array($key, self::FORBIDDEN_NODE_KEYS, true)) {
                $errors[] = ['path' => $path.'/'.$key, 'message' => 'Clé de nœud non autorisée.'];
            }
        }
    }

    /**
     * @param mixed $data
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateDataSection(mixed $data, string $path, array &$errors): void
    {
        if (!\is_array($data)) {
            $errors[] = ['path' => $path, 'message' => 'data doit être un objet.'];

            return;
        }
        if (isset($data['sources'])) {
            if (!\is_array($data['sources'])) {
                $errors[] = ['path' => $path.'/sources', 'message' => 'sources doit être un objet.'];
            } else {
                foreach ($data['sources'] as $name => $src) {
                    if (!\is_array($src) || !isset($src['collection']) || !\is_string($src['collection'])
                        || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $src['collection'])) {
                        $errors[] = ['path' => $path.'/sources/'.$name, 'message' => 'source.collection invalide.'];
                    }
                }
            }
        }
        if (isset($data['transforms'])) {
            if (!\is_array($data['transforms'])) {
                $errors[] = ['path' => $path.'/transforms', 'message' => 'transforms doit être un objet.'];
            } else {
                foreach ($data['transforms'] as $name => $tr) {
                    if (!\is_array($tr) || !isset($tr['jsonata']) || !\is_string($tr['jsonata'])) {
                        $errors[] = ['path' => $path.'/transforms/'.$name, 'message' => 'transform.jsonata requis.'];
                        continue;
                    }
                    if (\strlen($tr['jsonata']) > self::MAX_JSONATA) {
                        $errors[] = ['path' => $path.'/transforms/'.$name.'/jsonata', 'message' => 'Expression jsonata trop longue (max 2 Ko).'];
                    }
                }
            }
        }
    }

    private function isUnsafeUrl(string $attr, string $value): bool
    {
        $attrLower = strtolower($attr);
        $urlish = \in_array($attrLower, ['href', 'src', 'action', 'formaction', 'poster', 'data', 'cite'], true)
            || str_ends_with($attrLower, 'url')
            || str_ends_with($attrLower, 'href')
            || str_ends_with($attrLower, 'src');
        if (!$urlish) {
            return false;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/')) {
            return false;
        }
        $lower = strtolower($trimmed);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'vbscript:')) {
            return true;
        }
        if (str_starts_with($lower, 'data:')) {
            return !preg_match('#^data:image/(png|jpe?g|gif|webp|svg\\+xml);#i', $trimmed);
        }
        if (str_starts_with($lower, 'https:')) {
            return false;
        }
        // relative without leading slash already handled; reject http: and others
        return true;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
