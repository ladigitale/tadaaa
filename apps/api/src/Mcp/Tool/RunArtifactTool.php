<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;
use App\Mcp\Resource\ArtifactRunnerUiResource;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'run_artifact',
    description: <<<'DESC'
Exécute la version en ligne d'un artefact qui déclare un contrat `io` (artefact utilisé comme outil) : `id` ou `slug`, `input` (objet conforme à io.input.schema), `mode` (défaut headless ; doit figurer dans io.modes).

- headless : l'entrée est envoyée au store d'entrée comme action `io:input` (ou io.input.action), le réducteur JSONata tourne sans navigateur (actions de io.headless rejouées ensuite) jusqu'à io.output.when ; la sortie est lue dans io.output.from et validée par io.output.schema. Statut final immédiat : completed | incomplete | failed.
- interactive : un humain fait l'étape. Renvoie `{runId, status: "pending", url, runToken, expiresAt}` : donner `url` à la personne ; le viewer envoie l'entrée au store, puis publie la sortie quand io.output.when devient vrai. Suivre avec get_artifact_run (ou le webhook artifact.run.completed). Valable 24 h.
- embed : dans un hôte qui affiche les MCP Apps (Claude, ChatGPT…), l'artefact s'affiche dans la conversation (interface ui://tadaaa/artifact-runner) : la personne fait l'étape, la sortie revient au modèle en message. Ailleurs, comme interactive pour une page hôte : `<artifact-embed slug="…" run="<runId>" run-token="<runToken>">` ; la sortie est aussi émise en événement DOM `artifact-output`.

Essai d'un brouillon avant publication : `document` (document complet) + `input`, mode headless, rien n'est enregistré (`dryRun: true`).

Le run est enregistré (get_artifact_run) et déclenche les webhooks artifact.run.completed / artifact.run.failed. Contrats disponibles : list_artifact_tools.
DESC,
    processor: ArtifactMcpProcessor::class,
    // MCP Apps : interface affichée par l'hôte avec le résultat (clé plate gardée pour les hôtes antérieurs).
    meta: ['ui' => ['resourceUri' => ArtifactRunnerUiResource::URI], 'ui/resourceUri' => ArtifactRunnerUiResource::URI],
)]
final class RunArtifactTool
{
    /**
     * @param array<string, mixed>|null $input
     * @param array<string, mixed>|null $document
     */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $input = null,
        public ?string $mode = null,
        public ?array $document = null,
    ) {
    }
}
