<?php

declare(strict_types=1);

namespace App\Service;

final class FieldVersionMerger
{
    /**
     * @param array<string, string> $serverVersions
     * @param array<string, string> $clientVersions
     * @param array<string, mixed>  $clientFields
     *
     * @return array{versions: array<string, string>, applied: list<string>}
     */
    public static function merge(
        array $serverVersions,
        array $clientVersions,
        array $clientFields,
        callable $applyField,
    ): array {
        $versions = $serverVersions;
        $applied = [];

        foreach ($clientFields as $field => $value) {
            $clientAt = $clientVersions[$field] ?? null;
            if ($clientAt === null) {
                continue;
            }

            $serverAt = $serverVersions[$field] ?? null;
            $clientTime = new \DateTimeImmutable($clientAt);
            $serverTime = $serverAt !== null ? new \DateTimeImmutable($serverAt) : null;

            if ($serverTime === null || $clientTime >= $serverTime) {
                $applyField($field, $value);
                $versions[$field] = $clientAt;
                $applied[] = $field;
            }
        }

        return ['versions' => $versions, 'applied' => $applied];
    }

    /**
     * @param array<string, string> $versions
     */
    public static function maxTimestamp(array $versions): ?\DateTimeImmutable
    {
        $max = null;
        foreach ($versions as $value) {
            $time = new \DateTimeImmutable($value);
            if ($max === null || $time > $max) {
                $max = $time;
            }
        }

        return $max;
    }
}
