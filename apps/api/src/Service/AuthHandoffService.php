<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;

/**
 * One-time codes so sister SPAs (Belts, …) can inherit a JWT without a second password prompt.
 */
final class AuthHandoffService
{
    private const TTL_SECONDS = 60;
    private const PREFIX = 'auth_handoff_';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function issue(User $user): string
    {
        $code = bin2hex(random_bytes(24));
        $item = $this->cache->getItem(self::PREFIX.$code);
        $item->set($user->getUserIdentifier());
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $code;
    }

    public function consume(string $code): ?string
    {
        $code = trim($code);
        if ($code === '' || !preg_match('/^[a-f0-9]{48}$/', $code)) {
            return null;
        }

        $key = self::PREFIX.$code;
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }
        $email = $item->get();
        $this->cache->deleteItem($key);

        return \is_string($email) && $email !== '' ? $email : null;
    }

    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
