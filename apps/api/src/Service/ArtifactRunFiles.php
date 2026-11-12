<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Fichiers de sortie d'un run (io.output.files : dessin, photo, enregistrement…), envoyés par le
 * viewer avant la sortie. Mêmes formats et contrôles de contenu que les assets d'artefact
 * ({@see ArtifactAssets::detect()}), comptés dans le quota du propriétaire, supprimés avec le run.
 */
final class ArtifactRunFiles
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StorageQuota $quota,
    ) {
    }

    /** @return array{name: string, contentType: string, sizeBytes: int, sha256: string} */
    public function put(ArtifactRun $run, string $name, string $bytes): array
    {
        $mime = ArtifactAssets::detect($name, $bytes);
        $runId = $run->getId()->toRfc4122();
        $existing = $this->connection->fetchOne('SELECT size_bytes FROM artifact_run_files WHERE run_id = :r AND name = :n', ['r' => $runId, 'n' => $name]);
        $size = \strlen($bytes);
        $this->quota->assertCanGrow($run->getArtifact()->getDataset()->getOwner(), $size - ($existing === false ? 0 : (int) $existing) + 256);

        $sha = hash('sha256', $bytes);
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO artifact_run_files (id, run_id, name, content_type, size_bytes, sha256, data, created_at)
                VALUES (:id, :r, :n, :t, :s, :h, :d, :now)
                ON CONFLICT (run_id, name) DO UPDATE
                SET content_type = EXCLUDED.content_type, size_bytes = EXCLUDED.size_bytes,
                    sha256 = EXCLUDED.sha256, data = EXCLUDED.data, created_at = EXCLUDED.created_at
            SQL,
            ['id' => Uuid::v7()->toRfc4122(), 'r' => $runId, 'n' => $name, 't' => $mime, 's' => $size, 'h' => $sha, 'd' => $stream, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ['d' => ParameterType::LARGE_OBJECT],
        );
        fclose($stream);

        return ['name' => $name, 'contentType' => $mime, 'sizeBytes' => $size, 'sha256' => $sha];
    }

    /** @return list<array{name: string, contentType: string, sizeBytes: int, sha256: string}> */
    public function list(ArtifactRun $run): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT name, content_type, size_bytes, sha256 FROM artifact_run_files WHERE run_id = :r ORDER BY name',
            ['r' => $run->getId()->toRfc4122()],
        );

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'contentType' => (string) $r['content_type'],
            'sizeBytes' => (int) $r['size_bytes'],
            'sha256' => (string) $r['sha256'],
        ], $rows);
    }

    /** @return array{contentType: string, sizeBytes: int, sha256: string, data: string}|null */
    public function read(ArtifactRun $run, string $name): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT content_type, size_bytes, sha256, data FROM artifact_run_files WHERE run_id = :r AND name = :n',
            ['r' => $run->getId()->toRfc4122(), 'n' => $name],
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
