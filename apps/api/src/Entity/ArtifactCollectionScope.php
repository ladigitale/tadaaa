<?php

declare(strict_types=1);

namespace App\Entity;

enum ArtifactCollectionScope: string
{
    /** Tous les lecteurs autorisés voient les mêmes records. */
    case Shared = 'shared';
    /** Chaque utilisateur ne voit / écrit que ses propres records. */
    case PerUser = 'per_user';
}
