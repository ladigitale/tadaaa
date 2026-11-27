<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'restore_artifact_version',
    description: <<<'DESC'
Revient à une version précédente d'un artefact (`id` ou `slug`, `version` = numéro lu par get_artifact `versions: true`) : crée une NOUVELLE version identique à celle-ci (note « Restauration de la version n »), l'historique est gardé. La version restaurée est revalidée : refus si elle ne passe plus la validation actuelle.

Retourne `{id, slug, url, currentVersion, liveVersion}`.

Exemple : {"slug":"mon-jeu","version":12}
DESC,
    processor: ArtifactMcpProcessor::class,
    annotations: ['destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
)]
final class RestoreArtifactVersionTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?int $version = null,
    ) {
    }
}
