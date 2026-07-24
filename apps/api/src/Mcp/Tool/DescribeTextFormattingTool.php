<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'describe_text_formatting',
    description: 'Guide de formatage des descriptions de tâches pour agents : Markdown basique supporté à l’affichage web, et jetons transformés en liens via les détecteurs du compte (list_link_detectors / create_link_detector).',
    processor: CloudTodoMcpProcessor::class,
)]
final class DescribeTextFormattingTool
{
    public function __construct()
    {
    }
}
