<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Dataset;
use App\Entity\EmbedKey;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\EmbedKeyRepository;
use App\Repository\TagRepository;
use App\Repository\TodoRepository;
use App\Util\TodoDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class EmbedService
{
    private const PLAIN_PREFIX = 'emb_';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmbedKeyRepository $keys,
        private readonly TodoRepository $todos,
        private readonly TagRepository $tags,
        private readonly DatasetAccessService $access,
        private readonly UsageMeter $usage,
        private readonly BandwidthQuota $bandwidthQuota,
        private readonly AuditLogger $audit,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<EmbedKey>
     */
    public function listForUser(User $user): array
    {
        return $this->keys->findForUser($user);
    }

    public function getForUser(User $user, string $id): EmbedKey
    {
        $key = $this->keys->find($id);
        if (!$key instanceof EmbedKey) {
            throw new NotFoundHttpException('Embed key introuvable.');
        }
        if (!$key->getOwner()->getId()->equals($user->getId())) {
            throw new NotFoundHttpException('Embed key introuvable.');
        }
        if ($key->isRevoked()) {
            throw new NotFoundHttpException('Embed key introuvable.');
        }

        return $key;
    }

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $tagIds
     *
     * @return array{key: EmbedKey, plainToken: string}
     */
    public function create(
        User $user,
        string $name,
        string $datasetId,
        array $allowedOrigins = [],
        array $tagIds = [],
        bool $includeDone = false,
        bool $includeDescription = false,
        int $rateLimitPerMinute = 60,
    ): array {
        $dataset = $this->resolveWritableDataset($user, $datasetId);
        $origins = $this->normalizeOrigins($allowedOrigins);
        $tags = $this->normalizeTagIds($tagIds);
        [$plain, $hash, $prefix] = $this->mintToken();

        $key = new EmbedKey(
            $user,
            $dataset,
            $name !== '' ? $name : 'Embed',
            $hash,
            $prefix,
            $origins,
            $tags,
            $includeDone,
            $includeDescription,
            $rateLimitPerMinute,
        );
        $this->entityManager->persist($key);
        $this->entityManager->flush();

        $this->audit->log($user, AuditLog::CATEGORY_EMBED, 'embed.created', [
            'embedId' => $key->getId()->toRfc4122(),
            'datasetId' => $dataset->getId()->toRfc4122(),
            'tokenPrefix' => $prefix,
            'allowedOrigins' => $origins,
        ]);

        return ['key' => $key, 'plainToken' => $plain];
    }

    /**
     * @param array{
     *   name?: string,
     *   datasetId?: string,
     *   allowedOrigins?: list<string>,
     *   tagIds?: list<string>,
     *   includeDone?: bool,
     *   includeDescription?: bool,
     *   active?: bool,
     *   rateLimitPerMinute?: int
     * } $patch
     */
    public function update(User $user, string $id, array $patch): EmbedKey
    {
        $key = $this->getForUser($user, $id);

        if (array_key_exists('name', $patch) && is_string($patch['name'])) {
            $key->setName($patch['name'] !== '' ? $patch['name'] : $key->getName());
        }
        if (array_key_exists('datasetId', $patch) && is_string($patch['datasetId'])) {
            $key->setDataset($this->resolveWritableDataset($user, $patch['datasetId']));
        }
        if (array_key_exists('allowedOrigins', $patch) && is_array($patch['allowedOrigins'])) {
            $key->setAllowedOrigins($this->normalizeOrigins(
                array_values(array_map('strval', $patch['allowedOrigins'])),
            ));
        }
        if (array_key_exists('tagIds', $patch) && is_array($patch['tagIds'])) {
            $key->setTagIds($this->normalizeTagIds(
                array_values(array_map('strval', $patch['tagIds'])),
            ));
        }
        if (array_key_exists('includeDone', $patch)) {
            $key->setIncludeDone((bool) $patch['includeDone']);
        }
        if (array_key_exists('includeDescription', $patch)) {
            $key->setIncludeDescription((bool) $patch['includeDescription']);
        }
        if (array_key_exists('active', $patch)) {
            $key->setActive((bool) $patch['active']);
        }
        if (array_key_exists('rateLimitPerMinute', $patch)) {
            $key->setRateLimitPerMinute((int) $patch['rateLimitPerMinute']);
        }

        $this->entityManager->flush();
        $this->audit->log($user, AuditLog::CATEGORY_EMBED, 'embed.updated', [
            'embedId' => $key->getId()->toRfc4122(),
            'active' => $key->isActive(),
        ]);

        return $key;
    }

    /**
     * @return array{key: EmbedKey, plainToken: string}
     */
    public function rotate(User $user, string $id): array
    {
        $key = $this->getForUser($user, $id);
        [$plain, $hash, $prefix] = $this->mintToken();
        $key->rotateToken($hash, $prefix);
        $this->entityManager->flush();

        $this->audit->log($user, AuditLog::CATEGORY_EMBED, 'embed.rotated', [
            'embedId' => $key->getId()->toRfc4122(),
            'tokenPrefix' => $prefix,
        ]);

        return ['key' => $key, 'plainToken' => $plain];
    }

    public function revoke(User $user, string $id): void
    {
        $key = $this->getForUser($user, $id);
        $embedId = $key->getId()->toRfc4122();
        $key->revoke();
        $this->entityManager->flush();
        $this->audit->log($user, AuditLog::CATEGORY_EMBED, 'embed.revoked', [
            'embedId' => $embedId,
        ]);
    }

    public function findUsableByPlainToken(string $plainToken): ?EmbedKey
    {
        if (!str_starts_with($plainToken, self::PLAIN_PREFIX)) {
            return null;
        }

        return $this->keys->findUsableByHash(hash('sha256', $plainToken));
    }

    /**
     * Public feed. Origin null = non-browser client.
     *
     * @return array{body: array<string, mixed>, bytes: int}
     */
    public function publicFeed(string $plainToken, ?string $origin): array
    {
        $key = $this->findUsableByPlainToken($plainToken);
        if ($key === null) {
            throw new NotFoundHttpException('Embed introuvable.');
        }

        if (!$key->allowsOrigin($origin)) {
            $this->usage->increment(
                $key->getOwner(),
                $key->getDataset(),
                UsageMeter::EMBED_ORIGIN_DENIED,
            );
            throw new AccessDeniedHttpException('Origin non autorisée pour cette clé embed.');
        }

        $this->assertRateLimit($key);
        $this->bandwidthQuota->assertCanTransfer($key->getOwner());

        $payload = $this->buildFeed($key);
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        $bytes = \strlen($encoded);

        $key->recordHit($origin, $bytes);
        $this->usage->increment($key->getOwner(), $key->getDataset(), UsageMeter::EMBED_REQUESTS, flush: false);
        $this->usage->increment($key->getOwner(), $key->getDataset(), UsageMeter::EMBED_BYTES, $bytes, flush: false);
        $this->bandwidthQuota->recordTransfer($key->getOwner(), $key->getDataset(), $bytes);
        $this->entityManager->flush();

        return ['body' => $payload, 'bytes' => $bytes];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(EmbedKey $key): array
    {
        return [
            'id' => $key->getId()->toRfc4122(),
            'name' => $key->getName(),
            'tokenPrefix' => $key->getTokenPrefix(),
            'datasetId' => $key->getDataset()->getId()->toRfc4122(),
            'datasetName' => $key->getDataset()->getName(),
            'allowedOrigins' => $key->getAllowedOrigins(),
            'tagIds' => $key->getTagIds(),
            'includeDone' => $key->isIncludeDone(),
            'includeDescription' => $key->isIncludeDescription(),
            'active' => $key->isActive(),
            'rateLimitPerMinute' => $key->getRateLimitPerMinute(),
            'createdAt' => $key->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $key->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            'lastOrigin' => $key->getLastOrigin(),
            'requestCount' => $key->getRequestCount(),
            'bytesServed' => $key->getBytesServed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFeed(EmbedKey $key): array
    {
        $dataset = $key->getDataset();
        $filterTags = $key->getTagIds();
        $includeDone = $key->isIncludeDone();
        $includeDescription = $key->isIncludeDescription();

        $todosOut = [];
        $open = 0;
        $done = 0;
        $overdue = 0;
        $dated = 0;
        $now = new \DateTimeImmutable('now');

        foreach ($this->todos->findChangedSince($dataset, null) as $todo) {
            if ($todo->isDeleted() || $todo->isArchived()) {
                continue;
            }
            if (!$includeDone && $todo->isDone()) {
                continue;
            }
            if ($filterTags !== [] && !$this->todoMatchesTags($todo, $filterTags)) {
                continue;
            }

            $row = [
                'id' => $todo->getId(),
                'text' => $todo->getText(),
                'done' => $todo->isDone(),
                'priority' => $todo->getPriority(),
                'tagIds' => $todo->getTagIds(),
                'parentId' => $todo->getParentId(),
                'startAt' => TodoDate::format($todo->getStartAt()),
                'endAt' => TodoDate::format($todo->getEndAt()),
                'recurrence' => $todo->getRecurrence(),
            ];
            if ($includeDescription) {
                $row['description'] = $todo->getDescription();
            }
            $todosOut[] = $row;

            if ($todo->isDone()) {
                ++$done;
            } else {
                ++$open;
                $end = $todo->getEndAt() ?? $todo->getStartAt();
                if ($end !== null && $end < $now) {
                    ++$overdue;
                }
            }
            if ($todo->getStartAt() !== null || $todo->getEndAt() !== null) {
                ++$dated;
            }
        }

        $tagsOut = [];
        foreach ($this->tags->findChangedSince($dataset, null) as $tag) {
            if ($tag->isDeleted()) {
                continue;
            }
            if ($filterTags !== [] && !\in_array($tag->getId(), $filterTags, true)) {
                continue;
            }
            $tagsOut[] = [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'color' => $tag->getColor(),
            ];
        }

        return [
            'meta' => [
                'name' => $key->getName(),
                'datasetName' => $dataset->getName(),
                'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            ],
            'todos' => $todosOut,
            'tags' => $tagsOut,
            'stats' => [
                'open' => $open,
                'done' => $done,
                'overdue' => $overdue,
                'dated' => $dated,
                'total' => $open + $done,
            ],
        ];
    }

    /**
     * @param list<string> $filterTags
     */
    private function todoMatchesTags(Todo $todo, array $filterTags): bool
    {
        foreach ($todo->getTagIds() as $tagId) {
            if (\in_array($tagId, $filterTags, true)) {
                return true;
            }
        }

        return false;
    }

    private function assertRateLimit(EmbedKey $key): void
    {
        $cacheKey = 'embed_rl_'.$key->getId()->toRfc4122().'_'.gmdate('YmdHi');
        $limit = $key->getRateLimitPerMinute();
        $count = $this->cache->get($cacheKey, static function (ItemInterface $item) {
            $item->expiresAfter(120);

            return 0;
        });
        if ($count >= $limit) {
            throw new TooManyRequestsHttpException(60, 'Rate limit embed dépassé.');
        }
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, static function (ItemInterface $item) use ($count) {
            $item->expiresAfter(120);

            return $count + 1;
        });
    }

    private function resolveWritableDataset(User $user, string $datasetId): Dataset
    {
        $raw = trim($datasetId);
        if ($raw === '') {
            throw new BadRequestHttpException('datasetId requis.');
        }
        try {
            $uuid = Uuid::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('datasetId invalide.');
        }
        $dataset = $this->access->requireAccessibleById($user, $uuid);
        $this->access->assertCanWrite($user, $dataset);

        return $dataset;
    }

    /**
     * @param list<string> $origins
     *
     * @return list<string>
     */
    private function normalizeOrigins(array $origins): array
    {
        $out = [];
        foreach ($origins as $origin) {
            $origin = trim($origin);
            if ($origin === '') {
                continue;
            }
            if ($origin === '*') {
                $out['*'] = '*';
                continue;
            }
            if (!preg_match('#^https?://#i', $origin)) {
                throw new BadRequestHttpException('Origin invalide (http/https requis) : '.$origin);
            }
            $out[strtolower($origin)] = $origin;
        }

        return array_values($out);
    }

    /**
     * @param list<string> $tagIds
     *
     * @return list<string>
     */
    private function normalizeTagIds(array $tagIds): array
    {
        $out = [];
        foreach ($tagIds as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            $out[$id] = $id;
        }

        return array_values($out);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function mintToken(): array
    {
        $plain = self::PLAIN_PREFIX.bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, 12);

        return [$plain, $hash, $prefix];
    }
}
