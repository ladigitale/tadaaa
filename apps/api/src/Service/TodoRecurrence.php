<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Todo;
use App\Util\TodoDate;

/**
 * Todo recurrence: checked until the next calendar unit starts, then unchecked.
 */
final class TodoRecurrence
{
    public const NONE = 'none';
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';

    /** @var list<string> */
    public const VALUES = [self::NONE, self::DAILY, self::WEEKLY, self::MONTHLY];

    public static function normalize(?string $value): string
    {
        if ($value !== null && \in_array($value, self::VALUES, true)) {
            return $value;
        }

        return self::NONE;
    }

    public static function isActive(string $recurrence): bool
    {
        return self::normalize($recurrence) !== self::NONE;
    }

    /**
     * @return array{startAt: ?string, endAt: ?string}
     */
    public static function nextDates(
        ?\DateTimeImmutable $startAt,
        ?\DateTimeImmutable $endAt,
        string $recurrence,
    ): array {
        $recurrence = self::normalize($recurrence);
        if ($recurrence === self::NONE) {
            return ['startAt' => null, 'endAt' => null];
        }

        return [
            'startAt' => $startAt !== null ? TodoDate::format(self::shift($startAt, $recurrence)) : null,
            'endAt' => $endAt !== null ? TodoDate::format(self::shift($endAt, $recurrence)) : null,
        ];
    }

    /**
     * Uncheck + shift dates when the next recurrence unit has begun (UTC calendar).
     * Uses fieldVersions.done as completion timestamp.
     *
     * @return bool true when the todo was mutated
     */
    public static function maybeReset(Todo $todo, ?\DateTimeImmutable $now = null): bool
    {
        $recurrence = self::normalize($todo->getRecurrence());
        if (!$todo->isDone() || $recurrence === self::NONE) {
            return false;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $completedAt = self::completedAt($todo);
        if ($completedAt === null) {
            return false;
        }

        $resetAt = self::startOfNextUnit($completedAt, $recurrence);
        if ($now < $resetAt) {
            return false;
        }

        $units = max(1, self::unitsElapsed($completedAt, $now, $recurrence));
        $startAt = $todo->getStartAt();
        $endAt = $todo->getEndAt();
        for ($i = 0; $i < $units; ++$i) {
            if ($startAt !== null) {
                $startAt = self::shift($startAt, $recurrence);
            }
            if ($endAt !== null) {
                $endAt = self::shift($endAt, $recurrence);
            }
        }

        $stamp = $now->format(\DateTimeInterface::ATOM);
        $versions = $todo->getFieldVersions();
        $todo->setDone(false);
        $versions['done'] = $stamp;
        if ($todo->getStartAt() !== null) {
            $todo->setStartAt($startAt);
            $versions['startAt'] = $stamp;
        }
        if ($todo->getEndAt() !== null) {
            $todo->setEndAt($endAt);
            $versions['endAt'] = $stamp;
        }
        $todo->setFieldVersions($versions);

        return true;
    }

    private static function completedAt(Todo $todo): ?\DateTimeImmutable
    {
        $versions = $todo->getFieldVersions();
        $raw = $versions['done'] ?? $todo->getCreatedAt()->format(\DateTimeInterface::ATOM);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function calendarDay(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    private static function startOfNextUnit(\DateTimeImmutable $completedAt, string $recurrence): \DateTimeImmutable
    {
        $day = self::calendarDay($completedAt);
        $utc = new \DateTimeZone('UTC');

        return match ($recurrence) {
            self::DAILY => (new \DateTimeImmutable($day.' 00:00:00', $utc))->modify('+1 day'),
            self::WEEKLY => self::nextMondayMidnight($day),
            self::MONTHLY => self::firstOfNextMonth($day),
            default => (new \DateTimeImmutable($day.' 00:00:00', $utc))->modify('+1 day'),
        };
    }

    private static function nextMondayMidnight(string $day): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable($day.' 00:00:00', $utc);
        $dow = (int) $date->format('N'); // 1=Mon … 7=Sun
        $daysUntilNextMonday = 8 - $dow; // Mon→7, Tue→6, … Sun→1

        return $date->modify(sprintf('+%d days', $daysUntilNextMonday));
    }

    private static function firstOfNextMonth(string $day): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable($day.' 00:00:00', $utc);

        return $date->modify('first day of next month')->setTime(0, 0, 0);
    }

    private static function unitsElapsed(
        \DateTimeImmutable $completedAt,
        \DateTimeImmutable $now,
        string $recurrence,
    ): int {
        $from = self::calendarDay($completedAt);
        $to = self::calendarDay($now);
        if ($to <= $from) {
            return 0;
        }

        $fromDate = new \DateTimeImmutable($from.' 00:00:00', new \DateTimeZone('UTC'));
        $toDate = new \DateTimeImmutable($to.' 00:00:00', new \DateTimeZone('UTC'));

        return match ($recurrence) {
            self::DAILY => (int) $fromDate->diff($toDate)->days,
            self::WEEKLY => self::weeksBetween($from, $to),
            self::MONTHLY => self::monthsBetween($from, $to),
            default => 0,
        };
    }

    private static function weeksBetween(string $fromDay, string $toDay): int
    {
        $fromMonday = self::mondayOf($fromDay);
        $toMonday = self::mondayOf($toDay);
        $days = (int) $fromMonday->diff($toMonday)->days;

        return max(0, intdiv($days, 7));
    }

    private static function mondayOf(string $day): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable($day.' 00:00:00', $utc);
        $dow = (int) $date->format('N');

        return $date->modify(sprintf('-%d days', $dow - 1));
    }

    private static function monthsBetween(string $fromDay, string $toDay): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $fromDay));
        [$ty, $tm] = array_map('intval', explode('-', $toDay));

        return max(0, ($ty - $fy) * 12 + ($tm - $fm));
    }

    private static function shift(\DateTimeImmutable $date, string $recurrence): \DateTimeImmutable
    {
        return match ($recurrence) {
            self::DAILY => $date->modify('+1 day'),
            self::WEEKLY => $date->modify('+7 days'),
            self::MONTHLY => self::addMonthClamped($date),
            default => $date,
        };
    }

    private static function addMonthClamped(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = (int) $date->format('j');
        $firstOfNext = $date->modify('first day of next month');
        $lastDay = (int) $firstOfNext->format('t');
        $targetDay = min($day, $lastDay);

        return $firstOfNext->setDate(
            (int) $firstOfNext->format('Y'),
            (int) $firstOfNext->format('n'),
            $targetDay,
        );
    }
}
