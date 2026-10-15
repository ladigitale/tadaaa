<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kits d'artefacts : un document `artifacts/1` squelette + des paramètres. Le modèle
 * n'écrit que les paramètres (questions d'un quiz, règles d'un jeu, shader…) ; le kit
 * fournit la mise en page et le câblage (store, clavier, horloge…). Le document produit
 * est un document normal : on peut ensuite le modifier comme n'importe quel autre.
 *
 * Un kit = `config/artifacts/kits/<id>.json` :
 * - `id`, `title`, `description` (une ligne), `use` (quand le choisir) ;
 * - `params` : schéma (sous-ensemble de JSON Schema : type, properties, required, items,
 *   enum, default, minimum/maximum, minLength/maxLength, minItems/maxItems, description) ;
 * - `presets` (optionnel) : jeux de paramètres nommés, choisis par le paramètre `preset` ;
 * - `document` : le squelette, avec ces directives (objets à une clé `$…`) :
 *     {"$param": "a.b", "default": …}   valeur d'un paramètre
 *     {"$json": …}                       valeur développée puis encodée en chaîne JSON
 *                                        (attributs SDUI : initial d'un store, payload…)
 *     {"$if": "a.b", "then": …, "else": …} selon que le paramètre est vrai ("!a.b" : faux ;
 *                                        "a.b=x|y" : égal à x ou y ; ".x" : champ de l'élément
 *                                        courant) ; sans branche, l'élément ou la clé disparaît
 *     {"$each": "a.b", "template": …}    répété pour chaque élément (dans une liste : à plat ;
 *                                        ".x" : liste prise dans l'élément courant)
 *     {"$eachKey": "a.b", "key": …, "template": …} objet : une clé (texte) par élément
 *     {"$item": "x.y"}                   champ de l'élément courant ("" : l'élément)
 *     {"$text": "…{{a.b}}…{{.x}}…{{#}}…"} texte : paramètre, champ de l'élément, index
 *                                        ({{#}} depuis 0, {{#1}} depuis 1, {{^#}} index parent)
 *
 * Tout se fait sur des valeurs décodées en objets : `{}` reste `{}`.
 */
final class ArtifactKits
{
    public const MAX_PARAMS_BYTES = 80_000;

    private const MISSING = "\0missing";

    /** @var array<string, \stdClass>|null */
    private ?array $kits = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/kits')]
        private readonly string $dir,
    ) {
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * Sommaire pour un prompt : une ligne par kit, avec la signature de ses paramètres.
     */
    public function summary(): string
    {
        $lines = [];
        foreach ($this->all() as $id => $kit) {
            $presets = $kit->presets ?? null;
            $lines[] = sprintf(
                '- %s : %s %s%s Paramètres : %s',
                $id,
                (string) ($kit->description ?? ''),
                isset($kit->use) ? '('.$kit->use.')' : '',
                $presets instanceof \stdClass ? ' Presets : '.implode(', ', array_keys(get_object_vars($presets))).' (avec preset, tout le reste est facultatif).' : '',
                self::signature($kit->params ?? new \stdClass()),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Construit le document d'un kit.
     *
     * @param mixed $params paramètres décodés en objets (`json_decode(..., false)`)
     *
     * @return array{document: \stdClass|null, errors: list<string>}
     */
    public function build(string $id, mixed $params): array
    {
        $kit = $this->all()[$id] ?? null;
        if ($kit === null) {
            return ['document' => null, 'errors' => [sprintf('Kit inconnu : %s. Kits : %s.', $id, implode(', ', $this->ids()))]];
        }
        if ($params === null || $params === []) {
            $params = new \stdClass();
        }
        if (!$params instanceof \stdClass) {
            return ['document' => null, 'errors' => ['params : objet attendu.']];
        }
        if (\strlen((string) json_encode($params)) > self::MAX_PARAMS_BYTES) {
            return ['document' => null, 'errors' => [sprintf('params trop volumineux (%d octets max).', self::MAX_PARAMS_BYTES)]];
        }
        $params = self::copy($params);

        // Preset : ses valeurs servent de base, les paramètres donnés l'emportent.
        $preset = $params->preset ?? null;
        if (\is_string($preset) && $preset !== '') {
            $presets = $kit->presets ?? new \stdClass();
            if (!$presets instanceof \stdClass || !property_exists($presets, $preset)) {
                return ['document' => null, 'errors' => [sprintf('preset inconnu : %s.', $preset)]];
            }
            $params = self::overlay(self::copy($presets->{$preset}), $params);
        }

        $errors = [];
        $params = self::check($kit->params ?? new \stdClass(), $params, 'params', $errors);
        if ($errors !== []) {
            return ['document' => null, 'errors' => $errors];
        }
        $document = self::expand(self::copy($kit->document), $params, []);

        return $document instanceof \stdClass
            ? ['document' => $document, 'errors' => []]
            : ['document' => null, 'errors' => ['Kit invalide : le document n’est pas un objet.']];
    }

    /** @return array<string, \stdClass> */
    private function all(): array
    {
        if ($this->kits !== null) {
            return $this->kits;
        }
        $this->kits = [];
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $kit = json_decode((string) file_get_contents($file), false);
            if ($kit instanceof \stdClass && \is_string($kit->id ?? null) && ($kit->document ?? null) instanceof \stdClass) {
                $this->kits[$kit->id] = $kit;
            }
        }
        ksort($this->kits);

        return $this->kits;
    }

    // --- Paramètres --------------------------------------------------------------

    /**
     * Vérifie une valeur contre le schéma et complète les valeurs par défaut.
     *
     * @param list<string> $errors
     */
    private static function check(mixed $schema, mixed $value, string $path, array &$errors): mixed
    {
        if (!$schema instanceof \stdClass) {
            return $value;
        }
        if ($value === null && property_exists($schema, 'default')) {
            $value = self::copy($schema->default);
        }
        if ($value === null) {
            return null;
        }
        $type = $schema->type ?? null;
        $ok = match ($type) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'array' => \is_array($value),
            'object' => $value instanceof \stdClass,
            default => true,
        };
        if (!$ok) {
            $errors[] = sprintf('%s : %s attendu.', $path, $type);

            return $value;
        }
        if (isset($schema->enum) && \is_array($schema->enum) && !\in_array($value, $schema->enum, true)) {
            $errors[] = sprintf('%s : une valeur parmi %s.', $path, json_encode($schema->enum, \JSON_UNESCAPED_UNICODE));
        }
        if (\is_string($value)) {
            if (isset($schema->minLength) && mb_strlen($value) < $schema->minLength) {
                $errors[] = sprintf('%s : %d caractères minimum.', $path, $schema->minLength);
            }
            if (isset($schema->maxLength) && mb_strlen($value) > $schema->maxLength) {
                $errors[] = sprintf('%s : %d caractères maximum.', $path, $schema->maxLength);
            }
        }
        if (\is_int($value) || \is_float($value)) {
            if (isset($schema->minimum) && $value < $schema->minimum) {
                $errors[] = sprintf('%s : minimum %s.', $path, $schema->minimum);
            }
            if (isset($schema->maximum) && $value > $schema->maximum) {
                $errors[] = sprintf('%s : maximum %s.', $path, $schema->maximum);
            }
        }
        if (\is_array($value)) {
            if (isset($schema->minItems) && \count($value) < $schema->minItems) {
                $errors[] = sprintf('%s : %d éléments minimum.', $path, $schema->minItems);
            }
            if (isset($schema->maxItems) && \count($value) > $schema->maxItems) {
                $errors[] = sprintf('%s : %d éléments maximum.', $path, $schema->maxItems);
            }
            foreach ($value as $i => $item) {
                $value[$i] = self::check($schema->items ?? null, $item, sprintf('%s[%d]', $path, $i), $errors);
            }
        }
        if ($value instanceof \stdClass) {
            $properties = $schema->properties ?? new \stdClass();
            foreach (\is_array($schema->required ?? null) ? $schema->required : [] as $name) {
                if (!property_exists($value, $name) || $value->{$name} === null) {
                    $errors[] = sprintf('%s.%s : requis.', $path, $name);
                }
            }
            foreach (get_object_vars($properties) as $name => $sub) {
                $checked = self::check($sub, $value->{$name} ?? null, $path.'.'.$name, $errors);
                if ($checked !== null) {
                    $value->{$name} = $checked;
                }
            }
        }

        return $value;
    }

    /** Signature compacte d'un schéma (prompt). */
    private static function signature(mixed $schema, int $depth = 0): string
    {
        if (!$schema instanceof \stdClass) {
            return '?';
        }
        $type = (string) ($schema->type ?? 'any');
        if ($type === 'object' && ($schema->properties ?? null) instanceof \stdClass && $depth < 4) {
            $required = \is_array($schema->required ?? null) ? $schema->required : [];
            $parts = [];
            foreach (get_object_vars($schema->properties) as $name => $sub) {
                $parts[] = $name.(\in_array($name, $required, true) ? '' : '?').': '.self::signature($sub, $depth + 1);
            }

            return '{'.implode(', ', $parts).'}';
        }
        if ($type === 'array') {
            $bounds = isset($schema->minItems) || isset($schema->maxItems)
                ? sprintf(' %s..%s', $schema->minItems ?? 0, $schema->maxItems ?? '')
                : '';

            return '['.self::signature($schema->items ?? null, $depth + 1).$bounds.']';
        }
        $out = isset($schema->enum) && \is_array($schema->enum) ? implode('|', array_map('strval', $schema->enum)) : $type;
        if (property_exists($schema, 'default') && !\is_array($schema->default) && !$schema->default instanceof \stdClass) {
            $out .= '='.json_encode($schema->default, \JSON_UNESCAPED_UNICODE);
        }
        if (isset($schema->description) && \is_string($schema->description)) {
            $out .= ' ('.$schema->description.')';
        }

        return $out;
    }

    // --- Squelette ---------------------------------------------------------------

    /**
     * @param list<array{item: mixed, index: int}> $stack éléments des `$each` englobants
     */
    private static function expand(mixed $node, \stdClass $params, array $stack): mixed
    {
        if (\is_array($node)) {
            $out = [];
            foreach ($node as $child) {
                if ($child instanceof \stdClass && property_exists($child, '$each')) {
                    array_push($out, ...self::each($child, $params, $stack));
                    continue;
                }
                $value = self::expand($child, $params, $stack);
                if ($value !== self::MISSING) {
                    $out[] = $value;
                }
            }

            return $out;
        }
        if (!$node instanceof \stdClass) {
            return $node;
        }
        if (property_exists($node, '$param')) {
            $found = true;
            $value = self::lookup($params, (string) $node->{'$param'}, $found);

            return $found && $value !== null ? self::copy($value) : (property_exists($node, 'default') ? self::copy($node->default) : null);
        }
        if (property_exists($node, '$json')) {
            $value = self::expand($node->{'$json'}, $params, $stack);

            return (string) json_encode($value === self::MISSING ? null : $value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }
        if (property_exists($node, '$if')) {
            $condition = (string) $node->{'$if'};
            $negate = str_starts_with($condition, '!');
            $condition = ltrim($condition, '!');
            $expected = null;
            if (str_contains($condition, '=')) {
                [$condition, $list] = explode('=', $condition, 2);
                $expected = explode('|', $list);
            }
            $found = true;
            $value = str_starts_with($condition, '.')
                ? self::lookup($stack === [] ? null : $stack[\count($stack) - 1]['item'], substr($condition, 1), $found)
                : self::lookup($params, $condition, $found);
            $truthy = $expected !== null
                ? $found && \is_scalar($value) && \in_array(\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $expected, true)
                : $found && !\in_array($value, [null, false, 0, 0.0, '', []], true)
                    && !($value instanceof \stdClass && get_object_vars($value) === []);
            $branch = ($truthy xor $negate) ? 'then' : 'else';

            return property_exists($node, $branch) ? self::expand($node->{$branch}, $params, $stack) : self::MISSING;
        }
        if (property_exists($node, '$each')) {
            return self::each($node, $params, $stack);
        }
        if (property_exists($node, '$eachKey')) {
            $out = new \stdClass();
            $keyNode = $node->key ?? null;
            $proxy = (object) ['$each' => $node->{'$eachKey'}, 'template' => (object) ['k' => $keyNode, 'v' => $node->template ?? null]];
            foreach (self::each($proxy, $params, $stack) as $pair) {
                if ($pair instanceof \stdClass && \is_string($pair->k ?? null) && $pair->k !== '' && property_exists($pair, 'v')) {
                    $out->{$pair->k} = $pair->v;
                }
            }

            return $out;
        }
        if (property_exists($node, '$item')) {
            $current = $stack === [] ? null : $stack[\count($stack) - 1]['item'];
            $found = true;
            $value = (string) $node->{'$item'} === '' ? $current : self::lookup($current, (string) $node->{'$item'}, $found);

            return $found && $value !== null ? self::copy($value) : (property_exists($node, 'default') ? self::copy($node->default) : null);
        }
        if (property_exists($node, '$text')) {
            return self::text((string) $node->{'$text'}, $params, $stack);
        }
        $out = new \stdClass();
        foreach (get_object_vars($node) as $key => $child) {
            $value = self::expand($child, $params, $stack);
            if ($value !== self::MISSING) {
                $out->{$key} = $value;
            }
        }

        return $out;
    }

    /**
     * @param list<array{item: mixed, index: int}> $stack
     *
     * @return list<mixed>
     */
    private static function each(\stdClass $node, \stdClass $params, array $stack): array
    {
        $source = (string) $node->{'$each'};
        $found = true;
        $items = str_starts_with($source, '.')
            ? self::lookup($stack === [] ? null : $stack[\count($stack) - 1]['item'], substr($source, 1), $found)
            : self::lookup($params, $source, $found);
        if (!\is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_values($items) as $index => $item) {
            $value = self::expand($node->template ?? null, $params, [...$stack, ['item' => $item, 'index' => $index]]);
            if ($value !== self::MISSING) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** @param list<array{item: mixed, index: int}> $stack */
    private static function text(string $template, \stdClass $params, array $stack): string
    {
        return (string) preg_replace_callback('/\{\{\s*([^}]*?)\s*\}\}/', static function (array $m) use ($params, $stack): string {
            $expr = $m[1];
            $level = \count($stack) - 1;
            while (str_starts_with($expr, '^')) {
                --$level;
                $expr = substr($expr, 1);
            }
            $frame = $stack[$level] ?? null;
            if ($expr === '#') {
                return (string) ($frame['index'] ?? 0);
            }
            if ($expr === '#1') {
                return (string) (($frame['index'] ?? 0) + 1);
            }
            $found = true;
            $value = str_starts_with($expr, '.')
                ? self::lookup($frame['item'] ?? null, substr($expr, 1), $found)
                : self::lookup($params, $expr, $found);
            if (!$found || $value === null) {
                return '';
            }

            return \is_scalar($value) ? (\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value, \JSON_UNESCAPED_UNICODE);
        }, $template);
    }

    private static function lookup(mixed $value, string $path, bool &$found): mixed
    {
        $found = true;
        if ($path === '') {
            return $value;
        }
        foreach (explode('.', $path) as $segment) {
            if ($value instanceof \stdClass && property_exists($value, $segment)) {
                $value = $value->{$segment};
            } elseif (\is_array($value) && ctype_digit($segment) && \array_key_exists((int) $segment, $value)) {
                $value = $value[(int) $segment];
            } else {
                $found = false;

                return null;
            }
        }

        return $value;
    }

    /** Fusion récursive : les valeurs de `$top` l'emportent (objets fusionnés, le reste remplacé). */
    private static function overlay(mixed $base, mixed $top): mixed
    {
        if ($base instanceof \stdClass && $top instanceof \stdClass) {
            foreach (get_object_vars($top) as $key => $value) {
                $base->{$key} = property_exists($base, $key) ? self::overlay($base->{$key}, $value) : $value;
            }

            return $base;
        }

        return $top;
    }

    private static function copy(mixed $value): mixed
    {
        return \is_array($value) || \is_object($value) ? json_decode((string) json_encode($value), false) : $value;
    }
}
