<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'validate_artifact',
    description: <<<'DESC'
Valide un document SDUI sans l’enregistrer. Retourne `{valid, errors:[{path, message}]}`.

Exemple (erreur volontaire) :
```json
{"document":{"schema":"artifacts/1","title":"X","defaultView":"home","views":[{"id":"home","title":"H","root":{"nodes":[{"tagName":"evil-unknown"}]}}]}}
```
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class ValidateArtifactTool
{
    /** @param array<string, mixed>|null $document */
    public function __construct(
        public ?array $document = null,
    ) {
    }
}
