<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact',
    description: 'Récupère un artefact (document complet) par `id` ou `slug`. `version` optionnelle (sinon version courante).',
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?int $version = null,
    ) {
    }
}
