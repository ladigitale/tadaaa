<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Collecte contrôlée : schéma déclaré dans `data.sources.<x>.intake` + validation
 * stricte de chaque envoi anonyme. Classe pure (pas d'I/O) pour être testable.
 *
 * Déclaration :
 *   "intake": {
 *     "fields": {
 *       "name":  {"type": "string",  "max": 20, "required": true},
 *       "score": {"type": "integer", "min": 0, "max": 100000, "required": true},
 *       "level": {"type": "enum",    "values": ["facile", "moyen", "difficile"]}
 *     },
 *     "maxRecords": 40,       // par session ouverte (plafond dur HARD_MAX_RECORDS)
 *     "minInterval": 10,      // secondes entre deux envois d'une même IP
 *     "requireCode": false    // code à 4 chiffres généré à l'ouverture
 *   }
 */
final class ArtifactIntakeSchema
{
    public const MAX_FIELDS = 16;
    public const MAX_STRING = 200;
    public const HARD_MAX_RECORDS = 500;
    public const DEFAULT_MAX_RECORDS = 50;
    public const DEFAULT_MIN_INTERVAL = 10;
    public const MAX_SESSION_MINUTES = 1440;
    public const MAX_PAYLOAD_BYTES = 2048;

    private const TYPES = ['string', 'integer', 'number', 'boolean', 'enum'];
    private const FIELD_NAME = '/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/';

    /**
     * Vérifie une déclaration `intake` du document.
     *
     * @return list<string> messages d'erreur (vide = valide)
     */
    public static function validateDeclaration(mixed $intake): array
    {
        if (!\is_array($intake)) {
            return ['intake doit être un objet.'];
        }
        $errors = [];
        $fields = $intake['fields'] ?? null;
        if (!\is_array($fields) || $fields === [] || array_is_list($fields)) {
            return ['intake.fields doit être un objet non vide.'];
        }
        if (\count($fields) > self::MAX_FIELDS) {
            $errors[] = 'intake.fields : '.self::MAX_FIELDS.' champs maximum.';
        }
        foreach ($fields as $name => $def) {
            $p = 'intake.fields.'.$name;
            if (!\is_string($name) || !preg_match(self::FIELD_NAME, $name)) {
                $errors[] = $p.' : nom de champ invalide.';
                continue;
            }
            if (!\is_array($def) || !\in_array($def['type'] ?? null, self::TYPES, true)) {
                $errors[] = $p.'.type doit valoir '.implode(' | ', self::TYPES).'.';
                continue;
            }
            $type = $def['type'];
            if ($type === 'string') {
                $max = $def['max'] ?? null;
                if (!\is_int($max) || $max < 1 || $max > self::MAX_STRING) {
                    $errors[] = $p.'.max requis (1…'.self::MAX_STRING.').';
                }
            }
            if ($type === 'integer' || $type === 'number') {
                foreach (['min', 'max'] as $bound) {
                    if (!isset($def[$bound]) || !(\is_int($def[$bound]) || \is_float($def[$bound]))) {
                        $errors[] = $p.'.'.$bound.' requis pour un nombre.';
                    }
                }
                if (isset($def['min'], $def['max']) && is_numeric($def['min']) && is_numeric($def['max']) && $def['min'] > $def['max']) {
                    $errors[] = $p.' : min > max.';
                }
            }
            if ($type === 'enum') {
                $values = $def['values'] ?? null;
                if (!\is_array($values) || $values === [] || \count($values) > 50) {
                    $errors[] = $p.'.values : 1 à 50 valeurs.';
                } else {
                    foreach ($values as $v) {
                        if (!\is_string($v) || $v === '' || mb_strlen($v) > 60) {
                            $errors[] = $p.'.values : chaînes de 1 à 60 caractères.';
                            break;
                        }
                    }
                }
            }
            if (isset($def['required']) && !\is_bool($def['required'])) {
                $errors[] = $p.'.required doit être un booléen.';
            }
        }
        if (isset($intake['maxRecords']) && (!\is_int($intake['maxRecords']) || $intake['maxRecords'] < 1 || $intake['maxRecords'] > self::HARD_MAX_RECORDS)) {
            $errors[] = 'intake.maxRecords : 1…'.self::HARD_MAX_RECORDS.'.';
        }
        if (isset($intake['minInterval']) && (!\is_int($intake['minInterval']) || $intake['minInterval'] < 1 || $intake['minInterval'] > 3600)) {
            $errors[] = 'intake.minInterval : 1…3600 secondes.';
        }
        if (isset($intake['requireCode']) && !\is_bool($intake['requireCode'])) {
            $errors[] = 'intake.requireCode doit être un booléen.';
        }

        return $errors;
    }

    /**
     * Normalise une déclaration valide (valeurs par défaut appliquées).
     *
     * @return array{fields: array<string, array<string, mixed>>, maxRecords: int, minInterval: int, requireCode: bool}
     */
    public static function normalize(array $intake): array
    {
        $fields = [];
        foreach ($intake['fields'] as $name => $def) {
            $f = ['type' => $def['type'], 'required' => (bool) ($def['required'] ?? false)];
            foreach (['min', 'max', 'values'] as $k) {
                if (isset($def[$k])) {
                    $f[$k] = $def[$k];
                }
            }
            $fields[(string) $name] = $f;
        }

        return [
            'fields' => $fields,
            'maxRecords' => min(self::HARD_MAX_RECORDS, (int) ($intake['maxRecords'] ?? self::DEFAULT_MAX_RECORDS)),
            'minInterval' => (int) ($intake['minInterval'] ?? self::DEFAULT_MIN_INTERVAL),
            'requireCode' => (bool) ($intake['requireCode'] ?? false),
        ];
    }

    /**
     * Valide un envoi contre le schéma normalisé. Les champs inconnus sont refusés.
     *
     * @param array<string, array<string, mixed>> $fields
     *
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public static function validateRecord(array $fields, mixed $data): array
    {
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            return ['data' => [], 'errors' => ['data doit être un objet.']];
        }
        $encoded = json_encode($data);
        if ($encoded === false || \strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return ['data' => [], 'errors' => ['Envoi trop volumineux.']];
        }
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!\is_string($key) || !isset($fields[$key])) {
                $errors[] = 'Champ non autorisé : '.$key.'.';
            }
        }
        $out = [];
        foreach ($fields as $name => $def) {
            $present = \array_key_exists($name, $data) && $data[$name] !== null && $data[$name] !== '';
            if (!$present) {
                if ($def['required']) {
                    $errors[] = 'Champ requis : '.$name.'.';
                }
                continue;
            }
            $v = $data[$name];
            switch ($def['type']) {
                case 'string':
                    if (!\is_string($v)) {
                        $errors[] = $name.' : texte attendu.';
                        break;
                    }
                    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
                    if ($v === '' && $def['required']) {
                        $errors[] = 'Champ requis : '.$name.'.';
                        break;
                    }
                    // Pas de balisage : certains composants d'affichage rendent du HTML.
                    if (preg_match('/[<>]/', $v)) {
                        $errors[] = $name.' : caractères < et > non autorisés.';
                        break;
                    }
                    if (mb_strlen($v) > $def['max']) {
                        $errors[] = $name.' : '.$def['max'].' caractères maximum.';
                        break;
                    }
                    $out[$name] = $v;
                    break;
                case 'integer':
                    if (!\is_int($v) && !(\is_float($v) && floor($v) === $v)) {
                        $errors[] = $name.' : entier attendu.';
                        break;
                    }
                    $v = (int) $v;
                    if ($v < $def['min'] || $v > $def['max']) {
                        $errors[] = $name.' : hors limites ('.$def['min'].'…'.$def['max'].').';
                        break;
                    }
                    $out[$name] = $v;
                    break;
                case 'number':
                    if (!\is_int($v) && !\is_float($v) || !is_finite((float) $v)) {
                        $errors[] = $name.' : nombre attendu.';
                        break;
                    }
                    if ($v < $def['min'] || $v > $def['max']) {
                        $errors[] = $name.' : hors limites ('.$def['min'].'…'.$def['max'].').';
                        break;
                    }
                    $out[$name] = $v;
                    break;
                case 'boolean':
                    if (!\is_bool($v)) {
                        $errors[] = $name.' : booléen attendu.';
                        break;
                    }
                    $out[$name] = $v;
                    break;
                case 'enum':
                    if (!\is_string($v) || !\in_array($v, $def['values'], true)) {
                        $errors[] = $name.' : valeur non autorisée.';
                        break;
                    }
                    $out[$name] = $v;
                    break;
            }
        }

        return ['data' => $out, 'errors' => $errors];
    }
}
