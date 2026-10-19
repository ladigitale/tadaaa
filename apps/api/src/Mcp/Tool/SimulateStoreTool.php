<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'simulate_store',
    description: <<<'DESC'
Rejoue des actions sur le réducteur JSONata d'un store (data.stores.<nom> ou <sonic-store id>) sans navigateur. Fournir `document` (brouillon) OU `slug`/`id` (artefact enregistré), `store`, `actions` ([{type, payload?, repeat?}]).

Options : `until` (JSONata évalué sur l'état, arrêt quand vrai, ex. "phase='over'"), `maxSteps` (≤ 50000), `snapshots` (chemins pointés gardés à chaque étape, ex. ["phase","lives"]), `tickMs` (ajoute payload.t = étape × tickMs aux actions `tick`).

Retourne `{ok, steps, finalState, snapshots?, metrics:{msPerStep, maxMsStep, stateBytes}, errors}` : permet de vérifier qu'un réducteur ne plante pas, qu'une partie arrive à son terme, et de mesurer son coût par tick. Exécution bridée (8 s, 128 Mo, sans réseau ni fichiers). `$rand(seed)` est disponible.

Exemple :
```json
{"slug":"mon-jeu","store":"jeu","actions":[{"type":"start"},{"type":"tick","repeat":3000}],"until":"phase='over'","tickMs":33,"snapshots":["phase","score"]}
```
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class SimulateStoreTool
{
    /**
     * @param array<string, mixed>|null $document
     * @param list<mixed>|null          $actions
     * @param list<string>|null         $snapshots
     */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $document = null,
        public ?string $store = null,
        public ?array $actions = null,
        public ?string $until = null,
        public ?int $maxSteps = null,
        public ?array $snapshots = null,
        public ?int $tickMs = null,
    ) {
    }
}
