<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactWorkflowMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_artifact_workflows',
    description: <<<'DESC'
Liste les workflows d'artefacts du compte ({slug, title, steps}). Avec `slug` : le workflow complet (définition) et ses 10 derniers runs.
DESC,
    processor: ArtifactWorkflowMcpProcessor::class,
)]
final class ListArtifactWorkflowsTool
{
    public function __construct(
        public ?string $slug = null,
    ) {
    }
}
