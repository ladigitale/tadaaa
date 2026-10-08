<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\McpToolAdapter;
use App\Agent\Tool\RenderUiTool;
use App\Agent\Tool\Toolbox;
use App\Mcp\Processor\CloudTodoMcpProcessor;
use App\Mcp\Tool;

/**
 * Outils de l'agent intégré : les outils MCP « tâches » de Tadaaa (exécutés dans le
 * process, avec l'utilisateur courant) + render_ui. Pas d'outils destructifs ni
 * d'administration (webhooks, détecteurs, suppression de tags) dans cette première version.
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

    public function __construct(
        private readonly CloudTodoMcpProcessor $todoProcessor,
        private readonly RenderUiTool $renderUi,
    ) {
    }

    public function create(): Toolbox
    {
        $tools = array_map(fn (string $class) => new McpToolAdapter($class, $this->todoProcessor), self::MCP_TOOLS);
        $tools[] = $this->renderUi;

        return new Toolbox($tools);
    }
}
