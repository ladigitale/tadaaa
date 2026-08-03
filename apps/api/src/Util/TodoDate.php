<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Optional todo calendar dates: YYYY-MM-DD (all-day, stored 00:00:00 UTC)
 * or ISO UTC datetime (…Z / offset).
 */
final class TodoDate
{
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed, new \DateTimeZone('UTC'));

            return $date === false ? null : $date;
        }

        // Bare local-looking datetime without TZ → treat as UTC (clients send Z).
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $trimmed) === 1) {
            $normalized = str_replace(' ', 'T', $trimmed);
            if (substr_count($normalized, ':') === 1) {
                $normalized .= ':00';
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $normalized, new \DateTimeZone('UTC'));

            return $date === false ? null : $date;
        }

        try {
            $date = new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function format(?\DateTimeImmutable $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $utc = $value->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->format('H:i:s') === '00:00:00') {
            return $utc->format('Y-m-d');
        }

        return $utc->format('Y-m-d\TH:i:s\Z');
    }
}
