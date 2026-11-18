<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Règles d'interface des artefacts : balises refusées avec la recette qui les remplace,
 * et avertissements sur les constructions qui contournent les composants Concorde.
 *
 * Les recettes nommées sont dans config/artifacts/recipes.json (get_artifact_catalog rules=["recettes"]).
 */
final class ArtifactUiRules
{
    private const SELECTEUR = 'Liste de choix = recette « selecteur » (sonic-pop + sonic-menu + sonic-menu-item radio sur un DataProvider) ; 2 à 5 options visibles = recette « segments ». Détail : get_artifact_catalog rules=["recettes"].';

    /**
     * Balises refusées, qu'elles soient dans le catalogue ou non : message = la voie à suivre.
     *
     * @var array<string, string>
     */
    public const REPLACED_TAGS = [
        'sonic-select' => 'sonic-select est refusé dans un artefact. '.self::SELECTEUR,
        'select' => 'select natif refusé. '.self::SELECTEUR,
        'option' => 'option natif refusé. '.self::SELECTEUR,
        'button' => 'button natif refusé : utiliser sonic-button (type = couleur : default, primary, neutral… ; variant ; size). Un choix = sonic-button radio formDataProvider/name/value (recette « segments ») ; une action = sonic-action target/type autour du sonic-button.',
        'input' => 'input natif refusé : utiliser sonic-input (label, name, formDataProvider), sonic-checkbox, sonic-radio ou sonic-switch, rangés dans sonic-fieldset + sonic-form-layout (recette « formulaire »).',
        'textarea' => 'textarea natif refusé : utiliser sonic-textarea (label, name, formDataProvider), rangé dans sonic-fieldset + sonic-form-layout (recette « formulaire »).',
        'form' => 'form natif refusé : les champs Concorde écrivent dans un DataProvider (formDataProvider + name), rangés dans sonic-fieldset + sonic-form-layout (recette « formulaire »).',
    ];

    private const FIELD_TAGS = ['sonic-input', 'sonic-textarea', 'sonic-checkbox', 'sonic-radio', 'sonic-switch', 'sonic-input-autocomplete'];

    /** Composants qui font d'un document un jeu ou un instrument : le déclenchement à l'appui y est légitime. */
    private const REALTIME_TAGS = ['sonic-keyboard', 'sonic-gamepad', 'sonic-ticker', 'sonic-physics', 'sonic-sound', 'sonic-sfx', 'sonic-patch', 'sonic-voice', 'sonic-sampler', 'sonic-midi-in', 'sonic-midi-out'];

    private const TAB_ACTION = '/^(set[-_]?|select[-_]?|show[-_]?|go[-_]?|change[-_]?|choisir[-_]?|voir[-_]?)?(tab|tabs|onglet|panel|panneau)s?$/i';

    public static function replacedTagMessage(string $tagName): ?string
    {
        return self::REPLACED_TAGS[strtolower($tagName)] ?? null;
    }

    /**
     * @param array<string, mixed> $document Document déjà valide (fichiers résolus).
     *
     * @return list<array{path: string, message: string}>
     */
    public static function lint(array $document): array
    {
        $roots = [];
        foreach (\is_array($document['views'] ?? null) ? array_values($document['views']) : [] as $i => $view) {
            if (\is_array($view) && \is_array($view['root'] ?? null)) {
                $base = '/views/'.$i.'/root';
                $roots[$base] = $view['root'];
                foreach (\is_array($view['root']['library'] ?? null) ? $view['root']['library'] : [] as $key => $entry) {
                    if (\is_array($entry)) {
                        $roots[$base.'/library/'.$key] = $entry;
                    }
                }
            }
        }

        $realtime = false;
        $pointerdown = [];
        $warnings = [];
        $walk = static function (mixed $node, string $path, bool $inFormLayout) use (&$walk, &$realtime, &$pointerdown, &$warnings): int {
            if (!\is_array($node)) {
                return 0;
            }
            $tag = \is_string($node['tagName'] ?? null) ? strtolower($node['tagName']) : 'div';
            $attrs = \is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            if (\in_array($tag, self::REALTIME_TAGS, true)) {
                $realtime = true;
            }
            if ($tag === 'sonic-action') {
                if (($attrs['trigger'] ?? null) === 'pointerdown' && !\array_key_exists('hold-repeat', $attrs)) {
                    $pointerdown[] = $path;
                }
                if (\is_string($attrs['type'] ?? null) && preg_match(self::TAB_ACTION, $attrs['type']) === 1) {
                    $warnings[] = [
                        'path' => $path.'/attributes/type',
                        'message' => sprintf('sonic-action type="%s" : un changement d’onglet est un choix, pas une action. Recette « onglets » : sonic-button radio formDataProvider/name/value + sonic-states (l’onglet actif s’affiche seul). À garder seulement si le réducteur doit réagir au changement (fermer un modal).', $attrs['type']),
                    ];
                }
            }
            $inside = $inFormLayout || $tag === 'sonic-fieldset' || $tag === 'sonic-form-layout';
            $fields = \in_array($tag, self::FIELD_TAGS, true) && !$inside ? 1 : 0;
            foreach (\is_array($node['nodes'] ?? null) ? $node['nodes'] : [] as $i => $child) {
                $fields += $walk($child, $path.'/nodes/'.$i, $inside);
            }
            if ($tag === 'sonic-modal' || $tag === 'sonic-pop') {
                if ($fields >= 2) {
                    $warnings[] = [
                        'path' => $path,
                        'message' => sprintf('%d champs dans un %s sans sonic-fieldset ni sonic-form-layout : les ranger avec la recette « formulaire » (libellés alignés, deux colonnes dès 440 px).', $fields, $tag),
                    ];
                }

                return 0;
            }

            return $fields;
        };
        foreach ($roots as $path => $root) {
            $walk($root, $path, false);
        }

        if (!$realtime) {
            foreach ($pointerdown as $path) {
                $warnings[] = [
                    'path' => $path.'/attributes/trigger',
                    'message' => 'sonic-action trigger="pointerdown" sur un bouton d’interface : l’appui déclenche avant le défilement et empêche de faire défiler la page au doigt. Retirer trigger (départ au clic) ; pointerdown est réservé aux commandes de jeu et aux pads d’instrument.',
                ];
            }
        }

        return $warnings;
    }
}
