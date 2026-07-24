<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'create_link_detector',
    description: 'Ajoute un détecteur de liens au compte. pattern = regexp JS/PHP sans flags, 1er groupe = id ; urlTemplate doit contenir {id}. Ex. name="Redmine", pattern="RM-(\\\\d+)", urlTemplate="https://redmine.example/issues/{id}".',
    processor: CloudTodoMcpProcessor::class,
)]
final class CreateLinkDetectorTool
{
    public function __construct(
        public string $name = '',
        public string $pattern = '',
        public string $urlTemplate = '',
    ) {
    }
}
