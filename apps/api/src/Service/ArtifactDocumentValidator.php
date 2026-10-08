<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validates Artefacts SDUI envelopes against schema limits + component whitelist.
 * Rejects js/css/markup/innerHTML and unsafe URLs.
 */
final class ArtifactDocumentValidator
{
    public const MAX_BYTES = 512 * 1024;
    public const MAX_DEPTH = 32;
    public const MAX_NODES = 5000;
    public const MAX_JSONATA = 4096;
    public const MAX_REDUCER = 32 * 1024;
    public const MAX_SHADER_SOURCE = 32 * 1024;
    public const MAX_HF_TEXT = 8 * 1024;
    public const MAX_SOUND_BANK = 64 * 1024;
    public const MAX_AUDIO_PATTERN = 16 * 1024;
    public const MAX_AUDIO_SAMPLES = 16 * 1024;
    public const MAX_AUDIO_PARAMS = 4 * 1024;

    /** Accès sensibles déclarés par le document (`capabilities`) et composants qui les exigent. */
    public const CAPABILITIES = ['camera', 'microphone', 'midi', 'screen'];
    private const CAPABILITY_TAGS = ['sonic-camera' => 'camera', 'sonic-mic' => 'microphone', 'sonic-midi' => 'midi', 'sonic-screen' => 'screen'];

    /** @var array<string, int> balises rencontrées pendant la validation courante */
    private array $seenTags = [];

    private const FORBIDDEN_NODE_KEYS = ['markup', 'innerHTML', 'prefix', 'suffix', 'js', 'css'];
    private const FORBIDDEN_DESCRIPTOR_KEYS = ['js', 'css'];

    private const SHADER_SOURCE_ATTRS = [
        'image', 'buffer-a', 'buffer-b', 'buffer-c', 'buffer-d', 'common', 'shader',
        'post-image', 'post-buffer-a', 'post-buffer-b', 'post-buffer-c', 'post-buffer-d', 'post-common',
        'reducer', 'initial', 'keymap', 'palette',
    ];

    private const JSON_ATTRS = ['initial', 'keymap', 'palette', 'payload', 'repeat', 'options', 'bank', 'pattern', 'samples', 'params', 'notes-map', 'choke'];

    /** Mémo du format son pour l'agent (addon `sound` de @supersoniks/creative-stack). */
    private const SOUND_RULE = 'sonic-sound (1 par page) : tout est synthétisé, aucun fichier. '
        .'bank = JSON {"sfx":{nom: preset | {preset?, wave:sine|square|triangle|sawtooth|noise, freq, slide(demi-tons), dur, attack, decay, sustain, release, vol, arp:[0,4,7], arpRate, vibrato:{rate,depth}, filter:{type,freq,q,to}, repeat, jitter, layers:[…], bus:sfx|ui, cooldown}}, '
        .'"songs":{nom:{bpm, steps(4), loop(true), loopFrom, swing, vol, instruments:{nom: preset | SynthDef}, patterns:{A:{instrument:"C4 - . E4+G4 x X G4! |"}}, sequence:["A","B"]}}}. '
        .'Pistes : 1 jeton par pas ; note C4/F#3/Bb2, accord C4+E4, "-" tenue, "." silence, x/X frappe, "!" accent ; une piste courte qui divise la longueur du motif se répète. '
        .'Presets jouables sans déclaration : click hover select back toggle error success notify type coin pickup jump land shoot laser hit hurt explosion powerup levelup gameover whoosh bounce teleport alarm step. '
        .'Instruments : kick snare hat openhat clap tom shaker lead chip bass sub pad pluck bell organ flute strings (instrument nommé comme un preset = preset implicite). '
        .'control = DP (ex. sous-clé du store "game.sound") : {music: nom|null, fade, paused, muted, volume:{master,music,sfx,ui}, play:{nom: compteur | {n, pitch, vol}}} ; toute hausse d’un compteur joue le son (la 1re valeur sert de référence) ; un morceau dans play = jingle par-dessus la musique. '
        .'out-data-provider (défaut soundState) : {unlocked, muted, paused, music:{id, playing, ended, bpm, bar, beat, pattern, loops}, lastSfx, lastUi, played:{nom:n}, errors:[…]}. '
        .'Le son ne démarre qu’après un geste : afficher une invite tant que unlocked = false. '
        .'sonic-sfx sound="click" hover="hover" enveloppe des boutons (sons d’interface sans store).';

    /** Mémo de l'addon `media` (caméra, vidéo) et du micro. */
    private const MEDIA_RULE = 'Caméra / micro / MIDI / écran : déclarer "capabilities": ["camera"] / ["microphone"] / ["midi"] / ["screen"] à la racine du document (sinon refusé ; le viewer prévient l’utilisateur). Aucun accès au chargement : démarrer par un clic. '
        .'BOUTON : sonic-media-start for="cam mic" label="Activer la caméra" (caché une fois prêt, affiche un refus) ; ou sonic-audio-unlock start="mic" (son + micro en un clic). '
        .'CAMÉRA : sonic-camera id="cam" (hidden-preview si elle ne sert que de source, facing user|environment, mirror auto|true|false, fit, width/height/fps) control="dp.cam" ({active, facing, deviceId, snapshot: compteur}) snapshot-provider="gallery.channel0" (photo SonicMediaRef écrite là) ; état camState {status: idle|requesting|ready|paused|denied|error|unsupported, error, width, height, snapshot, snapshots}. Dans un shader : channel0="#cam". '
        .'Photo : incrémenter control.snapshot dans le reducer ; l’afficher avec un sonic-shader dataProvider="gallery" (texture(iChannel0, uv)). '
        .'MICRO : sonic-mic id="mic" (monitor pour l’entendre, sinon muet) control="dp.mic" ({active, monitor, gain}) ; état micState {status, error, rms, db} ; sonic-audio-analyser source="#mic" (pitch pour la hauteur, onsetCount pour les attaques). '
        .'VIDÉO : sonic-video id="clip" src="https://…webm|mp4" (https ou relative) loop muted autoplay hidden-preview preload="blob" audio-out="master" (son → moteur audio, analysable par sonic-audio-analyser source="#clip") control="dp.player" ({playing, seek: {t, n}, rate, volume, muted, loopStart, loopEnd}) ; état clipState {status: loading|ready|playing|paused|ended|needs-gesture|error, timeS, durationS, progress}. Shader : channel0="#clip". Autoplay avec son refusé par le navigateur → démarre muette (needs-gesture) : prévoir sonic-media-start for="clip". '
        .'ÉCRAN : sonic-screen id="screen" (hidden-preview, audio pour le son du partage, surface monitor|window|browser) démarré UNIQUEMENT par sonic-media-start for="screen" (clic direct exigé par le navigateur) ; état screenState {status, error ("partage arrêté" si l’utilisateur l’arrête), surface, width, height, audio} ; shader channel0="#screen" ; export video-source="#screen". '
        .'EXPORT : sonic-media-recorder id="export" video-source="#viz" (shader, caméra ou vidéo) audio-source="master|#id|none" control="dp.export" ({recording: true|false}) max-s="30" ; état exportState {status: waiting-source|ready|recording|error|unsupported, elapsedS, last: {url, mime, durS, size, width, height}}. '
        .'TÉLÉCHARGER : sonic-media-download source="exportState.last" filename="ma-creation" (contenu en slot, caché tant qu’il n’y a rien ; blob: uniquement : prises, photos, exports). '
        .'Compteurs (snapshot, seek.n) : la première valeur sert de référence, chaque hausse déclenche.';

