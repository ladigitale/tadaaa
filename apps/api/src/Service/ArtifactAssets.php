<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Assets binaires d'un artefact (images, sons, modèles 3D, JSON…), stockés en base (`artifact_assets`)
 * et servis par `GET /api/public/artifacts/{slug}/assets/{nom}`. Un document les référence par une
 * chaîne exactement égale à `@asset:<nom>` ({@see ArtifactAssetRefs}), remplacée à la lecture par
 * l'URL publique de l'asset.
 *
 * Le type est déduit de l'extension du nom ET vérifié sur le contenu (signature d'octets) : on ne
 * fait jamais confiance au Content-Type reçu. Les accès passent par DBAL pour ne pas charger le
 * contenu avec les métadonnées.
 */
final class ArtifactAssets
{
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    public const MAX_ASSETS = 200;
    public const NAME = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** @var array<string, string> extension → type MIME servi */
    public const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'webm' => 'video/webm',
        'mp4' => 'video/mp4',
        'glb' => 'model/gltf-binary',
        'json' => 'application/json',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly StorageQuota $quota,
    ) {
    }

    /**
     * Vérifie le nom et le contenu ; retourne le type MIME à servir.
     *
     * @throws BadRequestHttpException
     */
    public static function detect(string $name, string $bytes): string
    {
        if (!preg_match(self::NAME, $name)) {
            throw new BadRequestHttpException('Nom d’asset invalide (a-z, 0-9, . _ - ; 64 caractères max ; commence par une lettre ou un chiffre).');
        }
        $dot = strrpos($name, '.');
        $ext = $dot === false ? '' : substr($name, $dot + 1);
        $mime = self::TYPES[$ext] ?? null;
        if ($mime === null) {
            throw new BadRequestHttpException(sprintf('Extension non autorisée (%s).', implode(', ', array_keys(self::TYPES))));
        }
        $size = \strlen($bytes);
        if ($size === 0) {
            throw new BadRequestHttpException('Asset vide.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new BadRequestHttpException(sprintf('Asset trop volumineux (%d Mo max).', self::MAX_FILE_BYTES / 1024 / 1024));
        }
        if (!self::matchesSignature($ext, $bytes)) {
            throw new BadRequestHttpException(sprintf('Le contenu de « %s » ne correspond pas au format .%s.', $name, $ext));
        }

        return $mime;
    }

    private static function matchesSignature(string $ext, string $b): bool
    {
        return match ($ext) {
            'png' => str_starts_with($b, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($b, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a'),
            'webp' => str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP',
            'wav' => str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WAVE',
            'avif' => substr($b, 4, 4) === 'ftyp' && \in_array(substr($b, 8, 4), ['avif', 'avis'], true),
            'mp4' => substr($b, 4, 4) === 'ftyp',
            'ogg' => str_starts_with($b, 'OggS'),
            'flac' => str_starts_with($b, 'fLaC'),
            'webm' => str_starts_with($b, "\x1A\x45\xDF\xA3"),
            'glb' => str_starts_with($b, 'glTF'),
            'mp3' => str_starts_with($b, 'ID3') || (\strlen($b) > 1 && ord($b[0]) === 0xFF && (ord($b[1]) & 0xE0) === 0xE0),
            'json' => self::isJson($b),
            'svg' => self::isSafeSvg($b),
            default => false,
        };
    }

    private static function isJson(string $b): bool
    {
        json_decode($b);

        return json_last_error() === \JSON_ERROR_NONE;
    }

    /** SVG sans script ni gestionnaire d'événement ni objet externe (il est servi en image, jamais en page). */
    private static function isSafeSvg(string $b): bool
    {
        if (!preg_match('/<svg[\s>]/i', $b) || !mb_check_encoding($b, 'UTF-8')) {
            return false;
        }

        return !preg_match('/<script|<foreignObject|<iframe|<embed|<object|javascript:|<!ENTITY|\son[a-z]+\s*=/i', $b);
    }

    /**
     * Crée ou remplace un asset ; vérifie le quota du propriétaire du jeu de données.
     *
     * @return array{name: string, contentType: string, sizeBytes: int, sha256: string}
     */
    public function put(Artifact $artifact, string $name, string $bytes): array
    {
        $mime = self::detect($name, $bytes);
        $artifactId = $artifact->getId()->toRfc4122();
        $existing = $this->connection->fetchOne(
            'SELECT size_bytes FROM artifact_assets WHERE artifact_id = :a AND name = :n',
            ['a' => $artifactId, 'n' => $name],
        );
        if ($existing === false) {
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM artifact_assets WHERE artifact_id = :a', ['a' => $artifactId]);
            if ($count >= self::MAX_ASSETS) {
                throw new BadRequestHttpException(sprintf('Trop d’assets pour cet artefact (%d max).', self::MAX_ASSETS));
            }
        }
        $size = \strlen($bytes);
        $this->quota->assertCanGrow($artifact->getDataset()->getOwner(), $size - ($existing === false ? 0 : (int) $existing) + 256);

        $sha = hash('sha256', $bytes);
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO artifact_assets (id, artifact_id, name, content_type, size_bytes, sha256, data, created_at, updated_at)
                VALUES (:id, :a, :n, :t, :s, :h, :d, :now, :now)
                ON CONFLICT (artifact_id, name) DO UPDATE
                SET content_type = EXCLUDED.content_type, size_bytes = EXCLUDED.size_bytes,
                    sha256 = EXCLUDED.sha256, data = EXCLUDED.data, updated_at = EXCLUDED.updated_at
            SQL,
            ['id' => Uuid::v7()->toRfc4122(), 'a' => $artifactId, 'n' => $name, 't' => $mime, 's' => $size, 'h' => $sha, 'd' => $stream, 'now' => $now],
            ['d' => ParameterType::LARGE_OBJECT],
        );
        fclose($stream);

        return ['name' => $name, 'contentType' => $mime, 'sizeBytes' => $size, 'sha256' => $sha];
    }

    /** @return list<array{name: string, contentType: string, sizeBytes: int, sha256: string}> */
    public function list(Artifact $artifact): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT name, content_type, size_bytes, sha256 FROM artifact_assets WHERE artifact_id = :a ORDER BY name',
            ['a' => $artifact->getId()->toRfc4122()],
        );

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'contentType' => (string) $r['content_type'],
            'sizeBytes' => (int) $r['size_bytes'],
            'sha256' => (string) $r['sha256'],
        ], $rows);
    }

    /** @return array<string, string> nom → sha256, pour résoudre les références `@asset:` */
    public function hashes(Artifact $artifact): array
    {
        $out = [];
        foreach ($this->list($artifact) as $asset) {
            $out[$asset['name']] = $asset['sha256'];
        }

        return $out;
    }

    public function delete(Artifact $artifact, string $name): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM artifact_assets WHERE artifact_id = :a AND name = :n',
            ['a' => $artifact->getId()->toRfc4122(), 'n' => $name],
        ) > 0;
    }

    /** @return array{contentType: string, sizeBytes: int, sha256: string, data: string}|null */
    public function read(Artifact $artifact, string $name): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT content_type, size_bytes, sha256, data FROM artifact_assets WHERE artifact_id = :a AND name = :n',
            ['a' => $artifact->getId()->toRfc4122(), 'n' => $name],
        );
        if ($row === false) {
            return null;
        }
        $data = $row['data'];
        if (\is_resource($data)) {
            $data = stream_get_contents($data);
        }

        return [
            'contentType' => (string) $row['content_type'],
            'sizeBytes' => (int) $row['size_bytes'],
            'sha256' => (string) $row['sha256'],
            'data' => \is_string($data) ? $data : '',
        ];
    }
}
