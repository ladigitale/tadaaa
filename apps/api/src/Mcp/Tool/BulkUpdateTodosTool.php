<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'bulk_update_todos',
    description: 'Applique un patch (done, archived, priority, tagIds…) à toutes les tâches correspondant au filtre.',
    processor: CloudTodoMcpProcessor::class,
)]
final class BulkUpdateTodosTool
{
    /** @param list<string>|null $tagIds */
    public function __construct(
        public string $status = 'all',
        public ?string $q = null,
        public ?bool $done = null,
        public ?bool $archived = null,
        public ?string $priority = null,
        public ?array $tagIds = null,
        public int $limit = 100,
    ) {
    }
}
