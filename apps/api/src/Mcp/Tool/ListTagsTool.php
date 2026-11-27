<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_tags',
    description: 'Liste les étiquettes du jeu cloud actif.',
    processor: CloudTodoMcpProcessor::class,
    annotations: ['readOnlyHint' => true, 'openWorldHint' => false],
)]
final class ListTagsTool
{
    public function __construct()
    {
    }
}
