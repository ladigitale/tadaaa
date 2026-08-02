<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'update_webhook',
    description: 'Met à jour un webhook (url, events, active, datasetId). Passer datasetId vide pour le rendre account-wide.',
    processor: CloudTodoMcpProcessor::class,
)]
final class UpdateWebhookTool
{
    /** @param list<string>|null $events */
    public function __construct(
        public string $id = '',
        public ?string $url = null,
        public ?array $events = null,
        public ?bool $active = null,
        public ?string $datasetId = null,
    ) {
    }
}
