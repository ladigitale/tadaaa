<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ressources de base (shaders…) livrées avec Creative-stack, en lecture seule : copie de
 * `dist/library.json` dans `config/artifacts/library.json` (`node scripts/sync-resource-library.mjs`).
 * Identifiant `addon/id` (ex. `shader/plasma`) ; `version` et `sha256` servent à détecter qu'une copie
 * gardée par l'utilisateur ou par un artefact n'est plus à jour.
 */
final class BaseResources
{
    /** @var list<array<string, mixed>>|null */
    private ?array $entries = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/library.json')]
        private readonly string $file,
    ) {
    }

    /** @return list<array<string, mixed>> entrées complètes (avec `source`) */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $this->entries = [];
        if (is_file($this->file)) {
            $json = json_decode((string) file_get_contents($this->file), true);
            if (\is_array($json) && \is_array($json['entries'] ?? null)) {
                $this->entries = array_values(array_filter($json['entries'], static fn ($e): bool => \is_array($e) && \is_string($e['id'] ?? null) && \is_string($e['source'] ?? null)));
            }
        }

        return $this->entries;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Fiche d'une entrée sans son contenu, au même format que les ressources du compte.
     *
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    public static function summary(array $e): array
    {
        $source = (string) $e['source'];

        return [
            'ref' => 'base:'.$e['id'],
            'source' => 'base',
            'kind' => (string) ($e['kind'] ?? 'shader'),
            'slug' => $e['id'],
            'name' => (string) ($e['title'] ?? $e['id']),
            'description' => (string) ($e['description'] ?? ''),
            'tags' => array_values(array_filter((array) ($e['tags'] ?? []), 'is_string')),
            'version' => (int) ($e['version'] ?? 1),
            'sizeBytes' => \strlen($source),
            'sha256' => (string) ($e['sha256'] ?? hash('sha256', $source)),
            'license' => (string) ($e['license'] ?? ''),
            'channels' => array_values((array) ($e['channels'] ?? [])),
            'params' => array_values((array) ($e['params'] ?? [])),
        ];
    }
}
