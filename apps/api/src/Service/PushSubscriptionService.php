<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PushSubscriptionService
{
    private const MAX_ACTIVE_PER_USER = 10;
    private const MAX_KEY_LENGTH = 255;
    private const MIN_KEY_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushEndpointValidator $endpointValidator,
    ) {
    }

    /**
     * @param array{endpoint?: mixed, keys?: mixed, userAgent?: mixed} $payload
     *
     * @return array{id: string, endpoint: string}
     */
    public function upsert(User $user, array $payload): array
    {
        $endpoint = $this->endpointValidator->assertValid(
            is_string($payload['endpoint'] ?? null) ? $payload['endpoint'] : '',
        );

        $keys = $payload['keys'] ?? null;
        if (!is_array($keys)) {
            throw new BadRequestHttpException('keys object is required.');
        }
        $p256dh = $this->assertPushKey(is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '', 'p256dh');
        $auth = $this->assertPushKey(is_string($keys['auth'] ?? null) ? $keys['auth'] : '', 'auth');

        $userAgent = is_string($payload['userAgent'] ?? null) ? $payload['userAgent'] : null;

        $existing = $this->subscriptions->findOneByEndpoint($endpoint);
        if ($existing !== null) {
            if (!$existing->getUser()->getId()->equals($user->getId())) {
                // Same browser, account switch: only rebind when keys prove possession
                // of the existing subscription (not a forged endpoint+new keys).
                if (!$this->keysMatch($existing, $p256dh, $auth)) {
                    throw new ConflictHttpException(
                        'This push endpoint is already registered to another account.',
                    );
                }
                $existing->reassignUser($user);
            }
            $existing->updateKeys($p256dh, $auth);
            $existing->setUserAgent($userAgent);
            $sub = $existing;
        } else {
            $this->enforceActiveCap($user);
            $sub = new PushSubscription($user, $endpoint, $p256dh, $auth);
            $sub->setUserAgent($userAgent);
            $this->entityManager->persist($sub);
        }

        $this->entityManager->flush();

        return [
            'id' => $sub->getId()->toRfc4122(),
            'endpoint' => $sub->getEndpoint(),
        ];
    }

    public function revoke(User $user, string $endpoint): void
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new BadRequestHttpException('endpoint is required.');
        }

        $existing = $this->subscriptions->findOneByEndpoint($endpoint);
        if ($existing === null) {
            return;
        }
        if (!$existing->getUser()->getId()->equals($user->getId())) {
            return;
        }
        if (!$existing->isRevoked()) {
            $existing->revoke();
            $this->entityManager->flush();
        }
    }

    /**
     * @return list<array{
     *     id: string,
     *     endpoint: string,
     *     endpointHost: string,
     *     userAgent: string|null,
     *     createdAt: string,
     *     lastSeenAt: string|null
     * }>
     */
    public function listActiveForUser(User $user): array
    {
        $rows = [];
        foreach ($this->subscriptions->findActiveForUser($user) as $sub) {
            $endpoint = $sub->getEndpoint();
            $host = parse_url($endpoint, \PHP_URL_HOST);
            $rows[] = [
                'id' => $sub->getId()->toRfc4122(),
                'endpoint' => $endpoint,
                'endpointHost' => is_string($host) && $host !== '' ? $host : 'unknown',
                'userAgent' => $sub->getUserAgent(),
                'createdAt' => $sub->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'lastSeenAt' => $sub->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }

    private function assertPushKey(string $value, string $name): string
    {
        $value = trim($value);
        $len = strlen($value);
        if ($len < self::MIN_KEY_LENGTH || $len > self::MAX_KEY_LENGTH) {
            throw new BadRequestHttpException(sprintf('keys.%s length is invalid.', $name));
        }
        // Browser PushManager keys are URL-safe base64.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new BadRequestHttpException(sprintf('keys.%s format is invalid.', $name));
        }

        return $value;
    }

    private function keysMatch(PushSubscription $existing, string $p256dh, string $auth): bool
    {
        return hash_equals($existing->getP256dh(), $p256dh)
            && hash_equals($existing->getAuth(), $auth);
    }

    private function enforceActiveCap(User $user): void
    {
        $active = $this->subscriptions->findActiveForUser($user);
        $overflow = count($active) - self::MAX_ACTIVE_PER_USER + 1;
        if ($overflow <= 0) {
            return;
        }
        // Oldest first (repo orders createdAt DESC → reverse).
        $oldest = array_reverse($active);
        for ($i = 0; $i < $overflow; ++$i) {
            $oldest[$i]->revoke();
        }
    }
}
