<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_webhooks',
    description: 'Liste les webhooks HTTP du compte (URL, events, dataset optionnel, échecs).',
    processor: CloudTodoMcpProcessor::class,
)]
final class ListWebhooksTool
{
}
