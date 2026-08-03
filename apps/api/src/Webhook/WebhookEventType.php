<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Catalogue d’événements webhook (normés, découvrables).
 */
final class WebhookEventType
{
    public const TODO_CREATED = 'todo.created';
    public const TODO_UPDATED = 'todo.updated';
    public const TODO_CHECKED = 'todo.checked';
    public const TODO_UNCHECKED = 'todo.unchecked';
    public const TODO_DELETED = 'todo.deleted';

    public const TAG_CREATED = 'tag.created';
    public const TAG_UPDATED = 'tag.updated';
    public const TAG_DELETED = 'tag.deleted';

    public const DATASET_CREATED = 'dataset.created';
    public const DATASET_UPDATED = 'dataset.updated';
    public const DATASET_DELETED = 'dataset.deleted';
    public const DATASET_MEMBER_JOINED = 'dataset.member_joined';
    public const DATASET_INVITE_CREATED = 'dataset.invite_created';

    public const WEBHOOK_PING = 'webhook.ping';

    /**
     * @return list<array{type: string, description: string}>
     */
    public static function catalogue(): array
    {
        return [
            ['type' => self::TODO_CREATED, 'description' => 'Une tâche a été créée.'],
            ['type' => self::TODO_UPDATED, 'description' => 'Une tâche a été modifiée.'],
            ['type' => self::TODO_CHECKED, 'description' => 'Une tâche a été cochée (terminée).'],
            ['type' => self::TODO_UNCHECKED, 'description' => 'Une tâche a été décochée.'],
            ['type' => self::TODO_DELETED, 'description' => 'Une tâche a été supprimée.'],
            ['type' => self::TAG_CREATED, 'description' => 'Un tag a été créé.'],
            ['type' => self::TAG_UPDATED, 'description' => 'Un tag a été modifié.'],
            ['type' => self::TAG_DELETED, 'description' => 'Un tag a été supprimé.'],
            ['type' => self::DATASET_CREATED, 'description' => 'Un jeu de données a été créé.'],
            ['type' => self::DATASET_UPDATED, 'description' => 'Un jeu de données a été modifié.'],
            ['type' => self::DATASET_DELETED, 'description' => 'Un jeu de données a été supprimé.'],
            ['type' => self::DATASET_MEMBER_JOINED, 'description' => 'Un membre a rejoint un jeu.'],
            ['type' => self::DATASET_INVITE_CREATED, 'description' => 'Une invitation à un jeu a été créée.'],
            ['type' => self::WEBHOOK_PING, 'description' => 'Événement de test (ping).'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (array $row) => $row['type'], self::catalogue());
    }

    public static function isValid(string $type): bool
    {
        return \in_array($type, self::all(), true);
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    public static function normalizeList(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            if (!\is_string($event) || $event === '') {
                continue;
            }
            if (!self::isValid($event)) {
                continue;
            }
            $out[$event] = $event;
        }

        return array_values($out);
    }
}
