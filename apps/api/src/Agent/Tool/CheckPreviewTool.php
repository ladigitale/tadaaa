<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use ApiPlatform\Metadata\McpTool;
use ApiPlatform\State\ProcessorInterface;
use App\Mcp\Processor\ArtifactMcpProcessor;
use App\Mcp\Tool\RenderArtifactTool;
use App\Mcp\Tool\RunArtifactTool;
use App\Mcp\Tool\SimulateStoreTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `check_preview` (atelier Artefacts) : joue le brouillon courant dans le vrai viewer
 * (render_artifact) ou rejoue un réducteur (simulate_store), sans que le modèle renvoie
 * le document. Donne à l'agent de l'atelier ce que la validation ne voit pas : erreurs
 * console, JSONata, GLSL, actions refusées (budget du store), texte affiché, fps.
 *
 * La capture d'écran n'est pas transmise (résultats d'outil texte seulement) : le texte
 * affiché, les assertions et les erreurs en tiennent lieu.
 */
final class CheckPreviewTool implements AgentTool
{
    private readonly DraftStore $drafts;

    public function __construct(
        #[Autowire(service: ArtifactMcpProcessor::class)]
        private readonly ProcessorInterface $processor,
        ?DraftStore $drafts = null,
    ) {
        $this->drafts = $drafts ?? new DraftStore();
    }

    public function name(): string
    {
        return 'check_preview';
    }

    public function description(): string
    {
        return 'Contrôle le brouillon courant (dernier aperçu) sans renvoyer le document. '
            .'mode "render" (défaut) : le joue dans le vrai viewer (Chromium) et renvoie {ok, errors, warnings, '
            .'shaderErrors, assertions, text, perf} — options view, viewport {width,height} (390×844 téléphone, '
            .'1280×800 bureau), settleMs, fps, fullPage, steps (objets : {"click":"#id"}, {"press":"Enter"}, {"wait":ms}, '
            .'{"expectText":"Score"}, {"expectNoText":"undefined"}, {"waitText":"…","timeout":ms}). '
            .'mode "simulate" : rejoue des actions sur le réducteur d’un store — store, actions '
            .'([{type, payload?, repeat?}]), until (JSONata), snapshots, tickMs. '
            .'mode "io" : essaie le contrat io du brouillon comme un outil (headless) avec input (objet conforme à '
            .'io.input.schema) → {status, output, errors} ; à faire avant de publier un artefact qui déclare io. '
            .'À appeler après preview_artifact pour tout artefact interactif (jeu, filtres, animation, son, shader), '
            .'et avant de proposer la publication.';
    }

    public function inputSchema(): array
    {
        $objects = ['type' => 'array', 'items' => ['type' => 'object']];

        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['render', 'simulate', 'io']],
                'input' => ['type' => 'object', 'description' => 'mode io : entrée du contrat'],
                'view' => ['type' => 'string'],
                'viewport' => ['type' => 'object', 'properties' => ['width' => ['type' => 'integer'], 'height' => ['type' => 'integer']]],
                'settleMs' => ['type' => 'integer'],
                'fps' => ['type' => 'boolean'],
                'fullPage' => ['type' => 'boolean'],
                'steps' => $objects,
                'store' => ['type' => 'string'],
                'actions' => $objects,
                'until' => ['type' => 'string'],
                'maxSteps' => ['type' => 'integer'],
                'snapshots' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tickMs' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $draft = $this->drafts->current($context);
        if ($draft === null) {
            return ToolResult::json(['error' => 'Pas encore de brouillon : appelle d’abord preview_artifact.'], true);
        }
        $document = DraftStore::assoc($draft);
        $raw = (object) ['document' => $draft];
        $int = static fn (string $key): ?int => \is_int($input[$key] ?? null) ? $input[$key] : null;
        $str = static fn (string $key): ?string => \is_string($input[$key] ?? null) && $input[$key] !== '' ? $input[$key] : null;
        $list = static fn (string $key): ?array => \is_array($input[$key] ?? null) ? array_values($input[$key]) : null;

        if (($input['mode'] ?? 'render') === 'io') {
            $raw->input = json_decode((string) json_encode($input['input'] ?? new \stdClass()), false);
            $dto = new RunArtifactTool(input: \is_array($input['input'] ?? null) ? $input['input'] : [], document: $document);
        } elseif (($input['mode'] ?? 'render') === 'simulate') {
            $dto = new SimulateStoreTool(
                document: $document,
                store: $str('store'),
                actions: $list('actions'),
                until: $str('until'),
                maxSteps: $int('maxSteps'),
                snapshots: $list('snapshots'),
                tickMs: $int('tickMs'),
            );
        } else {
            $dto = new RenderArtifactTool(
                document: $document,
                view: $str('view'),
                viewport: \is_array($input['viewport'] ?? null) ? $input['viewport'] : null,
                steps: $list('steps'),
                settleMs: $int('settleMs'),
                fps: \is_bool($input['fps'] ?? null) ? $input['fps'] : null,
                fullPage: \is_bool($input['fullPage'] ?? null) ? $input['fullPage'] : null,
            );
        }

        try {
            $result = $this->processor->process($dto, $this->metadata($dto), [], ['raw_arguments' => $raw]);
        } catch (\Throwable $e) {
            return ToolResult::json(['error' => $e->getMessage()], true);
        }
        if (!$result instanceof CallToolResult) {
            return ToolResult::json(\is_array($result) ? $result : ['result' => $result]);
        }
        $texts = [];
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $texts[] = (string) $content->text;
            }
        }

        return new ToolResult(implode("\n", $texts), $result->isError);
    }

    private function metadata(object $dto): McpTool
    {
        return (new \ReflectionClass($dto))->getAttributes(McpTool::class)[0]->newInstance();
    }
}
