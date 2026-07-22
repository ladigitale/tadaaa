<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_datasets',
    description: 'Liste les jeux de données cloud du compte (indique le jeu actif).',
    processor: CloudTodoMcpProcessor::class,
)]
final class ListDatasetsTool
{
    public function __construct()
    {
    }
}
