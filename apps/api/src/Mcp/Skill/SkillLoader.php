<?php

declare(strict_types=1);

namespace App\Mcp\Skill;

use App\Service\ArtifactSkills;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\ResourceDefinition;

/**
 * Enregistre chaque fichier de chaque skill (ArtifactSkills) comme ressource MCP : `skill://<chemin>/SKILL.md`
 * (nom et description de son frontmatter, champs en plus dans `_meta` sous io.modelcontextprotocol.skills/) et
 * ses fichiers annexes (`skill://<chemin>/references/…`). Lu par `resources/read` ; découverte par skills/list
 * (SkillsRequestListener), extension déclarée par SkillsExtension.
 */
final class SkillLoader implements LoaderInterface
{
    public const META_PREFIX = 'io.modelcontextprotocol.skills/';

    public function __construct(private readonly ArtifactSkills $skills)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        foreach ($this->skills->all() as $name => $skill) {
            foreach ($skill['files'] as $file => $content) {
                $uri = ArtifactSkills::uri($skill['path'], $file);
                $isMain = $file === 'SKILL.md';
                $meta = null;
                if ($isMain && \is_array($skill['frontmatter']['metadata'] ?? null)) {
                    $meta = [self::META_PREFIX.'metadata' => $skill['frontmatter']['metadata']];
                }
                $registry->registerResource(
                    new ResourceDefinition(
                        uri: $uri,
                        name: $isMain ? (string) $name : self::resourceName($name.'-'.$file),
                        title: $isMain ? null : $name.' — '.$file,
                        description: $isMain ? (string) ($skill['frontmatter']['description'] ?? '') : 'Fichier annexe de la skill '.$name.'.',
                        mimeType: self::mimeType($file),
                        size: \strlen($content),
                        meta: $meta,
                    ),
                    static fn (): string => $content,
                );
            }
        }
    }

    public static function mimeType(string $file): string
    {
        return match (strtolower(pathinfo($file, \PATHINFO_EXTENSION))) {
            'md' => 'text/markdown',
            'json' => 'application/json',
            'glsl' => 'text/plain',
            default => 'text/plain',
        };
    }

    private static function resourceName(string $raw): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $raw), '-');
    }
}
