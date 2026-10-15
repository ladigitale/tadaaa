<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Service\JsonPatch;

/**
 * `edit_preview` (atelier Artefacts) : modifie le brouillon courant par JSON Patch
 * (RFC 6902) au lieu de renvoyer tout le document. Le résultat est validé puis affiché
 * comme preview_artifact ; invalide, le brouillon reste inchangé.
 */
final class EditPreviewTool implements AgentTool
{
    public function __construct(
        private readonly PreviewArtifactTool $preview,
        private readonly DraftStore $drafts,
    ) {
    }

    public function name(): string
    {
        return 'edit_preview';
    }

    public function description(): string
    {
        return 'Modifie le brouillon courant (dernier aperçu valide, ou artefact en cours d’édition) par opérations '
            .'JSON Patch, puis l’affiche. Bien moins coûteux que de renvoyer le document : à préférer pour toute '
            .'modification. Chemins JSON Pointer depuis la racine du document, ex. '
            .'{"op":"replace","path":"/views/0/title","value":"Quiz"}, {"op":"add","path":"/data/stores/quiz/initial/items/-","value":{…}}, '
            .'{"op":"remove","path":"/views/1"}. Utilise read_preview pour connaître la structure. '
            .'Renvoie {valid, errors} ; si invalide, rien n’est appliqué.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ops' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'op' => ['type' => 'string', 'enum' => ['add', 'remove', 'replace', 'move', 'copy', 'test']],
                            'path' => ['type' => 'string'],
                            'from' => ['type' => 'string'],
                            'value' => new \stdClass(),
                        ],
                        'required' => ['op', 'path'],
                    ],
                ],
            ],
            'required' => ['ops'],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $draft = $this->drafts->current($context);
        if ($draft === null) {
            return ToolResult::json(['error' => 'Pas encore de brouillon : crée une première version avec preview_artifact.'], true);
        }
        // Opérations brutes (objets vides gardés) si le client LLM les fournit.
        $ops = $context->rawInput?->ops ?? json_decode((string) json_encode($input['ops'] ?? null), false);
        if (!\is_array($ops) || $ops === []) {
            return ToolResult::json(['error' => 'ops : liste d’opérations attendue.'], true);
        }
        try {
            $patched = JsonPatch::apply($draft, $ops);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::json(['valid' => false, 'errors' => [['path' => '', 'message' => $e->getMessage()]]], true);
        }
        if (!$patched instanceof \stdClass) {
            return ToolResult::json(['valid' => false, 'errors' => [['path' => '', 'message' => 'Le document doit rester un objet.']]], true);
        }

        return $this->preview->show($patched, $context);
    }
}
