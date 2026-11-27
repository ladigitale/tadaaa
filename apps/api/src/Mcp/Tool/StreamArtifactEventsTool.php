<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'stream_artifact_events',
    description: <<<'DESC'
Pousse des messages dans le flux d'un run en cours (artefact dont le contrat déclare `io.stream`, run interactive ou embed lancé par run_artifact) : `runId`, `events` (64 au plus). Le viewer et les spectateurs (stream.watchUrl du run) les appliquent en direct.

Messages : `{"type":"action","name":"<action déclarée>","payload":…}` (réducteur du store) ; `{"type":"state.delta","patch":[JSON Patch sur l'état du store]}` / `{"type":"state.snapshot","state":{…}}` (si io.stream.state) ; `{"type":"document.patch","patch":[JSON Patch sur le document]}` (si io.stream.document ; validé comme une publication, /io intouchable) ; `{"type":"score","score":{duration, loop, speed, events:[{at, action, payload}], tracks:[{path:"/x", keys:[{at, value, ease}]}]}}` (si io.stream.score : partition jouée localement par chaque viewer, synchrone) ; `{"type":"score.stop"}` ; `{"type":"end"}` (fin : le viewer publie sa sortie). AG-UI accepté (CUSTOM, STATE_SNAPSHOT, STATE_DELTA).

Pour des évolutions longues ou rapides, préférer une partition (score) aux messages un par un : elle se joue à pleine vitesse chez le viewer. Débit max : io.stream.rate messages/s. Un émetteur sans compte utilise POST /api/public/artifacts/{slug}/runs/{runId}/events avec stream.emitToken (Bearer).
DESC,
    processor: ArtifactMcpProcessor::class,
    annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
)]
final class StreamArtifactEventsTool
{
    /**
     * @param list<array<string, mixed>>|null $events
     */
    public function __construct(
        public ?string $runId = null,
        public ?array $events = null,
    ) {
    }
}
