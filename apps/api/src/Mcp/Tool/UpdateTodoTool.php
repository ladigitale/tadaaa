<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'update_todo',
    description: 'Met à jour une tâche (done, archived, text, priority, tagIds, parentId, startAt, endAt…). description : Markdown basique + jetons des détecteurs de liens du compte (describe_text_formatting). startAt/endAt : dates YYYY-MM-DD optionnelles.',
    processor: CloudTodoMcpProcessor::class,
)]
final class UpdateTodoTool
{
    /** @param list<string>|null $tagIds */
    public function __construct(
        public string $id = '',
        public ?string $text = null,
        public ?string $description = null,
        public ?bool $done = null,
        public ?bool $archived = null,
        public ?string $priority = null,
        public ?array $tagIds = null,
        public ?string $parentId = null,
        public ?string $startAt = null,
        public ?string $endAt = null,
    ) {
    }
}
