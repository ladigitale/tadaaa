<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'open_artifact_intake',
    description: 'Ouvre (ou prolonge) une collecte contrôlée sur une collection déclarée avec `intake` dans data.sources. Les envois anonymes ne sont acceptés que pendant `minutes` (1…1440, défaut 60), dans la limite de `maxRecords` (défaut : celui du schéma, plafond 500). Retourne l’état, le code de session si `requireCode`, et `readToken` (lien secret de lecture : ajouter `&rk=<readToken>` à l’URL de l’artefact).',
    processor: ArtifactMcpProcessor::class,
)]
final class OpenArtifactIntakeTool
{
    public function __construct(
        public string $slug = '',
        public string $collection = '',
        public ?int $minutes = null,
        public ?int $maxRecords = null,
    ) {
    }
}
