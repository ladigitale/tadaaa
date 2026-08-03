<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'copy_todo',
    description: 'Duplique une tâche du jeu cloud actif MCP (texte, description, priorité, tags, parent, dates, récurrence). La copie est non terminée et non archivée. includeChildren=true copie aussi les sous-tâches (reparentées sous la nouvelle tâche).',
    processor: CloudTodoMcpProcessor::class,
)]
final class CopyTodoTool
{
    public function __construct(
        public string $id = '',
        public bool $includeChildren = false,
    ) {
    }
}
