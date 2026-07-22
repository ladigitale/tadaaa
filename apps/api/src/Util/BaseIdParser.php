<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Uid\Uuid;

final class BaseIdParser
{
    public static function parse(string $value): Uuid
    {
        $trimmed = trim($value);
        if (str_starts_with($trimmed, 'base-')) {
            $trimmed = substr($trimmed, 5);
        }

        return Uuid::fromString($trimmed);
    }

    public static function format(Uuid $baseId): string
    {
        return 'base-'.$baseId->toRfc4122();
    }
}