    /** Mémo des addons `physics` (planck.js) et `controller` (manettes). */
    private const PHYSICS_RULE = 'MONDE 2D (planck.js / Box2D) : <sonic-physics id="world" width="800" height="450" gravity="0 9.8" (m/s², "0 0" vue de dessus) bounds="walls|box (sans sol)|floor|none" store="jeu" bodies="jeu.bricks" input="jeu.input" control="jeu.ctl" drag> + enfants sonic-body / sonic-joint. Unités : px (y vers le bas), degrés, px/s. '
        .'CORPS : sonic-body name type="dynamic|static|kinematic" shape="circle (r)|box (w h)|polygon|edge|chain (points=\'x,y x,y\' relatifs au centre)" x y angle vx vy restitution friction density sensor bullet fixed-rotation tags="brick" clamp-x="60 740" fill stroke label. La même description en liste JSON dans le store (bodies="jeu.bricks") : le monde suit ajouts et retraits (retirer une brique = la filtrer dans le reducer). '
        .'JOINTS : sonic-joint type="revolute (motor °/s, lower/upper)|distance (frequency = ressort)|rope|weld|prismatic" a b at="x y" (b="ground" = décor). '
        .'PILOTAGE input = {"paddle": {"vx": -650}, "ship": {"fx": 0, "fy": -20}, "ball": {"impulse": {"n": compteur, "x": 10, "y": -34}, "set": {"n": compteur, "x": 400, "y": 396}}} (vx/vy imposés à chaque pas, fx/fy forces, impulse/set déclenchés quand n augmente). control = {running, reset: compteur, gravity: [x, y]}. '
        .'ÉVÉNEMENTS vers le store : collide {a, b, tagsA, tagsB, impulse, x, y}, enter/leave {sensor, body, tags}, out {body, side} (balle perdue), tap {x, y, body} ; mettre budget-ms="50" sur le store. État worldState {bodies: {nom: {x, y, angle, vx, vy}}, collisions}. Image du monde : sonic-shader channel0="#world" (lueur, déformations) ; hidden-preview si le shader seul s’affiche. '
        .'MANETTES : sonic-controller id="pad" store="jeu" keymap=\'{"a":"launch","start":"pause","left":"left"}\' release (envoie aussi "left:up") analog-action="stick" (→ {type:"stick", payload:{pad, lx, ly, rx, ry, lt, rt}}) control="jeu.padCtl" ({rumble: {n: compteur, ms}}) ; état padState {status: idle|ready, count, pads}. Clavier tenu : sonic-keyboard keyup (actions "left" puis "left:up").';

