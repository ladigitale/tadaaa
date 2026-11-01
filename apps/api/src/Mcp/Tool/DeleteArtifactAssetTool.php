<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_artifact_asset',
    description: 'Supprime un asset d’un artefact (`slug` ou `id`, `name`). Les références `@asset:nom` restantes dans le document restent telles quelles (non résolues) : les retirer ou ré-envoyer l’asset.',
    processor: ArtifactMcpProcessor::class,
)]
final class DeleteArtifactAssetTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public string $name = '',
    ) {
    }
}
