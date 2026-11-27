<?php

declare(strict_types=1);

namespace App\Mcp\Skill;

use Mcp\Schema\Extension\ServerExtensionInterface;

/**
 * Déclaration de l'extension Skills (SEP-2640) dans `capabilities.extensions` : le client peut appeler skills/list
 * et skills/get, et resources/directory/read sur tout dossier de l'espace skill:// (SkillsRequestListener).
 */
final class SkillsExtension implements ServerExtensionInterface
{
    public const ID = 'io.modelcontextprotocol/skills';

    public function getId(): string
    {
        return self::ID;
    }

    public function getCapabilities(): array
    {
        return ['directoryRead' => true];
    }
}
