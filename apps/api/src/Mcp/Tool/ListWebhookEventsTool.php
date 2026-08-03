<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_webhook_events',
    description: 'Liste le catalogue d’événements webhook normés (types découvrables pour create_webhook).',
    processor: CloudTodoMcpProcessor::class,
)]
final class ListWebhookEventsTool
{
}
