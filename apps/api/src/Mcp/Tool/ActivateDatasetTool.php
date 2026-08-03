<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'activate_dataset',
    description: 'Active un jeu cloud pour les outils MCP (id uuid ou baseId). N’affecte pas l’édition web.',
    processor: CloudTodoMcpProcessor::class,
)]
final class ActivateDatasetTool
{
    public function __construct(
        /** Uuid du jeu cloud, ou baseId (`base-…` ou uuid nu). */
        public string $id = '',
    ) {
    }
}
