<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validates Web Push endpoints to prevent SSRF (arbitrary https:// targets).
 */
final class PushEndpointValidator
{
    private const MAX_ENDPOINT_LENGTH = 2048;

    /**
     * Host suffixes of known browser push services (case-insensitive match on host).
     *
     * @var list<string>
     */
    private const ALLOWED_HOST_SUFFIXES = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'wns.windows.com',
        'push.apple.com',
        'web.push.apple.com',
    ];

    public function assertValid(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new BadRequestHttpException('endpoint must be an https URL.');
        }
        if (strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            throw new BadRequestHttpException('endpoint is too long.');
        }

        $parts = parse_url($endpoint);
        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || !is_string($parts['host'])
            || $parts['host'] === ''
        ) {
            throw new BadRequestHttpException('endpoint must be a valid https URL.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BadRequestHttpException('endpoint must not contain userinfo.');
        }

        $host = strtolower($parts['host']);
        // Reject raw IPs (v4 / v6 / bracketed).
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false
            || (str_starts_with($host, '[') && str_ends_with($host, ']')
                && filter_var(substr($host, 1, -1), \FILTER_VALIDATE_IP) !== false)
        ) {
            throw new BadRequestHttpException('endpoint host is not an allowed push service.');
        }

        if (!$this->isAllowedHost($host)) {
            throw new BadRequestHttpException('endpoint host is not an allowed push service.');
        }

        return $endpoint;
    }

    private function isAllowedHost(string $host): bool
    {
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
