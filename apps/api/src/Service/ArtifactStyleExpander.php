<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Dictionnaire de styles réutilisables.
 *
 * Le document déclare "styles": {"nom": "css inline"} et un nœud y fait référence
 * avec "sx": "nom autre" (clé de nœud). À l'enregistrement, chaque "sx" est remplacé par
 * l'attribut inline "style" (styles du dictionnaire dans l'ordre, puis style propre du nœud,
 * qui l'emporte). Le viewer ne reçoit donc que du style inline déjà validé ; "styles" est
 * conservé pour que des patchs ultérieurs puissent le réutiliser.
 */
final class ArtifactStyleExpander
{
    public const MAX_STYLES = 100;
    public const MAX_STYLE_LENGTH = 2000;

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function expand(array $document): array
    {
        $styles = self::styles($document);
        $walk = function (mixed $value, string $path) use (&$walk, $styles): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            if (!array_is_list($value) && \array_key_exists('sx', $value)) {
                $value = self::applyNode($value, $styles, $path);
            }
            foreach ($value as $key => $child) {
                if (\is_array($child)) {
                    $value[$key] = $walk($child, $path.'/'.$key);
                }
            }

            return $value;
        };

        foreach ($document as $key => $child) {
            if ($key === 'styles') {
                continue;
            }
            $document[$key] = $walk($child, '/'.$key);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, string>
     */
    private static function styles(array $document): array
    {
        if (!\array_key_exists('styles', $document)) {
            return [];
        }
        $styles = $document['styles'];
        if (!\is_array($styles) || array_is_list($styles) && $styles !== []) {
            throw new BadRequestHttpException('styles doit être un objet {nom: "css inline"}.');
        }
        if (\count($styles) > self::MAX_STYLES) {
            throw new BadRequestHttpException(sprintf('styles : %d entrées max.', self::MAX_STYLES));
        }
        $out = [];
        foreach ($styles as $name => $css) {
            if (!\is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/', $name)) {
                throw new BadRequestHttpException('styles : nom invalide (lettres, chiffres, - et _, 40 max).');
            }
            if (!\is_string($css) || $css === '' || \strlen($css) > self::MAX_STYLE_LENGTH) {
                throw new BadRequestHttpException(sprintf('styles.%s : chaîne CSS inline requise (%d car. max).', $name, self::MAX_STYLE_LENGTH));
            }
            $out[$name] = rtrim(trim($css), ';');
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $node
     * @param array<string, string> $styles
     *
     * @return array<string, mixed>
     */
    private static function applyNode(array $node, array $styles, string $path): array
    {
        $sx = $node['sx'];
        unset($node['sx']);
        if (!\is_string($sx) || trim($sx) === '') {
            throw new BadRequestHttpException(sprintf('%s/sx : liste de noms de styles séparés par des espaces.', $path));
        }
        $parts = [];
        foreach (preg_split('/\s+/', trim($sx)) ?: [] as $name) {
            if (!isset($styles[$name])) {
                throw new BadRequestHttpException(sprintf('%s/sx : style "%s" absent de "styles".', $path, $name));
            }
            $parts[] = $styles[$name];
        }
        $attributes = $node['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw new BadRequestHttpException($path.'/attributes doit être un objet.');
        }
        $own = isset($attributes['style']) && \is_string($attributes['style']) ? rtrim(trim($attributes['style']), ';') : '';
        if ($own !== '') {
            $parts[] = $own;
        }
        $attributes['style'] = implode(';', $parts).';';
        $node['attributes'] = $attributes;

        return $node;
    }
}
