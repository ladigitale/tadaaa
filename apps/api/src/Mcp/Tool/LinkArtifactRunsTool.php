<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'link_artifact_runs',
    description: <<<'DESC'
Relie deux runs en flux : chaque élément de la file d'envoi du run source (`io.stream.emit` = {from: "store.file", ack?: "store"} ; le viewer du run publie les éléments {id, data} de la file) est poussé dans le flux du run cible, comme par un émetteur (mêmes validations, même débit). Un artefact « télécommande » pilote ainsi un autre artefact (scène procgen, shader, régie…) sans qu'aucun jeton ne passe dans le navigateur.

- `fromRunId`, `toRunId` : runs en cours (interactive ou embed) lancés par run_artifact, dans des jeux où l'on peut écrire.
- `map` (JSONata, facultatif) : évalué sur chaque élément {id, data, from: {slug, runId}} ; résultat = un message du flux cible ({type: "action", name, payload}, state.delta, document.patch, score…), une liste de messages, ou rien (élément ignoré). Sans map : `data` doit déjà être un message.
  Exemple : `data.pad = "applaudir" ? {"type": "action", "name": "reaction", "payload": {"n": 20}}`

Retourne le lien (linkId, compteurs forwarded / failed, lastError). Le lien s'arrête quand l'un des runs se termine ; get_artifact_run montre les liens d'un run ; unlink_artifact_runs le coupe. 8 liens actifs au plus par run source (une télécommande peut piloter plusieurs scènes).
DESC,
    processor: ArtifactMcpProcessor::class,
    annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
)]
final class LinkArtifactRunsTool
{
    public function __construct(
        public ?string $fromRunId = null,
        public ?string $toRunId = null,
        public ?string $map = null,
    ) {
    }
}
