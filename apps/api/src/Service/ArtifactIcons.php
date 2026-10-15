<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Icônes des artefacts : `sonic-icon` charge ses SVG depuis un CDN (bibliothèques
 * déclarées par Concorde ; la CSP du viewer autorise `connect-src https:`).
 *
 * Bibliothèques de référence : iconoir (cdnjs, ~1150 icônes, le style de Concorde) et
 * heroicons (jsDelivr, outline / solid). Sans `library`, le petit jeu intégré à Concorde.
 * Les noms exacts sont dans config/artifacts/icons.json (scripts/build-icon-index.mjs) :
 * ils servent à refuser les icônes inventées et à `find_icons`.
 */
final class ArtifactIcons
{
    /** Jeu intégré à Concorde (sans `library`). */
    public const CORE = ['cancel', 'check', 'check-circled-outline', 'emoji-puzzled', 'info-empty', 'loader',
        'minus-small', 'more-horiz', 'more-vert', 'nav-arrow-down', 'warning-circled-outline'];

    /** Quelques équivalents français → mots des noms d'icônes (recherche). */
    private const SYNONYMS = [
        'coeur' => 'heart', 'cœur' => 'heart', 'etoile' => 'star-outline', 'étoile' => 'star-outline', 'maison' => 'home', 'accueil' => 'home',
        'musique' => 'music', 'note' => 'music', 'son' => 'sound', 'volume' => 'sound', 'micro' => 'microphone',
        'lecture' => 'play-outline', 'jouer' => 'play-outline', 'stop' => 'pause', 'suivant' => 'arrow-right', 'precedent' => 'arrow-left',
        'précédent' => 'arrow-left', 'fleche' => 'arrow', 'flèche' => 'arrow', 'haut' => 'up', 'bas' => 'down', 'gauche' => 'left', 'droite' => 'right',
        'recommencer' => 'refresh', 'rejouer' => 'refresh', 'hasard' => 'shuffle', 'aleatoire' => 'shuffle', 'aléatoire' => 'shuffle',
        'reglages' => 'settings', 'réglages' => 'settings', 'parametres' => 'settings', 'paramètres' => 'settings',
        'utilisateur' => 'user', 'personne' => 'user', 'groupe' => 'group', 'equipe' => 'group', 'équipe' => 'group',
        'horloge' => 'clock-outline', 'temps' => 'timer', 'chrono' => 'timer', 'calendrier' => 'calendar', 'date' => 'calendar',
        'carte' => 'map', 'lieu' => 'pin-alt', 'adresse' => 'pin-alt', 'telephone' => 'phone', 'téléphone' => 'phone',
        'courriel' => 'mail', 'email' => 'mail', 'message' => 'chat-bubble', 'panier' => 'cart', 'achat' => 'cart', 'prix' => 'euro',
        'recherche' => 'search', 'chercher' => 'search', 'poubelle' => 'trash', 'supprimer' => 'trash', 'ajouter' => 'plus',
        'plus' => 'plus', 'moins' => 'minus', 'valider' => 'check', 'ok' => 'check', 'fermer' => 'cancel', 'erreur' => 'warning',
        'attention' => 'warning', 'info' => 'info-empty', 'aide' => 'question-mark', 'question' => 'question', 'trophee' => 'trophy', 'trophée' => 'trophy',
        'medaille' => 'medal', 'médaille' => 'medal', 'jeu' => 'gamepad', 'manette' => 'gamepad', 'livre' => 'book',
        'ecole' => 'graduation', 'école' => 'graduation', 'photo' => 'camera', 'image' => 'media-image', 'video' => 'video', 'vidéo' => 'video',
        'lien' => 'link', 'partager' => 'share', 'telecharger' => 'download', 'télécharger' => 'download', 'cadenas' => 'lock',
        'soleil' => 'sun', 'lune' => 'moon', 'nuage' => 'cloud', 'pluie' => 'rain', 'arbre' => 'tree', 'feuille' => 'leaf',
        'fleur' => 'flower', 'velo' => 'bicycle', 'vélo' => 'bicycle', 'voiture' => 'car', 'train' => 'train', 'avion' => 'airplane',
        'repas' => 'pizza', 'cuisine' => 'pizza', 'cafe' => 'glass', 'café' => 'glass', 'cadeau' => 'gift', 'fete' => 'gift', 'fête' => 'gift',
        'argent' => 'coins', 'graphique' => 'graph', 'statistiques' => 'stats-report', 'idee' => 'light-bulb', 'idée' => 'light-bulb',
    ];

