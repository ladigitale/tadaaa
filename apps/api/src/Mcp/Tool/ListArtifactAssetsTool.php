<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_artifact_assets',
    description: 'Liste les assets binaires d’un artefact (`slug` ou `id`) : `{name, contentType, sizeBytes, sha256, ref}` où `ref` est la valeur `@asset:nom` à placer dans le document.',
    processor: ArtifactMcpProcessor::class,
)]
final class ListArtifactAssetsTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
    ) {
    }
}
