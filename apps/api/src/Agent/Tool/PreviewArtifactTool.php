<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Service\ArtifactDocumentValidator;

/**
 * `preview_artifact` (atelier Artefacts) : valide un document `artifacts/1` et, s'il
 * passe, l'envoie au panneau d'aperçu de l'atelier (`CUSTOM artifact-preview`).
 * Rien n'est enregistré : la publication reste une étape explicite.
 */
final class PreviewArtifactTool implements AgentTool
{
    public const EVENT = 'artifact-preview';

    private readonly DraftStore $drafts;

    public function __construct(
        private readonly ArtifactDocumentValidator $validator,
        ?DraftStore $drafts = null,
    ) {
        $this->drafts = $drafts ?? new DraftStore();
    }

    public function name(): string
    {
        return 'preview_artifact';
    }

    public function description(): string
    {
        return 'Affiche un document artifacts/1 dans le panneau d’aperçu de l’atelier, sans le publier. '
            .'Renvoie {valid, errors} : corrige et rappelle tant que valid est faux. '
            .'Pour une première version ou une refonte ; pour modifier, préfère edit_preview (patch). '
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
        // Forme objets (`{}` gardés) : arguments bruts du modèle si le client LLM les fournit.
        $raw = $context->rawInput?->document ?? null;
        $draft = $raw instanceof \stdClass ? $raw : json_decode((string) json_encode((object) $document), false);

        return $this->show($draft, $context);
    }

    /**
     * Valide un brouillon ; s'il passe, l'affiche dans l'atelier et le garde comme
     * brouillon courant. Sinon rien ne change et les erreurs reviennent au modèle.
     */
    public function show(\stdClass $draft, ToolContext $context): ToolResult
    {
        $result = $this->validator->validate(DraftStore::assoc($draft));
        if (!$result['valid']) {
            return ToolResult::json($result, true);
        }
        $context->sink->emit(['type' => 'CUSTOM', 'name' => self::EVENT, 'value' => ['document' => $draft]]);
        $this->drafts->save($context, $draft);

        return ToolResult::json(['valid' => true, 'previewed' => true]);
    }
}
