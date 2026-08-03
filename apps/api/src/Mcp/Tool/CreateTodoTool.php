<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'create_todo',
    description: 'Crée une tâche dans le jeu cloud actif MCP. description accepte du Markdown basique (**bold**, *italic*, `code`, [lien](url), listes) rendu dans l’UI web ; les jetons des détecteurs de liens du compte (voir describe_text_formatting / list_link_detectors) deviennent des liens cliquables. startAt/endAt : dates optionnelles YYYY-MM-DD (journée entière) ou ISO UTC (ex. 2026-08-03T14:30:00Z). recurrence : none|daily|weekly|monthly (à la complétion, crée la prochaine occurrence).',
    processor: CloudTodoMcpProcessor::class,
)]
final class CreateTodoTool
{
    /** @param list<string>|null $tagIds */
    public function __construct(
        public string $text = '',
        public ?string $description = null,
        public string $priority = 'medium',
        public ?array $tagIds = null,
        public ?string $parentId = null,
        public ?string $startAt = null,
        public ?string $endAt = null,
        public string $recurrence = 'none',
    ) {
    }
}
