<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Règle d'entrée d'un salon. Un code optionnel s'ajoute à l'une ou l'autre :
 * le code est un point de rendez-vous, pas un secret ; seul `Approval` est un contrôle d'accès.
 */
enum ArtifactRoomAccess: string
{
    /** Entrée directe (avec le code s'il y en a un). */
    case Open = 'open';
    /** L'hôte accepte ou refuse chaque demande. */
    case Approval = 'approval';
}
