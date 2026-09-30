<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_artifacts',
    description: 'Liste les artefacts accessibles (datasets lisibles). Filtre optionnel `datasetId`.',
    processor: ArtifactMcpProcessor::class,
)]
final class ListArtifactsTool
{
    public function __construct(
        public ?string $datasetId = null,
    ) {
    }
}
