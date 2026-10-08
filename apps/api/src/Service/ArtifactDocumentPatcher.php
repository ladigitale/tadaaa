<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Applique une liste d'opérations à un document SDUI sans le renvoyer en entier.
 *
 * Opérations (chemins = JSON Pointer RFC 6901, ex. /views/0/root/nodes/2/attributes/style) :
 *  - {op:"replace", path, value}      remplace une valeur existante
 *  - {op:"add", path, value}          ajoute une clé, ou insère à l'index (« - » = à la fin) d'une liste
 *  - {op:"remove", path}              supprime une clé ou un élément de liste
 *  - {op:"str_replace", path, find, replace[, all]}
 *                                     remplace du texte dans une chaîne (reducer, shader, banque de sons…) ;
 *                                     `find` doit apparaître exactement une fois, sauf all=true.
 */
final class ArtifactDocumentPatcher
{
    public const MAX_OPS = 200;

    /**
     * @param array<string, mixed>        $document
     * @param list<array<string, mixed>>  $ops
     *
     * @return array<string, mixed>
     */
    public static function apply(array $document, array $ops): array
    {
        if ($ops === [] || !array_is_list($ops)) {
            throw new BadRequestHttpException('patch doit être une liste non vide d’opérations.');
        }
        if (\count($ops) > self::MAX_OPS) {
            throw new BadRequestHttpException(sprintf('patch : %d opérations maximum.', self::MAX_OPS));
        }

        foreach ($ops as $i => $op) {
            try {
                $document = self::applyOne($document, \is_array($op) ? $op : []);
            } catch (BadRequestHttpException $e) {
                throw new BadRequestHttpException(sprintf('patch[%d] : %s', $i, $e->getMessage()), $e);
            }
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $op
     *
     * @return array<string, mixed>
     */
    private static function applyOne(array $document, array $op): array
    {
        $kind = $op['op'] ?? null;
        $path = $op['path'] ?? null;
        if (!\is_string($kind) || !\is_string($path) || ($path !== '' && $path[0] !== '/')) {
            throw new BadRequestHttpException('op et path (JSON Pointer commençant par « / ») requis.');
        }
        $tokens = $path === '' ? [] : array_map(
            static fn (string $t): string => str_replace(['~1', '~0'], ['/', '~'], $t),
            explode('/', substr($path, 1)),
        );
        if ($tokens === []) {
            throw new BadRequestHttpException('le document entier ne se remplace pas par patch : utiliser document.');
        }

        $parent = &self::resolveParent($document, $tokens);
        $key = end($tokens);

        switch ($kind) {
            case 'replace':
                if (!\array_key_exists('value', $op)) {
                    throw new BadRequestHttpException('value requis.');
                }
                self::assertExists($parent, $key, $path);
                $parent[self::normalizeKey($parent, $key)] = $op['value'];
                break;

            case 'add':
                if (!\array_key_exists('value', $op)) {
                    throw new BadRequestHttpException('value requis.');
                }
                if (array_is_list($parent) && $parent !== [] || ($parent === [] && ($key === '-' || ctype_digit($key)))) {
                    $index = $key === '-' ? \count($parent) : (ctype_digit($key) ? (int) $key : -1);
                    if ($index < 0 || $index > \count($parent)) {
                        throw new BadRequestHttpException(sprintf('index invalide : %s.', $path));
                    }
                    array_splice($parent, $index, 0, [$op['value']]);
                } else {
                    $parent[$key] = $op['value'];
                }
                break;

            case 'remove':
                self::assertExists($parent, $key, $path);
                if (array_is_list($parent)) {
                    array_splice($parent, (int) $key, 1);
                } else {
                    unset($parent[$key]);
                }
                break;

            case 'str_replace':
                $find = $op['find'] ?? null;
                $replace = $op['replace'] ?? null;
                if (!\is_string($find) || $find === '' || !\is_string($replace)) {
                    throw new BadRequestHttpException('find (non vide) et replace (chaîne) requis.');
                }
                self::assertExists($parent, $key, $path);
                $k = self::normalizeKey($parent, $key);
                if (!\is_string($parent[$k])) {
                    throw new BadRequestHttpException(sprintf('%s n’est pas une chaîne.', $path));
                }
                $count = substr_count($parent[$k], $find);
                if ($count === 0) {
                    throw new BadRequestHttpException(sprintf('texte à remplacer introuvable dans %s.', $path));
                }
                if ($count > 1 && ($op['all'] ?? false) !== true) {
                    throw new BadRequestHttpException(sprintf('texte présent %d fois dans %s : allonger find ou passer all=true.', $count, $path));
                }
                $parent[$k] = str_replace($find, $replace, $parent[$k]);
                break;

            default:
                throw new BadRequestHttpException(sprintf('op inconnue « %s » (replace|add|remove|str_replace).', $kind));
        }

        return $document;
    }

    /**
     * @param array<mixed>  $node
     * @param list<string>  $tokens
     *
     * @return array<mixed>
     */
    private static function &resolveParent(array &$node, array $tokens): array
    {
        $ref = &$node;
        for ($i = 0, $n = \count($tokens) - 1; $i < $n; ++$i) {
            $t = $tokens[$i];
            if (!\is_array($ref) || !\array_key_exists(self::normalizeKey($ref, $t), $ref) || !\is_array($ref[self::normalizeKey($ref, $t)])) {
                throw new BadRequestHttpException(sprintf('chemin introuvable : /%s.', implode('/', \array_slice($tokens, 0, $i + 1))));
            }
            $ref = &$ref[self::normalizeKey($ref, $t)];
        }

        return $ref;
    }

    /** @param array<mixed> $parent */
    private static function normalizeKey(array $parent, string $key): int|string
    {
        return array_is_list($parent) && ctype_digit($key) ? (int) $key : $key;
    }

    /** @param array<mixed> $parent */
    private static function assertExists(array $parent, string $key, string $path): void
    {
        if (!\array_key_exists(self::normalizeKey($parent, $key), $parent)) {
            throw new BadRequestHttpException(sprintf('chemin introuvable : %s.', $path));
        }
    }
}
