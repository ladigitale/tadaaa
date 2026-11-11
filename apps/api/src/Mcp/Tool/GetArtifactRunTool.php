<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact_run',
    description: 'Lit un run d’artefact par `runId` : `{status, input, output, errors, metrics, createdAt, completedAt}`. Avec `slug` (ou `id`) sans runId : les derniers runs de cet artefact (sans entrées ni sorties ; `limit` ≤ 100, défaut 20).',
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactRunTool
{
    public function __construct(
        public ?string $runId = null,
        public ?string $id = null,
        public ?string $slug = null,
        public ?int $limit = null,
    ) {
    }
}
