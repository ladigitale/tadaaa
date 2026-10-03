<?php

declare(strict_types=1);

namespace App\Entity;

enum ArtifactWriteMode: string
{
    case None = 'none';
    case Members = 'members';
    case Authenticated = 'authenticated';
    /** Envois anonymes contrôlés : schéma + session ouverte par le propriétaire + quota. */
    case Intake = 'intake';
}
