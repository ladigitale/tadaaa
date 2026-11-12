<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactWorkflowMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact_workflow_run',
    description: <<<'DESC'
Lit un run de workflow d'artefacts (`workflowRunId`) : statut, étapes (status, runId, output, files), étape en attente (`waiting` : url, runToken), sortie. `cancel: true` annule le run (et le run de l'étape en attente).
DESC,
    processor: ArtifactWorkflowMcpProcessor::class,
)]
final class GetArtifactWorkflowRunTool
{
    public function __construct(
        public ?string $workflowRunId = null,
        public ?bool $cancel = null,
    ) {
    }
}
