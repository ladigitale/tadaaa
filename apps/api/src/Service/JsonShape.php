<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Objets JSON vides : `json_decode(..., true)` rend `{}` et `[]` tous deux en `[]`, et
 * `json_encode([])` écrit `[]`. Un `"answered": {}` d'un document d'artefact revenait donc
 * en `"answered": []`.
 *
 * Le code métier garde des tableaux associatifs ; on mémorise à part les emplacements
 * (pointeurs JSON, RFC 6901) des objets vides du JSON d'origine, et on les rétablit
 * (`\stdClass`) au moment de réencoder.
 */
final class JsonShape
{
    /**
     * Pointeurs des objets vides d'une valeur décodée avec `json_decode(..., false)`.
     *
     * @return list<string>
     */
    public static function emptyObjectPaths(mixed $raw, string $base = ''): array
    {
        $out = [];
        if ($raw instanceof \stdClass) {
            $props = get_object_vars($raw);
            if ($props === []) {
                return [$base];
            }
            foreach ($props as $key => $value) {
                array_push($out, ...self::emptyObjectPaths($value, $base.'/'.self::escape((string) $key)));
            }
        } elseif (\is_array($raw)) {
            foreach ($raw as $i => $value) {
                array_push($out, ...self::emptyObjectPaths($value, $base.'/'.$i));
            }
        }

        return $out;
    }

    /**
     * Pointeurs des objets vides d'un JSON brut, sous un sous-chemin (`/params/arguments/document`).
     *
     * @return list<string>
     */
    public static function emptyObjectPathsInJson(string $json, string $at = ''): array
    {
        try {
            $raw = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $node = self::at($raw, $at);

        return $node === null ? [] : self::emptyObjectPaths($node);
    }

    /**
     * Ne garde que les pointeurs qui désignent bien un `[]` de `$assoc` (entrée non fiable,
     * ou document modifié entre-temps).
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public static function filter(mixed $assoc, array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (!\is_string($path)) {
                continue;
            }
            $found = true;
            $value = self::get($assoc, $path, $found);
            if ($found && $value === []) {
                $out[] = $path;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Rétablit les objets vides : la valeur rendue, passée à `json_encode`, écrit `{}` aux
     * emplacements donnés. Les pointeurs qui ne désignent pas un `[]` sont ignorés.
     *
     * @param list<string> $paths
     */
    public static function restore(mixed $assoc, array $paths): mixed
    {
        // Les plus profonds d'abord : un objet vide ne contient rien d'autre à rétablir.
        foreach (self::filter($assoc, $paths) as $path) {
            $assoc = self::replace($assoc, self::split($path));
        }

        return $assoc;
    }

    /** Sous-valeur d'une valeur décodée en objets (`/a/0/b`), ou null. */
    public static function at(mixed $raw, string $pointer): mixed
    {
        foreach (self::split($pointer) as $segment) {
            if ($raw instanceof \stdClass && property_exists($raw, $segment)) {
                $raw = $raw->{$segment};
            } elseif (\is_array($raw) && ctype_digit($segment) && \array_key_exists((int) $segment, $raw)) {
                $raw = $raw[(int) $segment];
            } else {
                return null;
            }
        }

        return $raw;
    }

    private static function get(mixed $assoc, string $pointer, bool &$found): mixed
    {
        foreach (self::split($pointer) as $segment) {
            if (\is_array($assoc) && \array_key_exists($segment, $assoc)) {
                $assoc = $assoc[$segment];
            } else {
                $found = false;

                return null;
            }
        }

        return $assoc;
    }

    /** @param list<string> $segments */
    private static function replace(mixed $assoc, array $segments): mixed
    {
        if ($segments === []) {
            return $assoc === [] ? new \stdClass() : $assoc;
        }
        if (!\is_array($assoc)) {
            return $assoc;
        }
        $key = array_shift($segments);
        if (!\array_key_exists($key, $assoc)) {
            return $assoc;
        }
        $assoc[$key] = self::replace($assoc[$key], $segments);

        return $assoc;
    }

    /** @return list<string> */
    private static function split(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        return array_map(
            static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s),
            \array_slice(explode('/', $pointer), 1),
        );
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }
}
