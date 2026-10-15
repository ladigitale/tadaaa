<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'build_artifact_from_kit',
    description: <<<'DESC'
Construit un document `artifacts/1` à partir d’un kit et de ses seuls paramètres (bien moins long à écrire qu’un document complet). Kits et signatures : clé `kits` de `get_artifact_catalog`.

Retourne `{valid, errors, document}` : le document est validé ; le publier ensuite avec `publish_artifact` (ou l’ajuster avant).

Exemple : `{"kit": "quiz", "params": {"title": "Capitales", "timer": 10, "speed": true, "questions": [{"question": "Capitale du Japon ?", "answers": ["Kyoto", "Tokyo"], "correct": 1}]}}`
Avec preset : `{"kit": "grid", "params": {"preset": "snake"}}`
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class BuildArtifactFromKitTool
{
    /** @param array<string, mixed>|null $params */
    public function __construct(
        public string $kit = '',
        public ?array $params = null,
    ) {
    }
}
