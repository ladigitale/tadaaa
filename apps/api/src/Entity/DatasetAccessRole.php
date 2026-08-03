<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Effective access for the current user on a dataset (owner or member).
 */
enum DatasetAccessRole: string
{
    case Owner = 'owner';
    case Writer = 'writer';
    case Reader = 'reader';

    public function canWrite(): bool
    {
        return $this === self::Owner || $this === self::Writer;
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
