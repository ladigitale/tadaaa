<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact',
    description: <<<'DESC'
Récupère un artefact par `id` ou `slug`. `version` optionnelle (sinon version courante).

Par défaut le document complet (peut être très volumineux). Pour économiser :
- `path` (JSON Pointer, ex. `/views/0/root/nodes/2`) : ne retourne que ce sous-arbre dans `value`.
- `outline: true` avec `path` : retourne seulement la forme (clés et tailles, tags des enfants) — utile pour se repérer avant un `patch`.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?int $version = null,
        public ?string $path = null,
        public bool $outline = false,
    ) {
    }
}
