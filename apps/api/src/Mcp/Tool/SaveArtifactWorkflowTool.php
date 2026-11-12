<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactWorkflowMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'save_artifact_workflow',
    description: <<<'DESC'
Crée ou remplace un workflow d'artefacts : enchaînement d'artefacts-outils (contrat io), la sortie d'une étape alimentant la suivante. `slug` + `definition` :
{title?, description?, input?: {schema}, steps: [{id, artifact (slug), mode? (headless|interactive|embed, défaut headless), input? (JSONata), if? (JSONata), optional?, title?}], output? (JSONata)}.
Expressions JSONata évaluées sur {input, steps: {<id>: {status, output, files}}, previous} ; input absent : l'entrée du workflow pour la 1re étape, previous ensuite. Une étape `if` fausse est sautée ; une étape `optional` en échec n'arrête pas le workflow. Vérifie la syntaxe JSONata, que chaque artefact existe, déclare io et le mode demandé (version en ligne). 20 étapes max. Lancer : run_artifact_workflow.
DESC,
    processor: ArtifactWorkflowMcpProcessor::class,
)]
final class SaveArtifactWorkflowTool
{
    /**
     * @param array<string, mixed>|null $definition
     */
    public function __construct(
        public ?string $slug = null,
        public ?array $definition = null,
    ) {
    }
}
