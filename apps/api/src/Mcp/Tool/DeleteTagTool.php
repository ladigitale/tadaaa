<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_tag',
    description: 'Supprime une étiquette et la retire des tâches.',
    processor: CloudTodoMcpProcessor::class,
)]
final class DeleteTagTool
{
    public function __construct(
        public string $id = '',
    ) {
    }
}
