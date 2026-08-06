<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\UsageDaily;
use App\Entity\User;
use App\Repository\UsageDailyRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UsageMeter
{
    public const TODOS_CREATED = 'todos_created';
    public const TODOS_UPDATED = 'todos_updated';
    public const TAGS_MUTATED = 'tags_mutated';
    public const MCP_CALLS = 'mcp_calls';
    public const WEBHOOK_DELIVERIES = 'webhook_deliveries';
    public const WEBHOOK_BYTES = 'webhook_bytes';
    public const WEBHOOK_FAILURES = 'webhook_failures';
    public const INVITES_SENT = 'invites_sent';
    public const DATASETS_CREATED = 'datasets_created';
    public const EMBED_REQUESTS = 'embed_requests';
    public const EMBED_BYTES = 'embed_bytes';
    public const EMBED_ORIGIN_DENIED = 'embed_origin_denied';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UsageDailyRepository $usage,
    ) {
    }

    public function increment(User $owner, ?Dataset $dataset, string $key, int $n = 1, bool $flush = true): void
    {
        if ($n === 0) {
            return;
        }

        $day = new \DateTimeImmutable('today');
        $row = $this->usage->findOneFor($owner, $day, $dataset);
        if ($row === null) {
            // In-memory row from a previous bump in this request
            foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                if (
                    $entity instanceof UsageDaily
                    && $entity->getOwner()->getId()->equals($owner->getId())
                    && $entity->getDay()->format('Y-m-d') === $day->format('Y-m-d')
                    && $entity->getDatasetKey() === ($dataset?->getId()->toRfc4122() ?? '_')
                ) {
                    $row = $entity;
                    break;
                }
            }
        }
        if ($row === null) {
            $row = new UsageDaily($owner, $day, $dataset);
            $this->entityManager->persist($row);
        }
        $row->increment($key, $n);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    /**
     * @return array{
     *   from: string,
     *   to: string,
     *   scope: 'user'|'all',
     *   userId: ?string,
     *   totals: array<string, int>,
     *   byDay: list<array{day: string, datasetId: ?string, counters: array<string, int>}>,
     * }
     */
    public function report(?User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->usage->findRange($owner, $from, $to);
        $totals = [];
        $byDay = [];
        foreach ($rows as $row) {
            $counters = $row->getCounters();
            foreach ($counters as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
            $byDay[] = [
                'day' => $row->getDay()->format('Y-m-d'),
                'datasetId' => $row->getDataset()?->getId()->toRfc4122(),
                'counters' => $counters,
            ];
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'scope' => $owner === null ? 'all' : 'user',
            'userId' => $owner?->getId()->toRfc4122(),
            'totals' => $totals,
            'byDay' => $byDay,
        ];
    }
}
