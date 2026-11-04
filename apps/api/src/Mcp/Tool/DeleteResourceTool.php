<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_resource',
    description: 'Supprime une ressource du compte (`slug`) avec toutes ses versions. Les artefacts qui en ont déjà copié le contenu ne sont pas modifiés. Les ressources de base ne se suppriment pas.',
    processor: ArtifactMcpProcessor::class,
)]
final class DeleteResourceTool
{
    public function __construct(
        public string $slug = '',
    ) {
    }
}
