<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'read_artifact_data',
    description: 'Lit les records d’une collection (`slug` + `collection`). Respecte le scope `per_user` (uniquement vos records).',
    processor: ArtifactMcpProcessor::class,
)]
final class ReadArtifactDataTool
{
    public function __construct(
        public string $slug = '',
        public string $collection = '',
    ) {
    }
}
