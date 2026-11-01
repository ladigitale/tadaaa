---
name: experience
description: Expériences créatives et immersives (art génératif, visuels audio-réactifs, caméra et effets, IA locale, instruments et séquenceurs, enregistreurs, installations et bornes, scrub/timeline) : une scène plein cadre, des commandes discrètes, le bon composant de la creative-stack pour chaque idée.
---
Prérequis : rules=["design"] puis les règles des briques utilisées (["shader"] est courte, ["audio"], ["son"], ["media"], ["ia"], ["animation"], ["procgen"], ["appareil"]). Une expérience se juge à son premier geste : en un clic, il se passe quelque chose de beau et de compréhensible.

## 1. Composition type
- `page.padding:"none"`, fond sombre ou papier selon l'œuvre, palette de zone.
- **Scène** plein cadre (`position:relative;height:100svh` ou `aspect-ratio` fixe) ; **commandes** dans un panneau compact qui flotte (`position:absolute;right:16px;bottom:16px;max-width:min(92vw,22rem)`, surface translucide `rgb(0 0 0/.45)` + `backdrop-filter:blur(12px)`, rayon 14–18) ; sur téléphone, sous la scène.
- **Écran d'invite** tant que le média n'est pas actif : titre de l'œuvre, une phrase, UN bouton (`sonic-audio-unlock`, `sonic-media-start for="cam mic"`) ; afficher refus et non-support (`<id>State.status` = denied|unsupported) avec une alternative.
- 3–6 réglages maximum, nommés par leur effet (« Densité », « Chaleur »), avec valeurs lisibles ; un bouton « Hasard » et un « Réinitialiser ». Le reste se cache dans un `sonic-pop` « Avancé ».
- Capture : `sonic-media-recorder` (vidéo de la scène + son) et `sonic-media-download`, ou photo de caméra ; c'est souvent ce que les gens veulent garder.

## 2. Quelle brique pour quelle idée
| Idée | Briques |
|---|---|
| fond animé, art génératif, effet plein écran | `sonic-shader` (GLSL ShaderToy, `param0..3` via un store, `mouse`) ; kit `shader` ; calcul lourd / particules : `sonic-webgpu` |
| paysage, ville, monde illustré qui vit | `sonic-procgen` (scènes animées, `style` night/palette/grain en direct, `select` au clic) |
| visualiseur de musique | `sonic-audio-analyser source="master|#id"` → `sonic-shader channel0="#spectre"` (spectre/onde en texture) ou `bands[]`/`rms` vers des nodes ; `sonic-video audio-out="master"` ou `sonic-patch` comme source |
| miroir, filtres, effets caméra | `sonic-camera hidden-preview` → `sonic-shader channel0="#cam"` ; réglages matériels (zoom, torche) via capabilities/adjust |
| reconnaissance, profondeur, classement | `sonic-hugging-face-infer` (consentement, progression du téléchargement, sortie chaînable vers un shader) |
| instrument, synthé, boîte à rythmes | `sonic-patch` (presets ou patch modulaire) + `sonic-sequencer` (mini-notation) + `sonic-sampler` ; contrôleurs `sonic-midi` (MPE : LinnStrument, Seaboard) |
| sons de l'environnement, boucles de voix | `sonic-mic`/`sonic-audio-recorder` → `sonic-sampler`/`sonic-grain` |
| objets qui tombent, se poussent, se lancent | `sonic-physics` + rendu `sonic-2d`/`sonic-stage`/`sonic-shader channel0="#world"` |
| récit animé, générique, motion design | `sonic-clock` + `sonic-timeline` (seq/par/stagger/tween/text), scrub par un curseur `type="range"` |
| inclinaison, secousse (téléphone) | `sonic-orientation` (capteurs, geste requis sur iOS) |
| expérience à plusieurs | `sonic-p2p` (état partagé, temps commun via `time-source` + `smooth`) |

## 3. Interfaces d'instruments
- Pads : grille `repeat(4,1fr)` de `sonic-button shape="square" size="xl"` dans `sonic-action` (`hold-repeat` si tenue), ou `sonic-matrix` + `sonic-gesture` pour un séquenceur pas-à-pas (case = pas, couleur = vélocité, la colonne jouée éclairée par `<seq>State.step`).
- Curseurs : `sonic-input type="range"` dans un `formDataProvider` ; `sonic-param to="flt.freq-hz" source="ui.cutoff" min max ramp-s` ; affiche la valeur (Hz, %, BPM) à côté.
- Transport : lecture/pause (icônes `play-outline`/`pause-outline`), BPM en gros chiffres tabulaires, indicateur de temps (4 points dont celui du `beat` courant).
- Une seule erreur de patch = aucun son : nomme chaque module ciblé, relis rules.audio, vérifie l'état `<id>State.errors`.

## 4. Mouvement et matière
- Lenteur = luxe : `iTime*.05–.2` pour un fond ; vitesse = énergie pour un visuel audio (suivre `rms`).
- Grain, vignette, aberration légère dans le shader donnent de la matière ; garder des noirs non purs (#0b0b12) et des blancs cassés (#f4f1ea).
- Couleur : 2 teintes + 1 accent, mélangées dans le GLSL (`mix`, `smoothstep`) ; params pour la teinte afin que l'utilisateur la change.
- `reducedMotion` (sonic-page-state) : passe la vitesse du shader à presque 0 via un param.
- Performance : `render_artifact` avec `fps:true` ≥ 50 sur le rendu par défaut ; boucles GLSL ≤ 64 itérations, pas de `for` imbriqués lourds ; `max-dpr` sur `sonic-2d`.

## 5. Borne, installation, exposition
`sonic-fullscreen` (bouton `for`) + `sonic-wake-lock auto` ; retour à l'écran d'accueil après inactivité (`sonic-ticker` qui compte les secondes sans action, remis à zéro par chaque action) ; cibles ≥ 64 px ; texte d'invite lisible à 2 m (≥ 28 px) ; aucune saisie clavier requise.

## 6. Contrôle spécifique
`render_artifact` (pas de caméra/micro en rendu : vérifie l'écran d'invite et les états refusés), `shaderErrors` vide, `fps`. Pour l'audio : la validation/publication renvoie les erreurs de patch ; teste dans l'aperçu après un clic. Décris à l'utilisateur ce qu'il doit faire en premier (« clique sur Activer le son, puis bouge la souris »).
