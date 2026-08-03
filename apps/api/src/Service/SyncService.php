<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Tag;
use App\Entity\Todo;
use App\Entity\User;
use App\Notification\NotificationEventType;
use App\Repository\DatasetRepository;
use App\Repository\TagRepository;
use App\Repository\TodoRepository;
use App\Util\BaseIdParser;
use App\Util\TodoDate;
use App\Webhook\WebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SyncService
{
    private const TODO_FIELDS = ['text', 'description', 'done', 'archived', 'priority', 'tagIds', 'parentId', 'startAt', 'endAt', 'recurrence'];
    private const TAG_FIELDS = ['name', 'color'];

    /** @var list<array{type: string, id: string, text: string}> */
    private array $todoNotifyBuffer = [];

    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $webhookEventBuffer = [];

    /** @var list<string> */
    private array $googleCalendarTodoIds = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DatasetRepository $datasets,
        private readonly TodoRepository $todos,
        private readonly TagRepository $tags,
        private readonly DatasetAccessService $access,
        private readonly DatasetRealtimePublisher $realtime,
        private readonly PushNotificationDispatcher $push,
        private readonly WebhookDispatcher $webhooks,
        private readonly GoogleCalendarSyncDispatcher $googleCalendar,
    ) {
    }

    /**
     * @return array{
     *   serverTime: string,
     *   datasetUpdatedAt: string,
     *   todos: list<array<string, mixed>>,
     *   tags: list<array<string, mixed>>
     * }
     */
    public function pull(User $user, string $baseIdRaw, ?string $sinceRaw): array
    {
        $dataset = $this->requireDataset($user, $baseIdRaw, write: false);
        $since = $this->parseSince($sinceRaw);

        $todoRows = $this->todos->findChangedSince($dataset, $since);
        $tagRows = $this->tags->findChangedSince($dataset, $since);

        return [
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'datasetUpdatedAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'todos' => array_map(static fn (Todo $todo) => $todo->toSyncArray(), $todoRows),
            'tags' => array_map(static fn (Tag $tag) => $tag->toSyncArray(), $tagRows),
        ];
    }

    /**
     * @param array{
     *   mutations?: list<array<string, mixed>>
     * } $payload
     *
     * @return array{
     *   accepted: list<string>,
     *   rejected: list<array{id: string, reason: string}>,
     *   datasetUpdatedAt: string
     * }
     */
    public function push(User $user, string $baseIdRaw, array $payload): array
    {
        $dataset = $this->requireDataset($user, $baseIdRaw, write: true);
        $mutations = $payload['mutations'] ?? [];
        if (!is_array($mutations)) {
            throw new BadRequestHttpException('mutations doit être un tableau.');
        }

        $accepted = [];
        $rejected = [];
        $this->todoNotifyBuffer = [];
        $this->webhookEventBuffer = [];
        $this->googleCalendarTodoIds = [];

        foreach ($mutations as $mutation) {
            if (!is_array($mutation)) {
                continue;
            }

            try {
                $key = $this->applyMutation($dataset, $mutation);
                $accepted[] = $key;
            } catch (\Throwable $exception) {
                $rejected[] = [
                    'id' => is_string($mutation['id'] ?? null) ? $mutation['id'] : 'unknown',
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        if ($accepted !== []) {
            $dataset->touch();
            $this->entityManager->flush();
            $this->realtime->publishDatasetChanged($dataset);
            if ($this->todoNotifyBuffer !== []) {
                $this->push->notifyTodoEvents($dataset, $user, $this->todoNotifyBuffer);
                $this->todoNotifyBuffer = [];
            }
            foreach ($this->webhookEventBuffer as $event) {
                $this->webhooks->dispatch($dataset, $event['type'], $event['data'], $user);
            }
            $this->webhookEventBuffer = [];
            if ($this->googleCalendarTodoIds !== []) {
                $this->googleCalendar->dispatchTodos($dataset, $this->googleCalendarTodoIds);
                $this->googleCalendarTodoIds = [];
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'datasetUpdatedAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array{
     *   name?: string,
     *   todos?: list<array<string, mixed>>,
     *   tags?: list<array<string, mixed>>
     * } $payload
     *
     * @return array{
     *   datasetId: string,
     *   baseId: string,
     *   datasetUpdatedAt: string,
     *   imported: array{todos: int, tags: int}
     * }
     */
    public function bootstrap(User $user, string $baseIdRaw, array $payload): array
    {
        $baseId = BaseIdParser::parse($baseIdRaw);
        $dataset = $this->datasets->findOneByBaseId($baseId);

        if ($dataset !== null) {
            $this->access->assertCanWrite($user, $dataset);
        } else {
            $name = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
                ? trim($payload['name'])
                : 'Mon jeu';
            // Même nom cloud déjà présent (ex. « Défaut » à l’inscription) → rattacher le baseId local
            $byName = $this->datasets->findOneByNameForUser($user, $name);
            if ($byName !== null) {
                $dataset = $byName;
                $dataset->setBaseId($baseId);
            } else {
                $dataset = new Dataset($name);
                $dataset->setBaseId($baseId);
                $dataset->setOwner($user);
                $this->entityManager->persist($dataset);
                if ($user->getActiveDataset() === null) {
                    $user->setActiveDataset($dataset);
                }
            }
        }

        $todoCount = 0;
        $tagCount = 0;

        foreach ($payload['tags'] ?? [] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $this->upsertTag($dataset, $row, true);
            ++$tagCount;
        }

        foreach ($payload['todos'] ?? [] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $this->upsertTodo($dataset, $row, true);
            ++$todoCount;
        }

        $dataset->touch();
        $this->entityManager->flush();

        return [
            'datasetId' => $dataset->getId()->toRfc4122(),
            'baseId' => BaseIdParser::format($dataset->getBaseId()),
            'datasetUpdatedAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'imported' => ['todos' => $todoCount, 'tags' => $tagCount],
        ];
    }

    /**
     * @param array<string, mixed> $mutation
     */
    private function applyMutation(Dataset $dataset, array $mutation): string
    {
        $entity = $mutation['entity'] ?? null;
        $op = $mutation['op'] ?? null;
        $id = $mutation['id'] ?? null;

        if (!is_string($entity) || !is_string($op) || !is_string($id) || $id === '') {
            throw new BadRequestHttpException('Mutation invalide.');
        }

        if ($op === 'delete') {
            return $this->deleteEntity($dataset, $entity, $id, $mutation);
        }

        if ($op !== 'upsert') {
            throw new BadRequestHttpException(sprintf('Opération « %s » non supportée.', $op));
        }

        $payload = $mutation['payload'] ?? null;
        if (!is_array($payload)) {
            throw new BadRequestHttpException('payload manquant.');
        }

        return match ($entity) {
            'todo' => $this->upsertTodo($dataset, array_merge($payload, ['id' => $id]), false),
            'tag' => $this->upsertTag($dataset, array_merge($payload, ['id' => $id]), false),
            default => throw new BadRequestHttpException(sprintf('Entité « %s » inconnue.', $entity)),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertTodo(Dataset $dataset, array $row, bool $replace): string
    {
        $id = (string) $row['id'];
        $fieldVersions = $this->readFieldVersions($row);
        $todo = $this->todos->findOneForDataset($dataset, $id);

        $existed = $todo !== null && !$todo->isDeleted();
        $prevDone = $existed ? $todo->isDone() : null;

        if ($todo === null) {
            $todo = new Todo($id, $dataset);
            $this->entityManager->persist($todo);
            if (is_string($row['createdAt'] ?? null)) {
                $todo->setCreatedAt(new \DateTimeImmutable($row['createdAt']));
            }
        } elseif ($todo->isDeleted()) {
            $todo->setDeletedAt(null);
        }

        $fields = [];
        foreach (self::TODO_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $fields[$field] = $row[$field];
            }
        }

        if ($replace && $fields === []) {
            foreach (self::TODO_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $fields[$field] = $row[$field];
                }
            }
        }

        if ($replace && $fieldVersions === []) {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            foreach (array_keys($fields) as $field) {
                $fieldVersions[$field] = $now;
            }
        }

        $result = FieldVersionMerger::merge(
            $todo->getFieldVersions(),
            $fieldVersions,
            $fields,
            function (string $field, mixed $value) use ($todo): void {
                $this->applyTodoField($todo, $field, $value);
            },
        );

        $todo->setFieldVersions($result['versions']);

        if (!$replace) {
            if (!$existed && !$todo->isDeleted()) {
                $this->todoNotifyBuffer[] = [
                    'type' => NotificationEventType::TODO_CREATED,
                    'id' => $id,
                    'text' => $todo->getText(),
                ];
                $this->webhookEventBuffer[] = [
                    'type' => WebhookEventType::TODO_CREATED,
                    'data' => $todo->toSyncArray(),
                ];
            } elseif ($existed && !$todo->isDeleted()) {
                $this->webhookEventBuffer[] = [
                    'type' => WebhookEventType::TODO_UPDATED,
                    'data' => $todo->toSyncArray(),
                ];
                if ($prevDone !== null && $todo->isDone() !== $prevDone) {
                    $this->todoNotifyBuffer[] = [
                        'type' => $todo->isDone()
                            ? NotificationEventType::TODO_CHECKED
                            : NotificationEventType::TODO_UNCHECKED,
                        'id' => $id,
                        'text' => $todo->getText(),
                    ];
                    $this->webhookEventBuffer[] = [
                        'type' => $todo->isDone()
                            ? WebhookEventType::TODO_CHECKED
                            : WebhookEventType::TODO_UNCHECKED,
                        'data' => $todo->toSyncArray(),
                    ];
                }
            }
        }

        $this->googleCalendarTodoIds[] = $id;

        return 'todo:'.$id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertTag(Dataset $dataset, array $row, bool $replace): string
    {
        $id = (string) $row['id'];
        $fieldVersions = $this->readFieldVersions($row);
        $tag = $this->tags->findOneForDataset($dataset, $id);
        $existed = $tag !== null && !$tag->isDeleted();

        if ($tag === null) {
            $tag = new Tag($id, $dataset);
            $this->entityManager->persist($tag);
        } elseif ($tag->isDeleted()) {
            $tag->setDeletedAt(null);
        }

        $fields = [];
        foreach (self::TAG_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $fields[$field] = $row[$field];
            }
        }

        if ($replace && $fieldVersions === []) {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            foreach (array_keys($fields) as $field) {
                $fieldVersions[$field] = $now;
            }
        }

        $result = FieldVersionMerger::merge(
            $tag->getFieldVersions(),
            $fieldVersions,
            $fields,
            function (string $field, mixed $value) use ($tag): void {
                $this->applyTagField($tag, $field, $value);
            },
        );

        $tag->setFieldVersions($result['versions']);

        if (!$replace && !$tag->isDeleted()) {
            $this->webhookEventBuffer[] = [
                'type' => $existed ? WebhookEventType::TAG_UPDATED : WebhookEventType::TAG_CREATED,
                'data' => $tag->toSyncArray(),
            ];
        }

        return 'tag:'.$id;
    }

    /**
     * @param array<string, mixed> $mutation
     */
    private function deleteEntity(Dataset $dataset, string $entity, string $id, array $mutation): string
    {
        $fieldVersions = $this->readFieldVersions($mutation);
        $deletedAtRaw = $fieldVersions['deletedAt'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $deletedAt = new \DateTimeImmutable($deletedAtRaw);

        if ($entity === 'todo') {
            $todo = $this->todos->findOneForDataset($dataset, $id);
            $text = $todo?->getText() ?? '';
            $wasActive = $todo !== null && !$todo->isDeleted();
            if ($todo === null) {
                $todo = new Todo($id, $dataset);
                $this->entityManager->persist($todo);
            }
            $todo->setDeletedAt($deletedAt);
            $versions = $todo->getFieldVersions();
            $versions['deletedAt'] = $deletedAt->format(\DateTimeInterface::ATOM);
            $todo->setFieldVersions($versions);
            if ($wasActive) {
                $this->todoNotifyBuffer[] = [
                    'type' => NotificationEventType::TODO_DELETED,
                    'id' => $id,
                    'text' => $text !== '' ? $text : $todo->getText(),
                ];
                $this->webhookEventBuffer[] = [
                    'type' => WebhookEventType::TODO_DELETED,
                    'data' => [
                        'id' => $id,
                        'text' => $text !== '' ? $text : $todo->getText(),
                    ],
                ];
            }
            $this->googleCalendarTodoIds[] = $id;

            return 'todo:'.$id;
        }

        if ($entity === 'tag') {
            $tag = $this->tags->findOneForDataset($dataset, $id);
            $wasActive = $tag !== null && !$tag->isDeleted();
            if ($tag === null) {
                $tag = new Tag($id, $dataset);
                $this->entityManager->persist($tag);
            }
            $tag->setDeletedAt($deletedAt);
            $versions = $tag->getFieldVersions();
            $versions['deletedAt'] = $deletedAt->format(\DateTimeInterface::ATOM);
            $tag->setFieldVersions($versions);
            if ($wasActive) {
                $this->webhookEventBuffer[] = [
                    'type' => WebhookEventType::TAG_DELETED,
                    'data' => ['id' => $id],
                ];
            }

            return 'tag:'.$id;
        }

        throw new BadRequestHttpException(sprintf('Entité « %s » inconnue.', $entity));
    }

    private function applyTodoField(Todo $todo, string $field, mixed $value): void
    {
        match ($field) {
            'text' => $todo->setText(is_string($value) ? $value : ''),
            'description' => $todo->setDescription(is_string($value) ? $value : null),
            'done' => $todo->setDone((bool) $value),
            'archived' => $todo->setArchived((bool) $value),
            'priority' => $todo->setPriority(is_string($value) ? $value : 'medium'),
            'tagIds' => $todo->setTagIds(is_array($value) ? array_values(array_map('strval', $value)) : []),
            'parentId' => $todo->setParentId(is_string($value) && $value !== '' ? $value : null),
            'startAt' => $todo->setStartAt(TodoDate::parse(is_string($value) ? $value : null)),
            'endAt' => $todo->setEndAt(TodoDate::parse(is_string($value) ? $value : null)),
            'recurrence' => $todo->setRecurrence(TodoRecurrence::normalize(is_string($value) ? $value : null)),
            default => null,
        };
    }

    private function applyTagField(Tag $tag, string $field, mixed $value): void
    {
        match ($field) {
            'name' => $tag->setName(is_string($value) ? $value : ''),
            'color' => $tag->setColor(is_string($value) ? $value : 'default'),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function readFieldVersions(array $row): array
    {
        $versions = $row['fieldVersions'] ?? [];
        if (!is_array($versions)) {
            return [];
        }

        $result = [];
        foreach ($versions as $field => $value) {
            if (is_string($field) && is_string($value) && $value !== '') {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    private function requireDataset(User $user, string $baseIdRaw, bool $write): Dataset
    {
        try {
            $baseId = BaseIdParser::parse($baseIdRaw);
        } catch (\Throwable) {
            throw new BadRequestHttpException('baseId invalide.');
        }

        $dataset = $this->datasets->findOneByBaseId($baseId);
        if ($dataset === null) {
            throw new NotFoundHttpException('Jeu de données introuvable pour ce compte.');
        }

        if ($write) {
            $this->access->assertCanWrite($user, $dataset);
        } else {
            $this->access->assertCanRead($user, $dataset);
        }

        return $dataset;
    }

    private function parseSince(?string $sinceRaw): ?\DateTimeImmutable
    {
        if ($sinceRaw === null || trim($sinceRaw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($sinceRaw);
        } catch (\Throwable) {
            throw new BadRequestHttpException('since invalide.');
        }
    }
}
