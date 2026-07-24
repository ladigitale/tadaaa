<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'list_link_detectors',
    description: 'Liste les détecteurs de liens du compte. Chaque détecteur transforme un jeton (regexp + groupe capturant) en URL ({id}) dans le texte et les descriptions des tâches (affichage web).',
    processor: CloudTodoMcpProcessor::class,
)]
final class ListLinkDetectorsTool
{
    public function __construct()
    {
    }
}
