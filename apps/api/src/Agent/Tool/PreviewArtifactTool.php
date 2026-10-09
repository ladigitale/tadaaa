<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Service\ArtifactDocumentValidator;
use App\Service\JsonShape;

/**
 * `preview_artifact` (atelier Artefacts) : valide un document `artifacts/1` et, s'il
 * passe, l'envoie au panneau d'aperçu de l'atelier (`CUSTOM artifact-preview`).
 * Rien n'est enregistré : la publication reste une étape explicite.
 */
final class PreviewArtifactTool implements AgentTool
{
    public const EVENT = 'artifact-preview';

    public function __construct(
        private readonly ArtifactDocumentValidator $validator,
    ) {
    }

    public function name(): string
    {
        return 'preview_artifact';
    }

    public function description(): string
    {
        return 'Affiche un document artifacts/1 dans le panneau d’aperçu de l’atelier, sans le publier. '
            .'Renvoie {valid, errors} : corrige et rappelle tant que valid est faux. '
            .'À utiliser après chaque modification, avant de proposer la publication : '
            .'publish_preview enregistre ensuite le dernier aperçu valide.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['document' => ['type' => 'object', 'description' => 'Document complet {"schema": "artifacts/1", …}']],
            'required' => ['document'],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $document = $input['document'] ?? null;
        if (!\is_array($document)) {
            return ToolResult::json(['valid' => false, 'errors' => [['path' => '', 'message' => 'document : objet attendu.']]], true);
        }
        $result = $this->validator->validate($document);
        if (!$result['valid']) {
            return ToolResult::json($result, true);
        }
        $wire = $context->withEmptyObjects($document, 'document');
        $context->sink->emit(['type' => 'CUSTOM', 'name' => self::EVENT, 'value' => ['document' => $wire]]);
        // Dernier aperçu valide : c'est lui que publish_preview enregistre.
        $context->workspace['preview'] = [
            'document' => $document,
            'emptyObjects' => JsonShape::emptyObjectPaths(json_decode((string) json_encode($wire), false)),
        ];

        return ToolResult::json(['valid' => true, 'previewed' => true]);
    }
}