    /** @var array<string, array{version: string, cdn: string, prefixes: list<string>, names: list<string>, set: array<string, true>}>|null */
    private ?array $libraries = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/icons.json')]
        private readonly string $indexPath,
    ) {
    }

    /** @return list<string> */
    public function libraries(): array
    {
        return array_keys($this->all());
    }

    /**
     * Erreur pour un `sonic-icon`, ou null s'il est correct.
     *
     * @param array<string, mixed> $attributes
     */
    public function check(array $attributes): ?string
    {
        foreach (['customIconLibraryPath', 'customIconDefaultPrefix', 'customLibraryPath'] as $forbidden) {
            foreach (array_keys($attributes) as $attr) {
                if (strcasecmp((string) $attr, $forbidden) === 0) {
                    return 'Bibliothèque d’icônes personnalisée interdite : library="iconoir" ou "heroicons".';
                }
            }
        }
        $library = \is_string($attributes['library'] ?? null) ? trim($attributes['library']) : '';
        $name = \is_string($attributes['name'] ?? null) ? trim($attributes['name']) : null;
        if ($library === '') {
            if ($name !== null && $name !== '' && !\in_array($name, self::CORE, true)) {
                return sprintf('Icône « %s » absente du jeu intégré : ajoute library="iconoir" (noms : find_icons).', $name);
            }

            return null;
        }
        $lib = $this->all()[$library] ?? null;
        if ($lib === null) {
            return sprintf('library : %s (bibliothèques de référence).', implode(' | ', $this->libraries()));
        }
        $prefix = \is_string($attributes['prefix'] ?? null) ? trim($attributes['prefix']) : '';
        if ($prefix !== '' && !\in_array($prefix, $lib['prefixes'], true)) {
            return $lib['prefixes'] === []
                ? sprintf('%s n’a pas de prefix.', $library)
                : sprintf('prefix : %s.', implode(' | ', $lib['prefixes']));
        }
        if ($name !== null && $name !== '' && !isset($lib['set'][$name])) {
            $close = $this->search($name, $library, 5);

            return sprintf('Icône « %s » inconnue dans %s%s.', $name, $library, $close === [] ? ' (find_icons)' : ' ; proches : '.implode(', ', $close));
        }

        return null;
    }

    /**
     * Noms d'icônes correspondant à une requête (mots en anglais ou en français).
     *
     * @return list<string>
     */
    public function search(string $query, string $library = 'iconoir', int $limit = 30): array
    {
        $lib = $this->all()[$library] ?? null;
        if ($lib === null) {
            return [];
        }
        $words = [];
        foreach (preg_split('/[\s,;\/_-]+/u', mb_strtolower(trim($query))) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $words[] = self::SYNONYMS[$word] ?? $word;
        }
        if ($words === []) {
            return [];
        }
        $needle = implode('-', $words);
        $scores = [];
        foreach ($lib['names'] as $name) {
            $score = 0;
            if ($name === $needle) {
                $score = 1000;
            } elseif (str_starts_with($name, $needle)) {
                $score = 500 - \strlen($name);
            } elseif (str_contains($name, $needle)) {
                $score = 300 - \strlen($name);
            } else {
                $hits = 0;
                foreach ($words as $word) {
                    foreach (explode('-', $word) as $part) {
                        if ($part !== '' && str_contains($name, $part)) {
                            ++$hits;
                        }
                    }
                }
                if ($hits > 0) {
                    $score = 100 * $hits - \strlen($name);
                } elseif (\strlen($needle) >= 4) {
                    similar_text($needle, $name, $percent);
                    $score = $percent >= 70 ? (int) $percent - 100 : 0;
                }
            }
            if ($score !== 0) {
                $scores[$name] = $score;
            }
        }
        arsort($scores);

        return \array_slice(array_keys($scores), 0, $limit);
    }

    /** Règle courte pour le sommaire du catalogue (prompt). */
    public function rule(): string
    {
        $common = ['check', 'cancel', 'plus', 'minus', 'play-outline', 'pause-outline', 'refresh-double', 'shuffle', 'timer',
            'clock-outline', 'star-outline', 'heart', 'trophy', 'medal', 'light-bulb', 'question-mark-circle', 'info-empty',
            'warning-triangle-outline', 'arrow-right', 'nav-arrow-right', 'home', 'user', 'group', 'calendar', 'pin-alt', 'map',
            'mail', 'phone', 'music-1', 'sound-high', 'camera', 'gamepad', 'book', 'gift', 'pizza-slice', 'sun-light', 'leaf',
            'flower', 'flash', 'settings', 'search', 'download', 'share-android', 'lock', 'link', 'euro', 'graph-up'];
        $known = array_values(array_filter($common, fn (string $n): bool => isset($this->all()['iconoir']['set'][$n])));

        return 'Icônes : <sonic-icon library="iconoir" name="…" size="sm|lg|xl|2xl"> (≈1150 icônes, chargées depuis le CDN ; '
            .'couleur = currentColor). Aussi library="heroicons" prefix="outline|solid". Noms exacts obligatoires : '
            .'find_icons(query) ; courants (iconoir) : '.implode(', ', $known).'.';
    }

    /** @return array<string, array{version: string, cdn: string, prefixes: list<string>, names: list<string>, set: array<string, true>}> */
    private function all(): array
    {
        if ($this->libraries !== null) {
            return $this->libraries;
        }
        $this->libraries = [];
        $data = is_file($this->indexPath) ? json_decode((string) file_get_contents($this->indexPath), true) : null;
        foreach (\is_array($data['libraries'] ?? null) ? $data['libraries'] : [] as $id => $lib) {
            if (!\is_array($lib) || !\is_array($lib['names'] ?? null)) {
                continue;
            }
            $this->libraries[(string) $id] = [
                'version' => (string) ($lib['version'] ?? ''),
                'cdn' => (string) ($lib['cdn'] ?? ''),
                'prefixes' => array_values(array_filter($lib['prefixes'] ?? [], 'is_string')),
                'names' => array_values(array_filter($lib['names'], 'is_string')),
                'set' => array_fill_keys(array_filter($lib['names'], 'is_string'), true),
            ];
        }

        return $this->libraries;
    }
}
