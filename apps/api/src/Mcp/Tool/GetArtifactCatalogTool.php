<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact_catalog',
    description: <<<'DESC'
Retourne le catalogue Concorde autorisé, le squelette d’enveloppe `artifacts/1` et des exemples valides.

Par défaut `compact=true` (props communes une fois + props spécifiques, < 8k tokens).
`compact=false` pour le catalogue complet.
`components` : filtre optionnel (ex. ["sonic-store","sonic-shader"]).
À appeler avant de composer un artefact SDUI.
Lire `rules.polices` et `rules.icones` : polices Google Fonts adaptées au thème et vraie police d’icônes (Material Symbols) par défaut.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactCatalogTool
{
    /**
     * @param list<string>|null $components
     */
    public function __construct(
        public bool $compact = true,
        public ?array $components = null,
    ) {
    }
}
