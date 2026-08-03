<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts secrets at rest (refresh/access tokens) using sodium secretbox.
 */
final class SecretBox
{
    private readonly string $key;

    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        string $appSecret,
    ) {
        $this->key = hash('sha256', 'tadaaa.gcal.'.$appSecret, true);
    }

    public function seal(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$cipher);
    }

    public function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid sealed payload.');
        }
        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt payload.');
        }

        return $plain;
    }
}
