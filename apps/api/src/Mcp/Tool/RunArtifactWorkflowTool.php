<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactWorkflowMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'run_artifact_workflow',
    description: <<<'DESC'
Lance un workflow d'artefacts : `slug`, `input` (objet conforme à definition.input.schema). Les étapes headless s'exécutent aussitôt ; à une étape interactive (ou embed) le workflow passe en `waiting` : `waiting.url` est la page à donner à la personne (24 h), le workflow reprend tout seul quand elle a terminé. Retourne {workflowRunId, status: running|waiting|completed|failed|cancelled, steps, output, waiting?, errors}. Suivre avec get_artifact_workflow_run ou les webhooks artifact.workflow.waiting / completed / failed.
DESC,
    processor: ArtifactWorkflowMcpProcessor::class,
)]
final class RunArtifactWorkflowTool
{
    /**
     * @param array<string, mixed>|null $input
     */
    public function __construct(
        public ?string $slug = null,
        public ?array $input = null,
    ) {
    }
}
