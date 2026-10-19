<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Fichiers séparés d'un artefact : `files` = {"nom": "contenu texte"} au niveau du document, référencés
 * par toute valeur de chaîne exactement égale à `@file:<nom>` (shader GLSL, réducteur JSONata, texte…).
 *
 * Intérêt : un gros shader ou réducteur s'édite par `update_artifact` + `edits` sur le chemin court
 * `/files/jeu.jsonata`, au lieu d'un chemin profond dans l'arbre des vues. Les références sont
 * résolues côté serveur (lecture publique, validation, rendu, simulation) : le viewer ne voit
 * jamais `files`.
 */
final class ArtifactFiles
{
    public const MAX_FILES = 24;
    public const MAX_FILE_BYTES = 65536;
    private const NAME = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
    private const REF = '/^@file:([a-z0-9][a-z0-9._-]{0,63})$/';

    /**
     * Erreurs de structure : `files` bien formé, références existantes.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{path: string, message: string}>
     */
    public static function check(array $document): array
    {
        $errors = [];
        $files = $document['files'] ?? null;
        if ($files !== null) {
            if (!\is_array($files) || ($files !== [] && array_is_list($files))) {
                return [['path' => '/files', 'message' => 'files doit être un objet {nom: contenu}.']];
            }
            if (\count($files) > self::MAX_FILES) {
                $errors[] = ['path' => '/files', 'message' => sprintf('Trop de fichiers (%d max).', self::MAX_FILES)];
            }
            foreach ($files as $name => $content) {
                if (!\is_string($name) || !preg_match(self::NAME, $name)) {
                    $errors[] = ['path' => '/files/'.$name, 'message' => 'Nom de fichier invalide (a-z, 0-9, . _ -).'];
                } elseif (!\is_string($content) || \strlen($content) > self::MAX_FILE_BYTES) {
                    $errors[] = ['path' => '/files/'.$name, 'message' => sprintf('Contenu texte attendu (%d Ko max).', self::MAX_FILE_BYTES / 1024)];
                }
            }
        }
        $known = \is_array($files) ? $files : [];
        $body = $document;
        unset($body['files']);
        foreach (self::refs($body, '') as [$path, $name]) {
            if (!\array_key_exists($name, $known)) {
                $errors[] = ['path' => $path, 'message' => sprintf('Fichier « %s » introuvable dans files.', $name)];
            }
        }

        return $errors;
    }

    /**
     * Document avec les références remplacées par le contenu des fichiers ; `files` retiré.
     * Accepte des tableaux associatifs comme des objets (`\stdClass`, document « wire »).
     * Les références inconnues sont laissées telles quelles (signalées par {@see check()}).
     */
    public static function expand(mixed $document): mixed
    {
        if ($document instanceof \stdClass) {
            // Copie profonde (les {} restent des objets) : on ne modifie jamais l'original.
            $copy = json_decode((string) json_encode($document), false);
            $files = (array) ($copy->files ?? []);
            unset($copy->files);

            return self::walk($copy, $files);
        }
        if (\is_array($document)) {
            $files = \is_array($document['files'] ?? null) ? $document['files'] : [];
            unset($document['files']);

            return self::walk($document, $files);
        }

        return $document;
    }

    /** @param array<string, mixed> $files */
    private static function walk(mixed $node, array $files): mixed
    {
        if (\is_string($node)) {
            return preg_match(self::REF, $node, $m) && \is_string($files[$m[1]] ?? null) ? $files[$m[1]] : $node;
        }
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $k => $v) {
                $node->{$k} = self::walk($v, $files);
            }

            return $node;
        }
        if (\is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = self::walk($v, $files);
            }
        }

        return $node;
    }

    /** @return list<array{string, string}> [pointeur, nom] */
    private static function refs(mixed $node, string $path): array
    {
        $out = [];
        if (\is_string($node)) {
            if (preg_match(self::REF, $node, $m)) {
                $out[] = [$path, $m[1]];
            }
        } elseif (\is_array($node) || $node instanceof \stdClass) {
            foreach ($node instanceof \stdClass ? get_object_vars($node) : $node as $k => $v) {
                array_push($out, ...self::refs($v, $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $k)));
            }
        }

        return $out;
    }
}
