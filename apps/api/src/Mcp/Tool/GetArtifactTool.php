<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact',
    description: 'Récupère un artefact (document complet) par `id` ou `slug`. `version` optionnelle (sinon version courante). `path` optionnel (JSON Pointer, ex. "/data/transforms/lb" ou "/views/0/root/nodes/3") : ne renvoie que cette partie du document (`{path, value}`), ou, pour un objet très gros, ajoute `keys` seul avec `keysOnly:true` ; bien moins coûteux que le document complet.',
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?int $version = null,
        public ?string $path = null,
        public ?bool $keysOnly = null,
    ) {
    }
}
