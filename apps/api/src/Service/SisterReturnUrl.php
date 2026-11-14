<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Adresse de retour vers une app sœur (Artefacts, Ceintures…) portée par le lien de
 * confirmation d'email. Même règle que le front (`isAllowedSisterReturnUrl`) : même site
 * que l'app Tadaaa, ou localhost.
 */
final class SisterReturnUrl
{
    private const MAX_LENGTH = 500;

    public static function sanitize(?string $returnTo, string $appPublicUrl): ?string
    {
        $value = trim((string) $returnTo);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return $value;
        }
        $appHost = strtolower((string) (parse_url($appPublicUrl, PHP_URL_HOST) ?? ''));
        $labels = explode('.', $appHost);
        if ($appHost === '' || count($labels) < 2) {
            return null;
        }
        $parent = implode('.', array_slice($labels, -2));
        if ($scheme !== 'https') {
            return null;
        }
        if ($host === $appHost || $host === $parent || str_ends_with($host, '.'.$parent)) {
            return $value;
        }

        return null;
    }
}
