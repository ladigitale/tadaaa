<?php

declare(strict_types=1);

namespace App\Agent\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement symétrique (libsodium secretbox) des clés API des utilisateurs.
 *
 * Clé : `AGENT_SETTINGS_KEY` (32 octets en base64, `php -r 'echo base64_encode(random_bytes(32));'`).
 * À défaut, une clé dérivée d'`APP_SECRET` (séparation de domaine) : pratique en dev,
 * mais une rotation d'`APP_SECRET` rendrait alors les clés enregistrées illisibles.
 */
final class SecretBox
{
    private const PREFIX = 'v1:';
    private readonly string $key;

    public function __construct(
        #[Autowire('%env(AGENT_SETTINGS_KEY)%')]
        string $configuredKey,
        #[Autowire('%kernel.secret%')]
        string $appSecret,
    ) {
        if ($configuredKey !== '') {
            $raw = base64_decode($configuredKey, true);
            if ($raw === false || \strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \InvalidArgumentException('AGENT_SETTINGS_KEY : 32 octets encodés en base64 attendus.');
            }
            $this->key = $raw;
        } else {
            $this->key = sodium_crypto_generichash('tadaaa-agent-settings:'.$appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** @throws \RuntimeException si le texte chiffré est illisible (clé changée, donnée altérée) */
    public function decrypt(string $cipher): string
    {
        if (!str_starts_with($cipher, self::PREFIX)) {
            throw new \RuntimeException('Format de clé chiffrée inconnu.');
        }
        $raw = base64_decode(substr($cipher, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Clé chiffrée illisible.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new \RuntimeException('Clé chiffrée illisible (clé de chiffrement changée ?).');
        }

        return $plain;
    }
}