    /** Mémo de l'addon `audio` (synthèse modulaire, séquenceur, sampler, analyseur). */
    private const AUDIO_RULE = 'Un seul moteur audio par page, démarré au premier geste (sonic-audio-unlock = bouton d’invite). '
        .'INSTRUMENT : sonic-patch preset="synth/lead|bass|pad|pluck|fm-bell|chip|drums/kick|snare|hat|kit" params=\'{"cutoff":900}\' (cutoff/reso, pad : attack, pluck : decay, chip : pw) events="dp.notes" trigger="dp.tick" (trigger = valeur qui change → rejoue events ; sans trigger : joué quand la liste change). '
        .'Événement : {note:"C4"|60, vel, durS, sample:"kick", type:note|noteOn|noteOff|param, when, id}. drums/kit : samples kick snare clap hat openhat. '
        .'PATCH MAIN : <sonic-patch><sonic-voice> modules joués par note </sonic-voice> modules globaux </sonic-patch>. Modules : sonic-osc (wave sine|square|sawtooth|triangle|pulse, freq-hz=voice.pitch, detune, octave, semi, level, fm, fm-amount), sonic-noise (color), sonic-mixer (in, levels), sonic-filter (type, freq-hz, q), sonic-vca (gain), sonic-env (a d s r), sonic-lfo (rate-hz | sync="1/8"), sonic-shaper, sonic-pan, sonic-delay (time s|"3/16", feedback, mix), sonic-reverb (size-s, mix), sonic-chorus, sonic-comp. '
        .'Câblage : name + in="o1 o2" (sinon module précédent ; global : la somme des voix). Modulation : sonic-mod from="env|lfo|voice.vel|voice.pitch|voice.rand" to="flt.freq-hz" amount (curve="exp" : demi-tons) ; raccourci gain="aenv". sonic-param to="flt.freq-hz" source="dp.cutoff" ramp-s min max | expose="cutoff". Boucle audio seulement via sonic-delay. Kit : plusieurs sonic-voice sample="kick" note="C2". '
        .'SÉQUENCEUR : sonic-sequencer bpm swing seed control="dp.transport" ({playing,bpm,swing,pattern}) pattern=\'{"kit":{"kick":"x...x...x...x...","hat":"[..x.]*4"},"bass":{"notes":"0 ~ 2 [4 7]","scale":"a2:minor-pentatonic"},"lead":"<c5 e5 g5>"}\' (clé = id d’instrument ; ligne = 1 mesure : grille x/X/., [ ] sous-division, ~ silence, - tenue, <a b> alternance, *n, !n, @n, ?p, x(3,8,r) euclide, c4+e4 accord, degrés avec scale). '
        .'store="idStore" : le reducer reçoit {type:"step", payload:{step,beat,bar,phase,when,bpm}} en avance et écrit des notes avec when (augmenter budget-ms / lookahead-ms si le reducer est lourd). État <id>State : {playing, step, beat, bar, phase}. '
        .'SAMPLER : sonic-sampler samples=\'{"kick":"https://…/kick.wav","voix":{"ref":"rec.last","root":"C4"}}\' (https uniquement ; gain pan pitch root start end loop reverse gate) choke=\'[["hat","openhat"]]\'. '
        .'ENREGISTRER : sonic-audio-recorder id="rec" source="#mic|#id|master" control="dp.rec" ({recording: true|false, target: "takes.A"}) max-s ; chaque prise {url, mime, durS, size} est écrite dans target, rejouable par sonic-sampler samples=\'{"A":{"ref":"takes.A"}}\' (pads vides listés dans padsState.empty) ; état recState {status: waiting-source|ready|recording, elapsedS, last, takes}. '
        .'MIDI ("capabilities": ["midi"]) : sonic-midi id="midi" démarré par sonic-media-start for="midi" (ou sonic-audio-unlock start="midi") ; ENTRÉE input="all|nom" channel mpe (LinnStrument, Seaboard : bend/pression/timbre par note) target="#voix" (notes jouées directement ; dans le patch : sonic-mod from="voice.pressure|voice.timbre|voice.bend") store="idStore" ({type:"midi", payload:{kind: noteOn|noteOff|cc|program|start|stop|beat, note, name, vel, ch, cc, value}}) ; état midiState {status, inputs, held[{name, bend, pressure, timbre}], last, notes, cc{"74":0.5}, clock{running, bpm, beat}}. '
        .'SORTIE output="nom d’appareil" out-channel : c’est un instrument (sonic-sequencer pattern=\'{"midi":"c3 e3 g3"}\' joue sur la machine), cc-out="dp.knobs" ({"74":0.5}), clock-out="#seq" (horloge 24 ppq + Start/Stop) ; control {active, input, output, channel, program, panic: compteur}. Horloge d’une machine : sonic-sequencer sync="#midi" (Start/Stop/tempo/phase externes ; état sync {locked, driftMs}). '
        .'MODULES AVANCÉS : sonic-osc sync="m" (synchro dure sur l’osc m, balayer s.freq-hz par une enveloppe curve="exp"), sonic-ladder (freq-hz, res 0..1.2 auto-oscillant, drive), sonic-fold (amount 0..12, bias), sonic-karplus (corde : decay, damp ; suit voice.pitch et le bend), sonic-resonator (passe-bandes à exciter : bruit, entrée ; q, partials "1 2 3"), sonic-grain sample="https://…|takes.voix" (granulaire : position 0..1, spread, size-s, density grains/s, pitch, jitter ; réglables en direct par sonic-param source="dp.pos"). Presets : synth/string, synth/sync-lead, synth/acid. Les 4 premiers utilisent un AudioWorklet chargé avant le premier son (repli natif annoncé dans warnings). '
        .'EFFET SUR UNE ENTRÉE : patch sans sonic-voice : <sonic-patch id="clean" output="none"><sonic-audio-input source="#mic"/> sonic-filter… </sonic-patch>, puis enregistrer / analyser "#clean" (output="none" avec le micro : pas de Larsen) ; état inputs {nom: true} une fois branché. '
        .'ANALYSEUR : sonic-audio-analyser id="spectre" source="master|#id" → DP spectreState {rms, peak, db, bands[], centroidHz, onset, onsetCount, pitchHz(attr pitch)} ; sonic-shader channel0="#spectre" : texture(iChannel0, vec2(x,0.25)).r spectre, vec2(x,0.75) onde. '
        .'États : <id>State de chaque composant (status idle tant que le son n’est pas actif, errors lisibles). Volumes : garder gain ≤ 0.6 par instrument, le master est limité.';

    /** @var list<string> */
    private const MODEL_HOST_ALLOWLIST = [
        'huggingface.co',
        'cdn.jsdelivr.net',
        'raw.githubusercontent.com',
        'github.com',
        'threejs.org',
        'modelviewer.dev',
    ];

    /** @var list<string> */
    private array $allowedTags;

    /** @var array<string, true> */
    private array $allowedTagSet;

    /**
     * @param array{components?: list<array{name: string}>, safeHtmlTags?: list<string>}|null $catalog
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/artifacts/catalog.json')]
        private readonly string $catalogPath,
        #[Autowire('%kernel.project_dir%/config/artifacts/sdui.schema.json')]
        private readonly string $schemaPath,
        private readonly ArtifactScriptsCatalog $scriptsCatalog,
        ?array $catalog = null,
    ) {
        $data = $catalog ?? $this->loadJson($this->catalogPath);
        $tags = $data['safeHtmlTags'] ?? [];
        foreach ($data['components'] ?? [] as $component) {
            if (isset($component['name']) && \is_string($component['name'])) {
                $tags[] = $component['name'];
            }
        }
        $this->allowedTags = array_values(array_unique($tags));
        $this->allowedTagSet = array_fill_keys($this->allowedTags, true);
    }

    /** @return list<string> */
    public function allowedTags(): array
    {
        return $this->allowedTags;
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return $this->loadJson($this->catalogPath);
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return $this->loadJson($this->schemaPath);
    }

