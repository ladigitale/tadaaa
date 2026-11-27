<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'cancel_artifact_run',
    description: 'Annule un run d’artefact en attente (modes interactive / embed) par `runId` : son lien ne sert plus, statut `cancelled`, webhook artifact.run.failed.',
    processor: ArtifactMcpProcessor::class,
    annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
)]
final class CancelArtifactRunTool
{
    public function __construct(
        public ?string $runId = null,
    ) {
    }
}
