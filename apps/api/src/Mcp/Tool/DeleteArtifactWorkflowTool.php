<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactWorkflowMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_artifact_workflow',
    description: <<<'DESC'
Supprime un workflow d'artefacts (`slug`) et l'historique de ses runs. Les artefacts ne sont pas touchés.
DESC,
    processor: ArtifactWorkflowMcpProcessor::class,
)]
final class DeleteArtifactWorkflowTool
{
    public function __construct(
        public ?string $slug = null,
    ) {
    }
}
