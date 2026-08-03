<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\TodoDate;

/**
 * Simple todo recurrence: shift calendar dates for the next occurrence.
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
