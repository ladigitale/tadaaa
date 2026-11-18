<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\OpsMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_artifact_validation_stats',
    description: <<<'DESC'
Super admin uniquement. Mesure de ce que les agents réussissent ou ratent en écrivant des artefacts :
part de documents valides et règles enfreintes, par origine.

- `days` : fenêtre en jours (1–90, défaut 7).
- `source` : `validate` (validate_artifact), `save` (création / mise à jour) ou `atelier` (aperçu de l'atelier) ; absent = toutes.

Retourne par origine `{total, valid, validRate, errors: {règle: documents refusés}, warnings: {avis: documents valides concernés}, byDay}`.
Une règle compte une fois par document. Règles : `refus:<balise>` (balise remplacée par une recette), `hors-liste:<balise>`
(balise inventée), `avis:pointerdown`, `avis:onglet-par-action`, `avis:champs-en-vrac`, sinon le message de validation sans ses valeurs.
Seuls des compteurs sont gardés : ni document ni utilisateur.
DESC,
    processor: OpsMcpProcessor::class,
    security: "is_granted('ROLE_ADMIN')",
)]
final class GetArtifactValidationStatsTool
{
    public function __construct(
        public ?int $days = null,
        public ?string $source = null,
    ) {
    }
}
