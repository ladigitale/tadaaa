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

Par défaut `compact=true` (props communes une fois, puis props spécifiques avec type et valeurs permises ; descriptions et défauts dans le catalogue complet).
`compact=false` pour le catalogue complet (avec descriptions des props et valeurs par défaut).
`components` : filtre optionnel (ex. ["sonic-store","sonic-shader"]).
`rules` : filtre optionnel des règles longues (ex. ["a2ui"], ["son","audio"], ["scripts"], ["io"] pour un artefact utilisé comme outil (contrat d’entrées / sorties, run_artifact)) ; les règles courtes sont toujours là.
Compétences de conception, à demander AVANT d'écrire une interface visible : `rules=["design", "<archétype>"]` — `design` (fondations vérifiées : style inline, jetons, palettes de zone, brief, checklist) puis UN archétype : `vitrine` (site, landing, one-page, récit, diaporama), `jeu` (plein écran, arcade, plateau, physique, 3D), `outil` (interface experte, filtres, liste/détail, formulaires), `dashboard` (KPI, graphiques sans bibliothèque), `experience` (art génératif, audio, caméra, IA, instruments) ; en plus si besoin `multivue` (onglets, modal, état partagé) et `carte` (sonic-map). Elles arrivent dans `skills`, jamais par défaut.
`examples=false` : sans les exemples de documents.
À appeler avant de composer un artefact SDUI.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class GetArtifactCatalogTool
{
    /**
     * @param list<string>|null $components
     * @param list<string>|null $rules
     */
    public function __construct(
        public bool $compact = true,
        public ?array $components = null,
        public ?array $rules = null,
        public bool $examples = true,
    ) {
    }
}
