<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Feature flag for Web Push (VAPID keys may be absent in local/php-fpm).
 */
final class PushFeature
{
    public function __construct(
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getSubject(): string
    {
        return $this->subject !== '' ? $this->subject : 'mailto:noreply@tadaaa.app';
    }
}
