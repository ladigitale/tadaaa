<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Service\ArtifactKits;

/**
 * `start_from_kit` (atelier Artefacts) : construit un document à partir d'un kit (quiz,
 * jeu de grille, shader, page, sondage…) et de ses paramètres, puis l'affiche comme
 * preview_artifact. Le modèle n'écrit que les paramètres ; la suite passe par edit_preview.
 */
final class StartFromKitTool implements AgentTool
{
    public function __construct(
        private readonly ArtifactKits $kits,
        private readonly PreviewArtifactTool $preview,
    ) {
    }

    public function name(): string
    {
        return 'start_from_kit';
    }

    public function description(): string
    {
        return 'Crée le brouillon à partir d’un kit (voir « Kits » dans tes instructions) : tu ne donnes que les '
            .'paramètres, le kit fournit la mise en page et le câblage. Le document est validé et affiché. '
            .'Pour un besoin proche mais pas identique, pars du kit puis ajuste avec edit_preview. '
            .'Renvoie {valid, errors} ; les erreurs de paramètres indiquent le chemin à corriger.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kit' => ['type' => 'string', 'enum' => $this->kits->ids()],
                'params' => ['type' => 'object', 'description' => 'Paramètres du kit (signature dans tes instructions)'],
            ],
            'required' => ['kit', 'params'],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $kit = \is_string($input['kit'] ?? null) ? $input['kit'] : '';
        // Paramètres bruts (objets vides gardés) si le client LLM les fournit.
        $params = $context->rawInput?->params ?? json_decode((string) json_encode($input['params'] ?? new \stdClass()), false);
        $built = $this->kits->build($kit, $params);
        if ($built['document'] === null) {
            return ToolResult::json(['valid' => false, 'errors' => array_map(
                static fn (string $m): array => ['path' => 'params', 'message' => $m],
                $built['errors'],
            )], true);
        }
        $result = $this->preview->show($built['document'], $context);
        if (!$result->isError) {
            return ToolResult::json(['valid' => true, 'previewed' => true, 'kit' => $kit,
                'next' => 'Pour ajuster : read_preview (plan) puis edit_preview.']);
        }

        return $result;
    }
}
