<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Contrat d'entrées / sorties d'un artefact (bloc `io` du document) : l'artefact devient un outil
 * appelable par un workflow ou un agent. Classe pure (pas d'I/O) pour être testable.
 *
 * Déclaration :
 *   "io": {
 *     "title": "Compter des points",            // optionnel, nom de l'outil (80 car.)
 *     "description": "…",                        // optionnel (500 car.)
 *     "modes": ["headless"],                     // headless | interactive | embed (au moins un)
 *     "input":  {"schema": {…JSON Schema…}, "store": "calc", "action": "io:input"},
 *     "output": {"schema": {…JSON Schema…}, "from": "calc.result", "when": "phase = 'done'",
 *                "files": {"dessin": "sketch.file"}},           // optionnel, modes interactive / embed
 *     "headless": {"actions": [{"type": "tick", "repeat": 100}], "maxSteps": 2000}
 *   }
 *
 * - `input` (optionnel) : l'entrée validée par `schema` est envoyée au store `store` comme action
 *   `{type: action, payload: entrée}` (action par défaut `io:input`).
 * - `output` (requis) : `from` = DataProvider pointé (`store` ou `store.chemin`), lu quand `when`
 *   (JSONata sur l'état de ce store, optionnel) devient vrai, puis validé par `schema`.
 * - `headless` : le run s'exécute sans navigateur sur le réducteur JSONata (même moteur que
 *   simulate_store) ; `from` doit alors lire le store d'entrée, et `headless.actions` rejoue des
 *   actions après l'entrée (horloge, calcul en plusieurs pas) jusqu'à `when`.
 *
 * Schémas : sous-ensemble de JSON Schema 2020-12 (type, properties, required,
 * additionalProperties booléen, items, enum, const, minimum, maximum, exclusiveMinimum,
 * exclusiveMaximum, minLength, maxLength, pattern, minItems, maxItems, title, description,
 * default, examples, format informatif). Pas de $ref, ni de combinateurs.
 */
final class ArtifactIo
{
    public const MODES = ['headless', 'interactive', 'embed'];
    public const DEFAULT_ACTION = 'io:input';
    public const MAX_SCHEMA_BYTES = 16384;
    public const MAX_SCHEMA_DEPTH = 8;
    public const MAX_VALUE_BYTES = 65536;
    public const MAX_HEADLESS_ACTIONS = 16;
    public const MAX_HEADLESS_STEPS = 20000;
    public const DEFAULT_HEADLESS_STEPS = 2000;
    public const MAX_OUTPUT_FILES = 4;
    public const FILE_KEY = '/^[a-z][a-z0-9_-]{0,31}$/';

    private const TYPES = ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'];
    private const KEYWORDS = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength',
        'pattern', 'minItems', 'maxItems', 'title', 'description', 'default', 'examples', 'format',
        '$schema',
    ];
    private const PROVIDER = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';
    private const ACTION = '/^[a-zA-Z][a-zA-Z0-9_:.-]{0,63}$/';
    private const DOTTED = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}(\.[a-zA-Z0-9_-]{1,64}){0,8}$/';

    /**
     * Vérifie la déclaration `io` contre le document (noms de DataProviders compris).
     *
     * @param array<string, mixed> $document document développé (fichiers @file remplacés)
     *
     * @return list<array{path: string, message: string}>
     */
    public static function validateDeclaration(mixed $io, array $document): array
    {
        if (!\is_array($io) || array_is_list($io)) {
            return [['path' => '/io', 'message' => 'io doit être un objet {modes, input?, output, headless?}.']];
        }
        $errors = [];
        foreach (array_keys($io) as $k) {
            if (!\in_array($k, ['title', 'description', 'modes', 'input', 'output', 'headless'], true)) {
                $errors[] = ['path' => '/io/'.$k, 'message' => 'Clé non autorisée (title, description, modes, input, output, headless).'];
            }
        }
        foreach (['title' => 80, 'description' => 500] as $k => $max) {
            if (\array_key_exists($k, $io) && (!\is_string($io[$k]) || trim($io[$k]) === '' || mb_strlen($io[$k]) > $max)) {
                $errors[] = ['path' => '/io/'.$k, 'message' => sprintf('%s : texte de 1 à %d caractères.', $k, $max)];
            }
        }

        $modes = $io['modes'] ?? null;
        if (!\is_array($modes) || $modes === [] || !array_is_list($modes) || array_diff($modes, self::MODES) !== [] || \count(array_unique($modes)) !== \count($modes)) {
            $errors[] = ['path' => '/io/modes', 'message' => 'modes : liste non vide parmi '.implode(', ', self::MODES).'.'];
            $modes = [];
        }
        $headless = \in_array('headless', $modes, true);

        $stores = self::storeNames($document);
        $providers = self::providerNames($document) + $stores;

        $inputStore = null;
        if (\array_key_exists('input', $io)) {
            $input = $io['input'];
            if (!\is_array($input) || array_is_list($input)) {
                $errors[] = ['path' => '/io/input', 'message' => 'input doit être un objet {schema, store, action?}.'];
            } else {
                foreach (array_keys($input) as $k) {
                    if (!\in_array($k, ['schema', 'store', 'action'], true)) {
                        $errors[] = ['path' => '/io/input/'.$k, 'message' => 'Clé non autorisée (schema, store, action).'];
                    }
                }
                $errors = [...$errors, ...self::checkSchema($input['schema'] ?? null, '/io/input/schema')];
                if (\is_array($input['schema'] ?? null) && ($input['schema']['type'] ?? null) !== 'object') {
                    $errors[] = ['path' => '/io/input/schema/type', 'message' => 'L’entrée est un objet (type "object", comme les arguments d’un outil MCP).'];
                }
                $store = $input['store'] ?? null;
                if (!\is_string($store) || !preg_match(self::PROVIDER, $store)) {
                    $errors[] = ['path' => '/io/input/store', 'message' => 'store requis : nom d’un store (data.stores ou sonic-store id).'];
                } elseif (!isset($stores[$store])) {
                    $errors[] = ['path' => '/io/input/store', 'message' => sprintf('« %s » : aucun store de ce nom (data.stores ou sonic-store id).', $store)];
                } else {
                    $inputStore = $store;
                }
                if (\array_key_exists('action', $input) && (!\is_string($input['action']) || !preg_match(self::ACTION, $input['action']))) {
                    $errors[] = ['path' => '/io/input/action', 'message' => 'action : nom d’action (lettres, chiffres, _ : . -), défaut io:input.'];
                }
            }
        } elseif ($headless) {
            $errors[] = ['path' => '/io/input', 'message' => 'Le mode headless exige input (le run démarre par l’action d’entrée).'];
        }

        $output = $io['output'] ?? null;
        if (!\is_array($output) || array_is_list($output)) {
            $errors[] = ['path' => '/io/output', 'message' => 'output requis : {schema, from, when?}.'];
        } else {
            foreach (array_keys($output) as $k) {
                if (!\in_array($k, ['schema', 'from', 'when', 'files'], true)) {
                    $errors[] = ['path' => '/io/output/'.$k, 'message' => 'Clé non autorisée (schema, from, when, files).'];
                }
            }
            $errors = [...$errors, ...self::checkSchema($output['schema'] ?? null, '/io/output/schema')];
            $from = $output['from'] ?? null;
            if (!\is_string($from) || !preg_match(self::DOTTED, $from)) {
                $errors[] = ['path' => '/io/output/from', 'message' => 'from requis : "dataProvider" ou "dataProvider.chemin.pointé".'];
            } else {
                $root = explode('.', $from)[0];
                if (!isset($providers[$root])) {
                    $errors[] = ['path' => '/io/output/from', 'message' => sprintf('« %s » ne correspond à aucun store, transform ou source.', $root)];
                } elseif ($headless && $inputStore !== null && $root !== $inputStore) {
                    $errors[] = ['path' => '/io/output/from', 'message' => sprintf('En mode headless, from doit lire le store d’entrée « %s » (seul son réducteur est exécuté).', $inputStore)];
                }
            }
            if (\array_key_exists('files', $output)) {
                $files = $output['files'];
                if (!\is_array($files) || array_is_list($files) || \count($files) > self::MAX_OUTPUT_FILES) {
                    $errors[] = ['path' => '/io/output/files', 'message' => sprintf('files : objet {nom: "dataProvider.chemin"} (%d fichiers max) ; le chemin donne l’URL du fichier (blob:, data:) ou {url}.', self::MAX_OUTPUT_FILES)];
                } else {
                    foreach ($files as $name => $path) {
                        $p = '/io/output/files/'.$name;
                        if (!preg_match(self::FILE_KEY, (string) $name)) {
                            $errors[] = ['path' => $p, 'message' => 'Nom de fichier : a-z, 0-9, _ ou - (32 car. max), commence par une lettre.'];
                        } elseif (!\is_string($path) || !preg_match(self::DOTTED, $path)) {
                            $errors[] = ['path' => $p, 'message' => 'Chemin "dataProvider.chemin" attendu.'];
                        } elseif (!isset($providers[explode('.', $path)[0]])) {
                            $errors[] = ['path' => $p, 'message' => sprintf('« %s » ne correspond à aucun store, transform ou source.', explode('.', $path)[0])];
                        }
                    }
                }
            }
            if (\array_key_exists('when', $output) && (!\is_string($output['when']) || trim($output['when']) === '' || \strlen($output['when']) > 2000)) {
                $errors[] = ['path' => '/io/output/when', 'message' => 'when : expression JSONata (2000 car. max) évaluée sur l’état du store lu par from.'];
            }
        }

        if (\array_key_exists('headless', $io)) {
            $h = $io['headless'];
            if (!$headless) {
                $errors[] = ['path' => '/io/headless', 'message' => 'headless ne sert qu’avec le mode headless.'];
            } elseif (!\is_array($h) || array_is_list($h)) {
                $errors[] = ['path' => '/io/headless', 'message' => 'headless doit être un objet {actions?, maxSteps?}.'];
            } else {
                foreach (array_keys($h) as $k) {
                    if (!\in_array($k, ['actions', 'maxSteps'], true)) {
                        $errors[] = ['path' => '/io/headless/'.$k, 'message' => 'Clé non autorisée (actions, maxSteps).'];
                    }
                }
                if (\array_key_exists('actions', $h)) {
                    $actions = $h['actions'];
                    if (!\is_array($actions) || !array_is_list($actions) || \count($actions) > self::MAX_HEADLESS_ACTIONS) {
                        $errors[] = ['path' => '/io/headless/actions', 'message' => sprintf('actions : liste de %d actions {type, payload?, repeat?} au plus.', self::MAX_HEADLESS_ACTIONS)];
                    } else {
                        foreach ($actions as $i => $a) {
                            $p = '/io/headless/actions/'.$i;
                            if (!\is_array($a) || !\is_string($a['type'] ?? null) || !preg_match(self::ACTION, $a['type'])) {
                                $errors[] = ['path' => $p, 'message' => 'action : {type, payload?, repeat?}.'];
                                continue;
                            }
                            if (\array_key_exists('repeat', $a) && (!\is_int($a['repeat']) || $a['repeat'] < 1 || $a['repeat'] > self::MAX_HEADLESS_STEPS)) {
                                $errors[] = ['path' => $p.'/repeat', 'message' => sprintf('repeat : entier 1…%d.', self::MAX_HEADLESS_STEPS)];
                            }
                            if (array_diff(array_keys($a), ['type', 'payload', 'repeat']) !== []) {
                                $errors[] = ['path' => $p, 'message' => 'Clés autorisées : type, payload, repeat.'];
                            }
                        }
                    }
                }
                if (\array_key_exists('maxSteps', $h) && (!\is_int($h['maxSteps']) || $h['maxSteps'] < 1 || $h['maxSteps'] > self::MAX_HEADLESS_STEPS)) {
                    $errors[] = ['path' => '/io/headless/maxSteps', 'message' => sprintf('maxSteps : entier 1…%d.', self::MAX_HEADLESS_STEPS)];
                }
            }
        }

        return $errors;
    }

    /**
     * Contrat publié (outil) : null si le document ne déclare pas `io`.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>|null
     */
    public static function describe(array $document): ?array
    {
        $io = $document['io'] ?? null;
        if (!\is_array($io) || !\is_array($io['output'] ?? null)) {
            return null;
        }
        $title = \is_string($io['title'] ?? null) ? $io['title'] : (\is_string($document['title'] ?? null) ? $document['title'] : null);

        return array_filter([
            'title' => $title,
            'description' => \is_string($io['description'] ?? null) ? $io['description'] : null,
            'modes' => \is_array($io['modes'] ?? null) ? array_values($io['modes']) : [],
            'inputSchema' => \is_array($io['input']['schema'] ?? null) ? $io['input']['schema'] : ['type' => 'object', 'properties' => new \stdClass()],
            'outputSchema' => $io['output']['schema'] ?? null,
            'outputFiles' => \is_array($io['output']['files'] ?? null) && $io['output']['files'] !== [] ? array_keys($io['output']['files']) : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Description OpenAPI 3.1 d'un artefact-outil (contrat publié par {@see self::describe()}) :
     * lancer un run, le lire, l'annuler. Pour n8n, Make, un client généré…
     *
     * @param array<string, mixed> $contract
     *
     * @return array<string, mixed>
     */
    public static function openApi(array $contract, string $slug, string $apiBase): array
    {
        $runPath = '/api/artifacts/'.$slug.'/runs';
        $output = $contract['outputSchema'] ?? new \stdClass();
        $run = [
            'type' => 'object',
            'properties' => [
                'runId' => ['type' => 'string', 'format' => 'uuid'],
                'slug' => ['type' => 'string'],
                'mode' => ['type' => 'string', 'enum' => self::MODES],
                'status' => ['type' => 'string', 'enum' => ['completed', 'incomplete', 'failed', 'pending', 'expired', 'cancelled']],
                'output' => $output,
                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Mode interactive : page à donner à la personne qui fait l’étape.'],
                'runToken' => ['type' => 'string', 'description' => 'Modes interactive / embed : jeton du run (24 h).'],
                'expiresAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
            'required' => ['runId', 'status'],
        ];
        $runRef = ['$ref' => '#/components/schemas/Run'];
        $json = static fn (array $schema): array => ['content' => ['application/json' => ['schema' => $schema]]];
        $error = ['description' => 'Erreur', ...$json(['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]])];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) ($contract['title'] ?? $slug),
                'version' => '1',
                'description' => (string) ($contract['description'] ?? 'Artefact Tadaaa utilisé comme outil (contrat io).'),
            ],
            'servers' => [['url' => rtrim($apiBase, '/')]],
            'security' => [['bearer' => []]],
            'paths' => [
                $runPath => [
                    'post' => [
                        'operationId' => 'runArtifact',
                        'summary' => 'Lancer un run',
                        'requestBody' => ['required' => true, ...$json([
                            'type' => 'object',
                            'properties' => [
                                'input' => $contract['inputSchema'] ?? ['type' => 'object'],
                                'mode' => ['type' => 'string', 'enum' => $contract['modes'] ?? self::MODES],
                            ],
                            'required' => ['input'],
                        ])],
                        'responses' => [
                            '201' => ['description' => 'Run headless terminé', ...$json($runRef)],
                            '200' => ['description' => 'Run en attente (interactive / embed) ou headless sans sortie conforme', ...$json($runRef)],
                            '400' => $error,
                            '429' => $error,
                        ],
                    ],
                ],
                $runPath.'/{runId}' => [
                    'get' => [
                        'operationId' => 'getArtifactRun',
                        'summary' => 'Lire un run',
                        'parameters' => [['name' => 'runId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]],
                        'responses' => ['200' => ['description' => 'Run', ...$json($runRef)], '404' => $error],
                    ],
                ],
                $runPath.'/{runId}/cancel' => [
                    'post' => [
                        'operationId' => 'cancelArtifactRun',
                        'summary' => 'Annuler un run en attente',
                        'parameters' => [['name' => 'runId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]],
                        'responses' => ['200' => ['description' => 'Run annulé', ...$json($runRef)], '409' => $error],
                    ],
                ],
            ],
            'webhooks' => [
                'artifact.run.completed' => ['post' => ['summary' => 'Run terminé (Standard Webhooks)', 'requestBody' => $json(['type' => 'object', 'properties' => ['type' => ['const' => 'artifact.run.completed'], 'data' => $runRef]]), 'responses' => ['2XX' => ['description' => 'Reçu']]]],
                'artifact.run.failed' => ['post' => ['summary' => 'Run échoué, expiré ou annulé (Standard Webhooks)', 'requestBody' => $json(['type' => 'object', 'properties' => ['type' => ['const' => 'artifact.run.failed'], 'data' => $runRef]]), 'responses' => ['2XX' => ['description' => 'Reçu']]]],
            ],
            'components' => [
                'schemas' => ['Run' => $run],
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Jeton d’API Tadaaa']],
            ],
        ];
    }

    /**
     * Plan d'un run headless : requête pour le simulateur de store et lecture de la sortie.
     *
     * @param array<string, mixed> $io
     *
     * @return array{store: string, actions: list<array<string, mixed>>, until: ?string, maxSteps: int, path: string}
     */
    public static function headlessPlan(array $io, mixed $input): array
    {
        $store = (string) $io['input']['store'];
        $action = \is_string($io['input']['action'] ?? null) ? $io['input']['action'] : self::DEFAULT_ACTION;
        $actions = [['type' => $action, 'payload' => $input]];
        foreach (\is_array($io['headless']['actions'] ?? null) ? $io['headless']['actions'] : [] as $a) {
            $actions[] = $a;
        }
        $from = (string) $io['output']['from'];
        $dot = strpos($from, '.');

        return [
            'store' => $store,
            'actions' => $actions,
            'until' => \is_string($io['output']['when'] ?? null) ? $io['output']['when'] : null,
            'maxSteps' => \is_int($io['headless']['maxSteps'] ?? null) ? $io['headless']['maxSteps'] : self::DEFAULT_HEADLESS_STEPS,
            'path' => $dot === false ? '' : substr($from, $dot + 1),
        ];
    }

    /** Lit un chemin pointé (`a.b.0`) dans un état décodé ; '' = l'état entier. */
    public static function pluck(mixed $state, string $path): mixed
    {
        if ($path === '') {
            return $state;
        }
        foreach (explode('.', $path) as $key) {
            if (\is_array($state) && \array_key_exists($key, $state)) {
                $state = $state[$key];
            } elseif ($state instanceof \stdClass && property_exists($state, $key)) {
                $state = $state->{$key};
            } else {
                return null;
            }
        }

        return $state;
    }

    /**
     * Vérifie un schéma déclaré (sous-ensemble autorisé, taille, profondeur).
     *
     * @return list<array{path: string, message: string}>
     */
    public static function checkSchema(mixed $schema, string $path): array
    {
        if (!\is_array($schema) || ($schema !== [] && array_is_list($schema))) {
            return [['path' => $path, 'message' => 'schema requis : objet JSON Schema (sous-ensemble 2020-12).']];
        }
        $encoded = json_encode($schema);
        if ($encoded === false || \strlen($encoded) > self::MAX_SCHEMA_BYTES) {
            return [['path' => $path, 'message' => sprintf('schema trop volumineux (%d Ko max).', self::MAX_SCHEMA_BYTES / 1024)]];
        }
        $errors = [];
        self::checkSchemaNode($schema, $path, 0, $errors);

        return $errors;
    }

    /**
     * Valide une valeur contre un schéma déclaré (déjà vérifié par checkSchema).
     *
     * @return list<string> messages (vide = valide)
     */
    public static function validateValue(mixed $schema, mixed $value, string $path = ''): array
    {
        $encoded = json_encode($value);
        if ($encoded === false) {
            return [($path ?: '/').' : valeur non sérialisable en JSON.'];
        }
        if (\strlen($encoded) > self::MAX_VALUE_BYTES) {
            return [sprintf('%s : valeur trop volumineuse (%d Ko max).', $path ?: '/', self::MAX_VALUE_BYTES / 1024)];
        }
        $errors = [];
        self::checkValue(\is_array($schema) ? $schema : [], $value, $path, $errors);

        return $errors;
    }

    /** @param list<array{path: string, message: string}> $errors */
    private static function checkSchemaNode(mixed $node, string $path, int $depth, array &$errors): void
    {
        if (!\is_array($node) || ($node !== [] && array_is_list($node))) {
            $errors[] = ['path' => $path, 'message' => 'Sous-schéma : objet attendu.'];

            return;
        }
        if ($depth > self::MAX_SCHEMA_DEPTH) {
            $errors[] = ['path' => $path, 'message' => sprintf('Schéma trop profond (%d niveaux max).', self::MAX_SCHEMA_DEPTH)];

            return;
        }
        foreach (array_keys($node) as $k) {
            if (!\in_array($k, self::KEYWORDS, true)) {
                $errors[] = ['path' => $path.'/'.$k, 'message' => 'Mot-clé non pris en charge (pas de $ref, allOf, anyOf, oneOf…).'];
            }
        }
        if (\array_key_exists('type', $node)) {
            $types = \is_array($node['type']) ? $node['type'] : [$node['type']];
            if ($types === [] || array_diff($types, self::TYPES) !== [] || !array_is_list($types)) {
                $errors[] = ['path' => $path.'/type', 'message' => 'type : '.implode(', ', self::TYPES).' (ou liste).'];
            }
        }
        if (\array_key_exists('properties', $node)) {
            $props = $node['properties'];
            if (!\is_array($props) || ($props !== [] && array_is_list($props))) {
                $errors[] = ['path' => $path.'/properties', 'message' => 'properties : objet {nom: schéma}.'];
            } else {
                foreach ($props as $name => $sub) {
                    self::checkSchemaNode($sub, $path.'/properties/'.$name, $depth + 1, $errors);
                }
            }
        }
        if (\array_key_exists('required', $node) && (!\is_array($node['required']) || !array_is_list($node['required']) || array_filter($node['required'], static fn ($r) => !\is_string($r)) !== [])) {
            $errors[] = ['path' => $path.'/required', 'message' => 'required : liste de noms.'];
        }
        if (\array_key_exists('additionalProperties', $node) && !\is_bool($node['additionalProperties'])) {
            $errors[] = ['path' => $path.'/additionalProperties', 'message' => 'additionalProperties : booléen uniquement.'];
        }
        if (\array_key_exists('items', $node)) {
            self::checkSchemaNode($node['items'], $path.'/items', $depth + 1, $errors);
        }
        if (\array_key_exists('enum', $node) && (!\is_array($node['enum']) || $node['enum'] === [] || !array_is_list($node['enum']))) {
            $errors[] = ['path' => $path.'/enum', 'message' => 'enum : liste non vide.'];
        }
        foreach (['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum'] as $k) {
            if (\array_key_exists($k, $node) && !\is_int($node[$k]) && !\is_float($node[$k])) {
                $errors[] = ['path' => $path.'/'.$k, 'message' => $k.' : nombre.'];
            }
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $k) {
            if (\array_key_exists($k, $node) && (!\is_int($node[$k]) || $node[$k] < 0)) {
                $errors[] = ['path' => $path.'/'.$k, 'message' => $k.' : entier positif.'];
            }
        }
        if (\array_key_exists('pattern', $node)) {
            $pattern = $node['pattern'];
            if (!\is_string($pattern) || \strlen($pattern) > 200 || @preg_match(self::regex($pattern), '') === false) {
                $errors[] = ['path' => $path.'/pattern', 'message' => 'pattern : expression régulière valide (200 car. max).'];
            }
        }
        foreach (['title', 'description', 'format'] as $k) {
            if (\array_key_exists($k, $node) && !\is_string($node[$k])) {
                $errors[] = ['path' => $path.'/'.$k, 'message' => $k.' : texte.'];
            }
        }
    }

    /** @param list<string> $errors */
    private static function checkValue(array $schema, mixed $value, string $path, array &$errors): void
    {
        $at = $path === '' ? '/' : $path;
        if (\array_key_exists('const', $schema) && !self::same($schema['const'], $value)) {
            $errors[] = $at.' : valeur imposée '.json_encode($schema['const'], JSON_UNESCAPED_UNICODE).'.';

            return;
        }
        if (\is_array($schema['enum'] ?? null)) {
            $ok = false;
            foreach ($schema['enum'] as $candidate) {
                if (self::same($candidate, $value)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = $at.' : valeur hors de '.json_encode($schema['enum'], JSON_UNESCAPED_UNICODE).'.';

                return;
            }
        }
        if (\array_key_exists('type', $schema)) {
            $types = \is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $actual = self::typeOf($value);
            $match = \in_array($actual, $types, true)
                || ($actual === 'integer' && \in_array('number', $types, true));
            if (!$match) {
                $errors[] = sprintf('%s : %s attendu, %s reçu.', $at, implode(' ou ', $types), $actual);

                return;
            }
        }
        $kind = self::typeOf($value);
        if ($kind === 'string') {
            $len = mb_strlen($value);
            if (isset($schema['minLength']) && $len < $schema['minLength']) {
                $errors[] = sprintf('%s : %d caractères minimum.', $at, $schema['minLength']);
            }
            if (isset($schema['maxLength']) && $len > $schema['maxLength']) {
                $errors[] = sprintf('%s : %d caractères maximum.', $at, $schema['maxLength']);
            }
            if (\is_string($schema['pattern'] ?? null) && @preg_match(self::regex($schema['pattern']), $value) !== 1) {
                $errors[] = sprintf('%s : ne respecte pas le motif %s.', $at, $schema['pattern']);
            }
        }
        if ($kind === 'integer' || $kind === 'number') {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = sprintf('%s : minimum %s.', $at, $schema['minimum']);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = sprintf('%s : maximum %s.', $at, $schema['maximum']);
            }
            if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
                $errors[] = sprintf('%s : strictement supérieur à %s.', $at, $schema['exclusiveMinimum']);
            }
            if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
                $errors[] = sprintf('%s : strictement inférieur à %s.', $at, $schema['exclusiveMaximum']);
            }
        }
        if ($kind === 'array') {
            $n = \count($value);
            if (isset($schema['minItems']) && $n < $schema['minItems']) {
                $errors[] = sprintf('%s : %d éléments minimum.', $at, $schema['minItems']);
            }
            if (isset($schema['maxItems']) && $n > $schema['maxItems']) {
                $errors[] = sprintf('%s : %d éléments maximum.', $at, $schema['maxItems']);
            }
            if (\is_array($schema['items'] ?? null)) {
                foreach (array_values($value) as $i => $item) {
                    self::checkValue($schema['items'], $item, $path.'/'.$i, $errors);
                }
            }
        }
        if ($kind === 'object') {
            $obj = $value instanceof \stdClass ? get_object_vars($value) : $value;
            foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
                if (!\array_key_exists($name, $obj)) {
                    $errors[] = sprintf('%s/%s : requis.', $path, $name);
                }
            }
            $props = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($obj as $name => $item) {
                if (\is_array($props[$name] ?? null)) {
                    self::checkValue($props[$name], $item, $path.'/'.$name, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = sprintf('%s/%s : propriété non prévue.', $path, $name);
                }
            }
        }
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => floor($value) === $value && abs($value) < 2 ** 53 ? 'integer' : 'number',
            \is_string($value) => 'string',
            $value instanceof \stdClass => 'object',
            \is_array($value) => ($value === [] || array_is_list($value)) ? 'array' : 'object',
            default => 'unknown',
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    private static function regex(string $pattern): string
    {
        return '/'.str_replace('/', '\/', $pattern).'/u';
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, true>
     */
    private static function storeNames(array $document): array
    {
        $names = [];
        foreach (\is_array($document['data']['stores'] ?? null) ? $document['data']['stores'] : [] as $k => $_) {
            $names[(string) $k] = true;
        }
        $walk = static function (mixed $node) use (&$walk, &$names): void {
            if (!\is_array($node)) {
                return;
            }
            if (($node['tagName'] ?? null) === 'sonic-store' && \is_string($node['attributes']['id'] ?? null)) {
                $names[$node['attributes']['id']] = true;
            }
            foreach (\is_array($node['nodes'] ?? null) ? $node['nodes'] : [] as $child) {
                $walk($child);
            }
        };
        foreach (\is_array($document['views'] ?? null) ? $document['views'] : [] as $view) {
            if (\is_array($view)) {
                $walk($view['root'] ?? null);
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, true>
     */
    private static function providerNames(array $document): array
    {
        $names = [];
        foreach (['transforms', 'sources'] as $section) {
            foreach (\is_array($document['data'][$section] ?? null) ? $document['data'][$section] : [] as $k => $_) {
                $names[(string) $k] = true;
            }
        }

        return $names;
    }
}
