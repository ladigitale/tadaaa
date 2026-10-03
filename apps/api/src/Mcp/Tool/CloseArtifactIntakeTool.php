<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'close_artifact_intake',
    description: 'Ferme immédiatement une collecte contrôlée (plus aucun envoi anonyme). `purge: true` supprime aussi tous les records de la collection. `rotateReadToken: true` invalide l’ancien lien secret de lecture.',
    processor: ArtifactMcpProcessor::class,
)]
final class CloseArtifactIntakeTool
{
    public function __construct(
        public string $slug = '',
        public string $collection = '',
        public bool $purge = false,
        public bool $rotateReadToken = false,
    ) {
    }
}
