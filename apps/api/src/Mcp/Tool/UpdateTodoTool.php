<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'update_todo',
    description: 'Met à jour une tâche (done, archived, text, priority, tagIds, parentId, startAt, endAt, recurrence…). description : Markdown basique + jetons des détecteurs de liens du compte (describe_text_formatting). startAt/endAt : YYYY-MM-DD (journée entière) ou ISO UTC optionnels. recurrence : none|daily|weekly|monthly — marquer done=true sur une tâche récurrente crée la prochaine occurrence.',
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
        public ?string $recurrence = null,
    ) {
    }
}
