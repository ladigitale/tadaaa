<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_webhook_deliveries',
    description: 'Liste les derniers appels (succès / échecs) d’un webhook.',
    processor: CloudTodoMcpProcessor::class,
)]
final class ListWebhookDeliveriesTool
{
    public function __construct(
        public string $id = '',
        public int $limit = 50,
    ) {
    }
}
