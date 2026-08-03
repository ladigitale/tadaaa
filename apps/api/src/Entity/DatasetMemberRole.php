<?php

declare(strict_types=1);

namespace App\Entity;

enum DatasetMemberRole: string
{
    case Writer = 'writer';
    case Reader = 'reader';

    public function canWrite(): bool
    {
        return $this === self::Writer;
    }
}
