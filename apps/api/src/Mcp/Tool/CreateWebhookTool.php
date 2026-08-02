<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'create_webhook',
    description: 'Inscrit un webhook HTTP. url http(s) requis. events : liste de types (vide = tous). datasetId optionnel pour restreindre à un jeu. Retourne plainSecret une seule fois (HMAC X-Tadaaa-Signature).',
    processor: CloudTodoMcpProcessor::class,
)]
final class CreateWebhookTool
{
    /** @param list<string>|null $events */
    public function __construct(
        public string $url = '',
        public ?array $events = null,
        public ?string $datasetId = null,
    ) {
    }
}
