<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Server push / preference catalogue.
 */
final class NotificationEventType
{
    public const DATASET_INVITE = 'dataset_invite';
    public const MEMBER_JOINED = 'member_joined';
    public const TODO_CHECKED = 'todo_checked';
    public const TODO_UNCHECKED = 'todo_unchecked';
    public const TODO_DELETED = 'todo_deleted';
    public const TODO_CREATED = 'todo_created';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DATASET_INVITE,
            self::MEMBER_JOINED,
            self::TODO_CHECKED,
            self::TODO_UNCHECKED,
            self::TODO_DELETED,
            self::TODO_CREATED,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::DATASET_INVITE => true,
            self::MEMBER_JOINED => true,
            self::TODO_CHECKED => true,
            self::TODO_UNCHECKED => true,
            self::TODO_DELETED => true,
            self::TODO_CREATED => false,
        ];
    }

    public static function isValid(string $type): bool
    {
        return \in_array($type, self::all(), true);
    }
}
