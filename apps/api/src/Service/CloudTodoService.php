<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Tag;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\DatasetRepository;
use App\Repository\TagRepository;
use App\Repository\TodoRepository;
use App\Util\BaseIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Opérations métier cloud sur le jeu actif (ou un baseId explicite).
 * Utilisé par le MCP et éventuellement l’API REST.
 */
final class CloudTodoService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DatasetRepository $datasets,
        private readonly TodoRepository $todos,
        private readonly TagRepository $tags,
    ) {
    }

    public function requireActiveDataset(User $user): Dataset
    {
        $dataset = $user->getActiveDataset();
        if ($dataset === null) {
            $all = $this->datasets->createQueryBuilder('d')
                ->andWhere('d.owner = :owner')
                ->setParameter('owner', $user)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$all instanceof Dataset) {
                throw new BadRequestHttpException('Aucun jeu de données cloud. Créez-en un ou synchronisez.');
            }

            return $all;
        }

        return $dataset;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTodos(User $user, ?string $status = 'all', ?string $q = null, int $limit = 50): array
    {
        $dataset = $this->requireActiveDataset($user);
        $rows = $this->todos->findChangedSince($dataset, null);
        $result = [];
        foreach ($rows as $todo) {
            if ($todo->isDeleted()) {
                continue;
            }
            if (!$this->matchesStatus($todo, $status ?? 'all')) {
                continue;
            }
            if ($q !== null && $q !== '') {
                $needle = mb_strtolower($q);
                $hay = mb_strtolower($todo->getText().' '.($todo->getDescription() ?? ''));
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $result[] = $todo->toSyncArray();
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTodo(User $user, string $id): array
    {
        $todo = $this->requireTodo($user, $id);
        return $todo->toSyncArray();
    }

    /**
     * @param list<string>|null $tagIds
     *
     * @return array<string, mixed>
     */
    public function createTodo(
        User $user,
        string $text,
        ?string $description = null,
        string $priority = 'medium',
        ?array $tagIds = null,
        ?string $parentId = null,
    ): array {
        $dataset = $this->requireActiveDataset($user);
        $text = trim($text);
        if ($text === '') {
            throw new BadRequestHttpException('Le texte de la tâche est requis.');
        }

        $id = 'todo-'.bin2hex(random_bytes(8));
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $todo = new Todo($id, $dataset);
        $todo->setText($text);
        $todo->setDescription($description);
        $todo->setPriority($priority);
        $todo->setTagIds($tagIds ?? []);
        $todo->setParentId($parentId);
        $todo->setFieldVersions([
            'text' => $now,
            'description' => $now,
            'done' => $now,
            'archived' => $now,
            'priority' => $now,
            'tagIds' => $now,
            'parentId' => $now,
        ]);

        $this->entityManager->persist($todo);
        $dataset->touch();
        $this->entityManager->flush();

        return $todo->toSyncArray();
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public function updateTodo(User $user, string $id, array $patch): array
    {
        $todo = $this->requireTodo($user, $id);
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $versions = $todo->getFieldVersions();

        if (array_key_exists('text', $patch) && is_string($patch['text'])) {
            $todo->setText($patch['text']);
            $versions['text'] = $now;
        }
        if (array_key_exists('description', $patch)) {
            $todo->setDescription(is_string($patch['description']) ? $patch['description'] : null);
            $versions['description'] = $now;
        }
        if (array_key_exists('done', $patch)) {
            $todo->setDone((bool) $patch['done']);
            $versions['done'] = $now;
        }
        if (array_key_exists('archived', $patch)) {
            $todo->setArchived((bool) $patch['archived']);
            $versions['archived'] = $now;
        }
        if (array_key_exists('priority', $patch) && is_string($patch['priority'])) {
            $todo->setPriority($patch['priority']);
            $versions['priority'] = $now;
        }
        if (array_key_exists('tagIds', $patch) && is_array($patch['tagIds'])) {
            $todo->setTagIds(array_values(array_map('strval', $patch['tagIds'])));
            $versions['tagIds'] = $now;
        }
        if (array_key_exists('parentId', $patch)) {
            $parentId = $patch['parentId'];
            $todo->setParentId(is_string($parentId) && $parentId !== '' ? $parentId : null);
            $versions['parentId'] = $now;
        }

        $todo->setFieldVersions($versions);
        $todo->getDataset()->touch();
        $this->entityManager->flush();

        return $todo->toSyncArray();
    }

    /**
     * @return array{updatedCount: int}
     */
    public function bulkUpdate(
        User $user,
        ?string $status,
        ?string $q,
        array $patch,
        int $limit = 200,
    ): array {
        $todos = $this->listTodos($user, $status, $q, $limit);
        $count = 0;
        foreach ($todos as $row) {
            $this->updateTodo($user, (string) $row['id'], $patch);
            ++$count;
        }

        return ['updatedCount' => $count];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTags(User $user): array
    {
        $dataset = $this->requireActiveDataset($user);
        $rows = $this->tags->findChangedSince($dataset, null);
        $result = [];
        foreach ($rows as $tag) {
            if ($tag->isDeleted()) {
                continue;
            }
            $result[] = $tag->toSyncArray();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function createTag(User $user, string $name, string $color = 'default'): array
    {
        $dataset = $this->requireActiveDataset($user);
        $name = trim($name);
        if ($name === '') {
            throw new BadRequestHttpException('Le nom du tag est requis.');
        }

        $id = 'tag-'.bin2hex(random_bytes(8));
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $tag = new Tag($id, $dataset);
        $tag->setName($name);
        $tag->setColor($color);
        $tag->setFieldVersions(['name' => $now, 'color' => $now]);

        $this->entityManager->persist($tag);
        $dataset->touch();
        $this->entityManager->flush();

        return $tag->toSyncArray();
    }

    /**
     * @param array{name?: string, color?: string} $patch
     *
     * @return array<string, mixed>
     */
    public function updateTag(User $user, string $id, array $patch): array
    {
        $dataset = $this->requireActiveDataset($user);
        $tag = $this->tags->findOneForDataset($dataset, $id);
        if ($tag === null || $tag->isDeleted()) {
            throw new NotFoundHttpException('Tag introuvable.');
        }

        if (!array_key_exists('name', $patch) && !array_key_exists('color', $patch)) {
            throw new BadRequestHttpException('Aucun champ à modifier (name, color).');
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $versions = $tag->getFieldVersions();

        if (array_key_exists('name', $patch)) {
            $name = is_string($patch['name']) ? trim($patch['name']) : '';
            if ($name === '') {
                throw new BadRequestHttpException('Le nom du tag est requis.');
            }
            $tag->setName($name);
            $versions['name'] = $now;
        }

        if (array_key_exists('color', $patch)) {
            $color = is_string($patch['color']) && $patch['color'] !== ''
                ? $patch['color']
                : 'default';
            $tag->setColor($color);
            $versions['color'] = $now;
        }

        $tag->setFieldVersions($versions);
        $dataset->touch();
        $this->entityManager->flush();

        return $tag->toSyncArray();
    }

    public function deleteTag(User $user, string $id): array
    {
        $dataset = $this->requireActiveDataset($user);
        $tag = $this->tags->findOneForDataset($dataset, $id);
        if ($tag === null || $tag->isDeleted()) {
            throw new NotFoundHttpException('Tag introuvable.');
        }

        $now = new \DateTimeImmutable();
        $tag->setDeletedAt($now);
        $versions = $tag->getFieldVersions();
        $versions['deletedAt'] = $now->format(\DateTimeInterface::ATOM);
        $tag->setFieldVersions($versions);

        foreach ($this->todos->findChangedSince($dataset, null) as $todo) {
            if ($todo->isDeleted()) {
                continue;
            }
            $tagIds = $todo->getTagIds();
            if (!in_array($id, $tagIds, true)) {
                continue;
            }
            $todo->setTagIds(array_values(array_filter($tagIds, static fn (string $tid) => $tid !== $id)));
            $tv = $todo->getFieldVersions();
            $tv['tagIds'] = $now->format(\DateTimeInterface::ATOM);
            $todo->setFieldVersions($tv);
        }

        $dataset->touch();
        $this->entityManager->flush();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Active le jeu cloud ciblé par les outils MCP (indépendant de l’édition web).
     *
     * @return array{id: string, baseId: string, name: string, active: bool}
     */
    public function activateDataset(User $user, string $idOrBaseId): array
    {
        $dataset = $this->resolveDatasetRef($user, $idOrBaseId);
        $user->setActiveDataset($dataset);
        $this->entityManager->flush();

        return $this->datasetToMcpArray($dataset, true);
    }

    /**
     * @return list<array{id: string, baseId: string, name: string, active: bool}>
     */
    public function listDatasets(User $user): array
    {
        $activeId = $user->getActiveDataset()?->getId()->toRfc4122();
        $rows = $this->datasets->createQueryBuilder('d')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $dataset) {
            if (!$dataset instanceof Dataset) {
                continue;
            }
            $result[] = $this->datasetToMcpArray(
                $dataset,
                $activeId === $dataset->getId()->toRfc4122(),
            );
        }

        return $result;
    }

    /**
     * @return array{id: string, baseId: string, name: string, active: bool}
     */
    private function datasetToMcpArray(Dataset $dataset, bool $active): array
    {
        return [
            'id' => $dataset->getId()->toRfc4122(),
            'baseId' => BaseIdParser::format($dataset->getBaseId()),
            'name' => $dataset->getName(),
            'active' => $active,
        ];
    }

    private function resolveDatasetRef(User $user, string $idOrBaseId): Dataset
    {
        $raw = trim($idOrBaseId);
        if ($raw === '') {
            throw new BadRequestHttpException('Paramètre id requis (uuid du jeu ou baseId).');
        }

        if (str_starts_with($raw, 'base-')) {
            try {
                $baseId = BaseIdParser::parse($raw);
            } catch (\InvalidArgumentException) {
                throw new BadRequestHttpException('baseId invalide.');
            }
            $dataset = $this->datasets->findOneByBaseIdForUser($user, $baseId);
            if ($dataset === null) {
                throw new NotFoundHttpException('Jeu de données introuvable.');
            }

            return $dataset;
        }

        try {
            $uuid = Uuid::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('id invalide.');
        }

        $byId = $this->datasets->find($uuid);
        if ($byId instanceof Dataset
            && $byId->getOwner()->getId()->toRfc4122() === $user->getId()->toRfc4122()
        ) {
            return $byId;
        }

        $byBase = $this->datasets->findOneByBaseIdForUser($user, $uuid);
        if ($byBase === null) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }

        return $byBase;
    }

    private function requireTodo(User $user, string $id): Todo
    {
        $dataset = $this->requireActiveDataset($user);
        $todo = $this->todos->findOneForDataset($dataset, $id);
        if ($todo === null || $todo->isDeleted()) {
            throw new NotFoundHttpException('Tâche introuvable.');
        }

        return $todo;
    }

    private function matchesStatus(Todo $todo, string $status): bool
    {
        return match ($status) {
            'active' => !$todo->isArchived() && !$todo->isDone(),
            'done' => !$todo->isArchived() && $todo->isDone(),
            'archived' => $todo->isArchived(),
            'all' => !$todo->isArchived(),
            default => true,
        };
    }
}
