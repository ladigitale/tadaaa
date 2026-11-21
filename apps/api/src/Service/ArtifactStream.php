<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Flux d'un run (bloc `io.stream`) : un émetteur quelconque (script, agent, capteur, autre
 * artefact…) pousse des messages dans un run interactif / intégré ouvert, le viewer et les
 * spectateurs les appliquent en direct. Classe pure (pas d'I/O) pour être testable.
 *
 * Déclaration :
 *   "stream": {
 *     "store": "scene",                         // store visé (défaut : io.input.store)
 *     "actions": {"palette": {"schema": {…}}, "pulse": {}},   // ou ["palette", "pulse"]
 *     "state": true,                            // state.snapshot / state.delta (JSON Patch sur l'état du store)
 *     "document": true,                         // document.patch (JSON Patch sur le document affiché)
 *     "score": true,                            // partitions jouées localement par le viewer
 *     "rate": 30,                               // messages par seconde acceptés (1…120, défaut 20)
 *     "progress": {"from": "scene.progress", "every": 1000},  // le viewer publie cette valeur (ms min. entre deux)
 *     "emit": {"from": "pad.outbox", "ack": "pad"}             // file d'envoi vers d'autres runs (liens)
 *   }
 *
 * `emit` : le viewer du run publie chaque élément {id, data} de la file `from` (une fois par id),
 * puis envoie {type: "emit:ok", payload: {id}} au store `ack` qui le retire de sa file. Les liens
 * entre runs ({@see ArtifactRunLinkService}) renvoient ces éléments dans le flux d'autres runs.
 * Un run qui ne fait qu'émettre n'a pas besoin de store ni d'actions.
 *
 * Messages entrants (AG-UI accepté : CUSTOM → action, STATE_SNAPSHOT, STATE_DELTA) :
 *   {"type": "action", "name": "palette", "payload": …}
 *   {"type": "state.snapshot", "state": …}       {"type": "state.delta", "patch": [ops]}
 *   {"type": "document.patch", "patch": [ops]}
 *   {"type": "score", "score": {…}}              {"type": "score.stop"}
 *   {"type": "end"}                              // fin du flux : le viewer publie sa sortie
 *
 * Partition (jouée par le viewer à partir de l'heure serveur `t` du message, donc synchrone chez
 * tous les spectateurs, même arrivés en cours) :
 *   {"duration": 8000, "loop": true, "speed": 1,
 *    "events": [{"at": 0, "action": "palette", "payload": …}],
 *    "tracks": [{"path": "/hue", "keys": [{"at": 0, "value": 0.1}, {"at": 4000, "value": 0.9, "ease": "inOut"}]}]}
 *   `events` déclenchent des actions déclarées ; `tracks` interpolent des valeurs (nombres, couleurs
 *   #rrggbb, ou paliers pour le reste) écrites dans l'état du store au pointeur `path`.
 *
 * Messages sortants (publiés par le viewer qui exécute le run) : progress {value}, snapshot
 * {seq, state} (pour les spectateurs qui arrivent en cours), emit {id, data, to?} (file d'envoi ;
 * `to` = runId ou liste de runIds : seuls les liens vers ces runs le reçoivent), ou une commande
 * d'appairage dans la même file : emit {id, op: "kick", runId} (libère la place de cet appareil et
 * termine son run), emit {id, op: "lock" | "unlock"} (ferme / rouvre les inscriptions).
 */
final class ArtifactStream
{
    public const MAX_MESSAGE_BYTES = 32768;
    public const MAX_BATCH = 64;
    public const MAX_BATCH_BYTES = 60000;
    public const MAX_RATE = 120;
    public const DEFAULT_RATE = 20;
    public const MAX_ACTIONS = 32;
    public const MAX_SCORE_EVENTS = 2000;
    public const MAX_SCORE_TRACKS = 32;
    public const MAX_TRACK_KEYS = 500;
    public const MAX_SCORE_DURATION = 86_400_000;
    public const MIN_PROGRESS_EVERY = 100;
    public const EASES = ['linear', 'in', 'out', 'inOut', 'step'];
    public const IN_TYPES = ['action', 'state.snapshot', 'state.delta', 'document.patch', 'score', 'score.stop', 'end'];
    public const OUT_TYPES = ['progress', 'snapshot', 'emit', 'alive', 'bye'];
    public const MAX_PAIRS = 32;
    public const DEFAULT_PAIRS = 8;
    public const DEFAULT_PAIR_TTL = 900;
    public const MAX_PAIR_MAP = 4000;
    public const MAX_EMIT_ID = 64;
    public const MAX_EMIT_TO = 64;
    public const MAX_PAIR_ROLES = 4;
    public const EMIT_OPS = ['kick', 'lock', 'unlock'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const ROLE = '/^[a-z][a-z0-9_-]{0,23}$/';

    private const ACTION = '/^[a-zA-Z][a-zA-Z0-9_:.-]{0,63}$/';
    private const DOTTED = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}(\.[a-zA-Z0-9_-]{1,64}){0,8}$/';
    private const POINTER = '#^(/([^/~]|~[01])*)*$#';
    /** Parties du document qu'un flux ne peut pas réécrire (contrat, fichiers séparés). */
    private const LOCKED_PATHS = ['/io', '/files', '/schema'];

    /**
     * @param array<string, true> $stores   noms des stores du document
     * @param array<string, true> $providers DataProviders connus (stores, transforms, sources)
     *
     * @return list<array{path: string, message: string}>
     */
    public static function validateDeclaration(mixed $stream, ?string $inputStore, array $stores, array $providers): array
    {
        $p = '/io/stream';
        if (!\is_array($stream) || array_is_list($stream)) {
            return [['path' => $p, 'message' => 'stream doit être un objet {store?, actions?, state?, document?, score?, rate?, progress?, emit?}.']];
        }
        $errors = [];
        foreach (array_keys($stream) as $k) {
            if (!\in_array($k, ['store', 'actions', 'state', 'document', 'score', 'rate', 'progress', 'emit', 'pair'], true)) {
                $errors[] = ['path' => $p.'/'.$k, 'message' => 'Clé non autorisée (store, actions, state, document, score, rate, progress, emit, pair).'];
            }
        }
        $accepts = self::actionNames($stream) !== [] || ($stream['state'] ?? false) === true || ($stream['document'] ?? false) === true || ($stream['score'] ?? false) === true;
        $store = $stream['store'] ?? $inputStore;
        if (($accepts || \array_key_exists('store', $stream)) && (!\is_string($store) || !isset($stores[$store]))) {
            $errors[] = ['path' => $p.'/store', 'message' => \is_string($store)
                ? sprintf('« %s » : aucun store de ce nom (data.stores ou sonic-store id).', $store)
                : 'store requis (ou io.input.store) : le store qui reçoit les messages du flux.'];
        }
        if (\array_key_exists('actions', $stream)) {
            $errors = [...$errors, ...self::checkActions($stream['actions'], $p.'/actions')];
        }
        foreach (['state', 'document', 'score'] as $flag) {
            if (\array_key_exists($flag, $stream) && !\is_bool($stream[$flag])) {
                $errors[] = ['path' => $p.'/'.$flag, 'message' => $flag.' : booléen.'];
            }
        }
        if (!$accepts && !\array_key_exists('emit', $stream)) {
            $errors[] = ['path' => $p, 'message' => 'Un flux doit accepter quelque chose (actions, state, document, score) ou émettre (emit).'];
        }
        if (\array_key_exists('emit', $stream)) {
            $em = $stream['emit'];
            if (!\is_array($em) || array_is_list($em) || array_diff(array_keys($em), ['from', 'ack']) !== []) {
                $errors[] = ['path' => $p.'/emit', 'message' => 'emit : {from: "dataProvider.chemin" (file [{id, data}]), ack?: "store"}.'];
            } else {
                $from = $em['from'] ?? null;
                if (!\is_string($from) || !preg_match(self::DOTTED, $from) || !isset($providers[explode('.', $from)[0]])) {
                    $errors[] = ['path' => $p.'/emit/from', 'message' => 'from : "dataProvider.chemin" existant (tableau [{id, data}]).'];
                }
                if (\array_key_exists('ack', $em) && (!\is_string($em['ack']) || !isset($stores[$em['ack']]))) {
                    $errors[] = ['path' => $p.'/emit/ack', 'message' => 'ack : nom d’un store (reçoit {type: "emit:ok", payload: {id}}).'];
                }
            }
        }
        if (\array_key_exists('pair', $stream)) {
            $errors = [...$errors, ...self::checkPair($stream['pair'], $accepts, \array_key_exists('emit', $stream), $p.'/pair')];
        }
        if (\array_key_exists('rate', $stream) && (!\is_int($stream['rate']) || $stream['rate'] < 1 || $stream['rate'] > self::MAX_RATE)) {
            $errors[] = ['path' => $p.'/rate', 'message' => sprintf('rate : entier 1…%d (messages par seconde).', self::MAX_RATE)];
        }
        if (\array_key_exists('progress', $stream)) {
            $pr = $stream['progress'];
            if (!\is_array($pr) || array_is_list($pr) || array_diff(array_keys($pr), ['from', 'every']) !== []) {
                $errors[] = ['path' => $p.'/progress', 'message' => 'progress : {from: "dataProvider.chemin", every?: ms}.'];
            } else {
                $from = $pr['from'] ?? null;
                if (!\is_string($from) || !preg_match(self::DOTTED, $from) || !isset($providers[explode('.', $from)[0]])) {
                    $errors[] = ['path' => $p.'/progress/from', 'message' => 'from : "dataProvider" ou "dataProvider.chemin" existant.'];
                }
                if (\array_key_exists('every', $pr) && (!\is_int($pr['every']) || $pr['every'] < self::MIN_PROGRESS_EVERY || $pr['every'] > 3_600_000)) {
                    $errors[] = ['path' => $p.'/progress/every', 'message' => sprintf('every : entier %d…3600000 (ms).', self::MIN_PROGRESS_EVERY)];
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $stream
     *
     * @return list<string>
     */
    public static function actionNames(array $stream): array
    {
        $actions = $stream['actions'] ?? [];
        if (!\is_array($actions)) {
            return [];
        }

        return array_values(array_filter(array_is_list($actions) ? $actions : array_keys($actions), 'is_string'));
    }

    /** Le flux déclare-t-il une file d'envoi (emit) ? @param array<string, mixed> $io */
    public static function emits(array $io): bool
    {
        return \is_array($io['stream']['emit'] ?? null);
    }

    /** Le flux accepte-t-il des messages (pas seulement emit) ? @param array<string, mixed> $io */
    public static function accepts(array $io): bool
    {
        $s = \is_array($io['stream'] ?? null) ? $io['stream'] : [];

        return self::actionNames($s) !== [] || ($s['state'] ?? false) === true || ($s['document'] ?? false) === true || ($s['score'] ?? false) === true;
    }

    /** Store visé par le flux. @param array<string, mixed> $io */
    public static function store(array $io): ?string
    {
        $s = $io['stream']['store'] ?? $io['input']['store'] ?? null;

        return \is_string($s) ? $s : null;
    }

    public static function rate(array $stream): int
    {
        return \is_int($stream['rate'] ?? null) ? $stream['rate'] : self::DEFAULT_RATE;
    }

    /** Contrat publié (outil) du flux. @param array<string, mixed> $stream @return array<string, mixed> */
    public static function describe(array $stream): array
    {
        $actions = [];
        foreach (self::actionNames($stream) as $name) {
            $schema = \is_array($stream['actions'][$name]['schema'] ?? null) ? $stream['actions'][$name]['schema'] : null;
            $actions[$name] = $schema !== null ? ['schema' => $schema] : new \stdClass();
        }

        return [
            'actions' => $actions === [] ? new \stdClass() : $actions,
            'state' => ($stream['state'] ?? false) === true,
            'document' => ($stream['document'] ?? false) === true,
            'score' => ($stream['score'] ?? false) === true,
            'rate' => self::rate($stream),
            'progress' => \is_array($stream['progress'] ?? null),
            'emit' => \is_array($stream['emit'] ?? null),
            'pair' => self::describePair(self::pairEntries($stream)),
        ];
    }

    /**
     * Normalise et vérifie un message entrant (décodé en objets : les {} restent des objets).
     * Accepte les événements AG-UI CUSTOM, STATE_SNAPSHOT, STATE_DELTA.
     *
     * @param array<string, mixed> $stream déclaration (forme tableau)
     *
     * @return array{0: ?\stdClass, 1: list<string>} [message normalisé, erreurs]
     */
    public static function normalizeIn(array $stream, mixed $msg): array
    {
        if (!$msg instanceof \stdClass || !\is_string($msg->type ?? null)) {
            return [null, ['message attendu : objet {type, …}.']];
        }
        $bytes = \strlen((string) json_encode($msg));
        if ($bytes > self::MAX_MESSAGE_BYTES) {
            return [null, [sprintf('message trop volumineux (%d octets max).', self::MAX_MESSAGE_BYTES)]];
        }
        $type = match ($msg->type) {
            'CUSTOM' => 'action',
            'STATE_SNAPSHOT' => 'state.snapshot',
            'STATE_DELTA' => 'state.delta',
            default => $msg->type,
        };
        if (!\in_array($type, self::IN_TYPES, true)) {
            return [null, [sprintf('type « %s » inconnu (%s, ou CUSTOM / STATE_SNAPSHOT / STATE_DELTA d’AG-UI).', $msg->type, implode(', ', self::IN_TYPES))]];
        }
        $out = new \stdClass();
        $out->type = $type;

        switch ($type) {
            case 'action':
                $name = $msg->name ?? null;
                $payload = property_exists($msg, 'payload') ? $msg->payload : ($msg->value ?? null);
                if (!\is_string($name) || !\in_array($name, self::actionNames($stream), true)) {
                    return [null, [sprintf('action « %s » non déclarée dans io.stream.actions (%s).', \is_string($name) ? $name : '?', implode(', ', self::actionNames($stream)) ?: 'aucune')]];
                }
                $schema = $stream['actions'][$name]['schema'] ?? null;
                if (\is_array($schema) && !array_is_list($stream['actions'])) {
                    $errors = ArtifactIo::validateValue($schema, $payload, '');
                    if ($errors !== []) {
                        return [null, array_map(static fn (string $e): string => 'payload : '.$e, \array_slice($errors, 0, 5))];
                    }
                }
                $out->name = $name;
                $out->payload = $payload;
                break;

            case 'state.snapshot':
                if (($stream['state'] ?? false) !== true) {
                    return [null, ['état direct non autorisé (io.stream.state).']];
                }
                $state = property_exists($msg, 'state') ? $msg->state : ($msg->snapshot ?? null);
                if (!$state instanceof \stdClass) {
                    return [null, ['state.snapshot : {state: {…}} (objet).']];
                }
                $out->state = $state;
                break;

            case 'state.delta':
            case 'document.patch':
                $flag = $type === 'state.delta' ? 'state' : 'document';
                if (($stream[$flag] ?? false) !== true) {
                    return [null, [sprintf('%s non autorisé (io.stream.%s).', $type, $flag)]];
                }
                $ops = property_exists($msg, 'patch') ? $msg->patch : ($msg->delta ?? null);
                $errors = self::checkPatch($ops, $type === 'document.patch');
                if ($errors !== []) {
                    return [null, $errors];
                }
                $out->patch = $ops;
                break;

            case 'score':
                if (($stream['score'] ?? false) !== true) {
                    return [null, ['partitions non autorisées (io.stream.score).']];
                }
                $errors = self::checkScore($msg->score ?? null, self::actionNames($stream));
                if ($errors !== []) {
                    return [null, $errors];
                }
                $out->score = $msg->score;
                break;

            case 'score.stop':
                if (($stream['score'] ?? false) !== true) {
                    return [null, ['partitions non autorisées (io.stream.score).']];
                }
                break;
        }

        return [$out, []];
    }

    /**
     * Message sortant publié par le viewer (jeton du run).
     *
     * @return array{0: ?\stdClass, 1: list<string>}
     */
    public static function normalizeOut(mixed $msg): array
    {
        if (!$msg instanceof \stdClass || !\in_array($msg->type ?? null, self::OUT_TYPES, true)) {
            return [null, ['message attendu : {type: progress|snapshot|emit|alive|bye, …}.']];
        }
        if (\strlen((string) json_encode($msg)) > self::MAX_MESSAGE_BYTES) {
            return [null, [sprintf('message trop volumineux (%d octets max).', self::MAX_MESSAGE_BYTES)]];
        }
        $out = new \stdClass();
        $out->type = $msg->type;
        if ($msg->type === 'alive' || $msg->type === 'bye') {
            // Présence : signe de vie du viewer (toutes les 5 s) ou départ (fermeture de la page).
        } elseif ($msg->type === 'progress') {
            $out->value = $msg->value ?? null;
        } elseif ($msg->type === 'emit') {
            $id = $msg->id ?? null;
            if (!(\is_string($id) && $id !== '' && mb_strlen($id) <= self::MAX_EMIT_ID) && !\is_int($id)) {
                return [null, [sprintf('emit : {id (texte de %d car. au plus ou entier), data}.', self::MAX_EMIT_ID)]];
            }
            $out->id = $id;
            $op = $msg->op ?? null;
            if ($op !== null) {
                if (!\in_array($op, self::EMIT_OPS, true)) {
                    return [null, ['emit : op vaut kick (avec runId), lock ou unlock.']];
                }
                $out->op = $op;
                if ($op === 'kick') {
                    $rid = $msg->runId ?? null;
                    if (!\is_string($rid) || !preg_match(self::UUID, strtolower($rid))) {
                        return [null, ['emit : op kick attend runId (run de l’appareil à retirer).']];
                    }
                    $out->runId = strtolower($rid);
                }

                return [$out, []];
            }
            $out->data = $msg->data ?? null;
            if (isset($msg->to)) {
                $to = \is_string($msg->to) ? [$msg->to] : $msg->to;
                if (!\is_array($to) || $to === [] || \count($to) > self::MAX_EMIT_TO) {
                    return [null, [sprintf('emit : to = runId ou liste de 1 à %d runIds.', self::MAX_EMIT_TO)]];
                }
                $ids = [];
                foreach ($to as $r) {
                    if (!\is_string($r) || !preg_match(self::UUID, strtolower($r))) {
                        return [null, ['emit : to contient un runId invalide.']];
                    }
                    $ids[] = strtolower($r);
                }
                $out->to = array_values(array_unique($ids));
            }
        } else {
            if (!\is_int($msg->seq ?? null) || $msg->seq < 0) {
                return [null, ['snapshot : {seq, state} (seq = dernier message appliqué).']];
            }
            $out->seq = $msg->seq;
            $out->state = $msg->state ?? null;
        }

        return [$out, []];
    }

    /**
     * Opérations JSON Patch (structure ; l'application vérifie le reste).
     *
     * @return list<string>
     */
    public static function checkPatch(mixed $ops, bool $document): array
    {
        if (!\is_array($ops) || !array_is_list($ops) || $ops === [] || \count($ops) > JsonPatch::MAX_OPS) {
            return [sprintf('patch : liste de 1 à %d opérations JSON Patch {op, path, value?, from?}.', JsonPatch::MAX_OPS)];
        }
        foreach ($ops as $i => $op) {
            if (!$op instanceof \stdClass || !\in_array($op->op ?? null, ['add', 'remove', 'replace', 'move', 'copy', 'test'], true)
                || !\is_string($op->path ?? null) || !preg_match(self::POINTER, $op->path)) {
                return [sprintf('patch[%d] : {op: add|remove|replace|move|copy|test, path: "/pointeur", …}.', $i)];
            }
            if (\in_array($op->op, ['move', 'copy'], true) && (!\is_string($op->from ?? null) || !preg_match(self::POINTER, $op->from))) {
                return [sprintf('patch[%d] : from requis (pointeur).', $i)];
            }
            if ($document) {
                foreach ([$op->path, $op->from ?? null] as $ptr) {
                    if (!\is_string($ptr)) {
                        continue;
                    }
                    if ($ptr === '') {
                        return [sprintf('patch[%d] : remplacer tout le document n’est pas permis (viser une partie).', $i)];
                    }
                    foreach (self::LOCKED_PATHS as $locked) {
                        if ($ptr === $locked || str_starts_with($ptr, $locked.'/')) {
                            return [sprintf('patch[%d] : %s ne change pas pendant un run.', $i, $locked)];
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param list<string> $actions actions déclarées
     *
     * @return list<string>
     */
    public static function checkScore(mixed $score, array $actions): array
    {
        if (!$score instanceof \stdClass) {
            return ['score : objet {duration?, loop?, speed?, events?, tracks?}.'];
        }
        $allowed = ['duration', 'loop', 'speed', 'events', 'tracks'];
        foreach (array_keys(get_object_vars($score)) as $k) {
            if (!\in_array($k, $allowed, true)) {
                return [sprintf('score.%s : clé non autorisée (%s).', $k, implode(', ', $allowed))];
            }
        }
        $errors = [];
        $num = static fn (mixed $v): bool => (\is_int($v) || \is_float($v)) && is_finite((float) $v);
        if (property_exists($score, 'duration') && (!$num($score->duration) || $score->duration <= 0 || $score->duration > self::MAX_SCORE_DURATION)) {
            $errors[] = sprintf('score.duration : ms, 1…%d.', self::MAX_SCORE_DURATION);
        }
        if (property_exists($score, 'loop') && !\is_bool($score->loop)) {
            $errors[] = 'score.loop : booléen.';
        }
        if (property_exists($score, 'speed') && (!$num($score->speed) || $score->speed <= 0 || $score->speed > 16)) {
            $errors[] = 'score.speed : nombre > 0 (16 max).';
        }
        $events = $score->events ?? [];
        if (!\is_array($events) || !array_is_list($events) || \count($events) > self::MAX_SCORE_EVENTS) {
            $errors[] = sprintf('score.events : liste de %d événements {at, action, payload?} au plus.', self::MAX_SCORE_EVENTS);
            $events = [];
        }
        foreach ($events as $i => $e) {
            if (!$e instanceof \stdClass || !$num($e->at ?? null) || $e->at < 0 || !\is_string($e->action ?? null)) {
                $errors[] = sprintf('score.events[%d] : {at: ms ≥ 0, action, payload?}.', $i);
                break;
            }
            if (!\in_array($e->action, $actions, true)) {
                $errors[] = sprintf('score.events[%d] : action « %s » non déclarée dans io.stream.actions.', $i, $e->action);
                break;
            }
        }
        $tracks = $score->tracks ?? [];
        if (!\is_array($tracks) || !array_is_list($tracks) || \count($tracks) > self::MAX_SCORE_TRACKS) {
            $errors[] = sprintf('score.tracks : liste de %d pistes {path, keys} au plus.', self::MAX_SCORE_TRACKS);
            $tracks = [];
        }
        foreach ($tracks as $i => $t) {
            if (!$t instanceof \stdClass || !\is_string($t->path ?? null) || $t->path === '' || !preg_match(self::POINTER, $t->path)) {
                $errors[] = sprintf('score.tracks[%d].path : pointeur JSON dans l’état du store ("/hue").', $i);
                break;
            }
            $keys = $t->keys ?? null;
            if (!\is_array($keys) || !array_is_list($keys) || $keys === [] || \count($keys) > self::MAX_TRACK_KEYS) {
                $errors[] = sprintf('score.tracks[%d].keys : 1 à %d clés {at, value, ease?}.', $i, self::MAX_TRACK_KEYS);
                break;
            }
            $last = -1;
            foreach ($keys as $j => $key) {
                if (!$key instanceof \stdClass || !$num($key->at ?? null) || $key->at < 0 || !property_exists($key, 'value')) {
                    $errors[] = sprintf('score.tracks[%d].keys[%d] : {at: ms ≥ 0, value, ease?}.', $i, $j);
                    break 2;
                }
                if ($key->at < $last) {
                    $errors[] = sprintf('score.tracks[%d].keys : at croissants.', $i);
                    break 2;
                }
                $last = $key->at;
                if (property_exists($key, 'ease') && !\in_array($key->ease, self::EASES, true)) {
                    $errors[] = sprintf('score.tracks[%d].keys[%d].ease : %s.', $i, $j, implode(', ', self::EASES));
                    break 2;
                }
            }
        }

        return $errors;
    }

    /**
     * Appairage : `pair = {remote, map?, back?, max?, ttl?, input?}` ou une liste de ces objets avec
     * `role` (un code par rôle : équipes, types d'appareils) — le viewer du run montre un code
     * (QR) ; l'ouvrir lance un run de l'artefact `remote` relié à celui-ci (`map` : remote → ce run,
     * `back` : ce run → remote).
     *
     * @return list<array{path: string, message: string}>
     */
    private static function checkPair(mixed $pair, bool $accepts, bool $emits, string $p): array
    {
        if (\is_array($pair) && array_is_list($pair) && $pair !== []) {
            // Plusieurs rôles (équipes, types d'appareils) : un code chacun.
            if (\count($pair) > self::MAX_PAIR_ROLES) {
                return [['path' => $p, 'message' => sprintf('pair : %d rôles au plus.', self::MAX_PAIR_ROLES)]];
            }
            $errors = [];
            $roles = [];
            foreach ($pair as $i => $entry) {
                $role = \is_array($entry) ? ($entry['role'] ?? null) : null;
                if (!\is_string($role) || !preg_match(self::ROLE, $role)) {
                    $errors[] = ['path' => $p.'/'.$i.'/role', 'message' => 'role : nom du rôle (minuscules, chiffres, _ -, 24 car.), obligatoire quand pair est une liste.'];
                } elseif (isset($roles[$role])) {
                    $errors[] = ['path' => $p.'/'.$i.'/role', 'message' => sprintf('role « %s » en double.', $role)];
                } else {
                    $roles[$role] = true;
                }
                $errors = [...$errors, ...self::checkPairEntry($entry, $accepts, $emits, $p.'/'.$i, true)];
            }

            return $errors;
        }

        return self::checkPairEntry($pair, $accepts, $emits, $p, false);
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private static function describePair(array $entries): ?array
    {
        if ($entries === []) {
            return null;
        }
        $out = [];
        foreach ($entries as $role => $e) {
            $out[] = ($role === '' ? [] : ['role' => $role]) + ['remote' => $e['remote'] ?? null, 'max' => $e['max'] ?? self::DEFAULT_PAIRS];
        }

        return array_keys($entries) === [''] ? $out[0] : $out;
    }

    /**
     * Rôles d'appairage déclarés : [role => entrée] (rôle "" pour la forme objet).
     *
     * @param array<string, mixed> $stream bloc io.stream
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pairEntries(array $stream): array
    {
        $pair = $stream['pair'] ?? null;
        if (!\is_array($pair) || $pair === []) {
            return [];
        }
        if (!array_is_list($pair)) {
            return ['' => $pair];
        }
        $out = [];
        foreach ($pair as $entry) {
            if (\is_array($entry) && \is_string($entry['role'] ?? null)) {
                $out[$entry['role']] = $entry;
            }
        }

        return $out;
    }

    /** @return list<array{path: string, message: string}> */
    private static function checkPairEntry(mixed $pair, bool $accepts, bool $emits, string $p, bool $withRole): array
    {
        if (!\is_array($pair) || array_is_list($pair)) {
            return [['path' => $p, 'message' => 'pair : {remote: "slug", map?, back?, max?, ttl?, input?} ou liste de {role, remote, …}.']];
        }
        $errors = [];
        $allowed = ['remote', 'map', 'back', 'max', 'ttl', 'input', ...($withRole ? ['role'] : [])];
        foreach (array_keys($pair) as $k) {
            if (!\in_array($k, $allowed, true)) {
                $errors[] = ['path' => $p.'/'.$k, 'message' => sprintf('Clé non autorisée (%s).', implode(', ', $allowed))];
            }
        }
        if (!\is_string($pair['remote'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $pair['remote'])) {
            $errors[] = ['path' => $p.'/remote', 'message' => 'remote : slug de l’artefact ouvert par le code (même compte), qui déclare io.stream.'];
        }
        foreach (['map', 'back'] as $k) {
            if (\array_key_exists($k, $pair) && (!\is_string($pair[$k]) || trim($pair[$k]) === '' || mb_strlen($pair[$k]) > self::MAX_PAIR_MAP)) {
                $errors[] = ['path' => $p.'/'.$k, 'message' => sprintf('%s : expression JSONata (%d caractères au plus).', $k, self::MAX_PAIR_MAP)];
            }
        }
        if (!$accepts) {
            $errors[] = ['path' => $p, 'message' => 'pair : ce flux doit accepter les messages de la télécommande (actions, state, document ou score).'];
        }
        if (\array_key_exists('back', $pair) && !$emits) {
            $errors[] = ['path' => $p.'/back', 'message' => 'back suppose une file d’envoi sur ce run (io.stream.emit).'];
        }
        if (\array_key_exists('max', $pair) && (!\is_int($pair['max']) || $pair['max'] < 1 || $pair['max'] > self::MAX_PAIRS)) {
            $errors[] = ['path' => $p.'/max', 'message' => sprintf('max : entier 1…%d (appareils reliés par run).', self::MAX_PAIRS)];
        }
        if (\array_key_exists('ttl', $pair) && (!\is_int($pair['ttl']) || $pair['ttl'] < 60 || $pair['ttl'] > 86_400)) {
            $errors[] = ['path' => $p.'/ttl', 'message' => 'ttl : entier 60…86400 (secondes de validité d’un code).'];
        }
        if (\array_key_exists('input', $pair) && (!\is_array($pair['input']) || (array_is_list($pair['input']) && $pair['input'] !== []))) {
            $errors[] = ['path' => $p.'/input', 'message' => 'input : objet (entrée du run de la télécommande).'];
        }

        return $errors;
    }

    /** @return list<array{path: string, message: string}> */
    private static function checkActions(mixed $actions, string $p): array
    {
        if (!\is_array($actions) || \count($actions) > self::MAX_ACTIONS) {
            return [['path' => $p, 'message' => sprintf('actions : ["nom", …] ou {"nom": {schema?}} (%d max).', self::MAX_ACTIONS)]];
        }
        $errors = [];
        if (array_is_list($actions)) {
            foreach ($actions as $i => $name) {
                if (!\is_string($name) || !preg_match(self::ACTION, $name)) {
                    $errors[] = ['path' => $p.'/'.$i, 'message' => 'Nom d’action : lettres, chiffres, _ : . - (64 car.).'];
                }
            }
            if (\count(array_unique(array_filter($actions, 'is_string'))) !== \count($actions)) {
                $errors[] = ['path' => $p, 'message' => 'Actions en double.'];
            }

            return $errors;
        }
        foreach ($actions as $name => $def) {
            $ap = $p.'/'.$name;
            if (!preg_match(self::ACTION, (string) $name)) {
                $errors[] = ['path' => $ap, 'message' => 'Nom d’action : lettres, chiffres, _ : . - (64 car.).'];
                continue;
            }
            if (!\is_array($def) || (array_is_list($def) && $def !== []) || array_diff(array_keys($def), ['schema', 'description']) !== []) {
                $errors[] = ['path' => $ap, 'message' => 'Action : {schema?, description?}.'];
                continue;
            }
            if (\array_key_exists('schema', $def)) {
                $errors = [...$errors, ...ArtifactIo::checkSchema($def['schema'], $ap.'/schema')];
            }
            if (\array_key_exists('description', $def) && (!\is_string($def['description']) || mb_strlen($def['description']) > 300)) {
                $errors[] = ['path' => $ap.'/description', 'message' => 'description : texte (300 car. max).'];
            }
        }

        return $errors;
    }
}
