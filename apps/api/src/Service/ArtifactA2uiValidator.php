<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Validation des vues A2UI d'un artefact (`views[].a2ui`, messages A2UI v0.9).
 *
 * Le viewer les rend avec @ladigitale/agent-stack (sonic-sdui profil safe, texte brut).
 * On ne garde ici que ce que ce rendu sait faire : les composants pris en charge, des
 * props littérales ou liées par chemin, pas de fonctions client. Tout le reste est
 * refusé à la publication plutôt que de casser à l'affichage.
 */
final class ArtifactA2uiValidator
{
    public const VERSION = 'v0.9';
    public const BASIC_CATALOG_ID = 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json';
    public const MAX_MESSAGES = 200;
    public const MAX_COMPONENTS_PER_MESSAGE = 500;
    public const MAX_STRING = 2000;

    /** Miroir de SUPPORTED_COMPONENTS (agent-stack, src/a2ui/basic-catalog.ts). */
    public const SUPPORTED_COMPONENTS = ['Row', 'Column', 'List', 'Text', 'Card', 'Divider', 'Icon', 'Image', 'Button', 'TextField'];

    private const MESSAGE_KINDS = ['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface'];
    private const ID = '/^[A-Za-z0-9_.-]{1,64}$/';
    private const SURFACE_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    public function validate(mixed $messages, string $path, array &$errors, int &$nodeCount): void
    {
        if (!\is_array($messages) || !array_is_list($messages) || $messages === []) {
            $errors[] = ['path' => $path, 'message' => 'a2ui : tableau non vide de messages A2UI attendu.'];

            return;
        }
        if (\count($messages) > self::MAX_MESSAGES) {
            $errors[] = ['path' => $path, 'message' => sprintf('Trop de messages A2UI (max %d).', self::MAX_MESSAGES)];

            return;
        }

        $surfaces = [];
        foreach ($messages as $i => $message) {
            $this->validateMessage($message, $path.'/'.$i, $surfaces, $errors, $nodeCount);
        }
    }

