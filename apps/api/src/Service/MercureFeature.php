<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Feature flag for Mercure (hub may be absent on php-fpm/Apache).
 */
final class MercureFeature
{
    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
