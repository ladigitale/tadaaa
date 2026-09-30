<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact_catalog',
    description: <<<'DESC'
Retourne le catalogue Concorde autorisé, le squelette d’enveloppe `artifacts/1` et 2–3 exemples valides.

À appeler avant de composer un artefact SDUI. Les champs interdits (markup, innerHTML, js, javascript:) sont rejetés à la validation.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactCatalogTool
{
}