    /**
     * @param array<string, true>                          $surfaces
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateMessage(mixed $message, string $path, array &$surfaces, array &$errors, int &$nodeCount): void
    {
        if (!\is_array($message) || array_is_list($message)) {
            $errors[] = ['path' => $path, 'message' => 'Message A2UI invalide.'];

            return;
        }
        if (($message['version'] ?? null) !== self::VERSION) {
            $errors[] = ['path' => $path.'/version', 'message' => sprintf('version doit être "%s".', self::VERSION)];
        }
        $kinds = array_values(array_intersect(array_keys($message), self::MESSAGE_KINDS));
        if (\count($kinds) !== 1) {
            $errors[] = ['path' => $path, 'message' => 'Un message A2UI porte exactement un de : '.implode(', ', self::MESSAGE_KINDS).'.'];

            return;
        }
        foreach (array_keys($message) as $key) {
            if ($key !== 'version' && $key !== $kinds[0]) {
                $errors[] = ['path' => $path.'/'.$key, 'message' => 'Clé non autorisée.'];
            }
        }

        $kind = $kinds[0];
        $body = $message[$kind];
        $base = $path.'/'.$kind;
        if (!\is_array($body) || array_is_list($body)) {
            $errors[] = ['path' => $base, 'message' => 'Objet attendu.'];

            return;
        }

        $surfaceId = $body['surfaceId'] ?? null;
        if (!\is_string($surfaceId) || !preg_match(self::SURFACE_ID, $surfaceId)) {
            $errors[] = ['path' => $base.'/surfaceId', 'message' => 'surfaceId invalide (lettres, chiffres, _ et -, 64 max).'];

            return;
        }

        switch ($kind) {
            case 'createSurface':
                $this->allowKeys($body, ['surfaceId', 'catalogId', 'theme', 'sendDataModel'], $base, $errors);
                if (($body['catalogId'] ?? null) !== self::BASIC_CATALOG_ID) {
                    $errors[] = ['path' => $base.'/catalogId', 'message' => 'Seul le catalogue de base A2UI est pris en charge : '.self::BASIC_CATALOG_ID];
                }
                if (isset($body['sendDataModel']) && !\is_bool($body['sendDataModel'])) {
                    $errors[] = ['path' => $base.'/sendDataModel', 'message' => 'Booléen attendu.'];
                }
                if (isset($surfaces[$surfaceId])) {
                    $errors[] = ['path' => $base.'/surfaceId', 'message' => 'Surface déjà créée.'];
                }
                $surfaces[$surfaceId] = true;
                break;

            case 'deleteSurface':
                $this->allowKeys($body, ['surfaceId'], $base, $errors);
                $this->requireSurface($surfaces, $surfaceId, $base, $errors);
                unset($surfaces[$surfaceId]);
                break;

            case 'updateDataModel':
                $this->allowKeys($body, ['surfaceId', 'path', 'value'], $base, $errors);
                $this->requireSurface($surfaces, $surfaceId, $base, $errors);
                if (\array_key_exists('path', $body)) {
                    $msg = $this->pointerError($body['path'], true);
                    if ($msg !== null) {
                        $errors[] = ['path' => $base.'/path', 'message' => $msg];
                    }
                }
                break;

            case 'updateComponents':
                $this->allowKeys($body, ['surfaceId', 'components'], $base, $errors);
                $this->requireSurface($surfaces, $surfaceId, $base, $errors);
                $components = $body['components'] ?? null;
                if (!\is_array($components) || !array_is_list($components)) {
                    $errors[] = ['path' => $base.'/components', 'message' => 'components : tableau attendu.'];
                    break;
                }
                if (\count($components) > self::MAX_COMPONENTS_PER_MESSAGE) {
                    $errors[] = ['path' => $base.'/components', 'message' => sprintf('Trop de composants (max %d par message).', self::MAX_COMPONENTS_PER_MESSAGE)];
                    break;
                }
                foreach ($components as $j => $component) {
                    ++$nodeCount;
                    $this->validateComponent($component, $base.'/components/'.$j, $errors);
                }
                break;
        }
    }

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateComponent(mixed $c, string $path, array &$errors): void
    {
        if (!\is_array($c) || array_is_list($c)) {
            $errors[] = ['path' => $path, 'message' => 'Composant invalide.'];

            return;
        }
        $id = $c['id'] ?? null;
        if (!\is_string($id) || !preg_match(self::ID, $id)) {
            $errors[] = ['path' => $path.'/id', 'message' => 'id de composant invalide.'];
        }
        $type = $c['component'] ?? null;
        if (!\is_string($type) || !\in_array($type, self::SUPPORTED_COMPONENTS, true)) {
            $errors[] = ['path' => $path.'/component', 'message' => sprintf(
                'Composant A2UI non pris en charge : %s (pris en charge : %s).',
                \is_string($type) ? $type : '?',
                implode(', ', self::SUPPORTED_COMPONENTS),
            )];
        }

        foreach ($c as $prop => $value) {
            $p = $path.'/'.$prop;
            switch ($prop) {
                case 'id':
                case 'component':
                    break;
                case 'children':
                    if (!\is_array($value) || !array_is_list($value)) {
                        $errors[] = ['path' => $p, 'message' => 'children : liste d’ids attendue (listes à gabarit non prises en charge).'];
                        break;
                    }
                    foreach ($value as $k => $child) {
                        if (!\is_string($child) || !preg_match(self::ID, $child)) {
                            $errors[] = ['path' => $p.'/'.$k, 'message' => 'id enfant invalide.'];
                        }
                    }
                    break;
                case 'child':
                    if (!\is_string($value) || !preg_match(self::ID, $value)) {
                        $errors[] = ['path' => $p, 'message' => 'child : id attendu.'];
                    }
                    break;
                case 'weight':
                    if (!\is_int($value) && !\is_float($value)) {
                        $errors[] = ['path' => $p, 'message' => 'weight : nombre attendu.'];
                    }
                    break;
                case 'action':
                    $this->validateAction($value, $p, $errors);
                    break;
                case 'checks':
                    // Ignorés au rendu (avertissement) : on les tolère sans les interpréter.
                    if (!\is_array($value)) {
                        $errors[] = ['path' => $p, 'message' => 'checks : tableau attendu.'];
                    }
                    break;
                default:
                    $this->validateProp((string) $prop, $value, $p, $errors);
            }
        }
    }

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateProp(string $prop, mixed $value, string $path, array &$errors): void
    {
        if (\is_string($value)) {
            if (mb_strlen($value) > self::MAX_STRING) {
                $errors[] = ['path' => $path, 'message' => sprintf('Texte trop long (max %d).', self::MAX_STRING)];
            }
            if (strcasecmp($prop, 'url') === 0 && !str_starts_with(strtolower(trim($value)), 'https:')) {
                $errors[] = ['path' => $path, 'message' => 'URL https uniquement.'];
            }

            return;
        }
        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return;
        }
        if (\is_array($value) && \array_key_exists('call', $value)) {
            $errors[] = ['path' => $path, 'message' => 'Fonctions A2UI (call) non prises en charge.'];

            return;
        }
        if (\is_array($value) && array_keys($value) === ['path']) {
            $msg = $this->pointerError($value['path'], false);
            if ($msg !== null) {
                $errors[] = ['path' => $path.'/path', 'message' => $msg];
            }

            return;
        }
        $errors[] = ['path' => $path, 'message' => 'Valeur attendue : texte, nombre, booléen ou {"path": "/…"}.'];
    }

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateAction(mixed $action, string $path, array &$errors): void
    {
        if (!\is_array($action) || array_keys($action) !== ['event']) {
            $errors[] = ['path' => $path, 'message' => 'action : {"event": {"name", "context"}} attendu (functionCall non pris en charge).'];

            return;
        }
        $event = $action['event'];
        if (!\is_array($event) || !\is_string($event['name'] ?? null) || !preg_match('/^[A-Za-z][\w:.-]{0,63}$/', $event['name'])) {
            $errors[] = ['path' => $path.'/event/name', 'message' => 'Nom d’action invalide.'];
        }
        if (!\is_array($event)) {
            return;
        }
        $this->allowKeys($event, ['name', 'context'], $path.'/event', $errors);
        if (isset($event['context'])) {
            if (!\is_array($event['context']) || ($event['context'] !== [] && array_is_list($event['context']))) {
                $errors[] = ['path' => $path.'/event/context', 'message' => 'context : objet attendu.'];

                return;
            }
            foreach ($event['context'] as $key => $value) {
                if (\is_array($value) && array_keys($value) === ['path']) {
                    $msg = $this->pointerError($value['path'], false);
                    if ($msg !== null) {
                        $errors[] = ['path' => $path.'/event/context/'.$key, 'message' => $msg];
                    }
                } elseif (!\is_scalar($value) && $value !== null) {
                    $errors[] = ['path' => $path.'/event/context/'.$key, 'message' => 'Valeur littérale ou {"path"} attendue.'];
                }
            }
        }
    }

    /**
     * Chemins absolus uniquement (pas de gabarits), segments sans "." (chemins Concorde).
     */
    private function pointerError(mixed $pointer, bool $rootAllowed): ?string
    {
        if (!\is_string($pointer)) {
            return 'Chemin : texte attendu.';
        }
        if ($pointer === '' || $pointer === '/') {
            return $rootAllowed ? null : 'Chemin vers une valeur attendu (pas la racine).';
        }
        if (!str_starts_with($pointer, '/')) {
            return 'Chemin absolu attendu (commence par "/").';
        }
        foreach (explode('/', substr($pointer, 1)) as $segment) {
            if ($segment === '' || str_contains($segment, '.')) {
                return 'Segment de chemin vide ou contenant "." : non pris en charge.';
            }
        }

        return null;
    }

    /**
     * @param array<string, true>                          $surfaces
     * @param list<array{path: string, message: string}> $errors
     */
    private function requireSurface(array $surfaces, string $surfaceId, string $path, array &$errors): void
    {
        if (!isset($surfaces[$surfaceId])) {
            $errors[] = ['path' => $path.'/surfaceId', 'message' => 'Surface inconnue : createSurface doit la précéder.'];
        }
    }

    /**
     * @param array<string, mixed>                       $object
     * @param list<string>                               $allowed
     * @param list<array{path: string, message: string}> $errors
     */
    private function allowKeys(array $object, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($object) as $key) {
            if (!\in_array($key, $allowed, true)) {
                $errors[] = ['path' => $path.'/'.$key, 'message' => 'Clé non autorisée.'];
            }
        }
    }
}
