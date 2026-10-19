<?php

declare(strict_types=1);

namespace App\Entity;

enum ArtifactRoomMemberStatus: string
{
    /** Demande d'entrée en attente de la décision de l'hôte. */
    case Pending = 'pending';
    case Active = 'active';
}
