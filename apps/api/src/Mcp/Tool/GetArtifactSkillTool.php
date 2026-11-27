<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact_skill',
    description: <<<'DESC'
Lit une skill des artefacts (même contenu que la ressource `skill://…/SKILL.md`, pour les clients qui ne lisent pas les ressources MCP).

- Sans `name` : le sommaire `{skills: [{name, uri, family, description}]}` (familles : pilote, conception, actions, briques). Commencer par `demarrer`.
- `name` (ex. "demarrer", "modifier-artefact", "design", "jeu", "3d", "audio") : `{name, uri, description, content, files}` ; `content` = SKILL.md sans son en-tête, `files` = fichiers annexes (`references/…`).
- `file` (ex. "references/outils.md") : le contenu de ce fichier annexe.
DESC,
    processor: ArtifactMcpProcessor::class,
    annotations: ['readOnlyHint' => true, 'openWorldHint' => false],
)]
final class GetArtifactSkillTool
{
    public function __construct(
        public ?string $name = null,
        public ?string $file = null,
    ) {
    }
}
