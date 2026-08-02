<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_webhook',
    description: 'Désinscrit (supprime) un webhook par id.',
    processor: CloudTodoMcpProcessor::class,
)]
final class DeleteWebhookTool
{
    public function __construct(
        public string $id = '',
    ) {
    }
}