    /**
     * Payload pour l’outil MCP get_artifact_catalog.
     *
     * @return array<string, mixed>
     */
    public function mcpCatalogPayload(bool $compact = true, ?array $components = null): array
    {
        $examplesDir = \dirname($this->catalogPath).'/examples';
        $examples = [];
        if (is_dir($examplesDir)) {
            foreach (glob($examplesDir.'/*.json') ?: [] as $file) {
                $decoded = $this->loadJson($file);
                if ($decoded !== []) {
                    $examples[] = $decoded;
                }
            }
        }

        $catalog = $this->catalog();
        if ($components !== null && $components !== []) {
            $want = array_fill_keys($components, true);
            $catalog['components'] = array_values(array_filter(
                $catalog['components'] ?? [],
                static fn (mixed $c): bool => \is_array($c) && isset($want[$c['name'] ?? '']),
            ));
        }
        if ($compact) {
            $catalog = $this->compactCatalog($catalog);
        }

        return [
            'catalog' => $catalog,
            'envelope' => [
                'schema' => 'artifacts/1',
                'title' => 'Titre affiché',
                'theme' => 'default',
                'defaultView' => 'home',
                'views' => [
                    [
                        'id' => 'home',
                        'title' => 'Accueil',
                        'root' => ['nodes' => [['tagName' => 'div', 'attributes' => ['class' => 'p-4']]]],
                    ],
                ],
                'data' => [
                    'sources' => ['votes' => ['collection' => 'votes']],
                    'transforms' => ['total' => ['jsonata' => '$count(votes)']],
                ],
            ],
            'rules' => [
                'interdit' => ['markup', 'innerHTML', 'js', 'css', 'prefix', 'suffix', 'javascript:', 'URL libre de script'],
                'scripts' => 'Optionnel: tableau d’IDs du catalogue scripts (voir scripts.libraries[].id). Ex: ["chartjs","leaflet"]. Pas d’URL, pas de balise <script>.',
                'tagName' => 'Uniquement composants Concorde (sonic-*) ou balises HTML sûres du catalogue.',
                'navigation' => 'views[].id = hash URL (#stats). defaultView si hash absent. views[].hidden = true : vue absente des onglets (accessible par son #id).',
                'interactive' => 'sonic-store + keyboard/gamepad/gesture/action/ticker + sonic-matrix.',
                'son' => self::SOUND_RULE,
                'audio' => self::AUDIO_RULE,
                'media' => self::MEDIA_RULE,
                'physique' => self::PHYSICS_RULE,
                'polices' => 'PAR DÉFAUT, toujours déclarer "fonts" avec des polices Google Fonts choisies pour le thème de l\'artefact (4 max, noms Google Fonts, pas d’URL ; axes optionnels "Fredoka:wght@400;700") : une police d’affichage pour les titres + une police de texte lisible. Ex. : jeu rétro → "Pixelify Sans" ; enfants/ludique → "Fredoka" ; éditorial/littéraire → "Playfair Display" + "Lora" ; technique/dashboard → "Space Grotesk" + "JetBrains Mono" ; manuscrit/atelier → "Patrick Hand". Appliquer ensuite font-family:\'Nom\',repli dans les styles (toujours un repli générique : serif, sans-serif, monospace, cursive). Ne pas laisser la police système par défaut.',
                'icones' => 'PAR DÉFAUT, toute icône (actions, boutons, navigation, états) vient d’une vraie police d’icônes, jamais d’émojis, de caractères Unicode décoratifs ni d’images : ajouter "Material Symbols Sharp" (ou "Material Symbols Rounded"/"Material Symbols Outlined" selon le style, à choisir en cohérence avec le thème) dans "fonts", puis <span style="font-family:\'Material Symbols Sharp\';font-size:1.4rem;line-height:1;display:inline-block;white-space:nowrap;overflow:hidden;max-width:1.1em">nom_icone</span> avec la ligature (sonic-value ou texte). Choisir le nom adapté à l’action : arrow_back/arrow_forward/arrow_upward/arrow_downward (directions), menu, close, check, add, delete, edit, search, settings, play_arrow, pause, replay, send, share, download, favorite, star, home, person, volume_up/volume_off, leaderboard, menu_book. Une action = une icône explicite ; ajouter un libellé ou aria-label si l’icône seule est ambiguë. Toujours compter cette police dans la limite de 4 polices.',
                'collecte' => 'Formulaire / scores anonymes : data.sources.<x> = {collection, intake:{fields:{nom:{type:string,max:20,required:true}, score:{type:integer,min:0,max:9999}}, maxRecords, minInterval, requireCode}}. Fermée par défaut : open_artifact_intake ouvre une session limitée. data.sinks.<x> = {collection, from:"store.outbox", merge:{champ:"dp.cle"}, code?:"dp.cle", ack?:"storeId"} : le viewer poste chaque élément {id, data} ajouté à la boîte d’envoi et renvoie sink:ok / sink:error au store. Lecture non publique : lien secret &rk=<readToken> (get_artifact.collections).',
            ],
            'scripts' => $this->scriptsCatalog->mcpSummary(),
            'examples' => array_slice($examples, 0, 5),
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     *
     * @return array<string, mixed>
     */
    private function compactCatalog(array $catalog): array
    {
        $common = [
            'class' => ['type' => 'string'],
            'id' => ['type' => 'string'],
            'style' => ['type' => 'string'],
            'slot' => ['type' => 'string'],
            'dataProvider' => ['type' => 'string'],
        ];
        $components = [];
        foreach ($catalog['components'] ?? [] as $component) {
            if (!\is_array($component) || !isset($component['name'])) {
                continue;
            }
            $props = \is_array($component['props'] ?? null) ? $component['props'] : [];
            $specific = [];
            foreach ($props as $prop => $meta) {
                if (isset($common[$prop])) {
                    continue;
                }
                $specific[$prop] = $meta;
            }
            $entry = [
                'name' => $component['name'],
                'props' => $specific,
            ];
            if (isset($component['description']) && \is_string($component['description'])) {
                $entry['description'] = $component['description'];
            }
            $components[] = $entry;
        }

        return [
            'concordeVersion' => $catalog['concordeVersion'] ?? null,
            'generatedFrom' => $catalog['generatedFrom'] ?? null,
            'commonProps' => $common,
            'components' => $components,
            'safeHtmlTags' => $catalog['safeHtmlTags'] ?? [],
            'notes' => $catalog['notes'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|mixed $document
     *
     * @return array{valid: bool, errors: list<array{path: string, message: string}>}
     */
    public function validate(mixed $document): array
    {
        $errors = [];
        if (!\is_array($document)) {
            return ['valid' => false, 'errors' => [['path' => '', 'message' => 'Le document doit être un objet JSON.']]];
        }

        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['valid' => false, 'errors' => [['path' => '', 'message' => 'JSON invalide.']]];
        }
        if (\strlen($encoded) > self::MAX_BYTES) {
            $errors[] = ['path' => '', 'message' => sprintf('Document trop volumineux (max %d Ko).', self::MAX_BYTES / 1024)];
        }

        $this->seenTags = [];

        if (($document['schema'] ?? null) !== 'artifacts/1') {
            $errors[] = ['path' => '/schema', 'message' => 'schema doit être "artifacts/1".'];
        }

        $title = $document['title'] ?? null;
        if (!\is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
            $errors[] = ['path' => '/title', 'message' => 'title requis (1–200 caractères).'];
        }

        $views = $document['views'] ?? null;
        if (!\is_array($views) || $views === []) {
            $errors[] = ['path' => '/views', 'message' => 'Au moins une vue est requise.'];
        } else {
            $viewIds = [];
            $nodeCount = 0;
            foreach (array_values($views) as $i => $view) {
                $base = '/views/'.$i;
                if (!\is_array($view)) {
                    $errors[] = ['path' => $base, 'message' => 'Vue invalide.'];
                    continue;
                }
                $id = $view['id'] ?? null;
                if (!\is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)) {
                    $errors[] = ['path' => $base.'/id', 'message' => 'id de vue invalide.'];
                } elseif (isset($viewIds[$id])) {
                    $errors[] = ['path' => $base.'/id', 'message' => 'id de vue dupliqué.'];
                } else {
                    $viewIds[$id] = true;
                }
                $vTitle = $view['title'] ?? null;
                if (!\is_string($vTitle) || trim($vTitle) === '') {
                    $errors[] = ['path' => $base.'/title', 'message' => 'title de vue requis.'];
                }
                if (isset($view['hidden']) && !\is_bool($view['hidden'])) {
                    $errors[] = ['path' => $base.'/hidden', 'message' => 'hidden doit être un booléen (vue hors navigation).'];
                }
                $root = $view['root'] ?? null;
                if (!\is_array($root)) {
                    $errors[] = ['path' => $base.'/root', 'message' => 'root (descripteur SDUI) requis.'];
                } else {
                    $this->validateDescriptor($root, $base.'/root', $errors, $nodeCount, 0);
                }
            }

            $defaultView = $document['defaultView'] ?? null;
            if (!\is_string($defaultView) || !isset($viewIds[$defaultView])) {
                $errors[] = ['path' => '/defaultView', 'message' => 'defaultView doit référencer une vue existante.'];
            }

            if ($nodeCount > self::MAX_NODES) {
                $errors[] = ['path' => '/views', 'message' => sprintf('Trop de nœuds (max %d).', self::MAX_NODES)];
            }
        }

        if (isset($document['data'])) {
            $this->validateDataSection($document['data'], '/data', $errors);
        }

        if (\array_key_exists('scripts', $document)) {
            $resolved = $this->scriptsCatalog->resolve($document['scripts']);
            foreach ($resolved['errors'] as $err) {
                $errors[] = $err;
            }
        }

        if (\array_key_exists('fonts', $document)) {
            $this->validateFonts($document['fonts'], $errors);
        }

        $this->validateCapabilities($document['capabilities'] ?? null, \array_key_exists('capabilities', $document), $errors);

        // Reject unknown top-level keys beyond envelope
        $allowedTop = [
            'schema' => true,
            'title' => true,
            'theme' => true,
            'scripts' => true,
            'views' => true,
            'defaultView' => true,
            'data' => true,
            'fonts' => true,
            'capabilities' => true,
        ];
        foreach (array_keys($document) as $key) {
            if (!isset($allowedTop[$key])) {
                $errors[] = ['path' => '/'.$key, 'message' => 'Clé non autorisée.'];
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $descriptor
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateDescriptor(array $descriptor, string $path, array &$errors, int &$nodeCount, int $depth): void
    {
        foreach (self::FORBIDDEN_DESCRIPTOR_KEYS as $forbidden) {
            if (\array_key_exists($forbidden, $descriptor)) {
                $errors[] = ['path' => $path.'/'.$forbidden, 'message' => sprintf('"%s" est interdit (sécurité).', $forbidden)];
            }
        }

        if (isset($descriptor['library'])) {
            if (!\is_array($descriptor['library'])) {
                $errors[] = ['path' => $path.'/library', 'message' => 'library doit être un objet.'];
            } else {
                foreach ($descriptor['library'] as $key => $node) {
                    if (!\is_array($node)) {
                        $errors[] = ['path' => $path.'/library/'.$key, 'message' => 'Nœud library invalide.'];
                        continue;
                    }
                    $this->validateNode($node, $path.'/library/'.$key, $errors, $nodeCount, $depth + 1);
                }
            }
        }

        if (isset($descriptor['nodes'])) {
            if (!\is_array($descriptor['nodes'])) {
                $errors[] = ['path' => $path.'/nodes', 'message' => 'nodes doit être un tableau.'];
            } else {
                foreach (array_values($descriptor['nodes']) as $i => $node) {
                    if (!\is_array($node)) {
                        $errors[] = ['path' => $path.'/nodes/'.$i, 'message' => 'Nœud invalide.'];
                        continue;
                    }
                    $this->validateNode($node, $path.'/nodes/'.$i, $errors, $nodeCount, $depth + 1);
                }
            }
        }

        foreach (array_keys($descriptor) as $key) {
            if (!\in_array($key, ['library', 'nodes'], true) && !\in_array($key, self::FORBIDDEN_DESCRIPTOR_KEYS, true)) {
                $errors[] = ['path' => $path.'/'.$key, 'message' => 'Clé descripteur non autorisée.'];
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateNode(array $node, string $path, array &$errors, int &$nodeCount, int $depth): void
    {
        ++$nodeCount;
        if ($depth > self::MAX_DEPTH) {
            $errors[] = ['path' => $path, 'message' => sprintf('Profondeur max %d dépassée.', self::MAX_DEPTH)];

            return;
        }

        foreach (self::FORBIDDEN_NODE_KEYS as $forbidden) {
            if (\array_key_exists($forbidden, $node)) {
                $errors[] = ['path' => $path.'/'.$forbidden, 'message' => sprintf('"%s" est interdit (sécurité).', $forbidden)];
            }
        }

        $tagName = $node['tagName'] ?? 'div';
        if (\is_string($tagName)) {
            $this->seenTags[$tagName] = ($this->seenTags[$tagName] ?? 0) + 1;
        }
        if (!\is_string($tagName) || $tagName === '') {
            $errors[] = ['path' => $path.'/tagName', 'message' => 'tagName invalide.'];
        } elseif (!isset($this->allowedTagSet[$tagName])) {
            $errors[] = ['path' => $path.'/tagName', 'message' => sprintf('Composant hors liste blanche : %s', $tagName)];
        } elseif (preg_match('/^script$/i', $tagName) || str_contains(strtolower($tagName), 'script')) {
            $errors[] = ['path' => $path.'/tagName', 'message' => 'Balise script interdite.'];
        }

        if (isset($node['attributes'])) {
            if (!\is_array($node['attributes'])) {
                $errors[] = ['path' => $path.'/attributes', 'message' => 'attributes doit être un objet.'];
            } else {
                foreach ($node['attributes'] as $attr => $value) {
                    if (!\is_string($attr) || $attr === '') {
                        $errors[] = ['path' => $path.'/attributes', 'message' => 'Nom d’attribut invalide.'];
                        continue;
                    }
                    if (preg_match('/^on/i', $attr) || strcasecmp($attr, 'srcdoc') === 0) {
                        $errors[] = ['path' => $path.'/attributes/'.$attr, 'message' => 'Attribut événement / srcdoc interdit.'];
                        continue;
                    }
                    if (\is_string($value)) {
                        $msg = $this->validateAttributeValue(\is_string($tagName) ? $tagName : 'div', $attr, $value);
                        if ($msg !== null) {
                            $errors[] = ['path' => $path.'/attributes/'.$attr, 'message' => $msg];
                        }
                    }
                }
            }
        }

        if (isset($node['nodes'])) {
            if (!\is_array($node['nodes'])) {
                $errors[] = ['path' => $path.'/nodes', 'message' => 'nodes doit être un tableau.'];
            } else {
                foreach (array_values($node['nodes']) as $i => $child) {
                    if (!\is_array($child)) {
                        $errors[] = ['path' => $path.'/nodes/'.$i, 'message' => 'Nœud enfant invalide.'];
                        continue;
                    }
                    $this->validateNode($child, $path.'/nodes/'.$i, $errors, $nodeCount, $depth + 1);
                }
            }
        }

        $allowedKeys = ['tagName' => true, 'attributes' => true, 'nodes' => true, 'libraryKey' => true, 'contentElementSelector' => true, 'parentElementSelector' => true];
        foreach (array_keys($node) as $key) {
            if (!isset($allowedKeys[$key]) && !\in_array($key, self::FORBIDDEN_NODE_KEYS, true)) {
                $errors[] = ['path' => $path.'/'.$key, 'message' => 'Clé de nœud non autorisée.'];
            }
        }
    }

    /**
     * @param mixed $data
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateDataSection(mixed $data, string $path, array &$errors): void
    {
        if (!\is_array($data)) {
            $errors[] = ['path' => $path, 'message' => 'data doit être un objet.'];

            return;
        }
        if (isset($data['sources'])) {
            if (!\is_array($data['sources'])) {
                $errors[] = ['path' => $path.'/sources', 'message' => 'sources doit être un objet.'];
            } else {
                foreach ($data['sources'] as $name => $src) {
                    if (!\is_array($src) || !isset($src['collection']) || !\is_string($src['collection'])
                        || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $src['collection'])) {
                        $errors[] = ['path' => $path.'/sources/'.$name, 'message' => 'source.collection invalide.'];
                        continue;
                    }
                    if (isset($src['writeMode']) && !\in_array($src['writeMode'], ['none', 'members', 'authenticated'], true)) {
                        $errors[] = ['path' => $path.'/sources/'.$name.'/writeMode', 'message' => 'writeMode : none | members | authenticated (collecte anonyme : déclarer `intake`).'];
                    }
                    if (isset($src['publicRead']) && !\is_bool($src['publicRead'])) {
                        $errors[] = ['path' => $path.'/sources/'.$name.'/publicRead', 'message' => 'publicRead doit être un booléen.'];
                    }
                    if (isset($src['refresh']) && (!\is_int($src['refresh']) || $src['refresh'] < 5 || $src['refresh'] > 3600)) {
                        $errors[] = ['path' => $path.'/sources/'.$name.'/refresh', 'message' => 'refresh : 5…3600 secondes.'];
                    }
                    if (isset($src['intake'])) {
                        foreach (ArtifactIntakeSchema::validateDeclaration($src['intake']) as $msg) {
                            $errors[] = ['path' => $path.'/sources/'.$name.'/intake', 'message' => $msg];
                        }
                    }
                }
            }
        }
        if (isset($data['transforms'])) {
            if (!\is_array($data['transforms'])) {
                $errors[] = ['path' => $path.'/transforms', 'message' => 'transforms doit être un objet.'];
            } else {
                foreach ($data['transforms'] as $name => $tr) {
                    if (!\is_array($tr) || !isset($tr['jsonata']) || !\is_string($tr['jsonata'])) {
                        $errors[] = ['path' => $path.'/transforms/'.$name, 'message' => 'transform.jsonata requis.'];
                        continue;
                    }
                    if (\strlen($tr['jsonata']) > self::MAX_JSONATA) {
                        $errors[] = ['path' => $path.'/transforms/'.$name.'/jsonata', 'message' => 'Expression jsonata trop longue (max 4 Ko).'];
                    }
                }
            }
        }
        if (isset($data['stores'])) {
            if (!\is_array($data['stores'])) {
                $errors[] = ['path' => $path.'/stores', 'message' => 'stores doit être un objet.'];
            } elseif (\count($data['stores']) > 8) {
                $errors[] = ['path' => $path.'/stores', 'message' => 'Trop de stores (max 8).'];
            } else {
                foreach ($data['stores'] as $name => $store) {
                    if (!\is_array($store)) {
                        $errors[] = ['path' => $path.'/stores/'.$name, 'message' => 'store invalide.'];
                        continue;
                    }
                    $reducer = $store['reducer'] ?? null;
                    if (!\is_string($reducer) || $reducer === '') {
                        $errors[] = ['path' => $path.'/stores/'.$name.'/reducer', 'message' => 'reducer requis.'];
                    } elseif (\strlen($reducer) > self::MAX_REDUCER) {
                        $errors[] = ['path' => $path.'/stores/'.$name.'/reducer', 'message' => 'reducer trop long (max 32 Ko).'];
                    }
                }
            }
        }
        if (isset($data['sinks'])) {
            $this->validateSinks($data['sinks'], \is_array($data['sources'] ?? null) ? $data['sources'] : [], $path.'/sinks', $errors);
        }
    }

    /**
     * `data.sinks` : le viewer poste vers une collecte les éléments d'une boîte d'envoi du store.
     *   "sinks": {"scores": {"collection": "scores", "from": "game.outbox", "merge": {"name": "eleve.name"}}}
     *
     * @param array<string, mixed> $sources
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateSinks(mixed $sinks, array $sources, string $path, array &$errors): void
    {
        if (!\is_array($sinks) || ($sinks !== [] && array_is_list($sinks))) {
            $errors[] = ['path' => $path, 'message' => 'sinks doit être un objet.'];

            return;
        }
        if (\count($sinks) > 4) {
            $errors[] = ['path' => $path, 'message' => 'Trop de sinks (max 4).'];
        }
        $intakeCollections = [];
        foreach ($sources as $src) {
            if (\is_array($src) && isset($src['intake'], $src['collection']) && \is_string($src['collection'])) {
                $intakeCollections[] = $src['collection'];
            }
        }
        $dpPath = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}(\.[a-zA-Z0-9_]{1,64}){1,4}$/';
        foreach ($sinks as $name => $sink) {
            $p = $path.'/'.$name;
            if (!\is_array($sink)) {
                $errors[] = ['path' => $p, 'message' => 'sink invalide.'];
                continue;
            }
            if (!\is_string($sink['collection'] ?? null) || !\in_array($sink['collection'], $intakeCollections, true)) {
                $errors[] = ['path' => $p.'/collection', 'message' => 'collection doit être une source déclarée avec `intake`.'];
            }
            if (!\is_string($sink['from'] ?? null) || !preg_match($dpPath, $sink['from'])) {
                $errors[] = ['path' => $p.'/from', 'message' => 'from : chemin DataProvider « store.cle ».'];
            }
            if (isset($sink['merge'])) {
                if (!\is_array($sink['merge']) || \count($sink['merge']) > 8) {
                    $errors[] = ['path' => $p.'/merge', 'message' => 'merge : objet (max 8 champs).'];
                } else {
                    foreach ($sink['merge'] as $field => $src) {
                        if (!\is_string($field) || !\is_string($src) || !preg_match($dpPath, $src)) {
                            $errors[] = ['path' => $p.'/merge/'.$field, 'message' => 'merge : champ → chemin DataProvider.'];
                        }
                    }
                }
            }
            if (isset($sink['code']) && (!\is_string($sink['code']) || !preg_match($dpPath, $sink['code']))) {
                $errors[] = ['path' => $p.'/code', 'message' => 'code : chemin DataProvider du code de session.'];
            }
            if (isset($sink['ack']) && (!\is_string($sink['ack']) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $sink['ack']))) {
                $errors[] = ['path' => $p.'/ack', 'message' => 'ack : id du sonic-store à notifier.'];
            }
        }
    }

    private function validateAttributeValue(string $tagName, string $attr, string $value): ?string
    {
        $attrLower = strtolower($attr);
        if (str_contains(strtolower($value), 'javascript:')) {
            return 'Valeur javascript: interdite.';
        }
        if (\in_array($attrLower, self::SHADER_SOURCE_ATTRS, true) || $attrLower === 'reducer') {
            $max = $attrLower === 'reducer' ? self::MAX_REDUCER : self::MAX_SHADER_SOURCE;
            if (\strlen($value) > $max) {
                return sprintf('Valeur trop longue (max %d octets).', $max);
            }
        }
        if (\in_array($attrLower, self::JSON_ATTRS, true)) {
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                json_decode($trim);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return 'JSON invalide pour '.$attr.'.';
                }
            }
            if ($attrLower === 'initial' && \strlen($value) > self::MAX_REDUCER) {
                return 'initial trop volumineux.';
            }
            if ($attrLower === 'bank' && \strlen($value) > self::MAX_SOUND_BANK) {
                return sprintf('bank trop volumineuse (max %d Ko).', self::MAX_SOUND_BANK / 1024);
            }
            $audioMax = ['pattern' => self::MAX_AUDIO_PATTERN, 'samples' => self::MAX_AUDIO_SAMPLES, 'params' => self::MAX_AUDIO_PARAMS];
            if (isset($audioMax[$attrLower]) && \strlen($value) > $audioMax[$attrLower]) {
                return sprintf('%s trop volumineux (max %d Ko).', $attr, $audioMax[$attrLower] / 1024);
            }
            if ($tagName === 'sonic-sampler' && $attrLower === 'samples') {
                $msg = $this->validateSampleUrls($value);
                if ($msg !== null) {
                    return $msg;
                }
            }
        }
        if ($tagName === 'sonic-grain' && $attrLower === 'sample') {
            $u = trim($value);
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) === 1 && stripos($u, 'https://') !== 0) {
                return 'sonic-grain.sample : URL https uniquement, chemin relatif, ou chemin DP d’une prise (takes.voix).';
            }
        }
        if ($tagName === 'sonic-hugging-face-infer' && $attrLower === 'model') {
            $ok = (bool) preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $value)
                || (bool) preg_match('#^https://(www\.)?huggingface\.co/#i', $value);
            if (!$ok) {
                return 'model HF : org/nom ou URL https huggingface.co.';
            }
        }
        if ($tagName === 'sonic-3d' && $attrLower === 'src') {
            $trim = trim($value);
            if ($trim === '' || str_starts_with($trim, '/')) {
                return null;
            }
            if (!str_starts_with(strtolower($trim), 'https://')) {
                return 'src 3D : https uniquement.';
            }
            $host = parse_url($trim, PHP_URL_HOST);
            if (!\is_string($host)) {
                return 'src 3D invalide.';
            }
            $host = strtolower($host);
            $allowed = false;
            foreach (self::MODEL_HOST_ALLOWLIST as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return 'Domaine modele 3D hors liste blanche.';
            }
        }
        if ($this->isUnsafeUrl($attr, $value)) {
            return 'URL non autorisee (https uniquement ; pas de javascript:/data: hors image).';
        }

        return null;
    }

    /**
     * `capabilities` : accès sensibles que le document peut demander (le viewer l'affiche
     * avant tout clic). Un sonic-camera / sonic-mic exige la capacité correspondante.
     *
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateCapabilities(mixed $caps, bool $present, array &$errors): void
    {
        $declared = [];
        if ($present) {
            if (!\is_array($caps) || !array_is_list($caps)) {
                $errors[] = ['path' => '/capabilities', 'message' => 'capabilities : liste attendue (ex. ["camera"]).'];
            } else {
                foreach ($caps as $i => $cap) {
                    if (!\is_string($cap) || !\in_array($cap, self::CAPABILITIES, true)) {
                        $errors[] = ['path' => '/capabilities/'.$i, 'message' => sprintf('Capacité inconnue (valeurs : %s).', implode(', ', self::CAPABILITIES))];
                        continue;
                    }
                    $declared[$cap] = true;
                }
            }
        }
        foreach (self::CAPABILITY_TAGS as $tag => $cap) {
            if (isset($this->seenTags[$tag]) && !isset($declared[$cap])) {
                $errors[] = ['path' => '/capabilities', 'message' => sprintf('%s utilisé : ajouter "%s" à capabilities.', $tag, $cap)];
            }
        }
        $limits = ['sonic-camera' => 2, 'sonic-mic' => 2, 'sonic-video' => 6, 'sonic-audio-recorder' => 4, 'sonic-media-recorder' => 2, 'sonic-midi' => 2, 'sonic-screen' => 1, 'sonic-physics' => 4, 'sonic-controller' => 2];
        foreach ($limits as $tag => $max) {
            if (($this->seenTags[$tag] ?? 0) > $max) {
                $errors[] = ['path' => '/views', 'message' => sprintf('Trop de %s (max %d).', $tag, $max)];
            }
        }
    }

    /** URLs des samples : https ou relatives (pas de http, data:, blob:, javascript:). */
    private function validateSampleUrls(string $json): ?string
    {
        $samples = json_decode($json, true);
        if (!\is_array($samples)) {
            return null;
        }
        foreach ($samples as $name => $spec) {
            $url = \is_string($spec) ? $spec : (\is_array($spec) ? ($spec['url'] ?? null) : null);
            if ($url === null) {
                continue;
            }
            if (!\is_string($url) || $url === '') {
                return sprintf('samples.%s : url invalide.', $name);
            }
            $u = trim($url);
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) === 1 && stripos($u, 'https://') !== 0) {
                return sprintf('samples.%s : URL https uniquement (ou relative ; enregistrement : "ref").', $name);
            }
        }

        return null;
    }

    private function isUnsafeUrl(string $attr, string $value): bool
    {
        $attrLower = strtolower($attr);
        $urlish = \in_array($attrLower, ['href', 'src', 'action', 'formaction', 'poster', 'data', 'cite'], true)
            || str_ends_with($attrLower, 'url')
            || str_ends_with($attrLower, 'href')
            || str_ends_with($attrLower, 'src');
        if (!$urlish) {
            return false;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/')) {
            return false;
        }
        $lower = strtolower($trimmed);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'vbscript:')) {
            return true;
        }
        if (str_starts_with($lower, 'data:')) {
            return !preg_match('#^data:image/(png|jpe?g|gif|webp|svg\\+xml);#i', $trimmed);
        }
        if (str_starts_with($lower, 'https:')) {
            return false;
        }
        // relative without leading slash already handled; reject http: and others
        return true;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public const MAX_FONTS = 4;
    /** Famille Google Fonts + axes optionnels (ex. "Fredoka:wght@400;700", "Lora:ital,wght@0,400;1,400"). */
    public const FONT_SPEC = '/^[A-Z][A-Za-z0-9]*( [A-Z0-9][A-Za-z0-9]*){0,4}(:(ital,)?wght@[0-9;,]{1,40}|:ital@[01;,]{1,10})?$/';

    /**
     * `fonts` : familles Google Fonts chargées par le viewer (fonts.googleapis.com, déjà autorisé par la CSP).
     * Uniquement des noms de famille : jamais d'URL.
     *
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateFonts(mixed $fonts, array &$errors): void
    {
        if (!\is_array($fonts) || !array_is_list($fonts)) {
            $errors[] = ['path' => '/fonts', 'message' => 'fonts doit être une liste de familles Google Fonts.'];

            return;
        }
        if (\count($fonts) > self::MAX_FONTS) {
            $errors[] = ['path' => '/fonts', 'message' => sprintf('%d polices maximum.', self::MAX_FONTS)];
        }
        foreach ($fonts as $i => $font) {
            if (!\is_string($font) || \strlen($font) > 80 || !preg_match(self::FONT_SPEC, $font)) {
                $errors[] = ['path' => '/fonts/'.$i, 'message' => 'Famille invalide (ex. "Patrick Hand" ou "Fredoka:wght@400;700").'];
            }
        }
    }

}
