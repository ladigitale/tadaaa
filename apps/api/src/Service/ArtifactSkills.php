<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Registre des Agent Skills des artefacts (servies sur MCP selon l'extension io.modelcontextprotocol/skills, SEP-2640).
 *
 * Deux sources :
 * - fichiers : chaque dossier de config/artifacts/skills qui contient un SKILL.md est une skill ; son chemin relatif
 *   est le <skill-path> de l'URI (skill://design/SKILL.md, skill://actions/modifier-artefact/SKILL.md) et les autres
 *   fichiers du dossier (references/…) sont ses fichiers annexes ;
 * - briques (briques.json) : une skill par famille de composants, générée à partir des règles du catalogue et des
 *   descriptions des composants (skill://briques/3d/SKILL.md), donc toujours à jour du catalogue.
 *
 * Le dernier segment du chemin est le `name` du frontmatter (vérifié par ArtifactSkillsTest).
 */
final class ArtifactSkills
{
    public const SCHEME = 'skill://';

    /** @var array<string, array{path: string, frontmatter: array<string, mixed>, files: array<string, string>}>|null */
    private ?array $skills = null;

    public function __construct(
        private readonly ArtifactDocumentValidator $validator,
        #[Autowire('%kernel.project_dir%/config/artifacts/skills')]
        private readonly string $dir,
    ) {
    }

    /**
     * Toutes les skills, par nom.
     *
     * @return array<string, array{path: string, frontmatter: array<string, mixed>, files: array<string, string>}>
     */
    public function all(): array
    {
        return $this->skills ??= $this->load();
    }

    /** @return array{path: string, frontmatter: array<string, mixed>, files: array<string, string>}|null */
    public function byName(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** Skill dont `uri` est le SKILL.md. @return array{path: string, frontmatter: array<string, mixed>, files: array<string, string>}|null */
    public function byUri(string $uri): ?array
    {
        foreach ($this->all() as $skill) {
            if (self::uri($skill['path']) === $uri) {
                return $skill;
            }
        }

        return null;
    }

    /** Contenu d'un fichier de skill (SKILL.md ou annexe) par son URI, null si inconnu. */
    public function read(string $uri): ?string
    {
        foreach ($this->all() as $skill) {
            foreach ($skill['files'] as $file => $content) {
                if (self::uri($skill['path'], $file) === $uri) {
                    return $content;
                }
            }
        }

        return null;
    }

    public static function uri(string $path, string $file = 'SKILL.md'): string
    {
        return self::SCHEME.$path.'/'.$file;
    }

    /**
     * Entrée `Skill` de skills/list et skills/get : frontmatter tel quel et manifeste complet des fichiers
     * (empreinte sha256 et taille des octets servis).
     *
     * @param array{path: string, frontmatter: array<string, mixed>, files: array<string, string>} $skill
     *
     * @return array{uri: string, frontmatter: array<string, mixed>, resources: list<array{uri: string, digest: string, size: int}>}
     */
    public static function entry(array $skill): array
    {
        $resources = [];
        foreach ($skill['files'] as $file => $content) {
            $resources[] = [
                'uri' => self::uri($skill['path'], $file),
                'digest' => 'sha256:'.hash('sha256', $content),
                'size' => \strlen($content),
            ];
        }

        return ['uri' => self::uri($skill['path']), 'frontmatter' => $skill['frontmatter'], 'resources' => $resources];
    }

    /** Corps d'un SKILL.md, sans son en-tête. */
    public static function body(string $text): string
    {
        return preg_match('/\A---\R.*?\R---\R(.*)\z/s', $text, $m) === 1 ? trim($m[1]) : trim($text);
    }

    /**
     * Sommaire des skills par famille (pour get_artifact_skill sans nom et les instructions du serveur).
     *
     * @return list<array{name: string, uri: string, family: string, description: string}>
     */
    public function index(): array
    {
        $out = [];
        foreach ($this->all() as $name => $skill) {
            $family = str_contains($skill['path'], '/') ? explode('/', $skill['path'])[0] : ($name === 'demarrer' ? 'pilote' : 'conception');
            $out[] = ['name' => $name, 'uri' => self::uri($skill['path']), 'family' => $family, 'description' => (string) ($skill['frontmatter']['description'] ?? '')];
        }

        return $out;
    }

    /** @return array<string, array{path: string, frontmatter: array<string, mixed>, files: array<string, string>}> */
    private function load(): array
    {
        $skills = [];
        foreach ($this->skillDirs() as $path) {
            $files = [];
            $base = $this->dir.'/'.$path;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $rel = substr($file->getPathname(), \strlen($base) + 1);
                // Un SKILL.md plus profond appartient à une autre skill.
                if ($rel !== 'SKILL.md' && str_ends_with($rel, '/SKILL.md')) {
                    continue;
                }
                if ($rel !== 'SKILL.md' && $this->isInsideNestedSkill($base, $rel)) {
                    continue;
                }
                $files[$rel] = (string) file_get_contents($file->getPathname());
            }
            ksort($files);
            $files = ['SKILL.md' => $files['SKILL.md']] + $files;
            $frontmatter = self::frontmatter($files['SKILL.md']);
            $skills[(string) ($frontmatter['name'] ?? basename($path))] = ['path' => $path, 'frontmatter' => $frontmatter, 'files' => $files];
        }
        foreach ($this->briques() as $name => $skill) {
            $skills[$name] ??= $skill;
        }

        return $skills;
    }

    /** @return list<string> chemins relatifs des dossiers qui ont un SKILL.md */
    private function skillDirs(): array
    {
        $dirs = [];
        if (!is_dir($this->dir)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getFilename() === 'SKILL.md') {
                $dirs[] = substr($file->getPath(), \strlen($this->dir) + 1);
            }
        }
        sort($dirs);

        return $dirs;
    }

    private function isInsideNestedSkill(string $base, string $rel): bool
    {
        $parts = explode('/', $rel);
        array_pop($parts);
        $dir = $base;
        foreach ($parts as $part) {
            $dir .= '/'.$part;
            if (is_file($dir.'/SKILL.md')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public static function frontmatter(string $text): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R/s', $text, $m) !== 1) {
            return [];
        }
        $parsed = Yaml::parse($m[1]);

        return \is_array($parsed) ? $parsed : [];
    }

    /**
     * Skills générées depuis briques.json : règles du catalogue + description des composants de chaque addon.
     *
     * @return array<string, array{path: string, frontmatter: array<string, mixed>, files: array<string, string>}>
     */
    private function briques(): array
    {
        $file = $this->dir.'/briques.json';
        if (!is_file($file)) {
            return [];
        }
        $spec = json_decode((string) file_get_contents($file), true);
        $rules = $this->validator->mcpCatalogPayload(true, ['__none__'], null, false)['rules'] ?? [];
        $byAddon = [];
        $byName = [];
        foreach ($this->validator->catalog()['components'] ?? [] as $component) {
            if (!\is_array($component) || !\is_string($component['name'] ?? null)) {
                continue;
            }
            $byName[$component['name']] = $component;
            $byAddon[\is_string($component['addon'] ?? null) ? $component['addon'] : 'concorde'][] = $component['name'];
        }

        $out = [];
        foreach ($spec['briques'] ?? [] as $b) {
            $name = (string) $b['name'];
            $components = $b['components'] ?? [];
            foreach ($b['addons'] ?? [] as $addon) {
                array_push($components, ...($byAddon[$addon] ?? []));
            }
            $components = array_values(array_unique(array_filter($components, static fn (string $c): bool => isset($byName[$c]))));
            $ruleKeys = array_values(array_filter($b['rules'] ?? [], static fn (string $r): bool => \is_string($rules[$r] ?? null)));

            $frontmatter = [
                'name' => $name,
                'description' => (string) $b['description'],
                'metadata' => array_filter([
                    'famille' => 'brique',
                    'composants' => implode(' ', $components),
                    'regles' => implode(' ', $ruleKeys),
                ], static fn (string $v): bool => $v !== ''),
            ];

            $lines = ['# Brique '.$name, '', (string) $b['description'], ''];
            $lines[] = '## Avant d\'écrire';
            $related = array_map(static fn (string $s): string => '`'.$s.'`', $b['skills'] ?? []);
            $lines[] = '- Interface visible : skill `design` puis l\'archétype'.($related !== [] ? ' (ici souvent '.implode(', ', $related).')' : '').'.';
            if ($components !== []) {
                $lines[] = '- Props exactes (types, valeurs permises) : `get_artifact_catalog(components=['.implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $components)).'], examples=false)`.';
            }
            $lines[] = '- Shader, modèle 3D, son, image ou données : `search_resources` avant de créer, `get_resource` pour reprendre.';
            $lines[] = '- Vérifier ensuite avec la skill `tester-artefact` (validate_artifact, simulate_store, render_artifact).';
            $lines[] = '';
            foreach ($ruleKeys as $key) {
                $lines[] = '## Règle « '.$key.' »';
                $lines[] = '';
                $lines[] = self::paragraphs((string) $rules[$key]);
                $lines[] = '';
            }
            if ($components !== []) {
                $lines[] = '## Composants';
                $lines[] = '';
                foreach ($components as $c) {
                    $desc = trim((string) ($byName[$c]['description'] ?? ''));
                    $lines[] = '- `'.$c.'`'.(isset($byName[$c]['addon']) ? ' (addon '.$byName[$c]['addon'].')' : '').($desc !== '' ? ' : '.$desc : '');
                }
                $lines[] = '';
            }

            $text = "---\n".self::yaml($frontmatter)."---\n\n".rtrim(implode("\n", $lines))."\n";
            $out[$name] = ['path' => 'briques/'.$name, 'frontmatter' => $frontmatter, 'files' => ['SKILL.md' => $text]];
        }

        return $out;
    }

    /** Coupe une règle d'un seul tenant en paragraphes lisibles (avant chaque phrase en capitales « MOT : »). */
    private static function paragraphs(string $rule): string
    {
        return trim((string) preg_replace('/(?<=[.;]) (?=[A-ZÀ-Ý][A-ZÀ-Ý0-9 ’\'()-]{3,} ?:)/u', "\n\n", $rule));
    }

    /** @param array<string, mixed> $data */
    private static function yaml(array $data): string
    {
        return Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
