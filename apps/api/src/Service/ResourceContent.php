<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Types de ressources de la bibliothèque et contrôle de leur contenu (logique pure, sans base).
 *
 * - `shader`  : GLSL ES 3 au format ShaderToy (`mainImage`), texte UTF-8 ;
 * - `data`    : JSON ;
 * - `model3d` : `.glb` (glTF binaire) ou JSON (description de scène) ;
 * - `image`, `audio` : mêmes formats et mêmes vérifications d'octets que les assets d'artefact.
 */
final class ResourceContent
{
    public const KINDS = ['shader', 'model3d', 'data', 'image', 'audio'];
    public const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    public const MAX_SHADER_BYTES = 65536;
    public const MAX_DATA_BYTES = 1024 * 1024;
    public const MAX_TAGS = 12;

    /** @var array<string, list<string>> */
    private const EXTENSIONS = [
        'model3d' => ['glb', 'json'],
        'image' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg'],
        'audio' => ['ogg', 'mp3', 'wav', 'flac'],
    ];

    /** Types dont le contenu est du texte renvoyé tel quel aux agents. */
    public static function isText(string $kind, string $contentType): bool
    {
        return $kind === 'shader' || $kind === 'data' || $contentType === 'application/json';
    }

    /**
     * Vérifie le contenu et retourne le type MIME à stocker.
     *
     * @throws BadRequestHttpException
     */
    public static function check(string $kind, string $filename, string $bytes): string
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new BadRequestHttpException(sprintf('Type de ressource inconnu (%s).', implode(', ', self::KINDS)));
        }
        if ($bytes === '') {
            throw new BadRequestHttpException('Contenu vide.');
        }

        if ($kind === 'shader') {
            if (\strlen($bytes) > self::MAX_SHADER_BYTES) {
                throw new BadRequestHttpException(sprintf('Shader trop long (%d Ko max).', self::MAX_SHADER_BYTES / 1024));
            }
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                throw new BadRequestHttpException('Le shader doit être en UTF-8.');
            }
            if (!preg_match('/void\s+mainImage\s*\(\s*out\s+vec4\s+\w+\s*,\s*(?:in\s+)?vec2\s+\w+\s*\)/', $bytes)) {
                throw new BadRequestHttpException('Le shader doit définir void mainImage(out vec4 c, in vec2 p) (format ShaderToy).');
            }
            if (preg_match('/^\s*#\s*version\b/m', $bytes)) {
                throw new BadRequestHttpException('Pas de #version : l’en-tête GLSL est ajouté par sonic-shader.');
            }

            return 'text/x-glsl';
        }

        if ($kind === 'data') {
            if (\strlen($bytes) > self::MAX_DATA_BYTES) {
                throw new BadRequestHttpException(sprintf('Données trop volumineuses (%d Mo max).', self::MAX_DATA_BYTES / 1024 / 1024));
            }

            return ArtifactAssets::detect('data.json', $bytes);
        }

        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if ($ext === '' && $kind === 'model3d') {
            $ext = str_starts_with(ltrim($bytes), '{') ? 'json' : 'glb';
        }
        if (!\in_array($ext, self::EXTENSIONS[$kind], true)) {
            throw new BadRequestHttpException(sprintf('Extension attendue pour %s : %s.', $kind, implode(', ', self::EXTENSIONS[$kind])));
        }

        return ArtifactAssets::detect('ressource.'.$ext, $bytes);
    }

    public static function slugify(string $name): string
    {
        // Table fixe plutôt qu'iconv : le résultat ne dépend pas de la locale du serveur.
        $s = strtr(mb_strtolower(trim($name)), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'œ' => 'oe', 'ù' => 'u',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ]);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        $s = rtrim(substr($s, 0, 64), '-');

        return preg_match(self::SLUG, $s) ? $s : '';
    }

    /**
     * @param mixed $tags
     *
     * @return list<string> minuscules, dédoublonnés, 32 caractères max, {@see MAX_TAGS} au plus
     */
    public static function normalizeTags(mixed $tags): array
    {
        if (!\is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                continue;
            }
            $t = mb_strtolower(trim($tag));
            if ($t === '' || mb_strlen($t) > 32 || \in_array($t, $out, true)) {
                continue;
            }
            $out[] = $t;
            if (\count($out) >= self::MAX_TAGS) {
                break;
            }
        }

        return $out;
    }
}
