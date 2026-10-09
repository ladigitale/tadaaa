<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\A2ui;
use App\Service\ArtifactA2uiValidator;

/**
 * `render_ui` : l'agent affiche une interface A2UI v0.9 dans la conversation.
 *
 * Le modèle ne fournit que des composants (et éventuellement des données) ; le serveur
 * construit les messages A2UI, les valide avec les mêmes règles que les artefacts, puis
 * les émet en `CUSTOM {name: "a2ui"}`. Une erreur de validation revient au modèle comme
 * résultat d'outil, pour qu'il corrige lui-même.
 */
final class RenderUiTool implements AgentTool
{
    public function __construct(
        private readonly ArtifactA2uiValidator $validator,
    ) {
    }

    public function name(): string
    {
        return 'render_ui';
    }

    public function description(): string
    {
        return 'Affiche une interface dans la conversation (liste, carte, formulaire, confirmation) au format A2UI v0.9. '
            .'components : liste plate de composants {id, component, …props} ; un composant d’id "root" est obligatoire. '
            .'data : data model initial (objet), lu par {"path": "/…"}. '
            .'surfaceId : pour remplacer une interface déjà affichée (renvoyé par un appel précédent) ; sinon une nouvelle est créée. '
            .'Les clics sur action.event reviennent comme message de l’utilisateur. Voir les règles UI du prompt système.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'components' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'component' => ['type' => 'string', 'enum' => ArtifactA2uiValidator::SUPPORTED_COMPONENTS],
                        ],
                        'required' => ['id', 'component'],
                    ],
                ],
                'data' => ['type' => 'object'],
                'surfaceId' => ['type' => 'string'],
            ],
            'required' => ['components'],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $components = $input['components'] ?? null;
        if (!\is_array($components) || !array_is_list($components) || $components === []) {
            return ToolResult::json(['error' => 'components : liste non vide attendue.'], true);
        }
        $existing = \is_string($input['surfaceId'] ?? null) && $input['surfaceId'] !== '';
        $surfaceId = $existing ? (string) $input['surfaceId'] : $context->nextId('ui');

        $messages = [];
        if (!$existing) {
            $messages[] = A2ui::message('createSurface', ['surfaceId' => $surfaceId, 'catalogId' => ArtifactA2uiValidator::BASIC_CATALOG_ID]);
        }
        $messages[] = A2ui::message('updateComponents', ['surfaceId' => $surfaceId, 'components' => $context->withEmptyObjects($components, 'components')]);
        if (\array_key_exists('data', $input) && $input['data'] !== null) {
            if (!\is_array($input['data'])) {
                return ToolResult::json(['error' => 'data : objet attendu.'], true);
            }
            $messages[] = A2ui::message('updateDataModel', ['surfaceId' => $surfaceId, 'path' => '/', 'value' => (object) $context->withEmptyObjects($input['data'], 'data')]);
        }

        // Une surface existante a été créée par un appel précédent : on la recrée pour la validation seulement.
        $toValidate = $existing
            ? [A2ui::message('createSurface', ['surfaceId' => $surfaceId, 'catalogId' => ArtifactA2uiValidator::BASIC_CATALOG_ID]), ...$messages]
            : $messages;
        $errors = [];
        $nodes = 0;
        $this->validator->validate(json_decode(json_encode($toValidate, JSON_THROW_ON_ERROR), true), '', $errors, $nodes);
        if (!$this->hasRoot($components) && !$existing) {
            $errors[] = ['path' => '/components', 'message' => 'Un composant d’id "root" est requis.'];
        }
        if ($errors !== []) {
            return ToolResult::json(['error' => 'Interface refusée, corrige et rappelle render_ui.', 'details' => $errors], true);
        }

        $context->sink->emit(['type' => 'CUSTOM', 'name' => 'a2ui', 'value' => $messages]);

        return ToolResult::json(['surfaceId' => $surfaceId, 'rendered' => \count($components)]);
    }

    /** @param list<mixed> $components */
    private function hasRoot(array $components): bool
    {
        foreach ($components as $c) {
            if (\is_array($c) && ($c['id'] ?? null) === 'root') {
                return true;
            }
        }

        return false;
    }
}
