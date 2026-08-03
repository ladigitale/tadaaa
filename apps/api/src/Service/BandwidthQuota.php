<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UsageDailyRepository;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class BandwidthQuota
{
    public const TRANSFER_BYTES = 'transfer_bytes';

    public function __construct(
        private readonly UsageMeter $usage,
        private readonly UsageDailyRepository $usageDaily,
        private readonly UserRepository $users,
        #[Autowire('%env(int:GLOBAL_MONTHLY_TRANSFER_BYTES)%')]
        private readonly int $globalMonthlyBytes,
        #[Autowire('%env(int:FLOOR_PER_USER_MONTH_BYTES)%')]
        private readonly int $floorMonth,
        #[Autowire('%env(int:CEIL_PER_USER_MONTH_BYTES)%')]
        private readonly int $ceilMonth,
        #[Autowire('%env(int:FLOOR_PER_USER_DAY_BYTES)%')]
        private readonly int $floorDay,
        #[Autowire('%env(int:CEIL_PER_USER_DAY_BYTES)%')]
        private readonly int $ceilDay,
    ) {
    }

    public function effectiveMonthQuotaBytes(User $user): ?int
    {
        $override = $user->getBandwidthQuotaMonthBytes();
        if ($override === 0) {
            return null;
        }
        if ($override !== null) {
            return $override;
        }

        $active = max(1, $this->countActiveUsers());
        $share = intdiv($this->globalMonthlyBytes, $active);

        return max($this->floorMonth, min($this->ceilMonth, $share));
    }

    public function effectiveDayQuotaBytes(User $user): ?int
    {
        $month = $this->effectiveMonthQuotaBytes($user);
        if ($month === null) {
            return null;
        }
        $dayShare = intdiv($month, 30);

        return max($this->floorDay, min($this->ceilDay, $dayShare));
    }

    public function usedDayBytes(User $user): int
    {
        $today = new \DateTimeImmutable('today');

        return $this->sumTransfer($user, $today, $today);
    }

    public function usedMonthBytes(User $user): int
    {
        $from = new \DateTimeImmutable('first day of this month');
        $to = new \DateTimeImmutable('today');

        return $this->sumTransfer($user, $from, $to);
    }

    /**
     * @return array{
     *   dayUsedBytes: int,
     *   dayQuotaBytes: ?int,
     *   monthUsedBytes: int,
     *   monthQuotaBytes: ?int,
     *   unlimited: bool
     * }
     */
    public function report(User $user): array
    {
        $monthQuota = $this->effectiveMonthQuotaBytes($user);
        $dayQuota = $this->effectiveDayQuotaBytes($user);

        return [
            'dayUsedBytes' => $this->usedDayBytes($user),
            'dayQuotaBytes' => $dayQuota,
            'monthUsedBytes' => $this->usedMonthBytes($user),
            'monthQuotaBytes' => $monthQuota,
            'unlimited' => $monthQuota === null,
        ];
    }

    public function assertCanTransfer(User $user, int $approxBytes = 0): void
    {
        $dayQuota = $this->effectiveDayQuotaBytes($user);
        $monthQuota = $this->effectiveMonthQuotaBytes($user);
        if ($dayQuota === null || $monthQuota === null) {
            return;
        }

        $dayUsed = $this->usedDayBytes($user);
        if ($dayUsed + $approxBytes > $dayQuota) {
            throw new TooManyRequestsHttpException(
                null,
                sprintf(
                    'Quota de bande passante journalier atteint (%s / %s). Réessayez demain.',
                    $this->formatBytes($dayUsed),
                    $this->formatBytes($dayQuota),
                ),
            );
        }

        $monthUsed = $this->usedMonthBytes($user);
        if ($monthUsed + $approxBytes > $monthQuota) {
            throw new TooManyRequestsHttpException(
                null,
                sprintf(
                    'Quota de bande passante mensuel atteint (%s / %s). Réessayez le mois prochain.',
                    $this->formatBytes($monthUsed),
                    $this->formatBytes($monthQuota),
                ),
            );
        }
    }

    public function recordTransfer(User $owner, ?Dataset $dataset, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }
        $this->usage->increment($owner, $dataset, self::TRANSFER_BYTES, $bytes);
    }

    private function sumTransfer(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $rows = $this->usageDaily->findRange($user, $from, $to);
        $total = 0;
        foreach ($rows as $row) {
            $counters = $row->getCounters();
            $total += (int) ($counters[self::TRANSFER_BYTES] ?? 0);
            $total += (int) ($counters[UsageMeter::WEBHOOK_BYTES] ?? 0);
        }

        return $total;
    }

    private function countActiveUsers(): int
    {
        return (int) $this->users->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.status = :status')
            ->setParameter('status', UserStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
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
