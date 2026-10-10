<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Contrôle d'un `sonic-patch` (creative-stack, addon audio) avant publication.
 *
 * Le compilateur du viewer refuse tout le patch à la première erreur (plus aucun son, sans
 * message visible dans l'aperçu) : on remonte ici, à l'agent, les erreurs que le compilateur
 * ferait, avec le message qui dit quoi écrire à la place. Les modules et leurs paramètres
 * viennent de config/artifacts/audio-patch.json (scripts/build-audio-patch-spec.mjs, source :
 * creative-stack patch/modules.ts).
 */
final class ArtifactPatchLint
{
    private const RESERVED = ['voices', 'master', 'voice'];

    /** @var array<string, array{kind: string, params: array<string, array{audio?: bool, values?: list<string>}>}> */
    private array $modules = [];
    /** @var list<string> */
    private array $expTargets = [];
    /** @var list<string> */
    private array $voiceSources = [];

    public function __construct(string $specPath)
    {
        $data = is_file($specPath) ? json_decode((string) file_get_contents($specPath), true) : null;
        if (\is_array($data)) {
            $this->modules = \is_array($data['modules'] ?? null) ? $data['modules'] : [];
            $this->expTargets = array_values(array_filter($data['expTargets'] ?? [], 'is_string'));
            $this->voiceSources = array_values(array_filter($data['voiceSources'] ?? [], 'is_string'));
        }
    }

    /**
     * @param array<string, mixed>            $patch   nœud sonic-patch
     * @param array<string, array<string, mixed>> $library nœuds `library` du descripteur (libraryKey)
     *
     * @return list<string> messages
     */
    public function check(array $patch, array $library = []): array
    {
        if ($this->modules === []) {
            return [];
        }
        $errors = [];
        $elements = [];
        foreach (array_values(\is_array($patch['nodes'] ?? null) ? $patch['nodes'] : []) as $node) {
            if (\is_array($node) && ($resolved = $this->resolve($node, $library)) !== null) {
                $elements[] = $resolved;
            }
        }

        // Modules (voix d'abord, puis globaux) : mêmes noms automatiques que le compilateur (`osc1`, `lfo2`…).
        $names = [];
        $counter = 0;
        $order = [];
        foreach ($elements as $el) {
            if ($el['tag'] === 'sonic-voice') {
                foreach ($el['children'] as $child) {
                    $order[] = $child;
                }
            }
        }
        foreach ($elements as $el) {
            if ($el['tag'] !== 'sonic-voice') {
                $order[] = $el;
            }
        }
        $mods = [];
        $params = [];
        foreach ($order as $el) {
            $tag = $el['tag'];
            if ($tag === 'sonic-mod') {
                $mods[] = $el;
                continue;
            }
            if ($tag === 'sonic-param') {
                $params[] = $el;
                continue;
            }
            if (!isset($this->modules[$tag])) {
                $errors[] = sprintf('balise %s inconnue dans un patch (modules : %s).', $tag, implode(', ', array_keys($this->modules)));
                continue;
            }
            $explicit = trim((string) ($el['attrs']['name'] ?? ''));
            $name = $explicit !== '' ? $explicit : substr($tag, \strlen('sonic-')).(++$counter);
            if (\in_array($name, self::RESERVED, true) || str_starts_with($name, 'voice.')) {
                $errors[] = sprintf('%s : nom réservé « %s ».', $tag, $name);
            } elseif (isset($names[$name])) {
                $errors[] = sprintf('%s : nom « %s » déjà utilisé.', $tag, $name);
            } else {
                $names[$name] = $tag;
            }
        }
        // Câbles (sonic-mod).
        $cables = [];
        foreach ($mods as $mod) {
            $a = $mod['attrs'];
            $from = trim((string) ($a['from'] ?? ''));
            $to = trim((string) ($a['to'] ?? ''));
            $label = sprintf('sonic-mod from="%s" to="%s"', $from, $to);
            if ($from === '' || $to === '') {
                $errors[] = 'sonic-mod : « from » et « to » requis.';
                continue;
            }
            if (isset($a['amount']) && !is_numeric(trim((string) $a['amount']))) {
                $errors[] = sprintf('%s : amount doit être un nombre (reçu « %s »). Pour le régler en direct : name sur le sonic-mod + sonic-param to="nom.amount".', $label, $a['amount']);
            }
            $cable = trim((string) ($a['name'] ?? ''));
            if ($cable !== '') {
                if (isset($names[$cable]) || isset($cables[$cable]) || \in_array($cable, self::RESERVED, true) || str_contains($cable, '.')) {
                    $errors[] = sprintf('%s : nom de câble « %s » invalide ou déjà pris (un nom de module ne convient pas).', $label, $cable);
                } else {
                    $cables[$cable] = true;
                }
            }
            if (str_starts_with($from, 'voice.')) {
                if (!\in_array($from, $this->voiceSources, true)) {
                    $errors[] = sprintf('%s : source « %s » inconnue (%s).', $label, $from, implode(', ', $this->voiceSources));
                }
            } elseif (!isset($names[$from])) {
                $errors[] = sprintf('%s : source « %s » inconnue : donne un name au module (modules : %s).', $label, $from, implode(', ', array_keys($names)) ?: 'aucun');
            }
            $this->checkTarget($to, $names, $label, true, $errors);
            if (trim((string) ($a['curve'] ?? '')) === 'exp') {
                [$m, $p] = $this->split($to);
                if ($p !== 'freq-hz' || !isset($names[$m]) || !\in_array($names[$m], $this->expTargets, true)) {
                    $errors[] = sprintf('%s : curve="exp" seulement vers freq-hz de %s.', $label, implode(', ', $this->expTargets));
                }
            }
        }

        // Paramètres pilotés (sonic-param).
        foreach ($params as $param) {
            $a = $param['attrs'];
            $to = trim((string) ($a['to'] ?? ''));
            $label = sprintf('sonic-param to="%s"', $to);
            [$m, $p] = $this->split($to);
            if (isset($cables[$m])) {
                if ($p !== 'amount') {
                    $errors[] = sprintf('%s : un sonic-mod nommé n\'a que le paramètre « amount ».', $label);
                }
            } else {
                $this->checkTarget($to, $names, $label, false, $errors);
            }
            if (trim((string) ($a['source'] ?? '')) === '' && trim((string) ($a['expose'] ?? '')) === '' && !isset($a['value'])) {
                $errors[] = sprintf('%s : ni source, ni expose, ni value : sans effet.', $label);
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, string> $names
     * @param list<string>          $errors
     */
    private function checkTarget(string $to, array $names, string $label, bool $needAudio, array &$errors): void
    {
        [$module, $param] = $this->split($to);
        if ($module === '') {
            $errors[] = sprintf('%s : cible « %s » attendue au format module.paramètre.', $label, $to);

            return;
        }
        if (!isset($names[$module])) {
            $errors[] = sprintf('%s : module « %s » inconnu (modules : %s). Donne un name aux modules visés.', $label, $module, implode(', ', array_keys($names)) ?: 'aucun');

            return;
        }
        $spec = $this->modules[$names[$module]]['params'] ?? [];
        if (!isset($spec[$param])) {
            $errors[] = sprintf('%s : %s n\'a pas de paramètre « %s » (%s).', $label, $names[$module], $param, implode(', ', array_keys($spec)));

            return;
        }
        if ($needAudio && empty($spec[$param]['audio'])) {
            $audio = array_keys(array_filter($spec, static fn (array $p): bool => !empty($p['audio'])));
            $errors[] = sprintf('%s : %s.%s n\'est pas modulable par un câble (modulables : %s).', $label, $module, $param, implode(', ', $audio) ?: 'aucun');
        }
    }

    /** @return array{0: string, 1: string} */
    private function split(string $to): array
    {
        $dot = strrpos($to, '.');

        return $dot === false || $dot === 0 ? ['', $to] : [substr($to, 0, $dot), substr($to, $dot + 1)];
    }

    /**
     * Nœud SDUI → {tag, attrs, children} ; `libraryKey` fusionne le nœud de la bibliothèque.
     *
     * @param array<string, mixed>                $node
     * @param array<string, array<string, mixed>> $library
     *
     * @return array{tag: string, attrs: array<string, mixed>, children: list<array<string, mixed>>}|null
     */
    private function resolve(array $node, array $library, int $depth = 0): ?array
    {
        $base = $node;
        if (isset($node['libraryKey']) && \is_string($node['libraryKey']) && isset($library[$node['libraryKey']]) && \is_array($library[$node['libraryKey']])) {
            $base = $library[$node['libraryKey']];
        }
        $tag = $base['tagName'] ?? $node['tagName'] ?? null;
        if (!\is_string($tag) || $depth > 6) {
            return null;
        }
        $attrs = array_merge(\is_array($base['attributes'] ?? null) ? $base['attributes'] : [], $base === $node ? [] : (\is_array($node['attributes'] ?? null) ? $node['attributes'] : []));
        $children = [];
        $kids = $node['nodes'] ?? $base['nodes'] ?? [];
        foreach (\is_array($kids) ? array_values($kids) : [] as $child) {
            if (\is_array($child) && ($r = $this->resolve($child, $library, $depth + 1)) !== null) {
                $children[] = $r;
            }
        }

        return ['tag' => $tag, 'attrs' => $attrs, 'children' => $children];
    }
}
