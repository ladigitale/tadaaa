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
        $sql = <<<'SQL'
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

        $value = $this->connection->fetchOne($sql, [
            'owner' => $user->getId()->toRfc4122(),
        ]);

        return (int) $value;
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
