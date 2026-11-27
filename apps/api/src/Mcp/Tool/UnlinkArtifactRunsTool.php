<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'unlink_artifact_runs',
    description: 'Coupe un lien entre runs (link_artifact_runs) : `linkId`. Retourne le lien avec ses compteurs.',
    processor: ArtifactMcpProcessor::class,
    annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
)]
final class UnlinkArtifactRunsTool
{
    public function __construct(
        public ?string $linkId = null,
    ) {
    }
}
