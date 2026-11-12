<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\CloudTodoMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'create_webhook',
    description: 'Inscrit un webhook HTTP. url http(s) requis. events : liste de types (vide = tous). datasetId optionnel pour restreindre à un jeu. Retourne une seule fois plainSecret (HMAC de l’en-tête X-Tadaaa-Signature) et standardSecret (whsec_…, pour les bibliothèques Standard Webhooks : en-têtes webhook-id, webhook-timestamp, webhook-signature).',
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
