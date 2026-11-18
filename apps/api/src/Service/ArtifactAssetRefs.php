<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Références d'assets dans un document : toute valeur de chaîne exactement égale à `@asset:<nom>`
 * (ex. `src` d'un sprite, d'une image ou d'un son) est remplacée, à la lecture, par l'URL publique
 * de l'asset. Le document stocké garde la référence ; l'URL (hôte de l'API, empreinte pour le cache,
 * clé de lien) n'est connue qu'au moment de servir.
 *
 * Une référence vers un asset qui n'existe pas (encore) est laissée telle quelle.
 */
final class ArtifactAssetRefs
{
    private const PREFIX = '@asset:';

    /**
     * Erreurs de syntaxe : une valeur qui commence par `@asset:` doit porter un nom valide.
     * L'existence de l'asset n'est pas vérifiée ici (la validation ne connaît pas l'artefact).
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{path: string, message: string}>
     */
    public static function check(array $document): array
    {
        $body = $document;
        unset($body['files']);
        $errors = [];
        foreach (self::refs($body, '') as [$path, $name]) {
            if (!preg_match(ArtifactAssets::NAME, $name)) {
                $errors[] = ['path' => $path, 'message' => sprintf('Nom d’asset invalide dans « %s » (a-z, 0-9, . _ -).', self::PREFIX.$name)];
            }
        }

        return $errors;
    }

    /**
     * Document avec les références connues remplacées par l'URL de l'asset.
     * Accepte des tableaux associatifs comme des objets (`\stdClass`, document « wire »).
     *
     * @param array<string, string> $hashes nom → sha256 des assets existants
     */
    public static function expand(mixed $document, string $baseUrl, string $slug, array $hashes, ?string $linkToken = null): mixed
    {
        if ($hashes === []) {
            return $document;
        }
        if ($document instanceof \stdClass) {
            $document = json_decode((string) json_encode($document), false);
        }
        $urls = [];
        foreach ($hashes as $name => $sha) {
            $query = 'v='.substr($sha, 0, 12).($linkToken !== null && $linkToken !== '' ? '&k='.rawurlencode($linkToken) : '');
            $urls[$name] = rtrim($baseUrl, '/').'/api/public/artifacts/'.$slug.'/assets/'.rawurlencode((string) $name).'?'.$query;
        }

        return self::walk($document, $urls);
    }

    /** @param array<string, string> $urls */
    private static function walk(mixed $node, array $urls): mixed
    {
        if (\is_string($node)) {
            if (str_starts_with($node, self::PREFIX)) {
                return $urls[substr($node, \strlen(self::PREFIX))] ?? $node;
            }

            return $node;
        }
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $k => $v) {
                $node->{$k} = self::walk($v, $urls);
            }

            return $node;
        }
        if (\is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = self::walk($v, $urls);
            }
        }

        return $node;
    }

    /**
     * Références `@asset:` d'une valeur quelconque (ex. l'entrée d'un run) : [pointeur JSON, nom].
     *
     * @return list<array{string, string}>
     */
    public static function find(mixed $value): array
    {
        return self::refs($value, '');
    }

    /** @return list<array{string, string}> [pointeur, nom] */
    private static function refs(mixed $node, string $path): array
    {
        $out = [];
        if (\is_string($node)) {
            if (str_starts_with($node, self::PREFIX)) {
                $out[] = [$path, substr($node, \strlen(self::PREFIX))];
            }
        } elseif (\is_array($node) || $node instanceof \stdClass) {
            foreach ($node instanceof \stdClass ? get_object_vars($node) : $node as $k => $v) {
                array_push($out, ...self::refs($v, $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $k)));
            }
        }

        return $out;
    }
}
