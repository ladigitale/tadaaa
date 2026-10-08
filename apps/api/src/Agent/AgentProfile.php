<?php

declare(strict_types=1);

namespace App\Agent;

/** Profils de l'agent intégré : un jeu d'outils et un prompt système par usage. */
final class AgentProfile
{
    public const TASKS = 'tasks';
    public const ARTIFACTS = 'artifacts';
    public const ALL = [self::TASKS, self::ARTIFACTS];
}
