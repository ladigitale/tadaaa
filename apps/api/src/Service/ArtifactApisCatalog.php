<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Catalogue d'APIs publiques sans clé que l'agent de l'atelier peut interroger
 * (config/artifacts/apis-catalog.json).
 *
 * Garanties : l'agent ne fournit jamais d'URL. L'hôte (https) vient du catalogue, le chemin
 * est un modèle du catalogue dont les `{variables}` sont encodées, les paramètres de requête
 * doivent être listés pour l'endpoint. Rien d'autre ne sort du serveur.
 */
final class ArtifactApisCatalog
{
    /** Caractères admis dans une variable de chemin (jamais « / », « % », « ? » ni « # »). */
    private const PATH_VALUE = '/^[\p{L}\p{N} _.,:\'()\-]{1,120}$/u';

    /** Au-delà, la recherche renvoie un index compact plutôt que le détail des endpoints. */
    private const DETAIL_MAX = 6;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/apis-catalog.json')]
        private readonly string $path,
    ) {
        $raw = file_get_contents($this->path);
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        $this->data = \is_array($decoded) ? $decoded : [];
    }

    public function timeoutSeconds(): float
    {
        return (float) ($this->data['limits']['timeoutSeconds'] ?? 8);
    }

    public function maxBytes(): int
    {
        return (int) ($this->data['limits']['maxBytes'] ?? 400000);
    }

    public function cacheSeconds(): int
    {
        return (int) ($this->data['limits']['cacheSeconds'] ?? 300);
    }

    private function maxParamLength(): int
    {
        return (int) ($this->data['limits']['maxParamLength'] ?? 200);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->apis());
    }

    /**
     * Index (sans filtre) ou détail des APIs qui correspondent à la recherche.
     *
     * @return array<string, mixed>
     */
    public function search(?string $query = null, ?string $category = null): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/', self::fold((string) $query)) ?: []));
        $category = $category !== null && $category !== '' ? $category : null;
        $matches = [];
        foreach ($this->apis() as $id => $api) {
            if ($category !== null && ($api['category'] ?? '') !== $category) {
                continue;
            }
            $haystack = self::fold($id.' '.($api['label'] ?? '').' '.($api['category'] ?? '').' '.$this->endpointText($api));
            foreach ($terms as $term) {
                if (!str_contains($haystack, $term)) {
                    continue 2;
                }
            }
            $matches[$id] = $api;
        }

        $out = ['categories' => $this->data['categories'] ?? new \stdClass(), 'count' => \count($matches)];
        $detailed = ($terms !== [] || $category !== null) && \count($matches) <= self::DETAIL_MAX;
        $out['apis'] = [];
        foreach ($matches as $id => $api) {
            $entry = ['id' => $id, 'label' => $api['label'] ?? $id, 'category' => $api['category'] ?? ''];
            if ($detailed) {
                $entry['doc'] = $api['doc'] ?? null;
                $entry['endpoints'] = $this->describeEndpoints($api);
            } else {
                $entry['endpoints'] = array_keys($api['endpoints'] ?? []);
            }
            $out['apis'][] = $entry;
        }
        if (!$detailed && $matches !== []) {
            $out['hint'] = 'Précise la recherche (query ou category) pour voir les paramètres et un exemple de chaque endpoint.';
        }

        return $out;
    }

    /**
     * URL finale d'un appel, ou exception (message destiné au modèle).
     *
     * @param array<string, mixed> $pathParams
     * @param array<string, mixed> $params
     *
     * @return array{url: string, host: string}
     */
    public function buildUrl(string $apiId, string $endpointId, array $pathParams = [], array $params = []): array
    {
        $api = $this->apis()[$apiId] ?? null;
        if ($api === null) {
            throw new \InvalidArgumentException(sprintf('API inconnue « %s ». Appelle search_public_apis.', $apiId));
        }
        $endpoint = $api['endpoints'][$endpointId] ?? null;
        if (!\is_array($endpoint)) {
            throw new \InvalidArgumentException(sprintf('Endpoint inconnu « %s » pour %s (disponibles : %s).', $endpointId, $apiId, implode(', ', array_keys($api['endpoints'] ?? []))));
        }

        $base = parse_url((string) ($api['baseUrl'] ?? ''));
        if (!\is_array($base) || ($base['scheme'] ?? '') !== 'https' || !isset($base['host']) || isset($base['path']) || isset($base['query'])) {
            throw new \LogicException(sprintf('Catalogue : baseUrl invalide pour %s.', $apiId));
        }

        $path = (string) ($endpoint['path'] ?? '');
        $declared = array_values(array_filter((array) ($endpoint['pathParams'] ?? []), 'is_string'));
        $given = array_keys($pathParams);
        if ($extra = array_diff($given, $declared)) {
            throw new \InvalidArgumentException(sprintf('path_params inconnus : %s (attendus : %s).', implode(', ', $extra), $declared === [] ? 'aucun' : implode(', ', $declared)));
        }
        foreach ($declared as $name) {
            if (!\array_key_exists($name, $pathParams)) {
                throw new \InvalidArgumentException(sprintf('path_params.%s est obligatoire.', $name));
            }
            $value = $this->scalar($pathParams[$name], 'path_params.'.$name);
            if (!preg_match(self::PATH_VALUE, $value) || $value === '.' || $value === '..') {
                throw new \InvalidArgumentException(sprintf('path_params.%s : valeur refusée (lettres, chiffres, espace et _ . , : \' ( ) - seulement).', $name));
            }
            $enum = $endpoint['pathEnums'][$name] ?? null;
            if (\is_array($enum) && !\in_array($value, $enum, true)) {
                throw new \InvalidArgumentException(sprintf('path_params.%s doit valoir : %s.', $name, implode(', ', $enum)));
            }
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }
        if (str_contains($path, '{')) {
            throw new \LogicException(sprintf('Catalogue : variable de chemin non déclarée pour %s/%s.', $apiId, $endpointId));
        }

        $allowed = array_values(array_filter((array) ($endpoint['params'] ?? []), 'is_string'));
        if ($extra = array_diff(array_keys($params), $allowed)) {
            throw new \InvalidArgumentException(sprintf('params inconnus : %s (autorisés : %s).', implode(', ', $extra), $allowed === [] ? 'aucun' : implode(', ', $allowed)));
        }
        foreach ((array) ($endpoint['required'] ?? []) as $name) {
            if (!\array_key_exists($name, $params) || $params[$name] === '' || $params[$name] === null) {
                throw new \InvalidArgumentException(sprintf('params.%s est obligatoire.', $name));
            }
        }
        $query = [];
        foreach ($params as $name => $value) {
            $query[(string) $name] = $this->scalar($value, 'params.'.$name);
        }

        $url = 'https://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '').$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        return ['url' => $url, 'host' => $base['host']];
    }

    /** @return array<string, array<string, mixed>> */
    private function apis(): array
    {
        $apis = $this->data['apis'] ?? [];

        return \is_array($apis) ? array_filter($apis, 'is_array') : [];
    }

    /** @param array<string, mixed> $api */
    private function endpointText(array $api): string
    {
        $text = [];
        foreach ($api['endpoints'] ?? [] as $id => $endpoint) {
            $text[] = $id.' '.($endpoint['description'] ?? '');
        }

        return implode(' ', $text);
    }

    /**
     * @param array<string, mixed> $api
     *
     * @return list<array<string, mixed>>
     */
    private function describeEndpoints(array $api): array
    {
        $out = [];
        foreach ($api['endpoints'] ?? [] as $id => $endpoint) {
            $entry = ['id' => $id, 'description' => $endpoint['description'] ?? ''];
            foreach (['pathParams', 'params', 'required', 'pathEnums'] as $key) {
                if (!empty($endpoint[$key])) {
                    $entry[$key] = $endpoint[$key];
                }
            }
            if (isset($endpoint['example'])) {
                $entry['example'] = $endpoint['example'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function scalar(mixed $value, string $label): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(sprintf('%s : une valeur simple est attendue.', $label));
        }
        $value = (string) $value;
        if (mb_strlen($value) > $this->maxParamLength() || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s : valeur trop longue ou avec des caractères de contrôle.', $label));
        }

        return $value;
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe',
        ]);
    }
}
