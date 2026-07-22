<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_todos',
    description: 'Liste les tâches du jeu cloud actif (filtre status/q).',
    processor: CloudTodoMcpProcessor::class,
)]
final class ListTodosTool
{
    public function __construct(
        public string $status = 'all',
        public ?string $q = null,
        public int $limit = 50,
    ) {
    }
}
