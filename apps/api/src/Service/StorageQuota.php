<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StorageQuota
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(int:DEFAULT_STORAGE_QUOTA_BYTES)%')]
        private readonly int $defaultQuotaBytes,
    ) {
    }

    public function effectiveQuotaBytes(User $user): ?int
    {
        // Les administrateurs ne sont jamais limités, même avec un quota personnalisé posé sur le compte.
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        $override = $user->getStorageQuotaBytes();
        if ($override === 0) {
            return null; // unlimited
        }
        if ($override !== null) {
            return $override;
        }

        return max(0, $this->defaultQuotaBytes);
    }

    public function usedBytes(User $user): int
    {
        $sqlTodos = <<<'SQL'
            SELECT COALESCE(SUM(
                COALESCE(octet_length(t.text), 0)
                + COALESCE(octet_length(t.description), 0)
                + COALESCE(octet_length(t.tag_ids::text), 0)
                + 128
            ), 0)::bigint AS bytes
            FROM todos t
            INNER JOIN datasets d ON d.id = t.dataset_id
            WHERE d.owner_id = :owner
              AND t.deleted_at IS NULL
        SQL;

        $sqlArtifacts = <<<'SQL'
            SELECT COALESCE(SUM(
                COALESCE(octet_length(v.document::text), 0)
                + COALESCE(octet_length(r.data::text), 0)
                + 256
            ), 0)::bigint AS bytes
            FROM artifacts a
            INNER JOIN datasets d ON d.id = a.dataset_id
            LEFT JOIN artifact_versions v ON v.artifact_id = a.id
            LEFT JOIN artifact_collections c ON c.artifact_id = a.id
            LEFT JOIN artifact_records r ON r.collection_id = c.id
            WHERE d.owner_id = :owner
        SQL;

        $sqlAssets = <<<'SQL'
            SELECT COALESCE(SUM(s.size_bytes + 256), 0)::bigint AS bytes
            FROM artifact_assets s
            INNER JOIN artifacts a ON a.id = s.artifact_id
            INNER JOIN datasets d ON d.id = a.dataset_id
            WHERE d.owner_id = :owner
        SQL;

        $sqlRunFiles = <<<'SQL'
            SELECT COALESCE(SUM(f.size_bytes + 256), 0)::bigint AS bytes
            FROM artifact_run_files f
            INNER JOIN artifact_runs r ON r.id = f.run_id
            INNER JOIN artifacts a ON a.id = r.artifact_id
            INNER JOIN datasets d ON d.id = a.dataset_id
            WHERE d.owner_id = :owner
        SQL;

        $sqlResources = <<<'SQL'
            SELECT COALESCE(
                (SELECT SUM(r.size_bytes + 256) FROM resources r WHERE r.user_id = :owner), 0
            ) + COALESCE(
                (SELECT SUM(v.size_bytes + 128) FROM resource_versions v INNER JOIN resources r ON r.id = v.resource_id WHERE r.user_id = :owner), 0
            ) AS bytes
        SQL;

        $owner = $user->getId()->toRfc4122();
        $todos = (int) $this->connection->fetchOne($sqlTodos, ['owner' => $owner]);
        try {
            $artifacts = (int) $this->connection->fetchOne($sqlArtifacts, ['owner' => $owner]);
        } catch (\Throwable) {
            $artifacts = 0;
        }
        try {
            $assets = (int) $this->connection->fetchOne($sqlAssets, ['owner' => $owner]);
        } catch (\Throwable) {
            $assets = 0;
        }

        try {
            $resources = (int) $this->connection->fetchOne($sqlResources, ['owner' => $owner]);
        } catch (\Throwable) {
            $resources = 0;
        }

        try {
            $runFiles = (int) $this->connection->fetchOne($sqlRunFiles, ['owner' => $owner]);
        } catch (\Throwable) {
            $runFiles = 0;
        }

        return $todos + $artifacts + $assets + $resources + $runFiles;
    }

    /**
     * @return array{usedBytes: int, quotaBytes: ?int, unlimited: bool, ratio: ?float}
     */
    public function report(User $user): array
    {
        $used = $this->usedBytes($user);
        $quota = $this->effectiveQuotaBytes($user);
        $unlimited = $quota === null;

        return [
            'usedBytes' => $used,
            'quotaBytes' => $quota,
            'unlimited' => $unlimited,
            'ratio' => $unlimited || $quota === 0 ? null : min(1, $used / max(1, $quota)),
        ];
    }

    public function assertCanGrow(User $owner, int $deltaBytes = 0): void
    {
        $quota = $this->effectiveQuotaBytes($owner);
        if ($quota === null) {
            return;
        }
        $used = $this->usedBytes($owner);
        if ($used + max(0, $deltaBytes) > $quota) {
            throw new HttpException(
                413,
                sprintf(
                    'Quota de stockage atteint (%s / %s). Supprimez des tâches ou demandez une augmentation.',
                    $this->formatBytes($used),
                    $this->formatBytes($quota),
                ),
            );
        }
    }

    private function formatBytes(int $n): string
    {
        if ($n < 1024) {
            return $n.' o';
        }
        if ($n < 1024 * 1024) {
            return round($n / 1024, 1).' Ko';
        }

        return round($n / (1024 * 1024), 1).' Mo';
    }
}
