<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Bibliothèque de ressources du compte (shaders, modèles 3D, données, images, sons), avec versions,
 * recherche, et ressources de base en lecture seule ({@see BaseResources}).
 *
 * Références : `mine:<slug>` (compte) ou `base:<addon>/<id>` (base). Une ressource créée à partir d'une
 * ressource de base garde `base_id` + `base_version` : `updateAvailable` passe à vrai quand la base évolue.
 * Accès DBAL (pas d'hydratation du contenu avec les métadonnées).
 */
final class Resources
{
    public const MAX_RESOURCES = 500;
    public const MAX_VERSIONS = 20;

    private const META = 'r.id, r.kind, r.slug, r.name, r.description, r.tags, r.prompt, r.content_type, r.version, r.size_bytes, r.sha256, r.base_id, r.base_version, r.base_sha256, r.created_at, r.updated_at';

    public function __construct(
        private readonly Connection $connection,
        private readonly StorageQuota $quota,
        private readonly BaseResources $base,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>> les ressources du compte d'abord (récentes en premier), puis celles de base
     */
    public function search(User $user, string $query = '', ?string $kind = null, ?string $tag = null, bool $includeBase = true, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $query = trim($query);
        $tag = $tag !== null ? mb_strtolower(trim($tag)) : null;
        if ($kind !== null && $kind !== '' && !\in_array($kind, ResourceContent::KINDS, true)) {
            throw new BadRequestHttpException(sprintf('Type inconnu (%s).', implode(', ', ResourceContent::KINDS)));
        }
        $kind = $kind === '' ? null : $kind;

        $where = ['r.user_id = :u'];
        $params = ['u' => $user->getId()->toRfc4122()];
        if ($kind !== null) {
            $where[] = 'r.kind = :k';
            $params['k'] = $kind;
        }
        if ($query !== '') {
            $where[] = '(r.name ILIKE :q OR r.slug ILIKE :q OR r.description ILIKE :q OR r.tags::text ILIKE :q)';
            $params['q'] = '%'.self::escapeLike($query).'%';
        }
        if ($tag !== null && $tag !== '') {
            $where[] = 'r.tags::jsonb @> :t::jsonb';
            $params['t'] = json_encode([$tag], \JSON_UNESCAPED_UNICODE);
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::META.' FROM resources r WHERE '.implode(' AND ', $where).' ORDER BY r.updated_at DESC, r.slug LIMIT '.($limit + $offset),
            $params,
        );
        $out = array_map(fn (array $r): array => $this->mine($r), $rows);

        if ($includeBase) {
            $base = [];
            foreach ($this->base->all() as $entry) {
                $s = BaseResources::summary($entry);
                if ($kind !== null && $s['kind'] !== $kind) {
                    continue;
                }
                if ($tag !== null && $tag !== '' && !\in_array($tag, $s['tags'], true)) {
                    continue;
                }
                if ($query !== '' && !self::matches($query, [$s['name'], $s['slug'], $s['description'], ...$s['tags']])) {
                    continue;
                }
                $base[] = $s;
            }
            usort($base, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
            $out = [...$out, ...$base];
        }

        return \array_slice($out, $offset, $limit);
    }

    /**
     * Fiche + contenu. Renvoie `data` (octets) ; la fiche est dans `meta`.
     *
     * @return array{meta: array<string, mixed>, data: string}
     */
    public function read(User $user, string $ref, ?int $version = null): array
    {
        [$scope, $id] = self::parseRef($ref);
        if ($scope === 'base') {
            $entry = $this->base->get($id);
            if ($entry === null || ($version !== null && $version !== (int) ($entry['version'] ?? 1))) {
                throw new NotFoundHttpException('Ressource de base introuvable.');
            }

            return ['meta' => BaseResources::summary($entry), 'data' => (string) $entry['source']];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT '.self::META.', r.data FROM resources r WHERE r.user_id = :u AND r.slug = :s',
            ['u' => $user->getId()->toRfc4122(), 's' => $id],
        );
        if ($row === false) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        $meta = $this->mine($row);
        if ($version !== null && $version !== (int) $row['version']) {
            $old = $this->connection->fetchAssociative(
                'SELECT version, content_type, size_bytes, sha256, prompt, data, created_at FROM resource_versions WHERE resource_id = :r AND version = :v',
                ['r' => $row['id'], 'v' => $version],
            );
            if ($old === false) {
                throw new NotFoundHttpException('Version introuvable.');
            }

            return [
                'meta' => [...$meta, 'version' => (int) $old['version'], 'contentType' => (string) $old['content_type'], 'sizeBytes' => (int) $old['size_bytes'], 'sha256' => (string) $old['sha256'], 'prompt' => (string) $old['prompt'], 'current' => false],
                'data' => self::bytes($old['data']),
            ];
        }

        return ['meta' => $meta, 'data' => self::bytes($row['data'])];
    }

    /** @return list<array{version: int, current: bool, sizeBytes: int, sha256: string, prompt: string, createdAt: string}> */
    public function versions(User $user, string $slug): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, version, size_bytes, sha256, prompt, updated_at FROM resources WHERE user_id = :u AND slug = :s',
            ['u' => $user->getId()->toRfc4122(), 's' => $slug],
        );
        if ($row === false) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        $out = [['version' => (int) $row['version'], 'current' => true, 'sizeBytes' => (int) $row['size_bytes'], 'sha256' => (string) $row['sha256'], 'prompt' => (string) $row['prompt'], 'createdAt' => self::date($row['updated_at'])]];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT version, size_bytes, sha256, prompt, created_at FROM resource_versions WHERE resource_id = :r ORDER BY version DESC',
            ['r' => $row['id']],
        ) as $v) {
            $out[] = ['version' => (int) $v['version'], 'current' => false, 'sizeBytes' => (int) $v['size_bytes'], 'sha256' => (string) $v['sha256'], 'prompt' => (string) $v['prompt'], 'createdAt' => self::date($v['created_at'])];
        }

        return $out;
    }

    /**
     * Crée la ressource ou, si le `slug` existe, enregistre une nouvelle version quand le contenu change
     * (les métadonnées seules sont mises à jour sans nouvelle version).
     *
     * @param array{kind: string, name: string, bytes: string, slug?: ?string, filename?: ?string, description?: ?string, tags?: mixed, prompt?: ?string, from?: ?string} $in
     *
     * @return array<string, mixed>
     */
    public function save(User $user, array $in, ?string $ip = null): array
    {
        $kind = (string) $in['kind'];
        $name = trim((string) $in['name']);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BadRequestHttpException('name requis (120 caractères max).');
        }
        $slug = trim((string) ($in['slug'] ?? ''));
        $slug = $slug !== '' ? $slug : ResourceContent::slugify($name);
        if (!preg_match(ResourceContent::SLUG, $slug)) {
            throw new BadRequestHttpException('slug invalide (a-z, 0-9, - ; 64 max) : le préciser explicitement.');
        }
        $bytes = (string) $in['bytes'];
        $contentType = ResourceContent::check($kind, (string) ($in['filename'] ?? ''), $bytes);
        $description = mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000);
        $prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 8000);
        $tags = ResourceContent::normalizeTags($in['tags'] ?? []);
        $sha = hash('sha256', $bytes);
        $size = \strlen($bytes);
        $from = null;
        if (\is_string($in['from'] ?? null) && $in['from'] !== '') {
            [$scope, $fromId] = self::parseRef($in['from']);
            if ($scope === 'base') {
                $from = $this->base->get($fromId) ?? throw new BadRequestHttpException('Ressource de base inconnue : '.$in['from']);
            }
        }

        $userId = $user->getId()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $result = $this->connection->transactional(function () use ($user, $userId, $kind, $slug, $name, $description, $prompt, $tags, $contentType, $bytes, $sha, $size, $from, $now): array {
            $existing = $this->connection->fetchAssociative(
                'SELECT id, kind, version, sha256, size_bytes, content_type, prompt FROM resources WHERE user_id = :u AND slug = :s FOR UPDATE',
                ['u' => $userId, 's' => $slug],
            );
            $json = json_encode($tags, \JSON_UNESCAPED_UNICODE);
            $baseParams = $from === null ? [null, null, null] : [(string) $from['id'], (int) ($from['version'] ?? 1), (string) ($from['sha256'] ?? hash('sha256', (string) $from['source']))];

            if ($existing === false) {
                $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM resources WHERE user_id = :u', ['u' => $userId]);
                if ($count >= self::MAX_RESOURCES) {
                    throw new BadRequestHttpException(sprintf('Trop de ressources (%d max).', self::MAX_RESOURCES));
                }
                $this->quota->assertCanGrow($user, $size + 256);
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO resources (id, user_id, kind, slug, name, description, tags, prompt, content_type, version, size_bytes, sha256, data, base_id, base_version, base_sha256, created_at, updated_at)
                        VALUES (:id, :u, :k, :s, :n, :d, :t, :p, :c, 1, :size, :h, :data, :bi, :bv, :bh, :now, :now)
                    SQL,
                    ['id' => Uuid::v7()->toRfc4122(), 'u' => $userId, 'k' => $kind, 's' => $slug, 'n' => $name, 'd' => $description, 't' => $json, 'p' => $prompt, 'c' => $contentType, 'size' => $size, 'h' => $sha, 'data' => self::stream($bytes), 'bi' => $baseParams[0], 'bv' => $baseParams[1], 'bh' => $baseParams[2], 'now' => $now],
                    ['data' => ParameterType::LARGE_OBJECT],
                );

                return ['created' => true, 'changed' => true];
            }

            if ((string) $existing['kind'] !== $kind) {
                throw new BadRequestHttpException(sprintf('« %s » existe avec un autre type (%s).', $slug, $existing['kind']));
            }
            $contentChanged = (string) $existing['sha256'] !== $sha;
            if ($contentChanged) {
                $this->quota->assertCanGrow($user, $size + 256);
                // La version courante devient une version précédente, puis on remplace le contenu.
                $this->connection->executeStatement(
                    'INSERT INTO resource_versions (resource_id, version, content_type, size_bytes, sha256, prompt, data, created_at) SELECT id, version, content_type, size_bytes, sha256, prompt, data, updated_at FROM resources WHERE id = :r',
                    ['r' => $existing['id']],
                );
                $this->connection->executeStatement(
                    'DELETE FROM resource_versions WHERE resource_id = :r AND version <= :keep',
                    ['r' => $existing['id'], 'keep' => (int) $existing['version'] - self::MAX_VERSIONS],
                );
            }
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE resources SET name = :n, description = :d, tags = :t,
                        prompt = CASE WHEN :changed THEN :p ELSE prompt END,
                        content_type = :c, version = version + CASE WHEN :changed THEN 1 ELSE 0 END,
                        size_bytes = :size, sha256 = :h, data = CASE WHEN :changed THEN :data ELSE data END,
                        base_id = COALESCE(:bi, base_id), base_version = COALESCE(:bv, base_version), base_sha256 = COALESCE(:bh, base_sha256),
                        updated_at = :now
                    WHERE id = :r
                SQL,
                ['n' => $name, 'd' => $description, 't' => $json, 'changed' => $contentChanged, 'p' => $prompt, 'c' => $contentType, 'size' => $size, 'h' => $sha, 'data' => self::stream($bytes), 'bi' => $baseParams[0], 'bv' => $baseParams[1], 'bh' => $baseParams[2], 'now' => $now, 'r' => $existing['id']],
                ['data' => ParameterType::LARGE_OBJECT, 'changed' => ParameterType::BOOLEAN],
            );

            return ['created' => false, 'changed' => $contentChanged];
        });

        $this->audit->log($user, AuditLog::CATEGORY_ARTIFACT, 'resource.save', ['slug' => $slug, 'kind' => $kind, 'sizeBytes' => $size, 'created' => $result['created']], $ip);

        return $this->read($user, 'mine:'.$slug)['meta'] + $result;
    }

    public function delete(User $user, string $slug, ?string $ip = null): void
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM resources WHERE user_id = :u AND slug = :s',
            ['u' => $user->getId()->toRfc4122(), 's' => $slug],
        );
        if ($deleted === 0) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        $this->audit->log($user, AuditLog::CATEGORY_ARTIFACT, 'resource.delete', ['slug' => $slug], $ip);
    }

    /**
     * @return array{0: 'mine'|'base', 1: string}
     */
    public static function parseRef(string $ref): array
    {
        $ref = trim($ref);
        if (str_starts_with($ref, 'base:')) {
            $id = substr($ref, 5);
            if (!preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $id)) {
                throw new BadRequestHttpException('Référence de base invalide (base:<addon>/<id>, ex. base:shader/plasma).');
            }

            return ['base', $id];
        }
        $slug = str_starts_with($ref, 'mine:') ? substr($ref, 5) : $ref;
        if (!preg_match(ResourceContent::SLUG, $slug)) {
            throw new BadRequestHttpException('Référence invalide (mine:<slug> ou base:<addon>/<id>).');
        }

        return ['mine', $slug];
    }

    public static function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    /** @param list<string> $haystacks */
    private static function matches(string $query, array $haystacks): bool
    {
        foreach (preg_split('/\s+/u', mb_strtolower($query), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $found = false;
            foreach ($haystacks as $h) {
                if (str_contains(mb_strtolower($h), $word)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function mine(array $r): array
    {
        $tags = \is_string($r['tags']) ? json_decode($r['tags'], true) : $r['tags'];
        $baseId = $r['base_id'] !== null ? (string) $r['base_id'] : null;
        $baseVersion = $r['base_version'] !== null ? (int) $r['base_version'] : null;
        $latest = $baseId !== null ? $this->base->get($baseId) : null;
        $latestVersion = $latest !== null ? (int) ($latest['version'] ?? 1) : null;

        return [
            'ref' => 'mine:'.$r['slug'],
            'source' => 'mine',
            'kind' => (string) $r['kind'],
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
            'description' => (string) $r['description'],
            'tags' => \is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
            'prompt' => (string) $r['prompt'],
            'contentType' => (string) $r['content_type'],
            'version' => (int) $r['version'],
            'sizeBytes' => (int) $r['size_bytes'],
            'sha256' => (string) $r['sha256'],
            'base' => $baseId === null ? null : ['ref' => 'base:'.$baseId, 'version' => $baseVersion, 'latest' => $latestVersion],
            'updateAvailable' => $latestVersion !== null && $baseVersion !== null && $latestVersion > $baseVersion,
            'createdAt' => self::date($r['created_at']),
            'updatedAt' => self::date($r['updated_at']),
        ];
    }

    private static function date(mixed $v): string
    {
        return (new \DateTimeImmutable((string) $v))->format(\DATE_ATOM);
    }

    private static function bytes(mixed $data): string
    {
        if (\is_resource($data)) {
            $data = stream_get_contents($data);
        }

        return \is_string($data) ? $data : '';
    }

    /** @return resource */
    private static function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
