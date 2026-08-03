<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'create_tag',
    description: 'Crée une étiquette (name, color).',
    processor: CloudTodoMcpProcessor::class,
)]
final class CreateTagTool
{
    public function __construct(
        public string $name = '',
        public string $color = 'default',
    ) {
    }
}
