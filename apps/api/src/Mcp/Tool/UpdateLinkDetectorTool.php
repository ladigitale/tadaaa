<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'update_link_detector',
    description: 'Met à jour un détecteur de liens du compte (name, pattern, urlTemplate). Voir list_link_detectors.',
    processor: CloudTodoMcpProcessor::class,
)]
final class UpdateLinkDetectorTool
{
    public function __construct(
        public string $id = '',
        public ?string $name = null,
        public ?string $pattern = null,
        public ?string $urlTemplate = null,
    ) {
    }
}
