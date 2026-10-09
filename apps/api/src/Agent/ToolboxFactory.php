<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\McpToolAdapter;
use App\Agent\Tool\PreviewArtifactTool;
use App\Agent\Tool\PublishPreviewTool;
use App\Agent\Tool\RenderUiTool;
use App\Agent\Tool\Toolbox;
use App\Mcp\Processor\ArtifactMcpProcessor;
use App\Mcp\Processor\CloudTodoMcpProcessor;
use App\Mcp\Tool;

/**
 * Outils de l'agent intégré, par profil :
 * - `tasks` : les outils MCP « tâches » de Tadaaa + render_ui ;
 * - `artifacts` (atelier) : les outils MCP « artefacts » (lecture, validation, métadonnées)
 *   + preview_artifact + publish_preview (enregistre le dernier aperçu) + render_ui.
 * Outils MCP exécutés dans le process, avec l'utilisateur courant. Pas d'outils
 * destructifs ni d'administration (suppression, webhooks, détecteurs, collecte).
 */
final class ToolboxFactory
{
    /** @var list<class-string> */
    public const MCP_TOOLS = [
        Tool\ListDatasetsTool::class,
        Tool\ActivateDatasetTool::class,
        Tool\ListTagsTool::class,
        Tool\ListTodosTool::class,
        Tool\CreateTodoTool::class,
        Tool\UpdateTodoTool::class,
        Tool\BulkUpdateTodosTool::class,
        Tool\CopyTodoTool::class,
        Tool\CreateTagTool::class,
    ];

    /** @var list<class-string> */
    public const ARTIFACT_TOOLS = [
        Tool\GetArtifactCatalogTool::class,
        Tool\ListArtifactsTool::class,
        Tool\GetArtifactTool::class,
        Tool\ValidateArtifactTool::class,
        // Métadonnées (titre, visibilité) ; le document passe par publish_preview.
        Tool\UpdateArtifactTool::class,
    ];

    public function __construct(
        private readonly CloudTodoMcpProcessor $todoProcessor,
        private readonly ArtifactMcpProcessor $artifactProcessor,
        private readonly RenderUiTool $renderUi,
        private readonly PreviewArtifactTool $previewArtifact,
        private readonly PublishPreviewTool $publishPreview,
    ) {
    }

    public function create(string $profile = AgentProfile::TASKS): Toolbox
    {
        if ($profile === AgentProfile::ARTIFACTS) {
            $tools = array_map(fn (string $class) => new McpToolAdapter($class, $this->artifactProcessor), self::ARTIFACT_TOOLS);
            $tools[] = $this->previewArtifact;
            $tools[] = $this->publishPreview;
        } else {
            $tools = array_map(fn (string $class) => new McpToolAdapter($class, $this->todoProcessor), self::MCP_TOOLS);
        }
        $tools[] = $this->renderUi;

        return new Toolbox($tools);
    }
}
