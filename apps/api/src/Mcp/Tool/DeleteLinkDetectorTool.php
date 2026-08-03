<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'delete_link_detector',
    description: 'Supprime un détecteur de liens du compte.',
    processor: CloudTodoMcpProcessor::class,
)]
final class DeleteLinkDetectorTool
{
    public function __construct(
        public string $id = '',
    ) {
    }
}
