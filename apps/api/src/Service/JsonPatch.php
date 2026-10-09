<?php

declare(strict_types=1);

namespace App\Service;

/**
 * JSON Patch (RFC 6902) sur une valeur décodée en objets (`json_decode(..., false)`),
 * pour que `{}` reste distinct de `[]`. Opérations : add, remove, replace, move, copy, test.
 *
 * Tout ou rien : une opération invalide lève une exception et la valeur d'origine
 * n'est pas modifiée (le patch s'applique à une copie).
 */
final class JsonPatch
{
    public const MAX_OPS = 100;

    /**
     * @param list<mixed> $ops opérations décodées en objets (`{op, path, value?, from?}`)
     *
     * @throws \InvalidArgumentException opération invalide (message avec son index)
     */
    public static function apply(mixed $document, array $ops): mixed
    {
        if (\count($ops) > self::MAX_OPS) {
            throw new \InvalidArgumentException(sprintf('Trop d’opérations (%d max).', self::MAX_OPS));
        }
        $doc = self::copy($document);
        foreach (array_values($ops) as $i => $op) {
            try {
                $doc = self::one($doc, $op);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(sprintf('ops[%d] : %s', $i, $e->getMessage()), 0, $e);
            }
        }

        return $doc;
    }

    /** Valeur au pointeur, ou exception si absente. */
    public static function get(mixed $doc, string $pointer): mixed
    {
        foreach (self::split($pointer) as $segment) {
            if ($doc instanceof \stdClass && property_exists($doc, $segment)) {
                $doc = $doc->{$segment};
            } elseif (\is_array($doc) && self::isIndex($segment) && \array_key_exists((int) $segment, $doc)) {
                $doc = $doc[(int) $segment];
            } else {
                throw new \InvalidArgumentException(sprintf('chemin introuvable : %s', $pointer));
            }
        }

        return $doc;
    }

    private static function one(mixed $doc, mixed $op): mixed
    {
        if (!$op instanceof \stdClass || !\is_string($op->op ?? null) || !\is_string($op->path ?? null)) {
            throw new \InvalidArgumentException('opération attendue {op, path, …}');
        }
        $path = $op->path;

        return match ($op->op) {
            'add' => self::add($doc, $path, self::value($op)),
            'remove' => self::remove($doc, $path),
            'replace' => self::replace($doc, $path, self::value($op)),
            'move' => self::move($doc, self::from($op), $path),
            'copy' => self::add($doc, $path, self::copy(self::get($doc, self::from($op)))),
            'test' => self::test($doc, $path, self::value($op)),
            default => throw new \InvalidArgumentException(sprintf('op inconnue : %s', $op->op)),
        };
    }

    private static function value(\stdClass $op): mixed
    {
        if (!property_exists($op, 'value')) {
            throw new \InvalidArgumentException('value requis');
        }

        return self::copy($op->value);
    }

    private static function from(\stdClass $op): string
    {
        if (!\is_string($op->from ?? null)) {
            throw new \InvalidArgumentException('from requis');
        }

        return $op->from;
    }

    private static function add(mixed $doc, string $path, mixed $value): mixed
    {
        if ($path === '') {
            return $value;
        }

        return self::edit($doc, self::split($path), static function (mixed $parent, string $key) use ($value, $path): mixed {
            if ($parent instanceof \stdClass) {
                $parent->{$key} = $value;

                return $parent;
            }
            if (\is_array($parent)) {
                if ($key === '-') {
                    $parent[] = $value;

                    return $parent;
                }
                if (!self::isIndex($key) || (int) $key > \count($parent)) {
                    throw new \InvalidArgumentException(sprintf('index invalide : %s', $path));
                }
                array_splice($parent, (int) $key, 0, [$value]);

                return $parent;
            }
            throw new \InvalidArgumentException(sprintf('parent non modifiable : %s', $path));
        });
    }

    private static function remove(mixed $doc, string $path): mixed
    {
        if ($path === '') {
            throw new \InvalidArgumentException('impossible de supprimer la racine');
        }
        self::get($doc, $path);

        return self::edit($doc, self::split($path), static function (mixed $parent, string $key): mixed {
            if ($parent instanceof \stdClass) {
                unset($parent->{$key});

                return $parent;
            }
            array_splice($parent, (int) $key, 1);

            return $parent;
        });
    }

    private static function replace(mixed $doc, string $path, mixed $value): mixed
    {
        if ($path === '') {
            return $value;
        }
        self::get($doc, $path);

        return self::edit($doc, self::split($path), static function (mixed $parent, string $key) use ($value): mixed {
            if ($parent instanceof \stdClass) {
                $parent->{$key} = $value;
            } else {
                $parent[(int) $key] = $value;
            }

            return $parent;
        });
    }

    private static function move(mixed $doc, string $from, string $path): mixed
    {
        if ($from === $path) {
            return $doc;
        }
        if (str_starts_with($path, $from.'/')) {
            throw new \InvalidArgumentException('déplacement dans son propre contenu');
        }
        $value = self::copy(self::get($doc, $from));

        return self::add(self::remove($doc, $from), $path, $value);
    }

    private static function test(mixed $doc, string $path, mixed $value): mixed
    {
        if (json_encode(self::get($doc, $path)) !== json_encode($value)) {
            throw new \InvalidArgumentException(sprintf('test échoué : %s', $path));
        }

        return $doc;
    }

    /**
     * Applique `$fn(parent, dernierSegment)` au parent du chemin et reconstruit la valeur
     * (les tableaux PHP sont des valeurs : on remonte la chaîne).
     *
     * @param list<string>                    $segments
     * @param \Closure(mixed, string): mixed $fn
     */
    private static function edit(mixed $doc, array $segments, \Closure $fn): mixed
    {
        $key = array_shift($segments);
        if ($segments === []) {
            return $fn($doc, $key);
        }
        if ($doc instanceof \stdClass && property_exists($doc, $key)) {
            $doc->{$key} = self::edit($doc->{$key}, $segments, $fn);

            return $doc;
        }
        if (\is_array($doc) && self::isIndex($key) && \array_key_exists((int) $key, $doc)) {
            $doc[(int) $key] = self::edit($doc[(int) $key], $segments, $fn);

            return $doc;
        }
        throw new \InvalidArgumentException(sprintf('chemin introuvable : /%s', $key));
    }

    private static function copy(mixed $value): mixed
    {
        return \is_array($value) || \is_object($value) ? json_decode((string) json_encode($value), false) : $value;
    }

    private static function isIndex(string $segment): bool
    {
        return $segment !== '' && ctype_digit($segment) && ($segment === '0' || $segment[0] !== '0');
    }

    /** @return list<string> */
    private static function split(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }
        if ($pointer[0] !== '/') {
            throw new \InvalidArgumentException(sprintf('pointeur invalide : %s', $pointer));
        }

        return array_map(
            static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s),
            \array_slice(explode('/', $pointer), 1),
        );
    }
}
