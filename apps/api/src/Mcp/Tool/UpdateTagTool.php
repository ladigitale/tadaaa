<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'update_tag',
    description: 'Met à jour une étiquette (name, color). Couleurs : default, primary, neutral, warning, info, success, danger, contrast.',
    processor: CloudTodoMcpProcessor::class,
)]
final class UpdateTagTool
{
    public function __construct(
        public string $id = '',
        public ?string $name = null,
        public ?string $color = null,
    ) {
    }
}
