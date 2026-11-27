<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_artifact',
    description: 'Supprime un artefact (writer du dataset). `id` ou `slug`.',
    processor: ArtifactMcpProcessor::class,
    annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
)]
final class DeleteArtifactTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
    ) {
    }
}
