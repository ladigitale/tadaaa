<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_artifact_tools',
    description: 'Liste les artefacts lisibles qui déclarent un contrat `io` (utilisables comme outils) : `{slug, id, title, description, modes, inputSchema, outputSchema}` (JSON Schema). `datasetId` optionnel. Pour en exécuter un : run_artifact.',
    processor: ArtifactMcpProcessor::class,
)]
final class ListArtifactToolsTool
{
    public function __construct(
        public ?string $datasetId = null,
    ) {
    }
}
