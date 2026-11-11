<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'run_artifact',
    description: <<<'DESC'
Exécute un artefact qui déclare un contrat `io` (artefact utilisé comme outil) : `id` ou `slug`, `input` (objet conforme à io.input.schema), `mode` (défaut headless, seul mode exécuté par l'API pour l'instant).

Headless : l'entrée est envoyée au store d'entrée comme action `io:input` (ou io.input.action), le réducteur JSONata tourne sans navigateur (actions de io.headless rejouées ensuite) jusqu'à io.output.when, puis la sortie est lue dans io.output.from et validée par io.output.schema.

Retourne `{runId, status: completed|incomplete|failed, output, errors, metrics}` ; le run est enregistré (get_artifact_run) et déclenche les webhooks artifact.run.completed / artifact.run.failed. Contrats disponibles : list_artifact_tools.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class RunArtifactTool
{
    /**
     * @param array<string, mixed>|null $input
     */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $input = null,
        public ?string $mode = null,
    ) {
    }
}
