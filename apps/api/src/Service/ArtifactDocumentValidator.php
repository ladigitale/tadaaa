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
    /** GLB embarqué en base64 (attribut `glb` / `src` data:model/gltf-binary de sonic-3d). */
    public const MAX_GLB_BASE64 = 300 * 1024;
    /** Image embarquée d'un sonic-sprite (data:image/…;base64). */
    public const MAX_SPRITE_IMAGE = 64 * 1024;
    public const MAX_HF_TEXT = 8 * 1024;
    public const MAX_SOUND_BANK = 64 * 1024;
    public const MAX_AUDIO_PATTERN = 16 * 1024;
    public const MAX_AUDIO_SAMPLES = 16 * 1024;
    public const MAX_AUDIO_PARAMS = 4 * 1024;
    /** Texte brut d'un nœud (`textContent`, rendu sans parsing HTML par Concorde) et descripteur d'une sonic-timeline. */
    public const MAX_TEXT_CONTENT = 4 * 1024;
    public const MAX_TIMELINE_DESCRIPTOR = 16 * 1024;
    private const NODE_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    /** Accès sensibles déclarés par le document (`capabilities`) et composants qui les exigent. */
    public const CAPABILITIES = ['camera', 'microphone', 'midi', 'screen', 'bluetooth', 'serial'];
    private const CAPABILITY_TAGS = ['sonic-camera' => 'camera', 'sonic-mic' => 'microphone', 'sonic-midi' => 'midi', 'sonic-screen' => 'screen', 'sonic-bluetooth' => 'bluetooth', 'sonic-serial' => 'serial'];
    /** Origines de modèles ML (`capabilities: [{"ml": ["https://hote/chemin/"]}]`, addon `vision`). */
    public const MAX_ML_ORIGINS = 8;

    /** @var array<string, int> balises rencontrées pendant la validation courante */
    private array $seenTags = [];

    private const FORBIDDEN_NODE_KEYS = ['markup', 'innerHTML', 'prefix', 'suffix', 'js', 'css'];
    private const FORBIDDEN_DESCRIPTOR_KEYS = ['js', 'css'];

    private const SHADER_SOURCE_ATTRS = [
        'image', 'buffer-a', 'buffer-b', 'buffer-c', 'buffer-d', 'common', 'shader',
        'post-image', 'post-buffer-a', 'post-buffer-b', 'post-buffer-c', 'post-buffer-d', 'post-common',
        'reducer', 'initial', 'keymap', 'palette',
    ];

    private const JSON_ATTRS = ['initial', 'keymap', 'palette', 'payload', 'repeat', 'options', 'bank', 'pattern', 'samples', 'params', 'notes-map', 'choke', 'descriptor'];

    /**
     * Clés refusées dans le descripteur d'une sonic-timeline. `prefix` / `suffix` n'y figurent pas :
     * ce sont des champs légitimes des nœuds `text` (compteur), contrairement aux nœuds SDUI.
     */
    private const TIMELINE_FORBIDDEN_KEYS = ['js', 'css', 'innerHTML', 'markup'];
    /**
     * Liaisons de données Concorde (`data-bind` + attributs `::prop`) : `::prop` écrit la
     * PROPRIÉTÉ DOM `prop` (tirets → camelCase, `x-html` → `xHTML`) avec la valeur où `$chemin`
     * est remplacé par la donnée. Propriétés qui insèrent du HTML ou du code : interdites.
     */
    private const BINDING_FORBIDDEN_PROP = '/^(.*-html|on.*|srcdoc|formaction|action|style|attributes)$/i';
    /** Propriétés d'URL : la valeur doit commencer par une URL https littérale (ou #, /). */
    private const BINDING_URL_PROPS = ['href', 'src', 'poster', 'action', 'formaction', 'data', 'srcset'];
    private const BINDING_PROP_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';
    private const MAX_BINDING = 1024;

    /** Mémo du format son pour l'agent (addon `sound` de @supersoniks/creative-stack). */
    private const LIST_RULE = 'LISTES (voie par défaut, SDUI + Concorde ; A2UI seulement pour une interface qui dialogue avec un agent) : '
        .'{"tagName":"sonic-list","attributes":{"dataProvider":"liste"},"nodes":[{"tagName":"template","nodes":[ …gabarit d’UNE ligne… ]}]}. '
        .'Le DP de la liste doit ÊTRE le tableau (pas de chemin pointé "etat.items", pas de key) : une data.transforms (lit les data.sources) ou, pour dériver d’un store, '
        .'{"tagName":"sonic-jsonata","attributes":{"inputProvider":"etat","outputProvider":"liste","formDataProvider":"filtres","expression":"$input.all[$contains(nom, $q)]"}} (champs du formulaire = $q…, recalcul à chaque saisie). '
        .'Plusieurs <template data-value="nom"> + templateKey="type" choisissent le gabarit selon item.type ; <template data-value="no-item"> = liste vide. '
        .'DANS LA LIGNE : sonic-value key="champ" (texte) et sonic-if key="champ" lisent la ligne sans dataProvider ; sur une balise HTML, "data-bind":"" + "::prop":"gabarit" écrit la propriété prop avec $champ remplacé : '
        .'"::text-content":"$ville ($dep)", "::href":"https://www.google.com/maps/search/?api=1&query=$adresse" (URL https LITTÉRALE en tête), "::src", "::title", "::class":"ligne $etat", "::aria-label". '
        .'INTERDIT : ::inner-html et tout ::…-html, ::on…, ::style, valeurs commençant par "|" ou contenant "js|". '
        .'CLIC SUR UNE LIGNE : {"tagName":"sonic-action","attributes":{"target":"etat","type":"select","data-bind":"","::payload":"$id"}, "nodes":[…]} envoie au réducteur $action.payload = la valeur id de la ligne. '
        .'Filtrer / trier / paginer : dans la transform ou le réducteur qui publie le tableau (la liste ne fait que l’afficher). '
        .'Un filtre JSONata à 1 seul résultat renvoie un OBJET, pas un tableau : enveloppez-le avec $append([], …). ';
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
        .'RÉGLAGES CAMÉRA (zoom, torche, mise au point, exposition, balance des blancs, image ; sans redémarrer le flux) : 1) LIRE camCapabilities ({status: unavailable|ready, controls:{clé:{kind: range|enum|boolean, label, unit, min, max, step, values, value}}, list:[…mêmes descripteurs en tableau], unsupported:[…]}) : n’existe qu’une fois la caméra ouverte, ne propose que les clés de controls (sonic-if dataProvider="camCapabilities" key="status" equals="ready" pour n’afficher les commandes qu’alors) ; '
        .'2) DEMANDER par adjust="dp.adj" (objet, seules les clés présentes changent, null oublie une clé) : {zoom: 2, torch: true, focusMode: "manual", focusDistance: 0.4} ; les modes passent avant les valeurs (focusMode avant focusDistance), revenir en auto = remettre le mode "continuous" ; '
        .'3) LIRE camSettings {desired, applied, pending, errors:{clé: message}} : afficher applied, jamais desired (le navigateur arrondit ou refuse). ptz (attribut booléen) ajoute pan/tilt/zoom motorisés et une permission supplémentaire : seulement si nécessaire. Surtout Chrome / Android : Firefox et Safari exposent peu ou rien (pas de torche sur iPhone), une webcam d’ordinateur n’offre souvent que luminosité, contraste, saturation, netteté ; toujours se fonder sur controls. Une seule sonic-camera par appareil. '
        .'SONDE DE LUMINOSITÉ (lire un signal lumineux : lampe, torche d’un autre téléphone, écran qui clignote) : sonic-luma id="sonde" source="#cam" (measure mean | peak pour une lampe lointaine, region "x,y,w,h" en 0..1) ; état sondeState {status, level, peak, peakX, peakY, lo, hi, signal, on, edges, last, events, fps, clock} ; target="store" envoie une action par front {type: "edge", payload: {n, on, t, dur, level, sinceMark, markGap, fps, clock}} (t et dur en ms ; dur = durée de l’état qui se termine : celle de l’éclat quand on = false, celle du silence quand on = true) : le réducteur décode (morse, comptage d’éclats) ; mark-from="dp.adj.torch" mesure le délai entre l’ordre donné à la torche et la lumière vue (sinceMark). detect="jump" (sauts brusques au lieu d’un seuil) dès qu’un éclat dure plus de quelques dixièmes de seconde : l’exposition automatique assombrit l’image pendant l’éclat et trompe un seuil. '
        .'MORSE PAR LA LUMIÈRE (chat entre deux téléphones, torche vers caméra) : sonic-camera id="cam" facing="environment" adjust="lampe" + sonic-luma id="sonde" source="#cam" detect="jump" max-on-ms="4000" + sonic-morse id="morse" unit="300" drive="lampe.torch" text-from="chat.outgoing" send-from="chat.txN" source="sondeState" target="chat" ; le réducteur écrit outgoing et incrémente txN pour émettre, reçoit {type: "morse:sent", payload: {text}} et {type: "morse:message", payload: {text, unit}} ; état morseState {status idle|sending|receiving, tx {text, done, rest, chars, total, percent}, rx {text, signs}} ; progression de l’envoi : sonic-value sur tx.done (émis) et tx.rest (restant), barre = div dataProvider="morseState" > sonic-progress data-bind="" ::value="$tx.percent" max="100" ; chaque message commence par un signal de début (start-units, 5 par défaut) exigé en réception, et un message de moins de min-chars caractères (2) est écarté : mêmes unit et start-units sur les deux appareils ; une torche de téléphone ne tient guère d’éclat sous 250 ms. Un nom d’attribut ne commence jamais par « on ». '
        .'Photo : incrémenter control.snapshot dans le reducer ; l’afficher avec un sonic-shader dataProvider="gallery" (texture(iChannel0, uv)). '
        .'MICRO : sonic-mic id="mic" (monitor pour l’entendre, sinon muet) control="dp.mic" ({active, monitor, gain}) ; état micState {status, error, rms, db} ; sonic-audio-analyser source="#mic" (pitch pour la hauteur, onsetCount pour les attaques). '
        .'VIDÉO : sonic-video id="clip" src="https://…webm|mp4" (https ou relative) loop muted autoplay hidden-preview preload="blob" audio-out="master" (son → moteur audio, analysable par sonic-audio-analyser source="#clip") control="dp.player" ({playing, seek: {t, n}, rate, volume, muted, loopStart, loopEnd}) ; état clipState {status: loading|ready|playing|paused|ended|needs-gesture|error, timeS, durationS, progress}. Shader : channel0="#clip". Autoplay avec son refusé par le navigateur → démarre muette (needs-gesture) : prévoir sonic-media-start for="clip". '
        .'ÉCRAN : sonic-screen id="screen" (hidden-preview, audio pour le son du partage, surface monitor|window|browser) démarré UNIQUEMENT par sonic-media-start for="screen" (clic direct exigé par le navigateur) ; état screenState {status, error ("partage arrêté" si l’utilisateur l’arrête), surface, width, height, audio} ; shader channel0="#screen" ; export video-source="#screen". '
        .'IMAGE FIXE : sonic-still id="photo" src="https://…|/chemin" (ou in-data-provider="dp.fichier" portant {url}) drop max-size="2048" ; se lit comme une caméra : shader channel0="#photo", trackers for="#photo" ; état photoState {status: idle|loading|ready|error, error, width, height}. '
        .'OUVRIR UN FICHIER : sonic-file-open accept="image/*|audio/*|.csv" ref-provider="dp.fichier" drop label="…" (choix par l’utilisateur, jamais automatique) ; publie {url blob:, mime, width, height} (à brancher sur sonic-still in-data-provider ou sonic-tape source="dp.fichier") ; état {name, size, kind, text (CSV/JSON), error}. '
        .'BANDE / PLATINE : sonic-tape id="bande" source="url|dp.prise" loop control="dp.bande" ({rate: -4..4 (négatif = à l’envers, 0 = arrêt), position: 0..1 (la bande rejoint la cible), play, hold}) ; rate-from="orientationState.rg" rate-mode="omega" (rotation du téléphone comme un plateau) ou rate-mode="accel" avec orientationState.ax (secousse) ; état bandeState {status, duration, position, rate, reversed}. '
        .'GRAPHIQUES : sonic-plot id="ventes" data-provider="dp.lignes" (tableau de lignes, ou {rows}) type="line|area|bar|barh|scatter|pie|donut|histogram|heatmap|sparkline" x="champ" y="champ1,champ2" (plusieurs champs = plusieurs séries) series="champ" (une série par valeur) value="champ" (pie, donut, heatmap) aggregate="sum|mean|count|min|max" (regroupe les lignes de même x) stack curve="monotone|step" heading="titre" (pas title) format-y="compact|percent|integer|currency:EUR|date" (formats nommés uniquement, jamais de fonction) height="280" ; spec="{…}" pour rules [{value, label}], colors [primary|info|success|warning|danger|#hex], yMin, yMax ; couleurs et police viennent du thème (ne rien styler). Réglages en direct : spec-data-provider="dp.graphe" ({type, x, y, series, heading, …}) l’emporte clé par clé sur les attributs. Survol et clic publiés dans <id>State {hover, selected: {series, label, value, row}, count, status: idle|ready|error, error} : brancher selected sur un store pour filtrer un tableau. Dates ISO (aaaa-mm-jj) reconnues sur l’axe x. '
        .'FORMES VECTORIELLES : sonic-vector-op op="union|intersect|difference|xor|offset|simplify|round|fit|clip" a-d="M0 0 H10 V10 Z" b-d="…" (chemins SVG en attribut) ou a="dp.formeA" b="dp.formeB" (chemin DP portant une chaîne ou {d}) ; delta (offset, négatif = réduit), tolerance (simplify, fit), radius (round), view="dp.vue" ({x,y,w,h}, pour clip) ; réglages en direct par params="dp.reglages" ou control="dp.op" ({op, delta, tolerance, radius, segments} ; hausse de n = recalcul) ; résultat dans out-data-provider="dp.res" {d, polygons, bbox:{x,y,w,h}, area, status, error} : d se lit avec un chemin SVG. Les booléens gèrent les trous. sonic-vector-library control="dp.biblio" out-data-provider="dp.etat" garde des formes {id, name, d, tags} (500 max) ; commandes à compteur n (n doit augmenter) : {n, add:{name, d, tags}}, {n, remove:id}, {n, rename:{id, name}}, {n, clear:true}, {n, load:[…]} ; filter="tag:texte" ; état {shapes:[{id, name, d, tags, bbox, area}], count, status, error}. '
        .'IMAGE VERS VECTEURS : sonic-vectorize source="#img" (#id d’une source d’images : sonic-still, sonic-camera, sonic-video, mais aussi sonic-paint, sonic-shader, sonic-2d, sonic-3d) auto (vectorise chaque nouvelle image, au plus toutes les interval="500" ms : un fichier déposé est revectorisé) ou control="dp.vec" ({n, …réglages} : une hausse de n relit l’image et lance) ; réglages colors="2..32" (défaut 8) palette="#c2410c,#0f766e,#1e293b" (palette imposée : chaque pixel prend la plus proche, colors ignoré ; aussi clé DP palette en liste, chaîne ou état de sonic-palette pour vectoriser sur la palette d’harmonie) tolerance="1" (px, bas = fidèle) min-area denoise="0..3" (retire aussi les liserés d’anticrénelage ; 0 garde les traits fins) curves (courbes lisses, coins vifs gardés) flat (sans empilement) max-size="512" (côté analysé, 16..1400) hidden-preview, aussi EN DIRECT par DP : les clés {colors, palette, tolerance, minArea, denoise, curves, flat, maxSize} du store control (un reducer qui change une clé suffit, sans toucher n) ou de params="dp.reglages" (prioritaire) recalculent sur l’image déjà lue ; état dans out-data-provider="dp.vecState" {status: idle|working|ready|error, error, width, height, sourceWidth, sourceHeight, layers, paths[{color, area, share, d}], frame (où la source apparaissait à l’écran), palette[], svg (document SVG autonome), file (SVG en blob: : sonic-media-download source="dp.vecState.file"), bytes, ms, runs, truncated, settings (réglages appliqués, à afficher)}. Pour rogner ou fusionner les formes obtenues, prendre les chemins des couches avec sonic-vector-op ; pour les retravailler à la main, les importer dans un sonic-sketch (import-from). Une image d’un autre site doit autoriser CORS. '
.'COMPTER DES OBJETS (vis, billes, perles, pièces, graines…) : sonic-blobs source="#photo" (#id d’une source d’images : sonic-still, sonic-camera, sonic-video) auto (compte chaque nouvelle image, au plus toutes les interval="500" ms : en continu sur une caméra, à chaque photo sur un sonic-still) ou control="dp.compter" ({n} : une hausse de n lance) out-data-provider="dp.compte" ; état {status: idle|working|ready|error, error, count (objets trouvés, amas séparés compris), estimate (somme des k : k = aire / aire d’un objet type, rattrape un amas non séparé), clusters, median, reference, polarity, width, height, ms, blobs[{id, cx, cy, nx, ny, nw, nh (0..1), area, k, split, border}], overlay {url} (SVG des marqueurs, lisible par sonic-still in-data-provider="dp.compte.overlay")}. Méthode : éclairage aplati (dégradés et ombres douces supprimés), seuil d’Otsu, polarité auto (objets = classe minoritaire), nettoyage, séparation des objets qui se touchent. Réglages : polarity="auto|dark|light", bias (−3..3 : négatif garde les objets pâles), min-area-rel="0.3" (taches plus petites que 30 % d’un objet type ignorées), open (0..4, nettoyage), no-split, drop-border (objets coupés par le bord ignorés), max-size="640" ; en direct par params="dp.reglages" ({polarity, bias, flatten, open, minAreaRel, split, dropBorder} : recalcul sans relire l’image) ; ref-from="dp.ref" ({x, y} 0..1 touché sur un objet type : sa taille sert de référence). AFFICHAGE : un conteneur position:relative de hauteur fixe ; dedans sonic-still (ou sonic-camera) puis sonic-blobs, tous deux style="position:absolute;inset:0;width:100%;height:100%" et le MÊME fit (contain) : l’aperçu de sonic-blobs dessine les cercles numérotés exactement sur l’image et ne capte aucun clic. Photo de caméra : sonic-camera snapshot-provider="shot.photo" → sonic-still in-data-provider="shot.photo" → sonic-blobs source="#photo" auto. Prévoir une correction manuelle (−/+) ajoutée à count, et dire à l’utilisateur : fond uni contrasté, vue de dessus, objets un peu écartés. '
        .'LIRE UN TEXTE (OCR) : sonic-ocr source="#scan" (#id d’une source d’images : sonic-still, sonic-camera, sonic-video) lang="eng|fra|deu|spa|ita|por|nld|pol|ces|swe|dan|nor|fin|tur|ron|hun|ell|rus" (plusieurs avec +, 3 au plus : fra+eng) auto (lit chaque nouvelle image, au plus toutes les interval="1000" ms) ou control="dp.lire" ({n, …réglages} : une hausse de n lance) max-size="2000" min-confidence="0..100" ; réglages aussi en direct par les clés {lang, minConfidence, maxSize} de control ou de params="dp.reglages" (minConfidence refiltre sans relire, lang recharge le moteur) ; état dans out-data-provider="dp.ocr" {status: idle|loading|working|ready|error, error, step, progress 0..1, text, confidence, lines[{text, confidence, x, y, w, h, words[{text, confidence, x, y, w, h}]}], words, width, height, lang, ms, runs, settings}, positions en pixels de l’image d’origine (de quoi surligner). Lecture locale ; le moteur (10 à 20 Mo) se télécharge au premier usage (status loading) : prévenir l’utilisateur et lancer par un bouton plutôt qu’avec auto sur mobile. Pour imprimé net et droit ; pas d’écriture manuscrite. '
        .'MARKDOWN (doc, notice, prompt à partager, réponse d’un agent) : sonic-markdown text="# Titre\n\nTexte **gras**…" (ou src="https://…/doc.md", ou source="dp.texte" : chaîne ou {text, done}) features="gfm highlight math copy vars toc anchors" (défaut gfm copy anchors ; highlight = coloration des blocs de code, math = $…$ et $$…$$) heading-offset="1" (# devient h2) ; HTML brut affiché comme texte (ne pas en écrire), liens https/mailto/#. Préférer un sonic-markdown à des dizaines de balises p/ul/strong pour du texte riche. Prompt à compléter : {{nom}} ou {{nom|défaut}} + features vars + vars="dp.valeurs" ({nom: valeur}, écrit par un formulaire) ; control="dp.mdCtl" {n, action: copy|copy-rendered|scroll-to|refresh, id} (une hausse de n lance ; copy = Markdown aux variables remplies). Flux : streaming + source qui reçoit le texte cumulé, puis done:true. État out-data-provider="dp.md" {status, title, frontmatter, toc[{level, text, id}], code[{lang, text}], links, vars[{name, default, value, filled}], missingVars (sans valeur ni défaut : griser le bouton copier), resolved, stats{words, readingMinutes}}. '
        .'PEINTURE : sonic-paint id="toile" width="1024" height="768" (px, 4096 de côté au plus) background="#ffffff|transparent" color="#e11d48" size="24" hardness="0.7" (0 flou, 1 net) opacity spacing smoothing="0.5" (0..0.95, stabilise le trait) tool="paint|erase|pick" (pick = pipette : la couleur vue sous le point, calques et sources d’images compris, devient celle du pinceau et est écrite dans brush-from.color ; Alt + clic aussi) pressure (stylet) readonly hidden-preview undo-levels="20" ; réglages en direct par brush-from="dp.pinceau" ({color, size, hardness, opacity, tool…} : une palette de boutons et de sliders écrit dans ce store) ; control="dp.peintureCtl" ({undo: n, redo: n, clear: n, save: n, stamp: n} compteurs : la commande part quand n augmente ; stamp fond dans l’image les sources de stamp-from="#vecteurs" telles qu’elles apparaissent à l’écran : aplatir un calque sonic-sketch, capturer un shader ou une scène) ; in-data-provider="dp.photo" (image de départ, par exemple de sonic-file-open) ; état dans out-data-provider="dp.peintureState" {status, error, width, height, strokes, drawing, canUndo, canRedo, tool, color, picked, size, last: {url blob:, mime, width, height, size} (après save : brancher sonic-media-download source="peintureState.last")}. La toile est une source d’images lue à chaque trait : sonic-shader channel0="#toile" (peindre pour déformer ou éclairer une image) ; hidden-preview si le shader est le seul affichage. L’opacité s’applique à chaque empreinte (un trait lent s’épaissit). Pointe personnalisée : tip="M0 0L10 0L5 9Z" (chemin SVG, net à toute taille) ou tip-from="dp.forme" (chaîne d, {d} ou forme de sonic-vector-library : choisir une forme change le pinceau), angle="0" angle-follow (la pointe suit le geste) angle-jitter="30" size-jitter="0.4" scatter="0.5" roundness="1" (0,05 pinceau plat) ; variations reproductibles. '
        .'DESSIN VECTORIEL À ZOOM INFINI : sonic-sketch id="dessin" width="900" height="600" background="#ffffff|transparent" ; les traits restent des données (points, pression, pinceau) redessinés nets à tout zoom (de 0,0001 à 10 millions de px par unité), export SVG. Navigation : molette = zoom sous le curseur, Espace + glisser / bouton du milieu / tool="hand" = déplacer, deux doigts = déplacer, zoomer et tourner, R / Maj+R = tourner de ±15°. Pinceau comme sonic-paint : color size (px d’écran ; size-space="world" pour des unités du monde) hardness="1" opacity spacing smoothing pressure, pointes tip / tip-from="dp.forme" angle angle-follow angle-jitter size-jitter scatter roundness ; tool="paint|erase|pick|select|select-color|hand" (select = clic sur une forme : la région d’aplat sous le point, Maj ajoute / retire ; select-color = toutes les régions de cette couleur, tolerance="0..100" (écart OKLab ×100, 0 = exacte, 10 à 20 pour les teintes de fusion) contiguous (seulement celles qui se touchent) ; avec un outil de sélection actif, une couleur écrite dans brush-from.color (palette, nuancier) RECOLORE la sélection (vraie couleur, ressoudée aux voisines de même couleur, annulable) ; Suppr supprime, Échap vide ; control {selectAll, deselect, deleteSelection, recolor (+ color)} ; état selection {count, colors, bbox, area} : prévoir un bouton sélection et un bouton « par couleur » à côté du pinceau, et désactiver Supprimer quand selection.count = 0 ; erase = vraie gomme : trou exact dans les aplats, masque sur les traits non aplatis ; annulable ; pick = pipette, Alt + clic aussi : la couleur vue, même née d’une fusion et absente de la palette, devient celle du pinceau et est écrite dans brush-from.color — prévoir un bouton pipette qui écrit {tool:"pick"} dans le store du pinceau, l’outil revient à paint au lever) ; brush-from="dp.pinceau" ; view-from="dp.vue" ({x, y, zoom, rotation en radians}) ; control="dp.dessinCtl" ({undo, redo, clear, save (SVG), savePng, fit, reset, zoomIn, zoomOut, rotateLeft, rotateRight, straighten} compteurs) ; blend="normal|multiply|screen|overlay|darken|lighten|color-dodge|color-burn|hard-light|soft-light|difference|exclusion|hue|saturation|color|luminosity" (mode de fusion des nouveaux traits, aussi en direct dans brush-from {blend}) ; simplify="0.35" (px d’écran : en fin de geste, points confondus joints puis simplification, pression comprise ; 0 = aucune) ; flatten (défaut vrai : à chaque lever, un trait plein — net, sans pointe ni variation ; opacité et mode permis — est aplati dans une carte de régions disjointes de vraies couleurs : couleurs de fusion calculées, parties recouvertes retirées, morceaux de même couleur unis ; flatten="false" garde chaque trait en empreintes) ; état dans out-data-provider="dp.dessinState" {status, error, zoom, x, y, rotation, strokes, points, lastStroke {before, after}, regions, colors, flats, masks, flattened, drawing, canUndo, canRedo, tool, color, picked, selection, imported, size, blend, bbox, last: {url blob:, mime, width, height, size} (brancher sonic-media-download source="dessinState.last")}. Source d’images pour sonic-shader channel0="#dessin" (et sonic-2d / sonic-3d). CALQUES PIXEL + VECTORIEL : sonic-paint navigable (pixels, dessous) et sonic-sketch background="transparent" (dessus) dans la même boîte (position:absolute;inset:0 dans un parent position:relative), avec le même view-sync="vue" (DP de vue partagée {x, y, zoom, rotation}, lu et écrit : vues alignées, 1 unité = 1 pixel, zoom borné par l’image) ; calque inactif en pointer-events:none (style lié au calque choisi) ; la pipette traverse les calques ; sonic-paint stamp-from="#idDuSketch" + control {stamp} aplatit le vectoriel dans les pixels. IMPORT DE FORMES (pixels, photo, shader → vectoriel) : sonic-vectorize source="#pixels" (ou "#photo", ou un sonic-shader générateur figé : motifs, bruit, fractales) hidden-preview palette="couleurs de la palette d’harmonie" out-data-provider="dp.vectoState", et sur le sonic-sketch import-from="dp.vectoState" import-auto (ou control {import} : bouton « Vectoriser » qui incrémente n du store control du vectoriseur, puis import auto) import-place="auto|screen|view" (screen : là où la source apparaissait à l’écran, aligné avec un calque pixel en view-sync ; view : ajusté dans la vue, pour un shader hidden-preview) import-skip="#ffffff" (couleurs ignorées, défaut background : le papier ne devient pas une forme) import-colors-from="dp.palette" (recolore : les couleurs de la source, par étendue, prennent tour à tour celles de la palette) ; les couches deviennent des aplats retravaillables (fusion, gomme, pipette), une étape d’annulation ; état imported {layers, regions, ms} | {error} ; moins de couleurs, tolerance plus haute, min-area plus grande = base plus simple. FORMES GÉNÉRATIVES (shader → vectoriel) : ressource base:shader/formes (get_resource : motif uParam0 0 cellules|1 courbes de niveau|2 rosace|3 entrelacs|4 taches, graine uParam1, densité uParam2 1..10, couleurs uParam3 2..6 ; aplats nets sur fond noir) dans un sonic-shader id="generateur" dataProvider="gen" (store {param0…param3}, sans texte) d’au moins 400 px de côté (aperçu réduit par transform:scale dans un cadre overflow:hidden), sonic-vectorize source="#generateur" curves tolerance="0.6" control="genVec" ({n, colors: param3+1} ; un bouton « Poser » incrémente n) out-data-provider="genState", et sur le sonic-sketch import-from="genState" import-auto import-place="view" import-skip="#000000" import-colors-from="<état de sonic-palette>" (les couleurs du motif prennent celles de l’harmonie). FINITION ET EXPORT PIXEL (post-traitement, le vectoriel reste la source) : sonic-sketch control {savePng: n, exportScale: 4, exportArea: "content"} (PNG redessiné net, 8192 px de côté au plus, dans dessinState.last) ; un sonic-jsonata recopie last dans {channel0: $input.last, param0…} d’un store fx lu par un sonic-shader dataProvider="fx" render-size="channel0" (rendu à la taille de l’export, pas de l’affichage) image = ressource base:shader/finition (uParam0 0 aucun|1 papier|2 trame|3 sérigraphie|4 objectif, uParam1 intensité, uParam2 échelle du grain ; même aspect à toute résolution) control="fxCtl" ({save: n} → PNG dans fxState.last, out-data-provider="fxState") puis sonic-media-download source="fxState.last" ; aperçu en direct : un second sonic-shader channel0="#dessin" posé sur la toile en pointer-events:none avec les mêmes param. sonic-shader render-size="2048x2048" fixe aussi la taille de rendu (source nette pour sonic-vectorize, quelle que soit sa taille affichée). NE JAMAIS donner le même out-data-provider à deux composants (le vectoriseur et le sketch ont chacun le leur). Ne JAMAIS donner à un composant l’id d’un store (data.stores ou sonic-store) ni la valeur id d’un élément de sonic-list (chaque élément rendu porte cet id dans le DOM) : source="#nom" ou channel0="#nom" trouverait le store ou l’élément de liste au lieu du composant. Limites : 20 000 traits, export SVG jusqu’à 100 000 empreintes. '
        .'PALETTE D’HARMONIE : sonic-palette base="#c2410c" rule="mono|analogous|complementary|split|triadic|tetradic|square" count="6" (1..12 ; au-delà des teintes de la règle, nuances plus claires / plus sombres ; 0 = nombre de teintes) spread="30" (° des teintes voisines) step="0.14" (écart de clarté des nuances) ; couleurs calculées en OKLCH (clarté perçue égale, gamut sRGB) : à préférer à une liste de couleurs inventée. Pastilles cliquables (clavier compris) : le choix part vers un store comme sonic-action, target="pinceau" action="set" key="color" payload=\'{"tool":"paint"}\' (réducteur $merge([$state, $action.payload])) ; selected="0" (rang choisi au départ, envoyé au store) ; follow (défaut vrai : quand la palette change, la couleur au même rang est renvoyée) ; direction="row|column|grid" columns shape="circle|square" swatch-size="26" ; headless (calcul seul) ; params="dp.palReglages" {base, rule, count, spread, step, selected} en direct ; control="dp.palCtl" {random, rotate} compteurs (teinte au hasard, +30°) ; état out-data-provider="dp.palette" {colors[], items[{hex, hue, l, c, role}], selected, selectedIndex}. '
        .'PRÉDICTION : sonic-train data-provider="dp.fichier.text" (texte CSV d’un sonic-file-open, ou tableau de lignes [{col: valeur}]) target="prix" (colonne à prédire, défaut : la dernière) task="auto|regression|classification" (auto : classification si la cible n’est pas numérique ou n’a que quelques entiers) features="col1,col2" kind="mlp|linear" hidden-units="16" epochs="150" learning-rate="0.02" test-split="0.2" seed="1" auto-train control="dp.entrCtl" ({train: n, stop: n} compteurs) model-data-provider="dp.modele" out-data-provider="dp.entr" {status: empty|ready|training|done|error, error, rows, columns, target, task, epoch, progress 0..1, curve: [{epoch, train, test}] (sonic-plot type="line" data-provider="entr.curve" x="epoch" y="train,test"), metrics (régression : r2, rmse, mae, baselineRmse ; classification : accuracy, baselineAccuracy, macroF1), importance: [{name, importance}] (barh), confusion: [{reel, predit, n}] (heatmap x="predit" y="reel" value="n"), ignored: [{name, reason}], model}. Colonnes typées automatiquement (nombres, catégories ≤ 30 valeurs ; identifiants et texte libre ignorés). Tout reste dans le navigateur. sonic-predict model-from="dp.modele" input-from="dp.saisie" (objet {colonne: valeur} d’un formulaire ou tableau de lignes) out-data-provider="dp.pred" {status, task, target, inputs: [{name, kind, values}] (pour bâtir le formulaire), metrics, prediction: {value} | {label, confidence, probabilities}, predictions}. Limites : 50 000 lignes, petit réseau (pas d’apprentissage profond), test tiré au hasard (séries temporelles : mesure optimiste). '
        .'DÉFILEMENT : sonic-scroll sections="[data-etape]" (sélecteur CSS des sections à suivre, 100 au plus) smooth="0.1" (inertie des valeurs lissées, 1 sans retard) target="#id" (conteneur ; défaut : le plus proche ancêtre qui défile, la visionneuse défile dans son propre conteneur) control="dp.defCtl" ({to: "#id"|px, n: compteur} fait défiler) out-data-provider="dp.defil" {y, max, progress 0..1, smoothY, smoothProgress, velocity, direction -1|0|1, atTop, atEnd, active {index, id, progress, pin}, sections: [{id, index, progress, pin, visible, inView}]}. Ne détourne pas le défilement. Animation au défilement : sonic-timeline time-source="defil.smoothProgress" (0..1 sur toute la page). Scène épinglée : une section haute (style height:300vh) contenant un bloc style position:sticky;top:0;height:100vh ; sa valeur pin (0..1) est l’avancement pendant l’épinglage (active.pin pour la section active). '
        .'CURSEUR : sonic-cursor follower="none|dot|ring" size="36" color="#fff" blend="difference" (inverse les couleurs sous le suiveur) smooth="0.2" (inertie, 1 sans retard) hover-scale="2.5" hide-native (masque le curseur natif, souris seulement) magnetic="[data-magnetic]" (sélecteur des éléments attirés, 100 au plus) magnetic-strength="0.35" magnetic-radius="60" magnetic-max="14" out-data-provider="dp.pointeur" {x, y, nx, ny, smoothX, smoothY, vx, vy, speed, down, inside, touch, hover {name, id}|null}. Un élément survolé se nomme avec l’attribut data-cursor="voir" (hover.name ; le suiveur grossit). L’élément attiré reçoit translate et les variables CSS --mx/--my en px (parallaxe d’un contenu interne). Jamais de suiveur ni d’attraction au tactile. '
        .'TEXTE CINÉTIQUE : sonic-split-text text="Le texte" (ou le contenu de l’élément) split="chars|words|lines" effect="rise|fade|blur|wipe|scale|slide" (wipe = balayage clip-path, rise = montée depuis un masque) spread="0.7" (part de la course consacrée au décalage entre unités) ease="linear|out|inout" distance="24" (px, slide) ; avancement 0..1 : progress="0.5" ou progress-source="dp.defil.smoothProgress" (défilement) ou play duration="1.2" (lecture auto à l’entrée dans l’écran) ; out-data-provider="dp.t" {progress, units, done}. Mettre la taille de police dans style. Texte complet lu par les lecteurs d’écran. '
        .'DÉTOURAGE D’OBJET (Segment Anything 2, local) : sonic-segment-anything id="objet" for="photo" (sonic-still, img, canvas, sonic-camera, sonic-video) click-on="photo" (clic = nouvelle sélection, Maj+clic = ajouter, Alt+clic ou clic droit = exclure, glisser = boîte) ou points-from="dp.pts" [{x, y, label 1|0}] / box-from="dp.boite" {x0, y0, x1, y1} (0..1) ou points="0.5,0.4,1" ; model="sam2-tiny|sam2-small|sam2-base-plus" (du plus rapide au plus précis ; calcul sur le processeur, 61, 69 et 108 Mo ; variantes « -gpu » sur carte graphique, expérimentales car certains GPU donnent un masque faux sans erreur : 89, 103 et 175 Mo) ou model-from="dp.q.model" pour un choix de qualité (l’ancien modèle est libéré, l’image réencodée) ; consentement : consent-provider="dp.q.ok" valeur "accepted" ou l’alias du modèle accepté (un accord par taille), afficher l’invite tant que phase = awaiting-consent ; refresh="manual|frames" fps="2" (frames : suit une caméra) ; mode="alpha|gray" invert ; refine="edges" (défaut : bord recalé sur les contours de la photo, environ 0,2 s par clic) ou "none" ; pour un contour net, garder feather et grow bas (1 et 0) sur sonic-mask-replace ; out-data-provider="dp.objet" {phase, progress, encoded, busy, points, box, present, score, coverage, cx, cy, x0, y0, x1, y1, error}. Le masque est une source d’images : sonic-mask-replace source="photo" mask="objet" effect="blur|cutout|fill", sonic-shader channel1="#objet". Un seul par document. Image encodée une fois (environ une seconde), puis chaque clic donne le masque en quelques dizaines de ms. '
        .'EXPORT : sonic-media-recorder id="export" video-source="#viz" (shader, caméra ou vidéo) audio-source="master|#id|none" control="dp.export" ({recording: true|false}) max-s="30" ; état exportState {status: waiting-source|ready|recording|error|unsupported, elapsedS, last: {url, mime, durS, size, width, height}}. '
        .'TÉLÉCHARGER : sonic-media-download source="exportState.last" filename="ma-creation" (contenu en slot, caché tant qu’il n’y a rien ; blob: uniquement : prises, photos, exports). '
        .'Compteurs (snapshot, seek.n) : la première valeur sert de référence, chaque hausse déclenche. '
        .'SUIVI DES MAINS (sans calibration, modèle local) : sonic-hand-tracker id="hand" for="cam" (source : sonic-camera ou sonic-video ; max-hands 1|2, smooth 0..1 lissage, hold-ms, mirror auto|true|false, gestures="false" pour les points seuls, thread auto|worker|main) ; map="cents=right.x*3600; gain=left.present ? 1-left.y : 0" = clés dérivées sans latence (nombres, chemins, + - * / %, comparaisons, && || !, ?:, min max abs clamp mix pow sqrt floor round log2 sign) ; sortie dans le DP "hand" : left / right en repère écran (miroir appliqué) {present 0|1, x, y, z, tipX, tipY, pinch 0..1, openness 0..1, roll 0..1, gesture Closed_Fist|Open_Palm|Pointing_Up|Thumb_Up|Thumb_Down|Victory|ILoveYou, gestureScore}, count, hands.N.landmarks.I.x|y|z (21 points) et les clés de map ; statut handState {phase: idle|awaiting-consent|declined|downloading|loading|ready|error|unsupported, error{code, message}, backend, thread, download{loaded, total}, fps, latencyMs, consent{origin, url}}. Le modèle par défaut est un alias de confiance (rien à déclarer) ; un modèle d’un autre hôte se déclare dans capabilities : {"ml": ["https://hote/chemin/"]} (1 à 8) et attend le consentement (consent-provider="dp.v" valant accepted|declined, bouton dans une sonic-modal quand phase vaut awaiting-consent). Un seul sonic-hand-tracker par document ; inférence locale, aucune image n’est envoyée ; ne rien superposer à la vue caméra. AUTRES SUIVIS (mêmes attributs communs : for, model, smooth, hold-ms, map, consent-provider, thread ; un par document) : sonic-face-tracker id="face" for="cam" → DP face {present, x, y, size, yaw/pitch/roll -1..1, mouthOpen, smile, pucker, browUp, browDown, blinkLeft, blinkRight, gazeX, gazeY, blend.<52 blendshapes>} (landmarks="true" ajoute faces.N.landmarks, 478 points) ; sonic-pose-tracker id="body" → DP body {present, x, y, raiseLeft/raiseRight 0..1, span, lean, joints.<nom>.{x,y,z,v}} (33 points : nose, leftShoulder, rightWrist…, côtés écran ; modèles pose-landmarker, pose-landmarker-full, pose-landmarker-heavy) ; sonic-object-detector id="obj" (categories="person,cup", max-results) → DP obj {present, count, label, score, x, y, w, h, labels.<étiquette> = nombre, objects[]} ; sonic-segmenter id="seg" → silhouette lue COMME UNE CAMÉRA par sonic-shader channel1="#seg" (mode gray|alpha, invert) + DP seg {present, coverage, cx, cy, x0, y0, x1, y1}. GESTES APPRIS : sonic-example-classifier source="hand" control="learn" storage="mes-gestes" (sans modèle : kNN sur les exemples de l’utilisateur ; le store learn porte {label, record, clear, clearAll}, les trois derniers sont des compteurs à incrémenter par sonic-action ; features hand|pose|face) → DP {label (stable), raw, confidence, scores.<étiquette>, match.<étiquette> 0|1, counts, total, recording, samplesLeft}. EFFET ALIGNÉ SUR LE VISAGE (un tracker rend son résultat 30 à 150 ms après avoir lu l’image : l’effet traîne sur la vidéo) : sonic-frame-sync id="synced" for="cam" sync="face" out-data-provider="syncState" (for = la MÊME source que le tracker ; sync = DP du tracker, qui publie frameSeq, frameTime, capturedAt, latencyMs) garde les dernières images et montre celle que le tracker a réellement analysée ; le shader lit channel0="#synced" (jamais #cam) et les valeurs du tracker (map="param0=x; param1=y; param2=present; param3=size", dataProvider="face"). MODES : sync (défaut dès que sync est renseigné, image exacte ; sans résultat récent depuis stale-ms, retard = dernière latence puis direct), delay-ms="120" (retard fixe, source sans frameSeq), live (aucun retard). history = images gardées (défaut 12, au moins latence×fps+2), max-width borne la mémoire. MIROIR : mirror="true" sur la caméra ET sur le tracker, ne pas retourner dans le shader. État syncState {mode, matched, seq, delayMs, trackerSeq, filled} : matched doit valoir true en mode sync. Pas de smooth élevé sur le tracker (il ajoute un retard non compensé). Deux sonic-frame-sync au plus par document. AFFICHAGE : sonic-landmarks-overlay source="hand" background="cam" (kind auto|hands|face|pose|objects) dessine points, squelette, contours et boîtes dans un PANNEAU À CÔTÉ de la vue (jamais par-dessus) ; le visage n’y apparaît que si le tracker a landmarks="true".';

    /** Mémo des addons `physics` (planck.js) et `controller` (manettes). */
    private const PHYSICS_RULE = 'MONDE 2D (planck.js / Box2D) : <sonic-physics id="world" width="800" height="450" gravity="0 9.8" (m/s², "0 0" vue de dessus) bounds="walls|box (sans sol)|floor|none" store="jeu" bodies="jeu.bricks" input="jeu.input" control="jeu.ctl" drag> + enfants sonic-body / sonic-joint. Unités : px (y vers le bas), degrés, px/s. '
        .'CORPS : sonic-body name type="dynamic|static|kinematic" shape="circle (r)|box (w h)|polygon|edge|chain (points=\'x,y x,y\' relatifs au centre)" x y angle vx vy restitution friction density sensor bullet fixed-rotation tags="brick" clamp-x="60 740" fill stroke label. La même description en liste JSON dans le store (bodies="jeu.bricks") : le monde suit ajouts et retraits (retirer une brique = la filtrer dans le reducer). '
        .'JOINTS : sonic-joint type="revolute (motor °/s, lower/upper)|distance (frequency = ressort)|rope|weld|prismatic" a b at="x y" (b="ground" = décor). '
        .'PANTIN ARTICULÉ : sonic-ragdoll name="pantin" preset="humain" x="400" y="200" (racine = bassin ; le corps humain monte d’environ 160 px et descend d’environ 165 : prévoir y ≥ 200 et du vide autour) scale angle limit="120" (° de débattement des os sans min/max) thickness density friction restitution damping angular-damping self-collide nodes-out="pantinPose", en enfant de sonic-physics ; os ajoutés ou modifiés par des enfants sonic-bone name parent length angle at offset min max (ex. <sonic-bone name="tete" length="60" min="-30" max="30">). Chaque os est un corps nommé pantin.torse, pantin.tete, pantin.bras-gauche… (input, publish, drag, événements collide avec les tags ragdoll et le nom de l’os) ; les membres d’un même pantin ne se heurtent pas. La pose est publiée au format de sonic-skeleton dans nodes-out : .nodes {os: {x, y, rz}}, .bones {os: {x, y, x2, y2, angle, rz, length}}, .present : les sprites d’un sonic-2d (pivot sur l’articulation) suivent comme pour un sonic-skeleton. Il tombe, se tasse et s’endort au sol. '
        .'PILOTAGE input = {"paddle": {"vx": -650}, "ship": {"fx": 0, "fy": -20}, "ball": {"impulse": {"n": compteur, "x": 10, "y": -34}, "set": {"n": compteur, "x": 400, "y": 396}}} (vx/vy imposés à chaque pas, fx/fy forces, impulse/set déclenchés quand n augmente). control = {running, reset: compteur, gravity: [x, y]}. '
        .'ÉVÉNEMENTS vers le store : collide {a, b, tagsA, tagsB, impulse, x, y}, enter/leave {sensor, body, tags}, out {body, side} (balle perdue), tap {x, y, body}, rest {bodies: {nom: {x, y, angle}}} (UNE fois quand le dernier corps dynamique s’immobilise : fin de coup, tour suivant) ; mettre budget-ms="50" sur le store. État worldState {bodies: {nom: {x, y, angle, vx, vy}}, collisions}. Image du monde : sonic-shader channel0="#world" (lueur, déformations) ; hidden-preview si le shader seul s’affiche. '
        .'AFFICHER LES CORPS AILLEURS, SANS ADAPTATEUR : nodes-plane="screen" (2D, pour sonic-2d / sonic-stage) | "xz" (sol 3D) | "xy" (mur 3D) ajoute nodes {nom: {x, y, rz}} à worldState ; le rendu se branche directement : dataProvider="worldState" (sprites / éléments / nœuds glTF du même nom que les corps). nodes-scale (unités du rendu par px ; 1 pour screen, 0.01 en 3D), nodes-origin="x y", nodes-y (hauteur 3D), nodes-ease (lissage, défaut 1000/rate ms). Jamais de JSONata entre la physique et le rendu (30 publications/s). Voir la règle « 2d ». '
        .'MANETTES : sonic-controller id="pad" store="jeu" keymap=\'{"a":"launch","start":"pause","left":"left"}\' release (envoie aussi "left:up") analog-action="stick" (→ {type:"stick", payload:{pad, lx, ly, rx, ry, lt, rt}}) control="jeu.padCtl" ({rumble: {n: compteur, ms}}) ; état padState {status: idle|ready, count, pads}. Clavier tenu : sonic-keyboard keyup (actions "left" puis "left:up").';

    /** Mémo de l'addon `3d` : scène pilotée par les données (jeux, plateaux). */
    private const SCENE3D_RULE = 'SCÈNE 3D PILOTÉE PAR LES DONNÉES (jeux de plateau, objets à manipuler) : <sonic-3d dataProvider="jeu" target="jeu" pick pick-names="^(m_|hole_)" min-polar="0.6" max-polar="1.25" min-distance="9" max-distance="17" fit="false">. '
        .'Le DP « jeu » est celui d’un store dont le reducer publie : glb = modèle GLB en base64 (aucun réseau ; 300 Ko base64 max, 64 Ko par fichier : le mettre dans "files" et référencer "@file:plateau.b64" dans data.stores.jeu.initial ; un glTF JSON avec buffers data: ne marche pas, exporter en GLB) '
        .'(nodes peut aussi venir directement d’un sonic-physics nodes-plane="xz" via dataProvider="worldState", ou d’une sonic-timeline nodes-out) '
        .'et nodes = {nomDeNoeud: {x,y,z (position locale), rx,ry,rz (rad), scale (n ou [x,y,z]), visible, color "#rrggbb", emissive (0..1), emissiveColor, opacity, ease:{ms,curve:linear|in|out|inOut}, lift (hauteur d’un arc en y pendant ease.ms, ex. saut de bille)}, "$reset": true}. '
        .'nodes est un PATCH : un champ absent reste tel quel, un nœud absent de l’objet n’est pas remis à zéro (utiliser "$reset": true pour revenir à l’état du fichier, puis redonner l’état voulu) ; republier la même valeur ne fait rien ; 200 nœuds max par publication ; nom inconnu ignoré. Les noms sont ceux du GLB (le générateur doit les rendre uniques). '
        .'Les couleurs ne sont pas animées (immédiates) ; x/y/z/rotation/scale/emissive/opacity le sont avec ease. Sans ease les changements sont immédiats. '
        .'TAP : un tap (pointeur < 6 px et < 400 ms ; un glissé fait tourner la caméra, il n’envoie rien) sur un nœud visible envoie au store {type: pick-action-type (défaut "pick"), payload:{objectId: nom du nœud, x, y, z}} ; le reducer lit $action.payload.objectId. pick-names (regex sur le nom) ignore le décor ; pick-empty envoie payload {objectId:null} pour un tap dans le vide (désélection). '
        .'Orbite : min/max-distance, min/max-polar en degrés (limiter le polar évite de passer sous le plateau). '
        .'PIÈGES : (1) nodes ne s’applique qu’une fois le GLB chargé (réappliqué automatiquement après chaque chargement) ; (2) donner les positions finales des nœuds dans le reducer, pas des deltas ; (3) pas de $random / $now dans le reducer : c’est ease qui anime ; (4) sans GLB (glb absent) la scène montre un cube de démo. '
        .'Sortie (out-data-provider) : yaw pitch distance hitObjectId hitX/Y/Z moving ready nodesMissing (nombre de noms de nodes introuvables : à 0 en régime normal). '
        .'Personnages, décors, mondes animés sans glb : clé monde (voir la règle « monde »).';

    /** Mémo du format « monde » (creative-stack src/shared/monde) : mondes en primitives pour sonic-3d et sonic-2d. */
    private const MONDE_RULE = 'MONDES EN PRIMITIVES (sonic-3d ET sonic-2d, même JSON, mêmes nodes) : clé « monde » du DataProvider (ex. data.stores.jeu.initial.monde, objet JSON) ou attribut monde. Pour un jeu, des personnages animés, un décor détaillé, en 3D comme en 2D (vue de dessus, profil, face, iso) : préférer ce format à un glb. '
        .'Repère : mètres, y vers le haut, sol y=0, avant d’un modèle vers +z ; rot en DEGRÉS dans le monde, rx ry rz en RADIANS dans nodes. '
        .'Clés : palette {rôle: "#rrggbb"} ; models {nom: modèle} (64 max) ; place [placements] ; textures {rôle: texture} ; texture (défaut de toutes les pièces, "grain" = gouache) ; look doux|toon|brillant ; detail low|mid|high ; shadows ; outline (px, 2D) ; view2d {view: top|side|front|iso, unit: px par m, origin: [x, y]} ; ciel "#rrggbb" et brume 0..1 (3D). '
        .'Modèle : {preset, seed, params, parts, clips, clip}. PRÉRÉGLAGES : bipede (params espece humain|chat|renard|ours|lapin|dino|monstre|grenouille|robot, taille, corpulence, grosseTete 0.26..0.42, tete, oreilles, queue, cornes, cheveux, yeux, museau, peau ; clips idle marche course controle tir passe tacle chute saut joie plongeon ; pièces bassin torse tete brasL/R avantbrasL/R mainL/R cuisseL/R molletL/R piedL/R…), quadrupede (clips idle marche course assis saut), arbre (forme rond|sapin|palmier|boules), rocher, maison (toit pente|plat|pointu), nuage, ballon (clip roule). '
        .'Pièce : {name, parent, at (ARTICULATION dans le repère du parent), rot, off (centre de la forme depuis l’articulation : bras pendant off [0,-0.2,0]), scale, role ou color, tex, glow 0..1, mirror:"x" (→ nomL et nomR, enfants dédoublés aussi), repeat {n, at, rot} (→ nom0…), hidden} + UNE forme : box [w,h,d,arrondi] | sphere r ou [rx,ry,rz] | capsule [r,longueur] (axe y) | cylinder [r,h,rHaut] | cone [r,h] | torus [R,r,arc°] | lathe [[r,y],…] | extrude {pts:[[x,y],…], depth} | tube {pts:[[x,y,z],…], r, r2} | plane [w,d] (sol). 160 pièces max ; même name qu’une pièce du préréglage = la remplace. '
        .'Clip : {ms, loop (défaut true), then (clip suivant), tracks: {pièce: {rx, ry, rz (degrés ajoutés au repos), x, y, z (m), s (échelle) : listes de clés, sync, phase}}} ; une piste au nom d’une paire mirror (« bras ») anime les deux côtés en opposition (sync:true ensemble), « brasR » un seul côté. '
        .'MODÈLES GEN (procgen, recommandés pour de beaux personnages, animaux, bâtiments, arbres, objets) : {gen: "character"|"animal.dog"|"animal.horse"|"animal.duck"|"building"|"tree"|"bush"|"rock"|"plant"|"prop.lamp"|"vehicle"|"ball.soccer"|"seat.chair"…, params (paramètres procgen : character → archetype chibi|cartoon|stylized|heroic, height (m), top tee|shirt|sweater…, bottom shorts|trousers…, pattern plain, hat none|cap|helmet…, hair, skin, bag none, item none, animal cat|fox|dog|bear|rabbit|mouse|pig|owl|frog|dino|lion|tiger|panda|koala|raccoon|monkey|cow|sheep|deer|horse|elephant|duck = personnage animal ; animalSpec retouche ou invente l’espèce : ears {shape pointed|round|floppy|long|flap|tuft|none, size 0..3, spread 0..1}, horns none|small|antlers, snout {w,h,l} (l>1,2 trompe), nose true|false|"disc", beak, mane, markings none|mask|patches, eyeBulge, spikes, eyeDiscs, tail {shape curve|bushy|wag|stub|pom|long|curl|fan|thick|tuft|none, length, tip}, colors {fur [#…], muzzle, nose, innerEar, beak, mane, marks, tailTip} ; mood neutral|happy|sad|angry|surprised|sleepy), seed}. VARIANTES : un placement d’un modèle gen accepte params (fusionnés) et genSeed : foule variée, humeur ou espèce changée en jeu sans nouveau modèle. CLIP LIBRE d’un modèle gen : {action idle|walk|run|wave|point|cheer|talk|carry|sit|jump|think|bow|hammer|sweep|dig|kick|drink|eat|sleep, speed m/s, tracks} ; clé style du monde = style procgen (gouache|doux|bd|encre|aquarelle|papier|nuit…) ; traits 0..3 (importance des contours, 1 = style ; 0,4 léger). Os = pièces sans forme : racine bassin dos torse cou tete epauleL/R brasL/R avantBrasL/R mainL/R cuisseL/R molletL/R piedL/R (animaux : cou1 cou2 queue1..3, oiseaux aileL/R) ; les clips les tournent EN PLUS de l’animation procédurale. Clips : idle, marche, course (foulée selon la distance réellement parcourue : déplacer l’instance par nodes la fait marcher / courir), joie, saut, salut, montre, parle, assis, boit, mange, dort… ; gestes tir passe controle tacle chute plongeon. roles maillot short chaussette chaussure peau cheveux fourrure museau truffe oreille bec criniere marques boutQueue cornes toit mur feuillage (ou noms procgen cloth1, skin, roof). nodes.<inst>_<os>.emissive (0..1) + emissiveColor : éclat de l’acteur. Génération différée (quelques dixièmes de s par personnage) ; partager un modèle entre instances (couleurs par roles). Pas de dessin dans la projection 2D de « monde » : en 2D, utiliser monde2d (gen aussi). '
        .'Placement : {model, name (instance pilotable : nœud name, pièces name_pièce ; sans name = décor statique fusionné, très peu coûteux), at, rot, scale, roles {rôle: couleur}, clip, hidden} ; décor en masse : grid [nx,nz] + step [dx,dy,dz], scatter n + area [x0,z0,x1,z1], jitter, turn (degrés), size [min,max], seed. Budgets : 4000 copies, 400 instances, 40 000 pièces dessinées, 6 000 pièces animées. '
        .'PREFABS PARAMÉTRÉS (un modèle écrit, autant de déclinaisons que voulu : mobilier, objets configurables, décor varié) : models.<nom>.params = {longueur: {default: 1.6, min: 0.8, max: 4, step: 0.2, unit: "m"}, couverts: {default: 4, options: [2, 4, 6]}, teinte: {default: "#8b5a2b"}} (types déduits : nombre, choix, couleur, bool, texte ; matiere se déclare par type) ; toute valeur d’une pièce peut alors être une expression "= …" : box ["= longueur", 0.05, 0.8], at ["= longueur / 2 - 0.08", 0, 0.32], color "= teinte" (paramètres, + - * / % ^, comparaisons, a ? b : c, min max clamp mix abs floor round sqrt pow sin cos en degrés, rand(a, b), randint, pick(…), chance(p), noise(x, y) ; aucun code exécuté). SOUS-MODÈLES use: [{model, name, parent, at, rot, scale, repeat, params}] : déplié en une articulation <name> et ses pièces <name>_<pièce> ; avec repeat les copies <name>0… lisent i (rang) et n (total) ; 4 niveaux, pas de modèle gen dans use (le placer à part). EMPLACEMENTS slots: {abatJour: {accepte: "abat-jour", at: [0, "= hauteur", 0], default: "cone"}} réservés aux modèles de famille "abat-jour" (clé famille du modèle). MATIÈRES : matiere: "chene" sur une pièce (base : chene pin noyer hetre bois-blanc bois-noir acier laiton metal-noir lin coton laine marbre pierre blanc noir ; materiaux {nom: "#rrggbb" | {color, tex, glow}} en ajoute). PLACEMENT : params {longueur: 2.4, couverts: 6} et slots {abatJour: "boule" | {model, params} | null} ; dans grid / scatter, expressions tirées PAR COPIE : params {longueur: "= pick(1.2, 1.6, 2.4)", teinte: "= pick(\'#8b5a2b\', \'#5b3a1e\')"} (chaînes entre guillemets simples dans une expression). COÛT : chaque jeu de valeurs distinct = une variante construite une fois (32 par modèle, 512 par monde, la plus proche réutilisée au-delà) : donner step ou options aux paramètres tirés au hasard ; un paramètre de couleur lu seulement par color "= nom" ne crée aucune variante et se change en jeu par nodes.<instance>.roles {"~teinte": "#rrggbb"}. UNITÉ : clé unite "cm" | "mm" du monde pour écrire pièces, use, placements (at, step, area) et translations de clips dans cette unité (pratique pour du mobilier) ; préréglages, modèles gen, view2d et nodes restent en mètres. CATALOGUE : famille, tags, pose (sol|mur|plafond|dessus), degagement sur un modèle ; mondeInfo.prefabs {nom: {params, famille, tags, pose, slots, variants}} publie les fiches (de quoi bâtir un formulaire), instances[].prefab = nom écrit. '
        .'POSE (un objet sur un autre, sans calculer de hauteur) : placement on: "<instance>" ou "<instance>@<surface>" = posé sur une instance NOMMÉE (écrite avant ou après), at devient [x, z] depuis le centre de la surface dans le repère de l’appui (ramené dans son contour), la base du modèle touche la surface ; surfaces : top (pièce marquée surface: "top", sinon le dessus de tout le modèle), ou toute pièce marquée surface: "<nom>" ou simplement nommée (t1@tablette) ; pas de surface sur un modèle gen (on peut le poser, pas poser dessus). on: "sol" pose la base du modèle à la hauteur at[1]. stack: n = pile de n copies (sans nom). grid / scatter avec on : répartis sur la surface, sans chevauchement. Semis au sol : spacing (écart minimal entre copies, en plus de leur emprise) et avoid [instances, modèles, famille ou tags] = emprises interdites parmi ce qui est écrit AVANT (ex. arbres hors des maisons et des routes). Une instance nommée posée sur une instance nommée la SUIT quand nodes déplace ou tourne l’appui (livre sur paquet sur table) ; en jeu nodes.<instance> = {on: "t2@top", at: [0, 0.1], ease: {ms: 300}, lift: 0.3} change d’appui, {on: null} détache sur place (x, y ou z aussi), ry tourne sur l’appui. mondeInfo.conflicts [{a, b, type: hors-appui|chevauchement}] liste les poses à revoir ; instances[].on = appui@surface. Limites : appui horizontal (ni mur ni plafond), format monde seulement. '
        .'ESPACE (une pièce à aménager ou un jardin : NE PAS modéliser murs et sol à la main) : clé espace du monde = {contour: [[x, z], …] (3 à 60 points, concave accepté ; le bord i va du point i au suivant), hauteur (des murs ; sans elle pas de mur : parcelle), bords: [{couleur | matiere, tex, hauteur, epaisseur, type} (sans bord : vaut pour tous), {bord: i, …, ouvertures: [{type: fenetre|porte|portail (ou model: un modèle du monde), at (distance du COIN GAUCHE du mur VU DE L’INTÉRIEUR au milieu de l’ouverture), largeur, hauteur, allege, params {cadre, teinte}}]}], zones: [{nom, contour (absent : tout l’espace), revetement parquet|chevron|moquette|carrelage|beton|lino|gazon|dalles|terrasse|gravier|paillis|terre|sable|eau, couleur, tex, layers: [{model, cell, density, count, spacing, avoid, params}] (semis dans le contour de la zone : massif, potager)}], plafond: true | {couleur}, murs: "pleins" (défaut : une face tournée vers la pièce, on voit dedans de partout)}. type de bord : mur (défaut d’une pièce), aucun, ou clôture procgen haie|cloture|palissade|muret|pierre-seche|grille|bambou. Avec unite "cm" tout s’écrit en centimètres. Instances créées, pilotables et servant d’APPUIS : mur0, mur1… (une par bord), plafond, une par zone (son nom ; plancher par défaut), fenetre0, porte0, portail0. POSER DANS L’ESPACE : {model, on: "mur0", at: [x, h]} = dos au mur, à x du coin gauche vu de l’intérieur, base à la hauteur h (étagère, cadre, applique) ; {model, against: "mur2", at: x} = pareil avec la base au sol (lit, bureau, armoire) ; {model, on: "plafond", at: [x, z]} = suspendu, en coordonnées de la pièce ; {model, on: "<zone>", at: [x, z]} = posé au sol en coordonnées de la pièce ; puis on: "<meuble>" pour poser sur un meuble (lampe sur le bureau). Un modèle mural a son dos en z = 0 et s’étend vers +z ; un modèle suspendu s’accroche en y = 0 et descend vers -y ; un meuble a sa base en y = 0 et, s’il va contre un mur, son dos en z = 0. Limites : pas de sous-pente, pas de soleil réglable. '
        .'COLLISIONS : mondeInfo.conflicts [{a, b, type}] est calculé dès qu’il y a un espace ou une pose (collisions: true le force, false le coupe) ; chaque pièce dessinée est une boîte orientée testée en 3D : chevauchement (deux objets se pénètrent de plus de 1,2 cm, ou un objet traverse un mur ; une chaise glissée sous une table ne compte pas), degagement (objet dans le passage d’une porte ou à moins de 15 cm devant une fenêtre, à sa hauteur), hors-espace (hors du contour), hors-appui ; collisions: "montrer" entoure aussi de rouge à l’écran (3D) les objets en conflit ; non testés : sols, plafond, objets plats (tapis) ; un modèle gen est mesuré une fois généré puis testé ; "souple": true sur un modèle tolère 10 cm (plantes, coussins, rideaux). ÉDITION (ranger un objet au point visé, sans calculer) : nodes.<instance> = {move: [x, z]} (mètres, ease possible) ou attribut drag=".*" (regex des instances) sur sonic-3d ou sonic-2d pour glisser au pointeur (R, Q / E ou la molette tournent l’objet saisi ; ry avec move = cap voulu) ; l’objet va sur l’appui de sa nature (mur, plafond, dessus d’un meuble, sol), s’aimante (dos au mur, bords des voisins, grille 5 cm) et glisse vers la place libre la plus proche ; au lâcher target reçoit {type: "place", payload} et la sortie publie placed = {objectId, x, y, z, ry, valid, hits, snaps, place} : pour garder la pose, réducteur du store : $merge([$state, {"monde": $mondePlace($state.monde, $action.payload)}]) ; ajouter un meuble à une place libre : $mondeAdd($state.monde, {"model": "lit", "params": {…}}, [x, z]?) (nommé lit1, lit2… ; store avec budget-ms="200") ; retirer : $mondeRemove($state.monde, "lit1") (avec ce qui est posé dessus) ; régler : $mondeParams($state.monde, "lit1", {"couette": "#e07a5f"}) ; tourner sur place : $mondeTurn($state.monde, "lit1", 90) ; un tap (pick) sur un objet du monde porte payload.instance (l’objet, jamais un mur ou le sol) et payload.src (à repasser en dernier argument de ces fonctions pour un objet nommé d’office) ; formulaires dans un sonic-pop : formDataProvider sur chaque champ (le contenu du pop ne voit pas ses ancêtres), sonic-button radio plutôt que sonic-menu-item ; conflicts est recalculé. IMMERSION (appli d’aménagement) : scène en plein écran, barres flottantes, et sur sonic-3d / sonic-2d un enfant slot="selection" (bulle Tourner / Régler / Retirer) qui suit l’objet sélectionné ; camera="yeux" sur sonic-3d = dans la pièce à hauteur d’yeux (glisser = regarder, tap sur le sol = y aller, molette = avancer), à basculer sans remonter la scène avec view-provider = un DataProvider {camera: "yeux" | "orbit"} ; l’objet saisi se soulève et un fantôme rouge signale une place impossible, rien à écrire. PORTES ET FENÊTRES DÉPLAÇABLES : les écrire dans ouvertures du monde (à côté d’espace, pas dedans) = [{type: "porte", bord: 3, at: 105}, {type: "fenetre", bord: 1, at: 150, largeur: 120, hauteur: 125, allege: 90}] ; elles se sélectionnent et se glissent le long des murs (drag), le même réducteur $mondePlace recopie bord et at ; noms porte0, fenetre0… dans l’ordre de la liste ; celles d’espace.bords[].ouvertures restent fixes. Attribut keep-heading : l’objet glissé garde son cap (pas de rotation automatique dos au mur). SÉLECTION D’ABORD (recommandé pour une appli d’aménagement) : attribut drag-selected en plus de drag et pick ; le tap écrit le nom dans la clé selection du store (réducteur : action du tap → {"selection": $action.payload.instance ? $action.payload.instance : ""}), l’objet sélectionné a un contour bleu et lui seul se glisse (s’il est le premier sous le pointeur) ; ailleurs le glisser tourne la vue ; historySkip: "select" sur la définition du store garde les sélections hors de @undo ; @reset revient à l’état initial. SAUVEGARDE chez le visiteur : data.persist = {providers: ["chambre", "piece"], version: 1} (stores ou transforms déclarés, 8 max) garde leur état dans le navigateur ; incrémenter version quand la forme de l’état change ; data.persist.online: true les enregistre aussi en ligne (retrouvés sur un autre appareil par un lien secret ?save=, rattachés au compte du visiteur connecté) et data.persist.status = nom d’un DataProvider qui reçoit {etat: local|envoi|ok|erreur|hors-ligne, lien, compte, maj, message} (bouton sonic-clipboard for=… avec ::text = $lien pour copier le lien). ÉMETTEURS (projectiles, armées, nuées ; sonic-3d avec play, et sonic-2d) : monde.emitters = {id: {model (modèle en pièces ou prefab, params tirés par copie "= rand(…)"), max (≤ 2000), from (instance source, le tir suit son cap) + offset, ou at ; dir, spread (degrés), speed [min, max], gravity, drag, life (s, 0 = immortel), rate (par s), mask (instances, modèles, familles, decor, sol, ou NOM D’UN AUTRE ÉMETTEUR pour toucher ses copies), radius, onHit die|bounce|stick|pierce, kills, spawn {count, area [x0, z0, x1, z1]}, flock {seek | flee (instance), speed, separation, cohesion, align, radius, wander, avoid, bounds}, scale, events ["expire"], publish N}}. Tir : le reducer écrit emit = {blaster: {n: compteur, count, toward}} dans le DP lu par la scène ; chaque hausse de n tire une salve. Le reducer reçoit {type: "hit", payload: {hits: [{emitter, id, target, copy, tags, x, y, z}]}} une fois par image (target = instance, étiquette ou émetteur touché) ; la sortie publie emitters {id: {alive, emitted, dropped, expired, near}} et hits. Jamais une copie émise dans nodes ni dans place. PARTICULES (purement visuelles ; sonic-3d avec play, et sonic-2d) : monde.particles = {id: {preset (feu, etincelles, braise, lueur, eclair, magie, fumee, vapeur, brume, poussiere, eclaboussure, gouttes, bulles, pluie, neige, confettis, debris, eclats, feuilles), toute clé le remplace : from (instance suivie) + offset ou at, shape point|sphere|box|ring + extent, dir, spread, speed, rate (continu), burst, life, max (≤ 4000), gravity, rise, drag, turbulence, wind, size [début, fin], color [1 à 4 couleurs], opacity [début, fin], blend add|normal, sprite disque|flocon|goutte|eclat|trait|volute, stretch, spin, floor, light {color, intensity [début, fin], radius}, then (système lancé après la salve)}} et monde.vent [x, y, z]. Salve : le reducer écrit fx = {boum: {n: compteur, at, scale, color}} dans le DP lu par la scène. Un émetteur sème une traînée (trail: id) et lance une salve à l’impact (burst: id). COLLISIONS EN JEU : collider sur un modèle ("none", ou jusqu’à 8 formes {box: [l, h, p]} | {sphere: r} | {capsule: [r, h]}, posées au sol, at pour décaler) remplace ses boîtes ; tags [..] et sensor: true sur un placement nommé (zone qui détecte sans bloquer : le reducer reçoit {type: "enter" | "leave", payload: {sensor, instance, tags}}) ; requêtes : le reducer écrit queries = {vise: {n: compteur, ray: {from, dir, max} | sphere: {at, r} | box: {lo, hi}, mask, ignore, live}} dans le DP lu par la scène, la réponse arrive dans la sortie queries.vise = {n, hit, instance, model, tags, x, y, z, normal, dist, all}. MONDE INFINI ET COLLISIONS : les copies des tuiles proches comptent comme décor pour queries (instance = identifiant de la copie, étiquette infini), capteurs, émetteurs (mask avec le nom du modèle ou infini) et physique (obstacles, chocs nommés) ; le relief porte nuées, projectiles et corps physiques ; retirer une copie touchée par tiles.<clé>.remove. MONDE INFINI : monde.infini = {seed, tile (côté d’une tuile), radius (tuiles, ≤ 6), follow (instance autour de laquelle charger), ground {relief (expression en mètres avec x, z, noise), color}, biomes {field (expression de -1 à 1), list [{to, name, color}]}, layers [{model | models [..], cell (une copie au plus par cellule), density (0 à 1 ou expression avec x, z, h, biome), scale, turn, biomes}], perTile} : tuiles générées et relâchées autour de l’instance suivie (le reducer la déplace par nodes), brume ≈ 20 / (radius × tile) pour cacher le bord. Retouches : tiles = {"cx,cz": {remove: [ids t.cx.cz.calque.rang], add: [{model, x, z}]}} ; le reducer reçoit {type: "tileEnter", payload: {cx, cz, biome}} ; sortie infini {center, loaded}. MODÈLES GEN, MATIÈRES ET ACCESSOIRES : finish par rôle sur un modèle gen ({maillot: velours, cuir: cuir, metal: chrome}, ou un nom pour tous les habits) ; parts d’un modèle gen = accessoires portés par un os (parent tete, torse, bassin, main + mirror x, pied…) : formes, tex, finish, souple (cape, jupe, écharpe repoussées par le corps), cheveux (sur une sphère hidden posée sur la tête, params hair none) ; ils suivent l’animation (3D). sonic-procgen et style des modèles gen : jeton matieres {role: fini} (metal, cloth1, roof, glass…). FINIS TEXTURÉS : sur une pièce avec tex, finish {relief (normales), profondeur (parallaxe : joints creusés), usure (bosses polies), occlusion (creux sombres)} ; finis pave, brique, ecorce, rouille (et bois, pierre, cuir, tissu). monde.arrondi (m) arrondit les boîtes sans 4e valeur ; monde.facettes : true pour du low-poly ; etalonnage.occlusion (0 à 1) : ombres de contact et recoins. PIÈCES SOUPLES (3D) : souple sur une pièce = tissu (cape, drapeau, rideau ; nappe sur la boîte), jupe (tube ouvert sur un cylindre : jupe, robe, manche) ou chaine (queue, écharpe, corde) ; {type, attache haut|bas|gauche|droite|avant|arriere, rigidite, poids, amorti, vent, collision, segments} ; cheveux sur la pièce tête : {coupe longs|courts|frange|queue|herisses, nombre, longueur, couleur, ondulation, raideur} ; le vent du monde les fait onduler. MATIÈRES AVANCÉES : finish sur une pièce ou une matière de materiaux = fini prêt (mat, satine, brillant, laque, plastique, ceramique, metal, brosse, chrome, miroir, verre, depoli, eau, tissu, velours, cuir, caoutchouc, bois, pierre, cire) ou {fini, rough, metal, clear (vernis), sheen (tissu), glass (verre, avec ior), opacity, relief (la tex sert de relief)} ; matières de base physiques : chrome, or, cuivre, aluminium, acier, laiton, verre, verre-depoli, verre-fume, plastique, plastique-brillant, laque, ceramique, cuir, velours, caoutchouc (plus bois, tissus, marbre, pierre) ; monde.environnement studio | exterieur | aucun (reflets ; studio par défaut dès qu’un fini reflète). RENDU PHOTO : monde.photo = true ou {echantillons (200), rebonds (5), attente (ms, 600)} : à l’arrêt de la vue, lancer de rayons progressif (reflets entre objets, ombres douces, verre réfracté), tout mouvement revient au temps réel ; avancement dans la sortie photo {etat attente|calcul|fini|indisponible, echantillons, total} ; ignoré en toon, sans particules ni brume ; photo.filtre peinture (rapide : 12 échantillons, demi-résolution, touches de pinceau Kuwahara qui suivent les contours ; tramage 0 à 1 avant le filtre, niveaux, touche en px) ou lisse (débruité) ; coûteux : bouton qui l’active plutôt que permanent sur mobile. Textures procédurales affinées automatiquement de près (plafond selon detail). PHYSIQUE 3D : monde.physique = true ou {gravite [0,-9.81,0], sol (true), contactMin, pause, liaisons} active des corps rigides (sonic-3d et sonic-2d) ; corps sur un placement NOMMÉ : dynamique | statique | cinematique ou {type, masse (kg), friction, rebond, amorti, gravite, ccd, bloqueRotation ["x","z"] (personnage debout), bloquePosition, forme auto|boite|sphere|capsule, vitesse} ; décor et instances sans corps = obstacles ; liaisons {type charniere|rotule|fixe|glissiere|ressort|corde, a, b (absent = monde), at, axe, butees, moteur {vitesse, force}, longueur, raideur, amorti} ; ordres clé DP forces.<instance> {vx vy vz (vitesse imposée), fx fy fz, torque, spin, impulse {n, x y z | toward, power, at} (N·s), set {n, x y z, rx ry rz, vx vy vz}} (compteurs n, set avant impulse) ; sortie physique {bodies {nom {x y z rx ry rz vx vy vz awake}}, awake} ; target reçoit contact {a, b, tagsA, tagsB, impulse, x, y, z} et rest ; push d’un émetteur pousse les corps touchés. AMBIANCES : monde.ambiance règle d’un mot rendu, ciel, brume, palette, style gen, lumière et étalonnage : realiste, militaire (tir réaliste), cartoon (karting, plateformes), jouet, cyber (néon), alien (extraterrestre), silhouette (ombres chinoises), noir (film noir), voxel (pixels ; caméra iso ou de côté), retro, horreur, western, pastel, onirique, tableau (peinture) ; ou {base, …} ; ce que le monde écrit l’emporte ; s’inspirer d’un genre sans reprendre personnages ni éléments d’une œuvre. monde.lumiere {intensite, couleur, hauteur, azimut, ambiante, couleurAmbiante} ; monde.etalonnage {saturation, contraste, luminosite, teinte, noirEtBlanc, sepia, grain, vignette, pixel, niveaux, silhouette, contours, couleurContours, halo, seuilHalo, aberration, lignes, peinture (rayon des touches en px), tramage (0 à 1)} (2D : couleurs, silhouette, grain). HERBES (3D) : monde.herbes = [{type herbe|ble|roseau|fleurs|gazon, zone [x0,z0,x1,z1] ou [[x,z]…], densite (brins/m²), hauteur [min,max], pied, pointe (couleurs), fleurs [couleurs], largeur, souplesse, plie, degage}] : brins instanciés qui ondulent au vent (monde.vent) et se couchent au passage des instances nommées ; sol libre sous le décor ; budget selon detail. IMAGES ET POSTERS : monde.images = {nom: "data:image/jpeg;base64,…"} (png, jpeg ou webp, 12 au plus, 800 Ko chacune) et "image": "nom" sur une pièce plaque l’image sur sa face avant ; prefab poster {largeur, hauteur, image, baguette: aucune|noire|bois|blanche, fond} ; une décoration murale ajoutée sans hauteur ($mondeAdd) est accrochée vers 1,2 m ; store qui porte des images : maxStateBytes (jusqu’à 1228800) dans sa définition. Atelier de poster : sonic-2d id="fond" (monde2d de la composition : rect du fond avec tex, forme, text) sous un sonic-paint background="transparent" stamp-from="#fond" target="<store>" export-action="poster" export-size="512" export-type="jpeg" export-for="<id du bouton>" : le clic envoie {type: "poster", payload: {image, width, height}} ; le réducteur range l’image dans monde.images et appelle $mondeAdd avec {model: "poster", params: {image: nom}} ; retirer le poster doit aussi retirer son image. MOTIFS DES SURFACES : espace.bords[].tex et zones[].tex = {kind, color2, scale, angle, contrast} (rayures, pois, damier, chevron, carrelage, brique, ecailles, hachures, tricot, nuages, marbre, bois, beton, camouflage, tissu…) avec couleur = fond : papiers peints et sols sur mesure ; tapis : params motif, teinte2, echelle, angle. Murs, sols, plafond, fenêtres et portes ne bougent pas ; un objet sans nom posé sur un meuble nommé est nommé <appui>-<modèle><n> (bureau-lampe1) et le suit. DÉPLACEMENTS EN JEU qui respectent les obstacles : nodes.<instance> = {x, z, collide: "glisser"} (longe l’obstacle) ou "bloquer" (s’arrête au dernier point libre), ease possible ; portes et fenêtres laissent passer, les murs arrêtent ; la sortie publie contact = {objectId, x, z, hits, blocked, t} quand quelque chose gêne (t change à chaque contact). MOBILIER : lit, bureau, table, etagere, armoire, commode, tapis, coussin, rideau, lampadaire, lampe, suspension, applique, cadre, plante, bac, potager, cabane, barbecue, borne existent déjà par leur nom (règle « mobilier », à lire avant de meubler une pièce ou un jardin). Aides d’écriture d’un prefab : vars {H: "= type == \'haut\' ? 1.7 : 0.3"} (valeurs intermédiaires), "if": "= expr" sur une pièce ou un use (gardé si vrai), fonction ceil. '
        .'TEXTURES (procédurales, au mètre, teintées par la couleur) : bois tissu marbre bruit grain rayures damier pois herbe brique carrelage ecailles fourrure metal pierre hachures tricot nuages camouflage parquet chevron moquette beton gravier dalles paillis, ou {kind, color2, scale (motifs par m), angle, contrast}. '
        .'PILOTAGE nodes.<instance> = {x, y, z, rx, ry (cap), rz, scale, visible, opacity, ease {ms, curve}, lift, clip, clipSpeed, clipT (0..1 pose figée, null = relâcher), roles {rôle: "#rrggbb"}} ; nodes.<instance>_<pièce> = {visible, color, opacity}. Le reducer écrit des états finaux (position cible + ease), jamais d’images intermédiaires ; changer de clip suffit à animer. '
        .'TAP : sonic-3d pick pick-names="^(p[0-9]+)$" → objectId = instance ; sonic-2d → {objectId: instance, part}. Sortie : out-data-provider DISTINCT reçoit mondeInfo {models {parts, clips, roles}, instances, decor, issues} (issues = erreurs du JSON, à relire). '
        .'PIÈGES : (1) une seule forme par pièce ; (2) noms de pièces uniques ; (3) le monde se met dans un store (pas dans un attribut sonic-store initial de plus de 32 Ko) ; (4) en 2D les positions des nodes sont en MÈTRES comme en 3D (view2d fait la mise à l’échelle).';

    /** Catalogue de mobilier du format « monde » (creative-stack src/shared/monde/catalogue.ts). */
    private const MOBILIER_RULE = 'MOBILIER (catalogue du format monde, sonic-3d et sonic-2d) : 20 modèles utilisables par leur NOM dans place, SANS les écrire dans models ; ne modélise un meuble à la main que s’il manque ici. Exemple : {"model": "lit", "name": "lit", "against": "mur2", "at": 85, "params": {"type": "mezzanine", "couette": "#e07a5f"}}. Les longueurs se donnent dans l’unité du document (unite "cm" : largeur 140 ; mètres : 1.4), les valeurs ci-dessous sont en mètres. Notation : nom [pose] (paramètre choix1|choix2 (le premier = défaut), ou mini–maxi m (défaut), matière, couleur #rrggbb, oui/non = booléen true/false). '
        .'lit [sol] (type simple|mezzanine|superpose, largeur 0.9|1.2|1.4|1.6, longueur 2|1.9|2.1, structure (matière, pin), drap (couleur), couette (couleur), tete oui) ; bureau [sol] (largeur 0.8–2.4 m (1.2), profondeur 0.6|0.5|0.7|0.8, hauteur 0.5–1.1 m (0.74), plateau (matière, chene), pietement panneaux|metal|treteaux, teinte (couleur), caisson non) ; table [sol] (forme rectangle|ronde, largeur 0.4–3 m (1.2), profondeur 0.4–1.6 m (0.8), hauteur 0.3–1.1 m (0.74), plateau (matière, chene), pieds (matière, metal-noir)) ; etagere [mur] (pose mur|sol, largeur 0.3–2.4 m (0.8), niveaux 3|1|2|4|5|6, ecart 0.2–0.5 m (0.32), profondeur 0.22|0.15|0.3|0.4, structure (matière, chene), fond non) ; armoire [sol] (largeur 0.5–3 m (1), hauteur 1.2–2.6 m (2), profondeur 0.6|0.45|0.65, portes battantes|coulissantes, structure (matière, bois-blanc), facade (couleur)) ; commode [sol] (largeur 0.4–1.8 m (0.8), hauteur 0.4–1.3 m (0.8), profondeur 0.45|0.35|0.55, tiroirs 3|1|2|4|5, structure (matière, chene), facade (couleur)) ; tapis [sol] (forme rectangle|rond, largeur 0.5–4 m (1.6), longueur 0.5–4 m (1.1), teinte (couleur), motif uni|tricot|rayures|pois|damier) ; coussin [dessus] (forme carre|rond, taille 0.3–0.8 m (0.45), teinte (couleur)) ; rideau [mur] (largeur 0.6–4 m (1.6), hauteur 1–3 m (2.2), ouverture 0.5|0|0.25|0.75|1, teinte (couleur), tringle (matière, metal-noir)) ; lampadaire [sol] (hauteur 1.1–1.9 m (1.5), abat cone|cylindre|boule, teinte (couleur), structure (matière, laiton), allume oui) ; lampe [dessus] (hauteur 0.25–0.7 m (0.4), abat cone|cylindre|boule, teinte (couleur), structure (matière, laiton), allume oui) ; suspension [plafond] (longueur 0.2–1.6 m (0.6), abat cone|cylindre|boule, teinte (couleur), structure (matière, laiton), allume oui) ; applique [mur] (teinte (couleur), structure (matière, laiton), allume oui) ; cadre [mur] (largeur 0.15–2 m (0.5), hauteur 0.15–2 m (0.4), cadre (couleur), image (couleur), miroir non) ; plante [sol] (forme buisson|palmier|cactus, taille 0.2–2.2 m (0.8), pot (couleur), feuillage (couleur)) ; bac [sol] (largeur 0.4–3 m (1.2), profondeur 0.3–1.5 m (0.4), hauteur 0.2–0.9 m (0.4), structure (matière, pin)) ; potager [sol] (largeur 0.6–3 m (1.2), longueur 0.6–4 m (1.2), rangs 4|2|3|5|6, structure (matière, pin), pousse (couleur)) ; cabane [sol] (largeur 1.5–5 m (2.4), profondeur 1.5–4 m (2), hauteur 1.8–2.6 m (2), structure (matière, pin), toit (couleur), porte (couleur)) ; barbecue [sol] (teinte (couleur)) ; borne [sol] (hauteur 0.3–1.2 m (0.6), teinte (couleur), structure (matière, metal-noir), allume oui). '
        .'POSE : [sol] base en y = 0, à mettre avec against: "murN" (dos au mur, at = distance du coin gauche vu de l’intérieur) ou on: "<zone>" (at [x, z] de la pièce) ; [mur] on: "murN", at [x, hauteur de la base] ; [plafond] on: "plafond", at [x, z] ; [dessus] on: "<meuble>" (at [x, z] depuis le centre du dessus). Appuis : lit, bureau, table, commode, bac ; planches d’une etagere : on: "etagere1@planche2". Matières : chene pin noyer hetre bois-blanc bois-noir acier laiton metal-noir lin coton laine marbre pierre blanc noir. Les couleurs ne coûtent rien ; chaque autre valeur distincte construit une variante (32 par modèle). ASSISES ET VÉGÉTAL : pas dans ce catalogue, ce sont des modèles gen à déclarer dans models : {"chaise": {"gen": "seat.chair"}} (seat.armchair, seat.sofa, seat.pouf, seat.beanbag, seat.stool, seat.bench, seat.deckchair…), {"arbre": {"gen": "tree"}}, bush, flowers ; ils se posent (on, against) mais ne servent pas d’appui. VÉRIFIER : mondeInfo.conflicts (out-data-provider) liste chevauchement (deux meubles l’un dans l’autre ou à travers un mur), degagement (devant une porte ou une fenêtre), hors-espace, hors-appui : corriger at jusqu’à ce que la liste soit vide. Un modèle écrit dans models sous le même nom remplace celui du catalogue. Écrire son propre prefab : règle « monde » (params, expressions, vars, if). ';

    /** Mémo du format « monde2d » (creative-stack src/shared/monde2d) : mondes 2D natifs dans sonic-2d. */
    private const MONDE2D_RULE = 'MONDES 2D NATIFS (sonic-2d, clé « monde2d » du DataProvider, ex. data.stores.jeu.initial.monde2d, ou attribut monde2d) : un monde 2D complet DESSINÉ POUR LA 2D (vue de profil, de face ou de dessus) ; pour un jeu 2D, une scène illustrée, des personnages animés, préférer ce format à des sprites un par un. Unités : px de la scène, y vers le bas, rot en DEGRÉS dans le monde, rz en RADIANS dans nodes. '
        .'Clés : ciel ("#rrggbb" ou ["#haut","#bas"]) ; layers [{name, parallax (0 fixe … 1 monde, >1 premier plan), ysort (tri par y : vue de dessus)}] (appliqués si l’attribut layers est vide) ; style plat|doux|bd|encre|gouache ; outline (px) ; relief ; palette {rôle: couleur} ; textures {rôle: motif} ; texture (tout) ; detail low|mid|high ; models {nom: modèle} ; place [placements]. '
        .'PRÉRÉGLAGES (models.<nom> = {preset, seed, params}) : personnage (params vue profil|face|dessus — regard vers +x en profil et dessus —, espece humain|chat|renard|ours|lapin|dino|monstre|grenouille|robot, taille px (64), corpulence, grosseTete, tete, oreilles, queue, cornes, cheveux, yeux, museau, numero, peau ; clips idle marche course controle tir passe tacle chute saut joie plongeon), animal (vue profil|dessus ; idle marche course assis saut), arbre (vue, forme rond|sapin|boules|palmier), buisson, rocher, fleur, herbe, nuage, maison (vue profil|dessus), sol et collines (profil), terrain (foot vu de dessus, largeur × hauteur), but (dessus ; flip pour l’autre camp), ballon (clip roule), foule (gradins, clip ola). '
        .'MODÈLES GEN (procgen, illustration vectorielle soignée : à préférer pour personnages, animaux, bâtiments, arbres, objets) : {gen: "character"|"animal.dog"|"building"|"tree"|"prop.lamp"…, vue profil|face|dessus|plongee (vue d’en haut inclinée, la plus lisible pour un jeu vu de dessus), taille (px pour la taille de référence : personnage 1,7 m, animal 0,8 m, arbre 6 m, bâtiment 7 m), pitch (plongée, ° ; 55), params procgen (archetype cartoon, top tee, bottom shorts, pattern plain, hat none, animal (22 espèces) et animalSpec comme « monde »…), seed} ; style flat|ligne-claire|gravure|affiche|papier-decoupe (ou styleGen : nom ou réglages {vector, outline, tones, shadows, hatch, halftone, posterize, papercut, paper, wash, chroma, contrast}) ; fpsGen (24), resGen (2 ; baisser si beaucoup d’acteurs) ; variantes par placement (params, genSeed) et clips libres (action, speed) comme « monde » ; traits 0..3 (épaisseur des traits, 1 = style). Os, clips (idle marche course joie… tir passe controle tacle chute plongeon) et roles (maillot short peau…) comme la règle « monde » ; en dessus/plongee rz = cap (le personnage est redessiné tourné) ; l’acteur porte son ombre. '
        .'Pièce : {name, parent, at [x,y] (ARTICULATION dans le parent), rot (°), off (centre de la forme depuis l’articulation), scale, z (ordre dans l’instance), role ou color, grad [clair, foncé], tex, line, stroke, fill:false (tracé seul), relief, glow, opacity, mirror x|y|z (z = profil : copie derrière, assombrie), repeat {n, at, rot}, hidden} + UNE forme : rect [w,h,rayon] | circle r | ellipse [rx,ry] | capsule [r,longueur] | poly [[x,y],…] | blob [[x,y],…] (courbe lisse) | star [n,r1,r2] | arc [r,début°,fin°,épaisseur] | path "M L Q C Z" | trait {pts, smooth} | text {text, size}. '
        .'Clip : {ms, loop, then, tracks: {pièce: {r (°), x, y (px), s, sx, sy, o : listes de clés, sync, phase}}}. Placement : {model, name (instance pilotable ; sans name = décor dessiné une fois), at [x,y], rot, scale, flip, roles, clip, layer, z, hidden} ; en masse : grid [nx,ny] + step [dx,dy], scatter n + area [x0,y0,x1,y1], jitter, turn, size [min,max], seed. Budgets : 3000 copies, 300 instances. '
        .'PILOTAGE nodes.<instance> = {x, y, rz, scale, opacity, visible, z, flip (regard à gauche), ease {ms, curve}, lift (saut en arc), clip, clipSpeed, clipT, roles} ; nodes.<instance>_<pièce> = {visible, color, opacity}. Le reducer écrit la destination + ease et le clip (« course »), jamais des images intermédiaires. TAP (target, pick-empty) : {objectId: instance, part, x, y} ou objectId null + x, y (pelouse). Sortie : out-data-provider reçoit monde2dInfo {models, instances, layers, issues} (erreurs du JSON). '
        .'PREFABS PARAMÉTRÉS : mêmes règles que la règle « monde », en pixels et sans clé unite : models.<nom>.params déclarés ({h: {default: 60, min: 20, max: 200, step: 20}, teinte: {default: "#e11d48"}}), expressions "= …" dans les pièces (rect [4, "= h"], at [0, "= -h / 2"], color "= teinte"), sous-modèles use (pièces <name>_<pièce>), emplacements slots, matiere, et params / slots par placement (tirés par copie dans grid / scatter : "= pick(40, 60, 80)") ; monde2dInfo.prefabs publie les fiches. '
        .'Formats voisins : la clé « monde » (règle « monde ») est un monde 3D que sonic-2d peut aussi projeter ; monde2d est le choix pour une vraie 2D.';

    /** Mémo des addons `stage` et `2d` : scènes 2D pilotées par le format nodes. */
    private const PROCGEN_RULE = 'MONDES PROCÉDURAUX 3D (sonic-procgen, 2 vues 3D par page). <sonic-procgen dataProvider="monde" target="jeu" style="display:block;height:420px"> compose un monde 3D (sol, relief, côte, routes, bâtiments, végétation, objets, personnages et animaux skinnés et animés) ; la 2D n’est qu’une caméra ou un style. Défilement : sonic-procgen et sonic-3d ont gestures=auto (coopère avec une page qui défile : un doigt vertical et la molette font défiler, Ctrl ou pincement zoome) ; gestures="capture" pour un jeu plein écran. Jeu vu du dessus en sonic-3d : camera="map" (glisser = déplacer, pincer = zoom, sans rotation), min-polar/max-polar bas, et la clé DP lookAt [x,y,z] pour suivre un joueur ou la balle. Unités : mètres (personnage 1,7 m, étage 3 m). '
        .'LU dans le DP (le composant n’y écrit JAMAIS) : scene, recipe (prioritaire), seed, params, style (préréglage gouache|doux|encre|bd|pixel|lowpoly|brume|nuit|neon|crepuscule|automne|printemps|desert|neige|papier|abysses|aquarelle, ou jetons {preset, palette, hue, contrast, shading flat|toon|soft|lit, bands, outline, lightAzimuth, lightElevation, shadows, fog, night 0..1, grain, vignette, pixel…, post, matieres {rôle: fini}}), view {camera side|front|iso|oblique|top|perspective|follow, yaw, pitch, zoom, cx, cy, cz, actor}, anim {play, speed}, actors, select, edit {id|layer, params}, hidden [ids], anchors ["heros@head.top", "maisons.2@door"], kick {ball, power, dir °, loft, n} (coup sans acteur, joué quand la valeur change), render 3d|vector, exportSvg (compteur). '
        .'POST-TRAITEMENT (rendu 3D) : style.post = préréglage riso|riso-3|trame-aniso|huile|aquarelle|gravure|lino|bd-tramee|pop|miniature|cyanotype|sepia|vitrail|1bit|gameboy|pico8|neon|vhs|argentique|thermique|bichromie|trichromie|pointillisme|decoupe|coucher, ou liste cumulable (8 au plus, dans l’ordre) [{fx, mix 0..1, mask near|far|objects|sky|{from, to} (profondeur en multiples de la distance visée), …réglages}] ; fx kuwahara-aniso (radius, dither, levels, pattern bayer|bruit-bleu), kuwahara, huile, aquarelle, bichromie (ink, ink2, paper), trichromie, riso (inks ["rose", "bleu"…]), cmjn, degrade (stops), palette (colors gameboy|pico8|… ou [#hex]), cyanotype, sepia, points, lignes, hachures, 1bit, lino, pointillisme, vitrail, bloom, halo, aberration, miniature, brume, grain, vignette, fuite, neon, thermique, glitch, crt, decoupe, kaleido, pixel, couleur ; {preset, …surcharges} dans la liste ; postQuality auto|low|high ; kuwahara-aniso et huile coûteux (scènes calmes). En vue orthographique, pixels et trames accrochés au monde (pixel: {size, snap: false} pour désactiver). Ignoré en render vector. '
        .'AMBIANCES (3D) : clé DP ambiance (ou recipe.ambiance, style.ambiance) = realiste|militaire|cartoon|jouet|cyber|alien|silhouette|noir|voxel|retro|horreur|western|pastel|tableau|onirique ou {base, ciel, brume, lumiere, etalonnage, palette, finish, meteo pluie|neige|feuilles|petales|brume|false} : lumière, ciel, brume, palette, finis, étalonnage d’un mot ; un style écrit la complète : n’y écrire QUE les jetons changés. RENDU SOIGNÉ : clé DP photo true|{moteur auto|rayons|raster, echantillons, filtre peinture|lisse} (scène immobile → image affinée, premier geste = temps réel) ; render:"photo" fige l’animation à l’arrêt ; state-out.photo {etat, progression}. '
        .'PRAIRIES (rendu 3D ; ignorées en vector) : recipe.meadows = [{kind herbe|ble|roseau|fleurs|gazon, zone [x0, z0, x1, z1] | [[x, z]…] | id d’une zone de recipe.zones (absente : toute la scène), density (brins/m²), height [min, max], base, tip, flowers ["#rrggbb"…], sway 0..2, bend false, clear false}] : brins instanciés posés sur le relief, jamais sur routes, eau ni sous les objets ; au vent recipe.wind (force) et windDir (°, 0 = vers +z), couchés au passage des acteurs, de l’objet glissé et des ballons ; recipe.meadowDetail auto|0|1|2 (auto : moins dense sur mobile) ; status.blades. Pour une herbe d’ambiance, préférer UN champ par zone plutôt que des centaines de couches grass. '
        .'PHYSIQUE 3D (rendu 3D ; Rapier chargé seulement si demandé) : recipe.physics true|{gravity, joints [{type hinge|ball|fixed|slider|spring|rope, a, b (ids d’objets ; absent = monde), at, axis, limits, motor, length}], paused, water, walls} ; layers[].physics (ou point at {x, z, y = hauteur de chute, physics}) "dynamic"|"static"|"kinematic"|false|{body, mass, friction, restitution, shape box|sphere|cylinder|capsule|hull, lockRotation, vel} : caisses, tonneaux, chaises qui tombent, roulent, flottent ; avec la physique de scène les ballons sont dynamiques (kick et tirs des acteurs passent par elle) ; décor, relief, ponts, bords de scène et acteurs = obstacles ; 200 corps au plus. DP forces {id: {vx, vy, vz, fx, fy, fz, impulse {n, x, y, z}, set {n, x, y, z}, wake}} (compteurs n), queries {id: {n, ray {from, dir, max} | sphere {at, r} | box {lo, hi}, mask, ignore, live}} ; state-out.physics {status loading|ready|error, bodies {id: {x, y, z, vx, vy, vz, awake}}}, state-out.queries ; événements contact {a, b, impulse}, settle {bodies}, splash, drop (corps lâché), enter/leave {zone, body} (zones = capteurs) ; un corps glissé (drag) pousse les autres puis retombe. '
        .'MONDE INFINI (rendu 3D) : recipe.infinite {tile (m, défaut 32), radius (tuiles, 1 à 4), follow (id de recipe.actors ou "camera"), seed, roads [{id, kind road|path|street|river, width, spacing, density, wiggle}], persist (retouches au plus)} : sol, routes et couches composés par tuiles autour du point suivi (même graine = même monde, sans bord) ; couches semées par place {scatter: {cell, density (nombre ou "= expr" avec x, z, h), jitter}}, along: "routes" suit le réseau, at ancré au monde ; personnages et animaux dans recipe.actors seulement ; ids <couche>.<cx>_<cz>_<rang>. Persistance : à l’action place, le reducer recopie payload.tiles dans la clé DP tiles (rien d’autre à calculer) ; state-out.infinite. Exemple prêt : scene "campagne". Sans physics ni meadows. '
        .'TISSUS ET MÈCHES (simulés en 3D, forme figée en vector) : character cape short|long (cape en tissu) et soft cloth|hair|all (cloth : cape de bag, pan d’écharpe, jupes skirt|longskirt, robes dress|robe, pans de coat ; hair : cheveux long, ponytail, lowtail en mèches ; défaut none = aspect inchangé) ; générateur textile (textile.flag, textile.banner, textile.pennant, textile.windsock, textile.laundry (count, length), textile.curtain (length, à poser dos au mur) ; fabric silk|cotton|canvas|wool, color, color2) ; ils suivent recipe.wind et windDir et les mouvements des acteurs ; 48 objets à tissus au plus par scène, simulation réduite au loin, hors écran et sur mobile ; status.soft. '
        .'MATIÈRES (rendu 3D) : style.matieres = {rôle: fini} (rôles road wall roof stone trim wood bark metal glass water cloth1 leather…, ou du format : maillot) ; finis du format monde mat|satine|laque|metal|chrome|velours|cuir|caoutchouc… ou {fini, rough, metal, clear, sheen} ; finis TEXTURÉS pierre|pave|brique|ecorce|bois|cuir|tissu|rouille (relief, profondeur, usure, occlusion 0..1 ; motif = texture du format brique|pierre|dalles|ecailles…, echelle = répétitions/m) : {"wall": "brique", "road": "pave", "bark": "ecorce", "roof": {"fini": "brique", "motif": "ecailles", "echelle": 2.5}} ; verre|depoli|eau = reflet opaque (pas de transparence). Qualité réduite sur mobile (postQuality auto). '
        .'PARTICULES (sans recomposer ; vector : aplats simplifiés sans occultation) : recipe.weather rain|snow|leaves|petals ou {kind, intensity 0..1} (au-dessus de la vue, densité par m²) ; vent recipe.wind et windDir ; recipe.fx {chimneys (fumée sur les ancres chimney.N des building, paramètre chimneys), campfires (flammes, braises, fumée des prop.campfire), cascades (embruns à l’ancre fall des water.cascade), mist, dust, quality auto|low|high} : true, intensité ou {intensity, color, size, rate} ; layers[].fx = smoke|fire|embers|sparks|steam|spray|leaves|snow… ou {preset, anchor (chimney, light, door, fall, top), …} sur chaque objet du calque ; recipe.particles {id: {preset, at [x,y,z] | "objet@ancre", rate, size, color…}} (réglages du format monde) ; salves : DP fx {campfires-sparks: {n}} (n incrémenté) ; budget de grains (réduit sur mobile) ; status.fx. '
        .'ÉMETTEURS (projectiles et nuées en masse, rendu 3D ; ignorés en vector) : recipe.emitters ou clé DP emitters (fusionnée par id, null retire ; réglable sans recomposer) = {id: {gen (animal.pigeon, animal.seagull, animal.chicken, ball.soccer, prop.crate…, pas de building/structure/wall/water) | shape sphere|stone|fireball|arrow|dart|bird|butterfly|bee|fish|rat|leaf (size m, color, color2 = rôles), params, variants 1..8, max (2000/émetteur, 6000 en tout), rate (/s), life (s, 0 immortelle), from (acteur ou objet, offset) | at [x, y, z], dir, spread °, speed [min, max], gravity, drag, mask [id, calque, générateur, batiment|mur|cloture|arbre|vegetation|rocher|vehicule|ouvrage|objet|ballon|decor|acteur|personnage|animal|sol|eau ou autre émetteur], radius, onHit die|bounce|stick|pierce, push (lance un ballon), flock {seek|flee, fleeRadius, speed, separation, cohesion, align, radius, wander, avoid, bounds [x0, z0, x1, z1], altitude [min, max] = nuée EN VOL au-dessus du relief (sinon au sol)}, spawn {count, area}, scale, flap (images/s), spin °/s, events ["expire", "exit"], publish N, lod (triangles par copie ; false), mobile 0..1 (défaut 0,5)}} ; tir : DP emit {id: {n (compteur : chaque hausse = une salve), count, at, dir, toward (acteur, objet ou [x, y, z]), clear, kill {n, ids}}} ; événements impact {hits [{emitter, id, target, tags, x, y, z}], t} (une fois par image ; hit reste le dribble d’un ballon), expire/exit {emitter, count} ; state-out.emitters {id: {alive, emitted, dropped, expired, exited, near}} et impacts. Les copies ne sont ni dans ids ni sélectionnables, sans ombre. '
        .'ÉCRIT dans state-out (défaut <dataProvider>State) : status {phase idle|composing|ready|error, progress, error, objects, actors, triangles, fps, issues [{path, code, hint, didYouMean}]}, selection {id, layer, index, place, x, z, yaw, w, d, movable…}, ids [{id, generator, layer, x, z, yaw, w, d, place}], actors, anchors, nodes (format sonic-stage : bulles HTML par-dessus), view, svg, balls {id: {x, y, z, moving}}, drag (pendant un glisser), free (réponse à placeRequest). '
        .'ÉVÉNEMENTS vers le store target (type préfixé par action-prefix) : pick {objectId, generator, layer, x, y, z, actor, zone} (point-and-click au sol), select, arrive {actor, target}, blocked {actor, reason}, enter/leave {zone, actor | ball} (but), actionEnd {actor, action}, kick, hit {ball, actor}, rest {ball, x, z}, place, dragstart, dragRefused, placeFree (édition). '
        .'ÉDITION AU DOIGT (éditeur de scène) : attributs drag=".*" (regex des ids ou calques) drag-selected keep-heading ; tap = select {objectId, layer, index, place, movable} (vide = désélection) ; seul l’objet sélectionné se glisse, rangé par le composant sans rien envoyer pendant le geste (relief, eau, routes, emprises des voisins, dos au mur, grille 5 cm, place libre la plus proche, rouge si impossible), puis UNE action place {objectId, layer, index, x, z, yaw (°), valid, snaps} au lâcher : le réducteur recopie x, z, yaw dans layers[i].place.at[index] pour une couche place {at}, sinon (calque réparti poisson|along|edges|scatter|grid…) dans layers[i].moves[index] = {x, z, yaw} : l’objet reste à sa place, les autres ne bougent pas ; layers[i].remove [index…] retire des objets ; un ballon envoie dragRefused ; PARTIES MOBILES : selection.parts et ids[].parts [{name, label, kind rotation|translation, range, value}] (lid, lid.f du carton, door.fl = conducteur, door.fr, door.rl, trunk, hood, door.side, door.back.l, tailgate, door et shutter.N d’un building, gate.l, wheel.N jamais ouvert, steer.fl parent de wheel.0.l), affords open, pick.part = partie touchée ; pour ouvrir ou fermer, le réducteur écrit la clé DP states {"<objectId>": {"door.fl": 1, "open": 0, "burning": true}} (0 fermé, 1 ouvert, booléen accepté ; préfixe door = toutes les portières ; fusionnée par objet ; animée sans recomposer, stateSpeed s par course, 0,6 par défaut) et reçoit state {objectId, part, value} en fin de mouvement ; ancres de partie lid.top, door.fl.handle ; seat.driver, seat.N, approach.door.fl des véhicules ; deux doigts = déplacer la vue et pincer ; R, Q / E ou molette tournent l’objet saisi (jamais de rotation automatique) ; nouvel objet sans collision : écrire placeRequest {id, gen, params, at [x, z]} dans le DP, le composant répond placeFree {id, x, z, valid} (et state-out.free), le réducteur ajoute alors l’objet à ce point. '
        .'ACTEURS (actors.<id>, écrits par le reducer, jamais à chaque image) : {x, z} ou {target:"maisons.2"} (chemin A* jusqu’à la porte, puis arrive ; run, speed), {target:"bancs.1.sit.0", action:"sit"} (s’assied), {vx, vz} (manette), {target:"ballons.0", action:"kick", power, loft} (tire ; les ballons rebondissent, roulent, se poussent), {action idle|walk|run|wave|point|cheer|talk|carry|sit|jump|think|bow|hammer|sweep|dig|drink|eat|sleep, duration s}, {target:"bassin.0.spot.1", action:"drink"|"eat"|"sleep"} (boit, mange, dort à la cible), {mood neutral|happy|sad|angry|surprised|sleepy}, {facing °} ; CONDUITE (3D) : {target:"voitures.0", action:"board", seat:"driver"|N} (portière ouverte puis refermée, assis au volant ; sans portière vélo, barque, charrette… ; monture animal.horse|deer|cow|bear à califourchon), {action:"alight"}, {action:"drive", to:{x, z}|zone|objet, speed} (routes, grille, eau ; puis arrive {actor, vehicle}) ; clé DP drive {id: {throttle -1..1, steer -1..1 (>0 = droite), brake 0..1}} (commande directe) ; state-out.vehicles {id: {x, z, yaw, speed, driver, passengers, seats}} ; événements board, alight, bump {vehicle, hit} ; view {camera:"follow", actor:"<véhicule>"}. '
        .'SCÈNES : village, rue, port, foret, bivouac, personnages, marche, montagne, vapeur, neon, blasons, jardin, ferme, bord-de-mer, aire-de-jeux, place-medievale, jardin-japonais, riviere, prairie. '
        .'RECETTE {params, size [w, d], ground {relief, scale, water, coast north|south|east|west, surface grass|sand|snow|dirt|paved|seabed}, paths [{id, points, width, kind street|road|path|river|plaza, sidewalk}], layers [{id, gen, count, place {at|grid|poisson|along (side, spacing, offset)|across (pont en travers d’une rivière)|cluster|ring|edges}, params, yaw, scale, onWater, behavior idle|wander|patrol, action, target ("abreuvoir.0.spot" : posé à sa cible), moves {"<index>": {x, z, yaw}}, remove [index], states {"<index>"|"*": {lid: 1, "door.fl": 0.5}} (état de départ sans recomposer), stateFx {"lid>0.3": "steam"} (particules liées à un état : partie ou état libre, seuil >, >=, <, <=)}], zones [{id, at, r}], actors {id: {params, at, action, target, states}}, view, style} ; états libres sans partie burning (flammes, fumée, lueur), lit (flamme et lumière à l’ancre light), boiling (vapeur et bulles), smoking (fumée) ; container open, structure.gate open, assembly part {name, pivot, axis, range, kind} = valeurs ou parties de départ ; nombres en expressions "= randint(1, 3)" (rand, pick, chance, noise, i, n, t ; aucun code exécuté) ; ids <couche>.<rang> stables. '
        .'GÉNÉRATEURS (gen, raccourcis prop.lamp, animal.horse, tree.oak) : character (role, figure, archetype chibi|cartoon|stylized|heroic, age, hair, beard, tenue, chapeau, objet tenu…), animal (15 espèces dont pigeon, seagull), tree, bush, grass, flowers, rock, plant, building (style bourg|colombages|chaumiere|chalet|japonais|chateau|phare|boutique… 17 styles, floors, roof, walls), prop (≈ 45 objets), vehicle (… moderncar, bus, truck, tractor, wagon, locomotive onRails), wall, emblem, ball (ball.soccer, ball.tennis…), seat (seat.parkbench, seat.sofa… ; user, places), assembly (objet sur mesure en parts box|rod|tube|lathe|blob|plate), tool (tool.rake, tool.hammer… ; tenu : item "tool.rake"), container (container.crate… ; open, fill), street (street.streetlight, street.roadsign sign stop…), pet (pet.kennel… ; for dog|cat|horse|chicken|rabbit|bird, ancre spot), structure (bridge, pontoon, quay, stairs, ramp, arch, gate, rails ; praticables), water (pond, basin, pool, cascade ; ancres spot.N), textile (drapeaux, linge, rideaux en tissu simulé) ; rôles red…black ; paramètres absents = tirés de la graine. '
        .'VRAI 2D : render:"vector" ou style ligne-claire|flat|gravure|affiche|papier-decoupe (variante "flat/nuit") = illustration vectorielle Canvas 2D (Bézier, net à tout zoom), mêmes ids, acteurs et événements ; caméras side ou oblique ; exportSvg → state-out.svg. '
        .'PIÈGES : (1) lire l’état dans <dp>State (ou state-out), pas dans dataProvider ; (2) ne pas réécrire view ni actors à chaque image : le composant interpole ; (3) ancienne API (patch, defs, select-action-type, status.composing, scènes "port vivant"…) : retirée, une clé inconnue est signalée dans status.issues sans bloquer le rendu.';
    private const SCENE2D_RULE = 'SCÈNES 2D PILOTÉES PAR LES DONNÉES. FORMAT nodes (le même pour sonic-3d, sonic-stage, sonic-2d) : clé nodes d’un DataProvider = {nom: {x, y, rz (RADIANS), scale (n ou [x,y]), opacity, visible, z (ordre d’empilement), color, ease: {ms, curve: linear|in|out|inOut}}, "$reset": true} ; PATCH (champ absent inchangé), republier la même valeur ne fait rien, 200 nœuds max. '
        .'PRODUCTEURS (un par DataProvider) : sonic-physics nodes-plane="screen" (→ worldState.nodes), reducer d’un store, sonic-timeline nodes-out="anim", sonic-p2p. Le SDUI crée les éléments, le producteur décide où ils sont, le rendu les place. '
        .'A) DOM : <sonic-stage dataProvider="worldState" width="800" height="450" target="jeu" pick-names="^b[0-9]$"> + enfants SDUI avec attributes.id = nom du nœud : (x, y) = CENTRE de l’élément dans la scène (mêmes width/height que le sonic-physics : les éléments HTML sont exactement les corps) ; layout="offset" = simple décalage depuis la mise en page. Le style vient du SDUI (la scène ne touche qu’à transform, opacity, visibility, z-index, color) ; un élément qui apparaît (sonic-if, sonic-states) reçoit l’état de son nœud. '
        .'B) WebGL (PixiJS : jeux, beaucoup d’objets, 2.5D) : <sonic-2d dataProvider="worldState" camera="jeu.camera" width="800" height="450" background="#0b1020" layers="ciel:0 decor:0.35 monde:1 hud:0" target="jeu" pick-empty pick-names="^b"> + enfants <sonic-sprite name shape="box (w h radius)|circle (r)|polygon (points=\'x,y …\' relatifs au centre)|text (text font-size font ; emoji acceptés)|image (src data:image/…;base64 ou https://, w h)" fill stroke line x y angle (°, position DE DÉPART) opacity layer z hidden> '
        .'(vocabulaire de sonic-body) ou sprites="jeu.decor" (liste du store, ajouts/retraits suivis, 500 max). layers : nom:parallaxe[:y] du fond vers l’avant, 1 suit la caméra (monde), 0 fixe à l’écran (score, aide), entre les deux = décor lointain, :y trie par y (vue de 3/4) ; défaut un calque "monde". '
        .'CAMÉRA {x, y, zoom, ease} : point du monde au centre de l’écran, écrite par le reducer (attribut camera="jeu.camera" ou clé camera du dataProvider) ; un monde plus large que la vue se parcourt en la déplaçant. '
        .'TAPS (les deux rendus, comme sonic-3d) : {type: pick-action-type (défaut "pick"), payload: {objectId: nom, x, y}} (coordonnées du monde) vers le store target ; pick-names = regex des noms acceptés ; pick-empty envoie aussi {objectId: null, x, y}. '
        .'PIÈGES : (1) le mouvement vient de nodes, jamais d’un changement de x/y du sprite ; (2) les noms des sprites / id des éléments = noms des corps ; (3) pas de JSONata entre producteur et rendu ; (4) sonic-2d : 4 par page (WebGL) ; out-data-provider {status loading|ready|error, count, missing (nœuds sans sprite), errors} ; (5) SVG non déclarable en SDUI : utiliser sonic-2d (polygon) ou du HTML.';

    /** Mémo de l'addon `audio` (synthèse modulaire, séquenceur, sampler, analyseur). */
    private const AUDIO_RULE = 'Un seul moteur audio par page, démarré au premier geste (sonic-audio-unlock = bouton d’invite). '
        .'INSTRUMENT : sonic-patch preset="synth/lead|bass|pad|pluck|fm-bell|chip|drums/kick|snare|hat|kit" params=\'{"cutoff":900}\' (cutoff/reso, pad : attack, pluck : decay, chip : pw) events="dp.notes" trigger="dp.tick" (trigger = valeur qui change → rejoue events ; sans trigger : joué quand la liste change). '
        .'Événement : {note:"C4"|60, vel, durS, sample:"kick", type:note|noteOn|noteOff|param, when, id}. drums/kit : samples kick snare clap hat openhat. '
        .'PATCH MAIN : <sonic-patch><sonic-voice> modules joués par note </sonic-voice> modules globaux </sonic-patch>. Modules : sonic-osc (wave sine|square|sawtooth|triangle|pulse, freq-hz=voice.pitch, detune, octave, semi, level, fm, fm-amount), sonic-noise (color), sonic-mixer (in, levels), sonic-filter (type, freq-hz, q), sonic-vca (gain), sonic-env (a d s r), sonic-lfo (rate-hz | sync="1/8"), sonic-shaper, sonic-pan, sonic-delay (time s|"3/16", feedback, mix), sonic-reverb (size-s, mix), sonic-chorus, sonic-comp. '
        .'Câblage : name + in="o1 o2" (sinon module précédent ; global : la somme des voix). Modulation : sonic-mod from="env|lfo|voice.vel|voice.pitch|voice.rand" to="flt.freq-hz" amount (curve="exp" : demi-tons, seulement vers freq-hz de sonic-osc|filter|ladder|karplus|resonator) ; raccourci gain="aenv". PROFONDEUR D’UN LFO / D’UNE ENVELOPPE : elle n’existe pas sur le LFO, c’est l’amount (nombre fixe) du sonic-mod. Pour la régler avec un potard : NOMME le câble et pilote-le, <sonic-mod name="vib" from="lfo" to="o1.detune" amount="0"/> + <sonic-param to="vib.amount" source="ui.vib" min="0" max="100"/> (amount = quantité, demi-tons si curve="exp" ; ou to="vib.depth" = multiplicateur, défaut 1 ; plusieurs sonic-mod peuvent porter le même name : un seul depth règle le groupe, idéal pour une matrice d’expression au lieu d’un sonic-vca par case). Jamais sonic-param to="lfo.amount", ni amount="dp.chemin" dans le sonic-mod. UNE SEULE ERREUR DE PATCH = AUCUN SON (le compilateur refuse tout) : donne un name à chaque module visé par un to/from ; to="module.paramètre" avec un paramètre modulable (freq-hz, detune, level, gain, res, q, pan, time, feedback, rate-hz…) ; un sonic-lfo sync="1/8" n’a plus de rate-hz modulable ; un module de voix ne module pas un module global. Le contrôle à la publication te renvoie ces erreurs.  sonic-param to="flt.freq-hz" source="dp.cutoff" ramp-s min max | expose="cutoff". Boucle audio seulement via sonic-delay. Kit : plusieurs sonic-voice sample="kick" note="C2". '
        .'SÉQUENCEUR : sonic-sequencer bpm swing seed control="dp.transport" ({playing,bpm,swing,pattern}) pattern=\'{"kit":{"kick":"x...x...x...x...","hat":"[..x.]*4"},"bass":{"notes":"0 ~ 2 [4 7]","scale":"a2:minor-pentatonic"},"lead":"<c5 e5 g5>"}\' (clé = id d’instrument ; ligne = 1 mesure : grille x/X/., [ ] sous-division, ~ silence, - tenue, <a b> alternance, *n, !n, @n, ?p, x(3,8,r) euclide, c4+e4 accord, degrés avec scale). '
        .'store="idStore" : le reducer reçoit {type:"step", payload:{step,beat,bar,phase,when,bpm}} en avance et écrit des notes avec when (augmenter budget-ms / lookahead-ms si le reducer est lourd). État <id>State : {playing, step, beat, bar, phase}. '
        .'SAMPLER : sonic-sampler samples=\'{"kick":"https://…/kick.wav","voix":{"ref":"rec.last","root":"C4"}}\' (https uniquement ; gain pan pitch root start end loop reverse gate) choke=\'[["hat","openhat"]]\'. '
        .'ENREGISTRER : sonic-audio-recorder id="rec" source="#mic|#id|master" control="dp.rec" ({recording: true|false, target: "takes.A"}) max-s ; chaque prise {url, mime, durS, size} est écrite dans target, rejouable par sonic-sampler samples=\'{"A":{"ref":"takes.A"}}\' (pads vides listés dans padsState.empty) ; état recState {status: waiting-source|ready|recording, elapsedS, last, takes}. '
        .'MIDI ("capabilities": ["midi"]) : sonic-midi id="midi" démarré par sonic-media-start for="midi" (ou sonic-audio-unlock start="midi") ; ENTRÉE input="all|nom" channel mpe (LinnStrument, Seaboard : bend/pression/timbre par note) target="#voix" (notes jouées directement ; dans le patch : sonic-mod from="voice.pressure|voice.timbre|voice.bend") store="idStore" ({type:"midi", payload:{kind: noteOn|noteOff|cc|program|start|stop|beat, note, name, vel, ch, cc, value}}) ; état midiState {status, inputs, held[{name, bend, pressure, timbre}], last, notes, cc{"74":0.5}, clock{running, bpm, beat}}. '
        .'SORTIE output="nom d’appareil" out-channel : c’est un instrument (sonic-sequencer pattern=\'{"midi":"c3 e3 g3"}\' joue sur la machine), cc-out="dp.knobs" ({"74":0.5}), clock-out="#seq" (horloge 24 ppq + Start/Stop) ; control {active, input, output, channel, program, panic: compteur}. Horloge d’une machine : sonic-sequencer sync="#midi" (Start/Stop/tempo/phase externes ; état sync {locked, driftMs}). '
        .'MODULES AVANCÉS : sonic-osc sync="m" (synchro dure sur l’osc m, balayer s.freq-hz par une enveloppe curve="exp"), sonic-ladder (freq-hz, res 0..1.2 auto-oscillant, drive), sonic-fold (amount 0..12, bias), sonic-karplus (corde : decay, damp ; suit voice.pitch et le bend), sonic-resonator (banc modal à exciter : bruit, entrée ; q, partials "1 2.76 5.4" et gains modifiables PENDANT LE JEU par sonic-param source (liste ou chaîne) : changer de corps sans gabarits ni recompilation ; jitter = cents aléatoires par partiel et par note), sonic-grain sample="https://…|takes.voix" (granulaire : position 0..1, spread, size-s, density grains/s, pitch, jitter ; réglables en direct par sonic-param source="dp.pos"). Presets : synth/string, synth/sync-lead, synth/acid. Les 4 premiers utilisent un AudioWorklet chargé avant le premier son (repli natif annoncé dans warnings). '
        .'GROS PATCH PILOTÉ (beaucoup de sources, de cases de modulation, de corps) : une voix = un graphe WebAudio créé à chaque note, son coût = ses modules. sonic-patch prune : un sonic-vca à gain 0, une source à level 0 (sans modulation) ou un câble nommé de depth 0 ne sont pas construits, ni ce qui ne nourrit qu’eux (valeur remontée = note suivante). active="false" sur un module de voix, ou sonic-param to="flt.active" source="dp.b", le retire des notes suivantes. ALÉA PAR NOTE : sonic-param to="env.d" random="0.2" (±20 %) et/ou random-abs="15" (± unités : cents, Hz), tiré pour chaque voix, aussi sur a/d/r/q ; sonic-patch seed="7" = tirages reproductibles. CHARGE : sonic-audio-load id="load" → DP loadState {status, average, peak, underrun} (0..1, Chrome ; unsupported ailleurs). '
        .'EFFET SUR UNE ENTRÉE : patch sans sonic-voice : <sonic-patch id="clean" output="none"><sonic-audio-input source="#mic"/> sonic-filter… </sonic-patch>, puis enregistrer / analyser "#clean" (output="none" avec le micro : pas de Larsen) ; état inputs {nom: true} une fois branché. '
        .'ANALYSEUR : sonic-audio-analyser id="spectre" source="master|#id" → DP spectreState {rms, peak, db, bands[], centroidHz, onset, onsetCount, pitchHz(attr pitch)} ; sonic-shader channel0="#spectre" : texture(iChannel0, vec2(x,0.25)).r spectre, vec2(x,0.75) onde. '
        .'États : <id>State de chaque composant (status idle tant que le son n’est pas actif, errors lisibles). Volumes : garder gain ≤ 0.6 par instrument, le master est limité.';

    /**
     * Raccourcis de modèles déclarés par le viewer (ladigitale/artifacts, src/app/hf-config.ts).
     * Facultatifs : `model` accepte aussi n'importe quel `org/nom` (ou URL huggingface.co/org/nom),
     * que le viewer résout via l'API du Hub avant le rendu (tâche, révision, dtype, taille).
     *
     * @var array<string, string> alias => description (reprise dans les règles du catalogue)
     */
    public const HF_MODEL_ALIASES = [
        'depth-small' => 'Xenova/depth-anything-small-hf : photo → carte de profondeur, ≈ 27 Mo',
        'zero-shot' => 'Xenova/mobilebert-uncased-mnli : classement de texte anglais, ≈ 25 Mo',
    ];

    /** Mémo de l'addon `animation` (sonic-animate, sonic-clock, sonic-timeline). */
    private const ANIMATION_RULE = 'ANIMATION sans JS : le temps est une valeur de DataProvider. COMMENCE PAR sonic-animate recipe="reveal|fade|slide-up|count-up|typewriter" trigger="viewport|load|data|manual" (défaut viewport) stagger="0.12" duration once="true|false" ease="linear|easeIn|easeOut|easeInOut" : il enveloppe ses enfants DIRECTS et les anime, sans horloge ni JSON ; trigger="manual" se lance par control="dp.anim" ({n} : une hausse de n lance ou relance, via un sonic-action) ; out-data-provider="dp.animState" publie {status: idle|running|ready|error, progress, done, runs} ; '
        .'count-up : from to decimals text-prefix text-suffix (le nombre s’écrit dans le texte de l’enfant) ; trigger="data" + watch="dp.chemin" ; time-source="dp.t" remplace l’horloge locale. '
        .'CHORÉGRAPHIE SUR MESURE : sonic-clock dataProvider="clock" duration="4" mode="once|loop|pingpong" rate autoplay="true" écrit clock.t (0..1), time, duration, playing, rate, direction ; écrire playing, rate ou t le pilote (un t écrit de l’extérieur le met en pause = scrub). '
        .'sonic-timeline time-source="clock.t" descriptor=\'{"duration":3,"root":{"type":"seq","nodes":[{"type":"tween","targets":["titre"],"props":{"opacity":[0,1],"y":[24,0]},"duration":0.8,"ease":"easeOut"}]}}\' (descriptor = JSON EN CHAÎNE, 16 Ko max ; nœuds : seq | par | stagger{each} | tween{targets, props{prop:[de, à]}, duration, delay, ease} | text{mode count|type, targets, from, to, decimals, prefix, suffix} | set{at, path, value ; jamais dans le DP du temps} | label{name, at} ; '
        .'targets : "id", "children:id", "group:nom" (éléments data-anim-group="nom"), "@self" ; smooth="0.08" lisse un temps venu du réseau ; out-of-range clamp|wrap). Sans time-source une timeline ne bouge jamais. '
        .'UN SEUL écrivain par DP de temps : une horloge OU un slider (sonic-input type="range" min="0" max="1" step="0.001" name="t" formDataProvider="scrub" puis time-source="scrub.t") OU une sortie sonic-p2p OU un analyseur audio (audio-fx.rms). '
        .'RÈGLES : chaque élément animé porte attributes.id (et nodeId si utile) ; propriétés animables opacity, x, y, scale, rotate, blur (nombres SANS unité) et variables CSS --nom, jamais width / height / top / left ; texte animé = textContent du nœud (écrit en texte brut) ; prefers-reduced-motion respecté (état final direct) ; '
        .'MODE nodes : nodes-out="anim" publie l’état des cibles dans anim.nodes (format nodes : x, y, scale, opacity ; rotate en degrés devient rz en radians) au lieu du DOM ; les cibles sont alors des NOMS de nœuds (élément de <sonic-stage dataProvider="anim">, sprite de sonic-2d, nœud glTF de sonic-3d) ; pas de children:/group: dans ce mode. '
        .'PHOTO ARTICULÉE (une personne photographiée qui danse) : sonic-camera + sonic-frame-sync id="held" control="app" (image figée quand app.hold) + sonic-segmenter + sonic-pose-tracker id="pose" (mirror="false" partout) + sonic-mask-replace id="personne" effect="cutout" target="background" grow="-3" style="display:none" (+ un second id="plan" effect="fill" target="subject" grow="8" : le décor sans la personne, posé dessous ; grow = px, positif sur le fill et négatif sur le cutout pour qu’aucun contour de découpe ne se voie dans le fond) ; <sonic-puppet source="#personne" pose="pose" frame="#held" capture="app.hold" nodes-out="rig" drive="anim.nodes" drive-prefix="d_" width="800" height="600"> mesure le squelette SUR la personne (jamais un préréglage) et publie rig.rest / rig.bones ; <sonic-2d dataProvider="rig" width="800" height="600"> + <sonic-sprite shape="mesh" source="#personne" x="400" y="300" w="800" h="600" grid="32,24" falloff="3"> déforme l’image par poids entre les os, sans trou aux articulations ; la timeline écrit d_<os, tirets en _> en rotate degrés (d_torse, d_bras_gauche, d_avant_bras_droit, d_cuisse_gauche…, ajoutés à la pose de la personne). Seules les parties vues existent (un buste suffit) ; rig.status.phase ready|error (error : source, pose, corps, silhouette). CADRAGE : zoom="auto" camera-drive="anim.nodes.cam" sur sonic-puppet publie rig.camera centrée sur le corps (sonic-2d sans attribut camera la lit). VISAGE : id="pantin" face="true" (yeux qui clignent seuls, bouche par d_bouche en y 0..1, paupières par d_paupieres) ; le sprite mesh lit alors source="#pantin". IMAGE ENVOYÉE au lieu de la caméra : sonic-file-open ref-provider="fichier" + sonic-still id="photo" in-data-provider="fichier" hidden-preview, et source "#photo" dans le store de control du frame-sync ({hold, source}). RELIEF : frame-sync snapshot-provider="photoFigee" → sonic-hugging-face-infer model="depth-small" inputProvider="photoFigee.url" outputProvider="profondeur" → sonic-still in-data-provider="profondeur.depth" en channel1 d’un sonic-shader qui déplace le décor. mode="pieces" : pièces rigides (rig.sprites, sonic-2d sprites="rig.sprites") pour un pantin aux articulations nettes ; armature + unit : armature libre (animal, personnage dessiné). 1 sonic-puppet par page. '
        .'un descripteur invalide rend la timeline muette (état out-data-provider : status error + issues {path, field, message, fix}). Limites : arbre de 8 niveaux, 200 cibles par tween, 600 s au total.';

    /** Mémo de l'addon `device` (API navigateur en DataProviders). */
    private const DEVICE_RULE = 'API NAVIGATEUR EN DATAPROVIDERS (composants sans rendu, sans JS) : sonic-fullscreen (bouton « Plein écran » : un sonic-button DANS sonic-fullscreen, clic = bascule, enfants swap="off"/"on" pour l’icône ; target="id" ou sélecteur, défaut ancêtre data-fullscreen-root sinon la page ; fallback simulate (défaut, iPhone : plein écran simulé en position fixe 100svh) | none ; aussi for, control {enter, exit, toggle} ; état active, simulated) ; sonic-pointer-lock (souris verrouillée, visée : control {lock, unlock, toggle, reset}, état dx dy x y raw, rate) ; sonic-wake-lock (écran allumé : control {acquire, release, toggle}, auto) ; '
        .'sonic-orientation (control {lock{type: landscape|portrait|…}, unlock, sensors, stop} ; état type angle alpha beta gamma ax ay az ra rb rg, smoothing, rate) ; sonic-geolocation (watch ou control {start, stop, once}, for = démarre dans le clic ; places="dp.lieux" = liste [{id, lat, lon, …}], radius m ; état lat lon accuracy (m, 5 à 15 en ville) heading speed, nearby [{…lieu, distance, bearing}] trié, nearest) ; '
        .'ANCRAGE SUR LE RÉEL (contenu accroché à une surface plane vue par la caméra : façade, panneau, vitrine, sol) : sonic-anchor-ref id="pose" source="#cam" for="btnPhoto" gravity="orientState" geo="geoState" (photo, 4 coins déplaçables au doigt ; poseState {reference (JSON à stocker), zone, points, quality none|weak|ok|good, thumb}) ; sonic-anchor id="ancre" source="#cam" reference="poseState.reference" (ou une liste de références) gravity="orientState" active (ancreState {status searching|tracking|holding, found, refId, corners [{x,y}×4] 0..1, inliers, confidence, fps}) ; sonic-anchor-view anchor="ancreState" fit="cover" content-width="800" content-height="600" avec le contenu à projeter en enfants (sonic-shader, texte, image), dans le MÊME conteneur position:relative que la sonic-camera (facing="environment", même fit). Surface unie = weak : demander une zone texturée. GARDER DES BALISES : sur sonic-anchor-ref, target="balises" (store) save-for="btnGarder" extra="brouillon" (DP dont les clés vont dans l’entrée : message, effet…) embedding="emb" → action save {id, createdAt, lat, lon, accuracy, points, ref, …extra} (réducteur : $append dans items ; remove par id), store gardé par data.persist {providers:["balises"], version, online} avec maxStateBytes 1200000 (30 à 50 Ko par balise) ; sonic-anchor reference="balises.items" (item = champs de l’entrée trouvée, ex. ancreState.item.message), within="geoState" (sonic-geolocation places="balises.items" : seulement les lieux proches) et embedding="emb" (un sonic-image-embedder id="emb" for="cam" partagé : candidates classées par ressemblance). '
        .'sonic-clipboard (control {copy{text}, read}) ; sonic-share (control {share{title, text, url http(s)}}, repli par copie) ; sonic-vibrate (control {vibrate{pattern:"100,50,100"}, stop} ; absent sous iOS) ; '
        .'sonic-page-state (visible, focus, connexion, taille, préférences : sans permission ni commande) ; sonic-capabilities (capsState {status, embedded, secureContext, fullscreen, wakeLock, pointerLock, orientation, clipboard, share, vibrate, gamepad, midi, camera, microphone, audio, screen, bluetooth, chacun {supported, allowed, permission}} : à lire pour n’afficher que les commandes utilisables). '
        .'Chaque composant publie <id>State (ou out-data-provider) {status: idle|requesting|ready|denied|error|unsupported, error, supported, allowed, active, …} et se pilote par COMPTEURS dans control : {toggle: 3} puis {toggle: 4} déclenche une fois (la 1re valeur vue sert de référence) ; avec arguments {share: {n: 2, text: "…"}}. '
        .'GESTE OBLIGATOIRE : plein écran, pointer lock, vibration, partage et capteurs iOS exigent un clic. Utilise for="idDuBouton" (l’action part dans le clic même) ou un compteur écrit par un bouton (sonic-action → reducer, fenêtre de quelques secondes) ; une commande lancée par un minuteur, au chargement ou par un agent est refusée (status denied). '
        .'Aucune entrée à déclarer dans capabilities (sauf Bluetooth : règle « bluetooth », et port série : règle « laser »). Prévoir un repli quand supported vaut false ou status denied. En intégration (iframe hôte) la fonction doit être autorisée par la page : allowed le dit.';

    /** Mémo de l'addon `bluetooth` (appareils Bluetooth LE, imprimante d'étiquettes). */
    private const BLUETOOTH_RULE = 'BLUETOOTH (Web Bluetooth : Chrome / Edge / Android en HTTPS ; PAS d’iPhone, ni Firefox, ni Safari) : déclarer "capabilities": ["bluetooth"] (sinon refusé). '
        .'Transport : <sonic-bluetooth id="bt" profile="…" for="idBouton" auto-reconnect> ; le CLIC ouvre le sélecteur du navigateur (jamais au chargement). '
        .'profile : luck (imprimante d’étiquettes Fichero d’Action, AiYin D11s), niimbot (étiquettes Niimbot D11, D110, D101, B1, B21, B18), escpos (tickets), uart-nus (ESP32, micro:bit, Espruino), uart-issc, uart-hm10, heart-rate, battery, csc, cycling-power ; plusieurs profils séparés par des espaces (profile="luck niimbot" : Fichero ou Niimbot avec le même bouton, btState.profile dit lequel) ; '
        .'sans profil : filters=\'[{"namePrefix":"X"}]\' + channels=\'{"tx":"service/caractéristique","rx":"…"}\' (UUID courts ou complets). '
        .'État btState {status: idle|requesting|ready|denied|error|unsupported, error, supported, connected, device{name}, values{rx:{hex, bytes, text, n}}, rx (compteur de notifications), reconnecting}. '
        .'Commandes par compteurs dans control : {connect, disconnect, toggle, write:{n, hex|bytes|text, channel}, read:{n, channel}}. '
        .'IMPRIMER UNE ÉTIQUETTE : <sonic-label-printer id="imp" transport="#bt" source="#dessin" label-mm="30x12" for="imprimer" show-preview style="width:240px"> ; '
        .'source = #id d’une source d’images (sonic-sketch, sonic-paint, sonic-still, sonic-2d, sonic-shader, canvas, img) ; dessiner dans le sens de lecture, au rapport de l’étiquette (30x12 → 240 x 96 points, 5:2) ; '
        .'dither threshold (texte, traits, QR ; défaut) | floyd (photo) | atkinson | bayer, threshold 0..255, density 0..2, copies 1..50, orientation landscape|portrait, flip (demi-tour) ; protocol auto (défaut : d’après l’appareil, Fichero ou Niimbot) ; Niimbot NON TESTÉ sur matériel : si rien ne sort, imposer protocol niimbot-d110 | niimbot-b1 | niimbot-d11v1 | niimbot-b21v1 | niimbot-v4 (B1 et B21 : tête 384 points, label-mm="50x30" par défaut) ; réglages aussi en direct dans control {dither, threshold, copies, labelMm}. '
        .'show-preview affiche l’aperçu 1 bit EXACT (mêmes points que sur le papier). État impState {status, error (capot ouvert, plus de papier…), model, battery, paper, cover, printing, progress, jobs (compteur), preview{width, height, ink}, issues}. '
        .'Interface : bouton « Connecter l’imprimante » (for du sonic-bluetooth), bouton « Imprimer » visible quand impState.status vaut ready (sonic-if), message d’erreur lisible, repli si btState.supported vaut false (« Ouvrez cette page dans Chrome ou Edge »). 2 sonic-bluetooth max par page.';

    /** Mémo des addons `serial` et `grbl` (port série, graveur / découpeuse laser GRBL). */
    private const LASER_RULE = 'LASER ET PORT SÉRIE (Web Serial : Chrome / Edge sur ordinateur, Chrome Android récent, en HTTPS ; PAS d’iPhone, ni Firefox, ni Safari) : déclarer "capabilities": ["serial"] (sinon refusé). '
        .'Trois composants en chaîne : <sonic-serial id="port" profile="ch340" for="connecter" auto-reconnect> (transport ; le CLIC ouvre le sélecteur de port, jamais au chargement ; profile ch340 = graveurs Atomstack, Ortur, Sculpfun ; grbl = tous les ponts courants ; cp210x, ftdi, arduino, esp32) ; '
        .'<sonic-gcode id="prog" source="#dessin" width-mm="60" power="25" speed="2000" auto show-preview control="reglages" style="width:320px"> (dessin → programme G-code) ; '
        .'<sonic-grbl id="laser" transport="#port" source="#prog" machine="atomstack-a5-pro" control="laserCtl" for="lancer"> (machine GRBL 1.1 ; machine auto = zone lue sur la machine). '
        .'sonic-gcode : source = #id d’un sonic-sketch (ses surfaces de couleur sont HACHURÉES = gravure) ou d’une source d’images (sonic-still, sonic-paint, sonic-camera : gravure par balayage : dither floyd|atkinson|jarvis|stucki|burkes|sierra|sierra-lite|bayer|random|threshold|adaptive|gray, ou en TRAITS hatch|waves|spiral|isolines|stipple avec lineSpacing (mm) et levels ; préparer l’image en amont par sonic-shader, shaders de bibliothèque niveaux (luminosité, contraste, gamma, éclairage local) et pile-effets (contours, encre, crayonné…), source="#effet") ; svg-from = DP d’un fichier SVG ouvert par sonic-file-open (ref-provider) ou du SVG en texte ({svg:"<svg…>"}) : trait (stroke) suivi, surface (fill) hachurée. '
        .'Réglages par défaut power (% de la pleine puissance), speed (mm/min), passes, mode auto|line|fill|skip, interval (mm entre lignes, 0.1), kerf ; PAR COULEUR : ops=\'[{"color":"#000000","mode":"fill","power":20,"speed":3000},{"color":"#ff0000","mode":"line","power":90,"speed":300,"passes":2}]\' exécutées dans l’ordre (gravure d’abord, DÉCOUPE EN DERNIER ; mode line sur une surface = son contour). Le blanc ne brûle pas. '
        .'Taille width-mm / height-mm (proportions gardées), position x-mm / y-mm, origine en bas à gauche. Mêmes réglages en direct dans control (camelCase : power, speed, passes, ops, widthMm, xMm…) avec les compteurs {build, download (fichier .gcode), clear}. '
        .'État progState {status, error, ready, kind vector|image, lines, bbox{x,y,w,h} mm, sizeMm{w,h}, estimateS, burnMm, issues[], colors[{color, fill, stroke}] (couleurs du dessin : en faire une liste où chaque couleur choisit son opération, puis ops)}. '
        .'Modèles prêts à graver : <sonic-laser-pattern id="modele" control="modeleCtl" out-data-provider="modeleState"> (control {pattern, params}) : grille (essais puissance × vitesse, fournit ops), boite, engrenages, puzzle, tampon, porte-cles, pendentif, dessous, regle ; état {svg, widthMm, heightMm, specs[{key, label, min, max, step, unit}], params, ops, notes[], catalog[{id, label, description}]} ; sonic-gcode svg-from="modeleState". Illustration : sonic-procgen render vector + compteur exportSvg → state-out.svg → svg-from. '
        .'sonic-grbl, commandes par compteurs dans control : {run, hold, resume, stop, frame (tour de l’emprise, faisceau éteint ; {n, power} 0..5 %), jog:{n, x, y} (mm, relatif), goto:{n, x, y}, setOrigin (l’origine devient la position de la tête), home, unlock (après une alarme), send:{n, line}}. '
        .'État laserState {status idle|requesting|ready|error, error, connected, firmware, machineState Idle|Run|Hold|Jog|Alarm, alarm{message}, pos{x,y}, area{x,y}, maxS, laserMode, job{kind, progress 0..1, running, paused, elapsedS, estimateS}, jobs (compteur), issues[], console[]}. '
        .'SÉCURITÉ intégrée, à ne pas contourner : run exige un vrai clic (for="lancer" ou compteur écrit par un bouton, jamais un minuteur), M3 est remplacé par M4, la tâche s’arrête si la page se ferme ; max-power="60" plafonne la puissance. '
        .'Interface conseillée, par étapes : 1 « Connecter » (for du sonic-serial ; repli si portState.supported vaut false : « Ouvrez cette page dans Chrome ou Edge sur ordinateur ») ; 2 réglages (matériau, puissance, vitesse, taille) et aperçu ; 3 placement : boutons de déplacement (jog ±1 / ±10 mm), « Origine ici » (setOrigin), « Cadrer » (frame) ; 4 « Lancer » (type danger), puis progression (laserState.job.progress), « Pause » / « Reprendre » / « Arrêter » ; afficher laserState.error, laserState.issues et progState.issues. '
        .'Rappeler à l’écran : lunettes de protection, ne jamais laisser la machine sans surveillance. Pilote NON TESTÉ sur matériel. Limites : 2 sonic-serial, 1 sonic-grbl, 4 sonic-gcode, 4 sonic-laser-pattern par page.';

    /** Mémo de l'addon `p2p` (salons temps réel). */
    private const ROOMS_RULE = 'SALONS TEMPS RÉEL (jeux, tableau blanc ; visiteurs d’un même artefact, sans compte) : UN SEUL sonic-p2p par page : <sonic-p2p id="net" pseudo="Ana" share="game.local" rate="20" control="net.ctl" store="jeu" message-action="net-msg">. N’écris NI artifact, NI api, NI link-token : le viewer les impose (toute valeur du document est ignorée). '
        .'share = DP dont la valeur locale est diffusée aux autres (rate envois par seconde, 20 par défaut, 60 max, la plus récente l’emporte) ; les valeurs des autres arrivent dans netPeers {idMembre: valeur} (peers-data-provider) : à lire dans un composant à part, c’est de la haute fréquence ; '
        .'état netState {status: idle|connecting|pending|ready|rejected|kicked|closed|error, error, rooms, room{id, name, access, hasCode, members, max, full, code? (créateur seulement)}, me{id, pseudo, host}, members[], pending[], received}. '
        .'COMMANDES : control = {cmd: {n: compteur, type, …}} exécutée à chaque HAUSSE de n : refresh (liste les salons publics → netState.rooms) | create {name, access: open|approval, code: true (généré) ou "mot" de 3 à 24 lettres/chiffres, listed, max 2..32 (8)} | join {roomId} ou {code}, pseudo facultatif | approve / reject / kick {id} (hôte) | leave (l’hôte qui part ferme le salon) | send {data} (message libre → action {type: message-action, payload: {from, pseudo, data}} au store). '
        .'Un salon avec code n’est pas listé ; access approval met chaque arrivant en attente (status pending) jusqu’à la décision de l’hôte. Prévoir l’interface : liste des salons, création, saisie de code, attente, salon complet (room.full). '
        .'LIMITES : message ≤ 64 Ko, pensé pour de l’état (quelques dizaines de Hz), pas du flux média ; un salon vit 6 h au plus et se ferme quand l’hôte part ou après 10 min d’inactivité ; ni chiffré ni authentifié au-delà du pseudo : AUCUN secret dans les messages. Temps partagé : time-source d’une sonic-timeline = valeur des pairs, avec smooth="0.08".';

    private const HF_RULE = 'INFÉRENCE LOCALE (Transformers.js, le calcul reste dans le navigateur) : <sonic-hugging-face-infer model="org/nom" inputProvider="dp.entree" outputProvider="dp.sortie" statusProvider="dp.statut" load="consent|first-use" trigger="mutation|demand" triggerProvider="dp.clic" options=\'{"call":{…}}\'>. '
        .'`model` : identifiant Hugging Face `org/nom` (ou https://huggingface.co/org/nom) d\'un modèle PUBLIC avec poids ONNX (ex. Xenova/…, onnx-community/…), ou un raccourci : %s. '
        .'Le viewer lit sa fiche sur le Hub : la tâche vient de son pipeline_tag. Tâches supportées : feature-extraction, text-classification, zero-shot-classification, translation, token-classification, fill-mask, question-answering, image-classification, zero-shot-image-classification, object-detection, zero-shot-object-detection, image-feature-extraction, image-segmentation, depth-estimation, image-to-text, audio-classification, automatic-speech-recognition. Pas de génération libre (text-generation, text-to-image, 3D…), pas de modèle gated, 600 Mo max. Un modèle refusé laisse le composant inactif (raison dans la console). '
        .'Entrée : texte, image (URL https, data URL, prise caméra snapshot-provider). Sortie en lecture seule, images produites {width, height, url} chaînables dans sonic-shader channel0 / sonic-image. '
        .'Statut statusProvider : {state: idle|empty|awaiting-consent|downloading|loading|ready|running|done|invalid-input|error|unsupported, progress:{percent}, size:{totalMB}, error:{code,message}} ; load="consent" : afficher une invite tant que state = awaiting-consent (consentProvider="dp.accepted", valeur "accepted"). Rien ne se télécharge tant que l’entrée est vide.';

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

    private const DEFAULT_CONCORDE_VERSION = '7.0.1';

    /** Règles longues, envoyées seulement sur demande (`rules`) quand on filtre. */
    /** Compétences (config/artifacts/skills/*.md) : servies à la demande par rules=["design", "<archétype>"], jamais par défaut. */
    public const SKILLS = ['design', 'vitrine', 'jeu', 'outil', 'dashboard', 'experience', 'multivue', 'carte'];
    public const LONG_RULES = ['listes', 'a2ui', 'son', 'audio', 'media', 'physique', '3d', '2d', 'procgen', 'monde', 'monde2d', 'mobilier', 'animation', 'appareil', 'bluetooth', 'salons', 'laser', 'ia', 'collecte', 'lieux', 'io'];

    private readonly ArtifactA2uiValidator $a2uiValidator;

    private readonly ArtifactIcons $icons;

    private readonly ArtifactPatchLint $patchLint;

    /** Bibliothèque (`library`) du descripteur en cours de validation, pour résoudre les libraryKey d'un patch. @var array<string, mixed> */
    private array $library = [];

    private readonly string $concordeVersion;

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
        ?ArtifactA2uiValidator $a2uiValidator = null,
        ?ArtifactIcons $icons = null,
        ?ArtifactPatchLint $patchLint = null,
    ) {
        $this->patchLint = $patchLint ?? new ArtifactPatchLint(\dirname($catalogPath).'/audio-patch.json');
        $this->a2uiValidator = $a2uiValidator ?? new ArtifactA2uiValidator();
        $this->icons = $icons ?? new ArtifactIcons(\dirname($catalogPath).'/icons.json');
        $data = $catalog ?? $this->loadJson($this->catalogPath);
        $tags = $data['safeHtmlTags'] ?? [];
        foreach ($data['components'] ?? [] as $component) {
            if (isset($component['name']) && \is_string($component['name']) && ArtifactUiRules::replacedTagMessage($component['name']) === null) {
                $tags[] = $component['name'];
            }
        }
        $this->concordeVersion = \is_string($data['concordeVersion'] ?? null) && $data['concordeVersion'] !== ''
            ? $data['concordeVersion']
            : self::DEFAULT_CONCORDE_VERSION;
        $this->allowedTags = array_values(array_unique($tags));
        $this->allowedTagSet = array_fill_keys($this->allowedTags, true);
    }

    /** Version de Concorde du catalogue (enregistrée sur chaque artefact créé ou mis à jour). */
    public function concordeVersion(): string
    {
        return $this->concordeVersion;
    }

    /** @return list<string> */
    public function allowedTags(): array
    {
        return $this->allowedTags;
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        $catalog = $this->loadJson($this->catalogPath);
        if (\is_array($catalog['components'] ?? null)) {
            // Un composant refusé par la validation n'est pas proposé.
            $catalog['components'] = array_values(array_filter(
                $catalog['components'],
                static fn (mixed $c): bool => !\is_array($c) || !\is_string($c['name'] ?? null) || ArtifactUiRules::replacedTagMessage($c['name']) === null,
            ));
        }

        return $catalog;
    }

    /**
     * Recettes d'interface nommées (fragments SDUI complets, à copier) : config/artifacts/recipes.json.
     *
     * @return array<string, array{title: string, when: string, stores?: array<string, mixed>, node: array<string, mixed>, notes?: string}>
     */
    public function recipes(): array
    {
        return $this->loadJson(\dirname($this->catalogPath).'/recipes.json');
    }

    private function recipesRule(): string
    {
        return 'Fragments SDUI validés, à copier puis adapter : '.implode(', ', array_keys($this->recipes()))
            .' (rules=["recettes"] → clé recipes : stores, node, notes). '
            .'select, sonic-select, button, input, textarea, form : refusés, le message d’erreur nomme la recette.';
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
    public function mcpCatalogPayload(bool $compact = true, ?array $components = null, ?array $rules = null, bool $examples = true): array
    {
        $withExamples = $examples;
        $examplesDir = \dirname($this->catalogPath).'/examples';
        $examples = [];
        if ($withExamples && is_dir($examplesDir)) {
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

        $payload = [
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
                'recettes' => $this->recipesRule(),
                'icone' => 'Optionnel : icône d’application, "icon": {"emoji": "📍", "background": "#111", "color": "#fff"} (ou "text": 1 à 3 lettres ; ou "src": "@asset:icone.png", carré 512 px) : favicon de l’onglet, couleur du navigateur et icône quand on installe l’artefact sur l’écran d’accueil (manifeste servi par l’API). Sans icon : initiale du titre sur fond sombre.',
                'page' => 'Optionnel : cadre de la page, "page": {"padding": "none"|"compact"|"normal" (défaut normal : marge du viewer ; none = plein cadre, pour un jeu ou une scène), "background": couleur CSS du fond de toute la page (#hex, rgb(), hsl(), nom ou var(--sc-…)), "nav": "tabs"|"none" (défaut tabs : barre d’onglets du viewer quand il y a plusieurs vues ; none = le document dessine ses onglets, voir la règle « navigation »)}.',
                'navigation' => 'views[].id = hash URL (#stats). defaultView si hash absent. views[].hidden = true : vue absente des onglets (accessible par son #id). '
                    .'Onglets : le viewer affiche un sonic-menu de sonic-menu-item radio branchés sur le DataProvider artifactView ({view} = id de la vue affichée), l’onglet actif est rendu par Concorde. Pour des onglets dans la palette de l’artefact : "page": {"nav": "none"} puis, dans les vues, {"tagName":"sonic-menu","attributes":{"direction":"row"},"nodes":[{"tagName":"sonic-menu-item","attributes":{"radio":true,"formDataProvider":"artifactView","name":"view","value":"stats"},"textContent":"Stats"}, …]} (à répéter dans chaque vue, ou dans une vue qui les contient toutes) ; lire la vue courante : sonic-value dataProvider="artifactView" key="view". Jamais de sonic-button à variant calculé pour des onglets.',
                'interactive' => 'sonic-store + keyboard/gamepad/gesture/action/ticker + sonic-matrix. Câblage des données : voir la règle « donnees ». '
                    .'sonic-gesture : pan (dx,dy px), pinch (dScale, cx, cy), wheel, drag (fraction cumulée).',
                'donnees' => 'Un DataProvider (DP) est un nom : les composants le lisent avec dataProvider="nom", les actions l’écrivent avec target="nom". '
                    .'DÉCLARER : (a) data.stores.<nom> = {initial: objet, reducer: JSONata ($state, $action = {type, payload}), dataProvider?: défaut = nom}, présent quelle que soit la vue ; '
                    .'ou, dans une vue, <sonic-store id="nom" initial="<JSON en chaîne>" reducer="…"> ; (b) data.transforms.<nom> = {jsonata} : publie le résultat, peut lire les data.sources par leur nom ; (c) data.sources.<nom> = {collection} : publie {member:[…]}. '
                    .'LIRE : sur le composant d’affichage, dataProvider="nom" + key="chemin.pointé" (ex. "cur.answers.0") : sonic-value (affiche le texte), sonic-if (+ equals="valeur", comparée en chaîne : equals="false"), sonic-matrix (key = tableau de cellules), sonic-shader (le DP fournit les uParam). '
                    .'ÉCRIRE : <sonic-action target="nom du store" type="nom-action" payload=\'{"a":1}\'> enveloppe le bouton ; l’action part au clic, comme un bouton Concorde (trigger="pointerdown" seulement pour les commandes de jeu et les pads d’instrument, implicite avec hold-repeat) ; le reducer reçoit $action.type et $action.payload (objets en chaîne JSON dans les attributs). '
                    .'CHOISIR (segment, filtre, onglet, réglage, sélection) : pas de sonic-action ; un sonic-button ou un sonic-menu-item avec formDataProvider="nom" name="clé" value="valeur" radio écrit la clé dans le DP au clic et s’affiche actif quand la clé vaut sa valeur (état sélectionné rendu par Concorde, rien à lier) ; sonic-group formDataProvider="nom" pour une rangée de boutons ; unique = radio désélectionnable ; sans radio = bascule. Valeurs par défaut : data.stores.nom = {initial, reducer: "$state"} ; les valeurs sont des chaînes ($number pour les nombres). Ce qui en dépend (données filtrées, descripteur de graphique, vue de carte, scène) = sonic-jsonata inputProvider="nom". Un même store peut recevoir aussi des actions (clic sur la carte, pick 3D) : radios et actions coexistent. Détail : compétence design §1 bis. '
                    .'TEXTES : texte FIXE = "textContent" du nœud (texte brut, 4 Ko max, ignoré si le nœud a des enfants ; jamais de HTML), ex. {"tagName":"h2","textContent":"Bienvenue"} ; texte QUI CHANGE = sonic-value lisant un store (reducer "$state" pour des valeurs initiales) ou une transform. "nodeId" (lettre puis lettres, chiffres, _ ou -, 64 max) nomme un nœud sans toucher à son id DOM. '
                    .'PIÈGES : (1) le nom d’un dataProvider ou d’un target n’est pas vérifié par la validation : une faute de frappe donne un composant vide sans erreur, relis chaque nom contre data.stores / data.transforms / data.sources / sonic-store id ; '
                    .'(2) pas de textes dans le store relié par dataProvider à un composant qui en recopie les clés en propriétés (sonic-shader) : mets-les dans un store séparé ; '
                    .'(3) une liste de longueur variable (cartes, lignes, résultats filtrés) se fait en SDUI avec sonic-list et un gabarit <template> : voir la règle « listes ». '
                    .'DONNÉES RÉELLES : l’atelier récupère des données avec search_public_apis / fetch_public_api (catalogue d’APIs publiques, pas d’URL libre) et les copie dans data.stores.<nom>.initial : la page publiée affiche un instantané, elle n’appelle pas ces APIs.',
                'alertes' => 'PAS DE TEXTE D’AIDE OU D’ALERTE GRIS PERMANENT (petit texte en opacité réduite, « astuce », « remarque », avertissements sous les boutons) : personne ne les lit. Interface au service de l’action en cours : un libellé de bouton clair suffit. '
                    .'Un retour qui doit être vu passe au moment où il sert : <sonic-notify watch="store.notice" status="error|success|warning|info"> montre un toast quand la valeur change (texte ou {text, label, status}) — le réducteur écrit notice à l’événement (erreur, confirmation) ; '
                    .'une décision ou un avertissement bloquant = sonic-modal dataProvider="dlg" (le réducteur met dlg.visible à true pour l’ouvrir ; sonic-modal-title / -content / -actions, un sonic-button hideModal dans sonic-modal-actions la ferme). Les demandes d’accès (caméra, micro, Bluetooth, position) partent d’un clic : le navigateur affiche sa propre demande, ne pas l’annoncer par un texte.',
                'listes' => self::LIST_RULE,
                'son' => self::SOUND_RULE,
                'audio' => self::AUDIO_RULE,
                'media' => self::MEDIA_RULE,
                'physique' => self::PHYSICS_RULE,
                '3d' => self::SCENE3D_RULE,
                '2d' => self::SCENE2D_RULE,
                'procgen' => self::PROCGEN_RULE,
                'monde' => self::MONDE_RULE,
                'monde2d' => self::MONDE2D_RULE,
                'mobilier' => self::MOBILIER_RULE,
                'animation' => self::ANIMATION_RULE,
                'appareil' => self::DEVICE_RULE,
                'bluetooth' => self::BLUETOOTH_RULE,
                'laser' => self::LASER_RULE,
                'salons' => self::ROOMS_RULE,
                'ia' => $this->huggingFaceRule(),
                'a2ui' => 'Vue A2UI v0.9 (alternative à root) : views[].a2ui = liste de messages {"version":"v0.9", createSurface|updateComponents|updateDataModel|deleteSurface}. Catalogue de base uniquement (catalogId '.ArtifactA2uiValidator::BASIC_CATALOG_ID.'). Composants : '.implode(', ', ArtifactA2uiValidator::SUPPORTED_COMPONENTS).'. Props : littéraux ou {"path":"/abs"} ; pas de call. CheckBox (value booléen), ChoicePicker (options [{label, value}], value = liste de chaînes, variant mutuallyExclusive|multipleSelection), Slider (min, max, value nombre), DateTimeInput (enableDate, enableTime, value ISO), Tabs (tabs [{title, child}]), Modal (trigger, content) ; libellés en texte littéral. Liste à gabarit : "children": {"path":"/items","componentId":"item"} — le sous-arbre "item" est répété par élément, chemins relatifs ("name") résolus sur l’élément ; imbrication possible. Composants dans n’importe quel ordre, "root" obligatoire. views[].actionStore = id d’un data.stores : un clic sur action.event devient {type: name, payload: {...context résolu, surfaceId, sourceComponentId}} dispatché au store (reducer, sinks). views[].a2uiBindings = {"/booking": "booking"} : l’état du store est recopié dans le data model des surfaces à ce chemin à chaque changement (lire avec {"path":"/booking/count"}). Gabarits SDUI chat:answer / chat:result-card / chat:confirm / chat:short-form / chat:actions et a2ui:<Composant> disponibles en libraryKey dans les vues root.',
                'shader' => 'sonic-shader (format ShaderToy, GLSL ES 3) : image="void mainImage(out vec4 o, in vec2 f){…}". '
                    .'Uniformes disponibles sans les déclarer : iResolution, iTime, iMouse (z > 0 pendant l’appui), iChannel0…3 et uParam0…uParam3 (float). '
                    .'RÉGLAGES : uParamN lit la propriété paramN du composant (4 max, nombres). Soit fixe : param0="0.5" en attribut ; soit pilotée : dataProvider="nom" sur le sonic-shader, '
                    .'les clés param0, param1, param2, param3 du store « nom » sont recopiées dans ses propriétés et uParamN suit chaque changement en direct. '
                    .'Exemple : store {"id":"scene","initial":{"param0":1,"param1":4,"param2":0.6}} dont le reducer modifie ces clés selon $action (inc / dec / reset), '
                    .'<sonic-shader dataProvider="scene" image="…float t = iTime * uParam0; float s = uParam1;…"> ; des sonic-action target="scene" type="inc" payload=\'{"p":1}\' agissent sur les boutons −/+. '
                    .'Pas de texte dans ce store (il serait recopié en propriétés) : libellés et valeurs affichés dans un autre store lu par sonic-value. '
                    .'Plus de 4 réglages : dérive-les dans le GLSL (ex. fract(uParam0 * 7.0)). Sources d’image : channel0…3="#id" d’un composant source (caméra, vidéo, écran, analyseur audio…). '
                    .'Ou pars du kit shader (start_from_kit : shader GLSL + jusqu’à 4 réglages « controls » déjà câblés), puis ajuste avec edit_preview.',
                'icones' => $this->icons->rule(),
                'polices' => 'Optionnel : "fonts": ["Patrick Hand", "Fredoka:wght@400;700"] (4 max, noms Google Fonts, pas d’URL). Le viewer les charge ; utiliser ensuite font-family:\'Patrick Hand\',cursive dans les styles. Toujours prévoir une police de repli. Police à soi (ou variable) : envoyer un .woff2 avec upload_artifact_asset puis déclarer "fontFiles": [{"family": "Mon Titre", "src": "@asset:titre.woff2", "weight": "100 900" (plage = police variable ; ou "400"), "style": "normal|italic"}] (4 max) et utiliser font-family:\'Mon Titre\',sans-serif ; artefact lisible par lien ou public (une police ne s’authentifie pas).',
                'transitions' => 'Plusieurs vues (#id) : "viewTransition": "fade|slide|zoom" au niveau du document anime le changement de vue (API View Transitions ; sans effet avec « moins d’animations »). Transition de la page entière : pas de morphing d’éléments partagés.',
                'fichiers' => 'Gros shader / réducteur / texte : déclarer "files": {"jeu.jsonata": "…", "scene.glsl": "…"} au niveau du document et y renvoyer par la valeur exacte "@file:jeu.jsonata" (attribut image d’un sonic-shader, reducer d’un store, jsonata d’une transform…). Le serveur remplace les références avant validation et affichage ; retoucher un fichier = update_artifact avec edits sur /files/jeu.jsonata (chemin court, pas besoin de renvoyer le document). Noms a-z0-9._-, 24 fichiers max, 64 Ko chacun. Fichier copié d’une ressource de la bibliothèque (get_resource) : ajouter au niveau du document "resources": {"scene.glsl": {"ref": "base:shader/plasma", "version": 1, "sha256": "…"}} (ref, version et sha256 renvoyés par get_resource) pour que l’utilisateur soit prévenu quand une version plus récente existe.',
                'assets' => 'Images, sons, vidéos, modèles 3D (.glb), JSON volumineux : fichiers binaires propres à l’artefact (20 Mo max chacun, 200 max ; png jpg webp gif avif svg ogg mp3 wav flac webm mp4 glb woff2 json). Les envoyer avec upload_artifact_asset (contentBase64 pour un petit fichier, ou fetch {url, sha256} depuis un hôte autorisé) AVANT de publier, puis les référencer dans le document par la valeur exacte "@asset:nom.ext" partout où une URL est attendue (src d’un sonic-sprite ou d’une image, source d’un sampler…) : le serveur la remplace par l’URL publique de l’asset. Une référence vers un asset absent reste telle quelle. Le stockage compte dans le quota du propriétaire (list_artifact_assets, delete_artifact_asset).',
                'io' => 'Artefact utilisé comme OUTIL (workflow, agent) : "io" au niveau du document = {title?, description?, modes:["headless"|"interactive"|"embed"], input?:{schema, store, action?}, output:{schema, from, when?, files?}, headless?:{actions?, maxSteps?}, stream?:{…}}. Schémas : sous-ensemble JSON Schema 2020-12 (type, properties, required, additionalProperties booléen, items, enum, const, minimum/maximum, minLength/maxLength, pattern, minItems/maxItems, title, description, default, format ; pas de $ref ni de combinateurs) ; input.schema est un objet (type "object"). ENTRÉE : validée par input.schema puis envoyée au store input.store comme action {type: input.action (défaut "io:input"), payload: entrée} : le réducteur la range dans l’état (champs lus avec $state.x, jamais x seul). SORTIE : output.from = "store.chemin.pointé" (ou un store/transform entier), lue quand output.when (JSONata sur l’état de ce store) est vrai, validée par output.schema. FICHIERS (interactive/embed) : output.files = {"dessin": "sketch.file"} (4 max) : chemin d’un DataProvider qui donne l’URL du fichier (blob:, data:, ou {url}) ; png jpg webp gif svg webm mp4 ogg mp3 wav json, 20 Mo max ; envoyés avec la sortie. HEADLESS (sans navigateur, réducteur JSONata seul, comme simulate_store) : input requis, from lit le store d’entrée ; headless.actions = actions rejouées après l’entrée ([{type:"tick", repeat:200}]) jusqu’à when, maxSteps ≤ 20000 (défaut 2000). Le calcul doit donc vivre dans le réducteur. INTERACTIVE (un humain fait l’étape, composants compris : caméra, dessin, formulaire…) : run_artifact {slug, input, mode:"interactive"} → {runId, status:"pending", url} ; le viewer ouvert par url envoie l’entrée au store puis publie la sortie (from) dès que when est vrai (sans when : au premier changement non vide de from) ; prévoir un état final explicite (ex. phase=\'done\' au clic sur « Valider », sonic-action trigger="click"). EMBED : même chose dans <artifact-embed run="…" run-token="…"> (ou dans la conversation d’un hôte MCP Apps) ; <artifact-embed> sans run émet aussi la sortie (avec input=\'{…}\' pour une entrée locale) ; événement DOM artifact-output {output, files, runId}. Les runs exécutent la version EN LIGNE. Avant publication : check_preview mode "io" (atelier) ou run_artifact {document, input} (brouillon, headless). Outils : run_artifact, list_artifact_tools, get_artifact_run (file : contenu d’un fichier), cancel_artifact_run, stream_artifact_events, link_artifact_runs, unlink_artifact_runs ; webhooks artifact.run.completed / artifact.run.failed (Standard Webhooks) ; OpenAPI : GET /api/artifacts/{slug}/openapi.json. Enchaîner plusieurs artefacts : save_artifact_workflow / run_artifact_workflow. FLUX EN DIRECT (interactive/embed) : io.stream = {store? (défaut input.store), actions?: {"nom": {schema?}} ou ["nom"], state?: bool, document?: bool, score?: bool, rate?: 1…120 msg/s (défaut 20), progress?: {from: "store.chemin", every?: ms}} ; le run renvoie stream {emitToken, watchToken, eventsUrl, watchUrl} ; n’importe quel émetteur (script, agent, capteur, autre service) pousse des messages (stream_artifact_events, ou POST eventsUrl avec Authorization: Bearer emitToken) : action {name, payload} → réducteur du store (action.type = name) ; state.snapshot / state.delta (JSON Patch sur l’état) ; document.patch (JSON Patch sur le document, validé comme une publication, /io intouchable) ; score {duration, loop, speed, events:[{at, action, payload}], tracks:[{path:"/hue", keys:[{at, value, ease: linear|in|out|inOut|step}]}]} = partition jouée localement par chaque viewer (synchrone, interpolation de nombres et couleurs #rrggbb) : préférer une partition aux messages un par un pour les évolutions longues ou rapides ; end → le viewer publie sa sortie. Store du flux : budget-ms="50" sur un sonic-store de la vue (une action hors budget est ignorée et l’écran se désynchronise ; 50 par défaut dans data.stores). watchUrl = spectateurs en lecture seule (rattrapés à leur arrivée). LIEN « REGARDER » ET QR : le viewer d’un run en flux publie le DataProvider artifactRun = {runId, role: performer|watcher, watchUrl, status} ; sonic-qr text-from="artifactRun.watchUrl" l’affiche en QR code (lien spectateur en lecture seule ; jamais le jeton du run). APPAIRAGE (scannez pour piloter / pour ouvrir un écran) : io.stream.pair = {remote: "<slug d’un artefact du même compte, en lien ou public>", map?: JSONata remote → ce run, back?: JSONata ce run → remote (suppose io.stream.emit ici), max?: 1…32 appareils (8), ttl?: secondes de validité d’un code (900), input?: entrée du run remote} ; le viewer du run publie artifactRun.pair = {code, url, expiresAt, remaining, max, paired} (code renouvelé seul) : sonic-qr text-from="artifactRun.pair.url" ; l’appareil qui ouvre l’URL lance un run de remote relié à cet écran (map / back comme link_artifact_runs, from.runId distingue les appareils). RÔLES (équipes, types d’appareils) : pair = [{role: "rouge", remote, input: {equipe: "rouge"}, …}, {role: "bleu", …}] (4 au plus) → un code par rôle, artifactRun.pairs.<role> (artifactRun.pair = le premier). PLACES : un appareil absent plus de 2 min, terminé ou retiré libère sa place ; quand l’hôte se termine, ses appareils reçoivent end. ENVOI CIBLÉ ET COMMANDES (file d’envoi de l’hôte) : {id, to: runId ou [runIds], data} ne part que vers ces appareils (sans to : vers tous) ; {id, op: "kick", runId} retire un appareil (son run reçoit end) ; {id, op: "lock"} / {id, op: "unlock"} ferme / rouvre les inscriptions (artifactRun.pair.locked). PRÉSENCE : les runs reliés (liens, appairage) qui ont un viewer ouvert sont des membres ; à chaque arrivée ou départ, le flux reçoit {type: "presence", count, members: [{runId, slug, index, since, seen, role}]} (index = rang d’arrivée, stable quand un autre part ; un appareil qui recharge la page garde son run et son rang) : artifactRun.presence (sonic-value key="presence.count"), et action presence vers le store du flux (une tuile par runId ; nom, score, équipe rangés par runId dans le réducteur). RELIER DEUX ARTEFACTS (télécommande → scène) : la source déclare io.stream.emit = {from: "store.file", ack?: "store"} (son réducteur ajoute {id, data} à la file et retire l’élément sur {type: "emit:ok", payload: {id}}) ; le propriétaire relie les deux runs avec link_artifact_runs {fromRunId, toRunId, map?} (map JSONata sur {id, data, from} → message du flux cible ; sans map, data est déjà un message). Webhooks artifact.run.progress / artifact.run.stream_ended. Kit direct : scène shader déjà câblée pour le flux. Kits quiz et survey : io déjà déclaré (score / réponses). Exemple : "data":{"stores":{"calc":{"initial":{"phase":"idle"},"reducer":"$action.type=\'io:input\' ? {\'phase\':\'done\',\'total\':$sum($action.payload.values)} : $state"}}}, "io":{"modes":["headless","interactive"],"input":{"schema":{"type":"object","properties":{"values":{"type":"array","items":{"type":"number"}}},"required":["values"]},"store":"calc"},"output":{"schema":{"type":"number"},"from":"calc.total","when":"phase=\'done\'"}}.',
                'lieux' => 'Lieux partagés (balises, notes de terrain, sons de lieu) : data.places.<nom> = {maxBytes (≤ 400000, défaut 150000), maxRecords (≤ 5000), radius (m, défaut 300), near: "geoState" (DP avec lat/lon : sonic-geolocation), fetch (corps complets des N plus proches, défaut 6), from: "store.outbox", ack: "store", refresh (s, défaut 30)}. N’importe quel visiteur pose ; tous lisent ceux qui sont proches. Le viewer publie le DataProvider <nom> = {status, items: [{id, lat, lon, distance, mine, …champs courts, …corps complet pour les fetch plus proches}], error}. Boîte d’envoi (tableau dans le store) : {id, op: "add", lat, lon, data: {…}} pose (le viewer renvoie place:ok {id, placeId} ou place:error au store ack, qui retire l’élément) ; {id, op: "delete", place} retire un lieu posé depuis cet appareil ; {id, op: "report", place} signale (masqué au 3e signalement) ; {id, op: "addRef", place, ref} ajoute une surface de remplacement au lieu (mur repeint, affiche changée : n’importe qui, 4 gardées ; sonic-anchor lit item.refs sous le même id). DISCUSSION du lieu : thread: "filCible.place" (DP donnant l’id d’un lieu) → le viewer publie <nom>Fil = {place, status, messages [{id, kind text|voice|drawing, text, pseudo, at, origin?, media (data: URL, chargé à part pour vocaux et dessins), mine}], drawings [mêmes entrées de dessins], count}, rechargé toutes les 15 s ; boîte d’envoi {id, op: "message", place, text?, media?, pseudo?} (vocal data:audio/…;base64 ou dessin data:image/…, 350 Ko au plus) et {id, op: "reportMessage", place, message}. Champs courts des listes : textes ≤ 200 caractères, nombres, booléens du corps ; le reste (référence, data: URL) seulement dans le corps complet. CARTE (sans tout charger) : une 2e entrée {collection: "<nom>", area: "carteState"} (état d’un sonic-map, bounds) publie la zone visible à chaque fin de déplacement : {status, items, clusters [{lat, lon, count}] au-delà de 300 lieux, points (items + groupes {id, lat, lon, count, label, groupe: true}, prêts pour points-provider="<entrée>.points"), total}.',
                'collecte' => 'Formulaire / scores anonymes : data.sources.<x> = {collection, intake:{fields:{nom:{type:string,max:20,required:true}, score:{type:integer,min:0,max:9999}}, maxRecords, minInterval, requireCode}}. Fermée par défaut : open_artifact_intake ouvre une session limitée (24 h max) ; intake.permanent:true = toujours ouverte (pas de session, garde au plus maxRecords en évinçant le pire ou le plus ancien, minInterval 3 s par défaut) ; intake.unique:{field:"nom",keep:"max:score"} = un seul record par valeur de field (keep : max:<champ>|min:<champ>|last), idéal pour un classement (meilleur score par pseudo). Lecture triée/bornée côté serveur : data.sources.<x> accepte sort (\"-score\") et limit (5) ; l’API accepte ?sort=-score&limit=5 et read_artifact_data aussi. data.sinks.<x> = {collection, from:"store.outbox", merge:{champ:"dp.cle"}, code?:"dp.cle", ack?:"storeId"} : le viewer poste chaque élément {id, data} ajouté à la boîte d’envoi et renvoie sink:ok / sink:error au store. Lecture non publique : lien secret &rk=<readToken> (get_artifact.collections).',
            ],
            'scripts' => $this->scriptsCatalog->mcpSummary(),
            'examples' => array_slice($examples, 0, 5),
        ];

        if ($rules !== null && $rules !== []) {
            $want = array_fill_keys($rules, true);
            foreach (self::LONG_RULES as $topic) {
                if (!isset($want[$topic])) {
                    unset($payload['rules'][$topic]);
                }
            }
            // Le détail des bibliothèques de scripts aussi, sur demande (`rules: ["scripts"]`).
            if (!isset($want['scripts'])) {
                unset($payload['scripts']);
            }
        }
        if ($rules !== null && array_intersect($rules, ['recettes', 'design', 'outil', 'dashboard', 'multivue']) !== []) {
            $payload['recipes'] = $this->recipes();
        }
        if ($rules !== null && $rules !== []) {
            $skills = $this->skills();
            foreach ($rules as $name) {
                if (\is_string($name) && isset($skills[$name])) {
                    $payload['skills'][$name] = $skills[$name]['body'];
                }
            }
        }
        if (!$withExamples) {
            unset($payload['examples']);
        }

        return $payload;
    }

    /**
     * Compétences de conception : fichiers Markdown à en-tête (name, description) dans config/artifacts/skills.
     * Source unique : l'atelier (sommaire du prompt) et le MCP (get_artifact_catalog rules=[…]) les lisent ici.
     *
     * @return array<string, array{description: string, body: string}>
     */
    public function skills(): array
    {
        $dir = \dirname($this->catalogPath).'/skills';
        $out = [];
        foreach (self::SKILLS as $name) {
            $file = $dir.'/'.$name.'.md';
            if (!is_file($file)) {
                continue;
            }
            $text = (string) file_get_contents($file);
            $description = '';
            if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $text, $m) === 1) {
                if (preg_match('/^description:\s*(.+)$/m', $m[1], $d) === 1) {
                    $description = trim($d[1]);
                }
                $text = $m[2];
            }
            $out[$name] = ['description' => $description, 'body' => trim($text)];
        }

        return $out;
    }

    /**
     * Sommaire du catalogue pour un prompt système (~1k tokens) : noms des composants par
     * origine et thèmes de règles. Le détail se demande ensuite à get_artifact_catalog
     * (`components`, `rules`) : on évite d'envoyer 20k tokens de catalogue au modèle.
     */
    public function catalogIndex(): string
    {
        $groups = [];
        foreach ($this->catalog()['components'] ?? [] as $component) {
            if (\is_array($component) && \is_string($component['name'] ?? null)) {
                $groups[\is_string($component['addon'] ?? null) ? $component['addon'] : 'concorde'][] = $component['name'];
            }
        }
        ksort($groups);
        $lines = ['Concorde '.$this->concordeVersion().' — composants (tagName) :'];
        foreach (['concorde' => $groups['concorde'] ?? []] + $groups as $group => $names) {
            $lines[] = sprintf('- %s : %s', $group === 'concorde' ? 'UI' : 'addon '.$group, implode(', ', $names));
        }
        $payload = $this->mcpCatalogPayload(true, ['__none__'], null, false);
        $lines[] = 'Balises HTML sûres : '.implode(', ', $payload['catalog']['safeHtmlTags'] ?? []).'.';
        $lines[] = 'Règles longues (rules) : '.implode(', ', self::LONG_RULES).', scripts (détail des bibliothèques).';
        $skillLines = [];
        foreach ($this->skills() as $name => $skill) {
            $skillLines[] = sprintf('%s (%s)', $name, $skill['description']);
        }
        if ($skillLines !== []) {
            $lines[] = 'Compétences (à demander avec rules=[…] AVANT d\'écrire l\'interface) : '.implode(' ; ', $skillLines).'.';
        }
        $short = array_diff_key($payload['rules'], array_fill_keys(self::LONG_RULES, true));
        $lines[] = 'Règles courtes : '.json_encode($short, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $lines[] = 'Bibliothèques scripts (ids) : '.implode(', ', array_map(
            static fn (mixed $l): string => \is_array($l) ? (string) ($l['id'] ?? '') : '',
            $payload['scripts']['libraries'] ?? [],
        ));

        return implode("\n", $lines);
    }

    private static function firstSentence(string $text, int $max = 140): string
    {
        $text = trim($text);
        if (preg_match('/^.{20,}?[.!?](?=\s|$)/u', $text, $m) === 1 && mb_strlen($m[0]) <= $max) {
            return $m[0];
        }

        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /**
     * @param array<string, mixed> $catalog
     *
     * @return array<string, mixed>
     */
    /** Règle « ia » du catalogue : syntaxe de `model`, tâches supportées et raccourcis. */
    private function huggingFaceRule(): string
    {
        $aliases = [];
        foreach (self::HF_MODEL_ALIASES as $alias => $description) {
            $aliases[] = $alias.' ('.$description.')';
        }

        return sprintf(self::HF_RULE, implode(' ; ', $aliases));
    }

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
                // Compact : type, valeurs permises et défaut ; descriptions dans le catalogue complet.
                if (\is_array($meta)) {
                    unset($meta['description'], $meta['default']);
                }
                $specific[$prop] = $meta;
            }
            $entry = [
                'name' => $component['name'],
                'props' => $specific,
            ];
            if (isset($component['description']) && \is_string($component['description'])) {
                $entry['description'] = self::firstSentence($component['description']);
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

        // Fichiers séparés (`files`, références @file:nom) : structure vérifiée, puis le reste de la
        // validation porte sur le document développé.
        $fileErrors = ArtifactFiles::check($document);
        if ($fileErrors !== []) {
            return ['valid' => false, 'errors' => $fileErrors];
        }
        $document = ArtifactFiles::expand($document);

        // Références d'assets (`@asset:nom`) : syntaxe seulement (l'existence dépend de l'artefact).
        $assetErrors = ArtifactAssetRefs::check($document);
        if ($assetErrors !== []) {
            return ['valid' => false, 'errors' => $assetErrors];
        }

        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['valid' => false, 'errors' => [['path' => '', 'message' => 'JSON invalide.']]];
        }
        if (\strlen($encoded) > self::MAX_BYTES) {
            $errors[] = ['path' => '', 'message' => sprintf('Document trop volumineux (max %d Ko).', self::MAX_BYTES / 1024)];
        }

        $this->seenTags = [];
        $this->library = [];

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
                // Une vue porte soit `root` (descripteur SDUI), soit `a2ui` (messages A2UI v0.9).
                $hasRoot = \array_key_exists('root', $view);
                $hasA2ui = \array_key_exists('a2ui', $view);
                if ($hasRoot && $hasA2ui) {
                    $errors[] = ['path' => $base, 'message' => 'root et a2ui sont exclusifs.'];
                } elseif ($hasA2ui) {
                    $this->a2uiValidator->validate($view['a2ui'], $base.'/a2ui', $errors, $nodeCount);
                } else {
                    $root = $view['root'] ?? null;
                    if (!\is_array($root)) {
                        $errors[] = ['path' => $base.'/root', 'message' => 'root (descripteur SDUI) ou a2ui (messages A2UI) requis.'];
                    } else {
                        $this->validateDescriptor($root, $base.'/root', $errors, $nodeCount, 0);
                    }
                }
                if (\array_key_exists('a2uiBindings', $view)) {
                    $stores = \is_array($document['data']['stores'] ?? null) ? $document['data']['stores'] : [];
                    $bindings = $view['a2uiBindings'];
                    if (!$hasA2ui) {
                        $errors[] = ['path' => $base.'/a2uiBindings', 'message' => 'a2uiBindings ne sert qu’aux vues a2ui.'];
                    } elseif (!\is_array($bindings) || ($bindings !== [] && array_is_list($bindings)) || \count($bindings) > 8) {
                        $errors[] = ['path' => $base.'/a2uiBindings', 'message' => 'a2uiBindings : objet {"/chemin": "store"} (8 max).'];
                    } else {
                        foreach ($bindings as $pointer => $store) {
                            if (!\is_string($pointer) || !preg_match('#^(/[^/.]+)+$#', $pointer)) {
                                $errors[] = ['path' => $base.'/a2uiBindings', 'message' => sprintf('Chemin "%s" : absolu, sans ".", hors racine.', (string) $pointer)];
                            } elseif (!\is_string($store) || !\array_key_exists($store, $stores)) {
                                $errors[] = ['path' => $base.'/a2uiBindings', 'message' => sprintf('"%s" doit nommer un store de data.stores.', $pointer)];
                            }
                        }
                    }
                }
                if (\array_key_exists('actionStore', $view)) {
                    $store = $view['actionStore'];
                    $stores = \is_array($document['data']['stores'] ?? null) ? $document['data']['stores'] : [];
                    if (!$hasA2ui) {
                        $errors[] = ['path' => $base.'/actionStore', 'message' => 'actionStore ne sert qu’aux vues a2ui.'];
                    } elseif (!\is_string($store) || !\array_key_exists($store, $stores)) {
                        $errors[] = ['path' => $base.'/actionStore', 'message' => 'actionStore doit nommer un store de data.stores.'];
                    }
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

        if (\array_key_exists('fontFiles', $document)) {
            $this->validateFontFiles($document['fontFiles'], $errors);
        }

        if (\array_key_exists('viewTransition', $document) && !\in_array($document['viewTransition'], ['fade', 'slide', 'zoom'], true)) {
            $errors[] = ['path' => '/viewTransition', 'message' => 'viewTransition : fade, slide ou zoom.'];
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
            'fontFiles' => true,
            'viewTransition' => true,
            'capabilities' => true,
            'page' => true,
            'io' => true,
            'icon' => true,
        ];
        foreach (array_keys($document) as $key) {
            if (!isset($allowedTop[$key])) {
                $errors[] = ['path' => '/'.$key, 'message' => 'Clé non autorisée.'];
            }
        }
        if (\array_key_exists('page', $document)) {
            $errors = [...$errors, ...self::validatePage($document['page'])];
        }
        if (\array_key_exists('io', $document)) {
            $errors = [...$errors, ...ArtifactIo::validateDeclaration($document['io'], $document)];
        }
        if (\array_key_exists('icon', $document)) {
            $errors = [...$errors, ...ArtifactAppIcon::validate($document['icon'])];
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /** Couleur CSS sûre pour page.background (même forme que le viewer, src/app/page-frame.ts). */
    private const PAGE_COLOR = '/^(#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\\([0-9.,%\\s\\/deg+-]{1,60}\\)|[a-z]{3,24}|var\\(--sc-[a-z0-9-]{1,40}\\))$/i';

    /**
     * Cadre de page : {padding?: none|compact|normal, background?: couleur CSS, nav?: tabs|none}.
     *
     * @return list<array{path: string, message: string}>
     */
    private static function validatePage(mixed $page): array
    {
        if (!\is_array($page) || array_is_list($page) && $page !== []) {
            return [['path' => '/page', 'message' => 'page doit être un objet {padding, background, nav}.']];
        }
        $errors = [];
        foreach (array_keys($page) as $k) {
            if (!\in_array($k, ['padding', 'background', 'nav'], true)) {
                $errors[] = ['path' => '/page/'.$k, 'message' => 'Clé non autorisée (padding, background, nav).'];
            }
        }
        if (isset($page['padding']) && !\in_array($page['padding'], ['none', 'compact', 'normal'], true)) {
            $errors[] = ['path' => '/page/padding', 'message' => 'padding : none, compact ou normal.'];
        }
        if (isset($page['nav']) && !\in_array($page['nav'], ['tabs', 'none'], true)) {
            $errors[] = ['path' => '/page/nav', 'message' => 'nav : tabs ou none.'];
        }
        if (isset($page['background'])) {
            $bg = $page['background'];
            if (!\is_string($bg) || \strlen($bg) > 80 || preg_match(self::PAGE_COLOR, trim($bg)) !== 1) {
                $errors[] = ['path' => '/page/background', 'message' => 'background : couleur CSS (#hex, rgb(), hsl(), nom ou var(--sc-…)), sans url() ni dégradé.'];
            }
        }

        return $errors;
    }

    /**
     * Contrôles sémantiques non bloquants (fautes de frappe silencieuses) : sinks et câblage clavier.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{path: string, message: string}>
     */
    public function lint(array $document): array
    {
        $warnings = [];
        $data = \is_array($document['data'] ?? null) ? $document['data'] : [];
        $providers = [];
        foreach (['stores', 'transforms', 'sources'] as $section) {
            if (\is_array($data[$section] ?? null)) {
                foreach (array_keys($data[$section]) as $k) {
                    $providers[(string) $k] = true;
                }
            }
        }
        /** @var array<string, string> $reducers */
        $reducers = [];
        $keyboards = [];
        $walk = function (mixed $node, string $path) use (&$walk, &$providers, &$reducers, &$keyboards): void {
            if (!\is_array($node)) {
                return;
            }
            $attrs = \is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            $tag = $node['tagName'] ?? null;
            if ($tag === 'sonic-store' && \is_string($attrs['id'] ?? null)) {
                $providers[$attrs['id']] = true;
                $reducers[$attrs['id']] = \is_string($attrs['reducer'] ?? null) ? $attrs['reducer'] : '';
            }
            if ($tag === 'sonic-keyboard') {
                $keyboards[] = [$path, $attrs];
            }
            foreach (\is_array($node['nodes'] ?? null) ? $node['nodes'] : [] as $i => $child) {
                $walk($child, $path.'/nodes/'.$i);
            }
        };
        foreach (\is_array($document['views'] ?? null) ? array_values($document['views']) : [] as $i => $view) {
            if (\is_array($view) && \is_array($view['root'] ?? null)) {
                $walk($view['root'], '/views/'.$i.'/root');
            }
        }

        foreach (\is_array($data['sinks'] ?? null) ? $data['sinks'] : [] as $name => $sink) {
            if (!\is_array($sink)) {
                continue;
            }
            $p = '/data/sinks/'.$name;
            $paths = array_filter(['from' => $sink['from'] ?? null, 'code' => $sink['code'] ?? null] + (\is_array($sink['merge'] ?? null) ? $sink['merge'] : []), '\is_string');
            foreach ($paths as $field => $dp) {
                $root = explode('.', $dp)[0];
                if (!isset($providers[$root])) {
                    $warnings[] = ['path' => $p.'/'.$field, 'message' => sprintf('« %s » ne correspond à aucun store, transform ou source.', $root)];
                }
            }
            if (\is_string($sink['ack'] ?? null) && !isset($providers[$sink['ack']])) {
                $warnings[] = ['path' => $p.'/ack', 'message' => sprintf('ack « %s » : aucun store de ce nom (sink:ok / sink:error ne seront jamais reçus).', $sink['ack'])];
            }
        }

        foreach ($keyboards as [$path, $attrs]) {
            $store = $attrs['store'] ?? null;
            if (\is_string($store) && !isset($providers[$store])) {
                $warnings[] = ['path' => $path.'/attributes/store', 'message' => sprintf('store « %s » introuvable.', $store)];
                continue;
            }
            $keymap = \is_string($attrs['keymap'] ?? null) ? json_decode($attrs['keymap'], true) : null;
            $reducer = \is_string($store) ? ($reducers[$store] ?? '') : '';
            if (!\is_array($keymap) || $reducer === '') {
                continue;
            }
            foreach (array_unique(array_filter(array_values($keymap), '\is_string')) as $action) {
                if (!str_contains($reducer, "'".$action."'") && !str_contains($reducer, '"'.$action.'"')) {
                    $warnings[] = ['path' => $path.'/attributes/keymap', 'message' => sprintf('Action « %s » absente du réducteur de « %s » : la touche ne fera rien.', $action, $store)];
                }
            }
        }

        return [...$warnings, ...ArtifactUiRules::lint($document)];
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

        $outerLibrary = $this->library;
        $this->library = \is_array($descriptor['library'] ?? null) ? $descriptor['library'] : [];
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

        $this->library = $outerLibrary;

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
        } elseif (($replaced = ArtifactUiRules::replacedTagMessage($tagName)) !== null) {
            $errors[] = ['path' => $path.'/tagName', 'message' => $replaced];
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
                    // `once` (sonic-animate) n'est pas un gestionnaire d'événement.
                    if ((preg_match('/^on/i', $attr) && strcasecmp($attr, 'once') !== 0) || strcasecmp($attr, 'srcdoc') === 0) {
                        $errors[] = ['path' => $path.'/attributes/'.$attr, 'message' => 'Attribut événement / srcdoc interdit.'];
                        continue;
                    }
                    if (str_starts_with($attr, '::')) {
                        $msg = $this->validateBinding(substr($attr, 2), $value);
                        if ($msg !== null) {
                            $errors[] = ['path' => $path.'/attributes/'.$attr, 'message' => $msg];
                        }
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

        if ($tagName === 'sonic-patch') {
            foreach ($this->patchLint->check($node, $this->library) as $message) {
                $errors[] = ['path' => $path, 'message' => 'sonic-patch : '.$message];
            }
        }

        if ($tagName === 'sonic-icon' && \is_array($node['attributes'] ?? [])) {
            $msg = $this->icons->check($node['attributes'] ?? []);
            if ($msg !== null) {
                $errors[] = ['path' => $path.'/attributes', 'message' => $msg];
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

        // textContent : texte brut (Concorde l'écrit via textContent, jamais parsé en HTML ; ignoré si le nœud a des enfants).
        if (\array_key_exists('textContent', $node)) {
            if (!\is_string($node['textContent'])) {
                $errors[] = ['path' => $path.'/textContent', 'message' => 'textContent doit être une chaîne.'];
            } elseif (\strlen($node['textContent']) > self::MAX_TEXT_CONTENT) {
                $errors[] = ['path' => $path.'/textContent', 'message' => sprintf('textContent trop long (max %d Ko).', self::MAX_TEXT_CONTENT / 1024)];
            }
        }
        // nodeId : identifiant logique (cibles d'animation, sourceNodeId des actions), distinct de l'attribut DOM `id`.
        if (\array_key_exists('nodeId', $node) && (!\is_string($node['nodeId']) || preg_match(self::NODE_ID_PATTERN, $node['nodeId']) !== 1)) {
            $errors[] = ['path' => $path.'/nodeId', 'message' => 'nodeId : lettre puis lettres, chiffres, _ ou - (64 max).'];
        }

        $allowedKeys = ['tagName' => true, 'attributes' => true, 'nodes' => true, 'libraryKey' => true, 'contentElementSelector' => true, 'parentElementSelector' => true, 'textContent' => true, 'nodeId' => true];
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
                    if (isset($src['sort']) && (!\is_string($src['sort']) || !preg_match('/^[-+]?[a-zA-Z][a-zA-Z0-9_]{0,31}(,[-+]?[a-zA-Z][a-zA-Z0-9_]{0,31}){0,2}$/', $src['sort']))) {
                        $errors[] = ['path' => $path.'/sources/'.$name.'/sort', 'message' => 'sort : champs séparés par des virgules, - pour décroissant (ex. "-score,nom", 3 max).'];
                    }
                    if (isset($src['limit']) && (!\is_int($src['limit']) || $src['limit'] < 1 || $src['limit'] > ArtifactIntakeSchema::HARD_MAX_RECORDS)) {
                        $errors[] = ['path' => $path.'/sources/'.$name.'/limit', 'message' => 'limit : 1…'.ArtifactIntakeSchema::HARD_MAX_RECORDS.'.'];
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
                    if (isset($store['maxStateBytes']) && (!\is_int($store['maxStateBytes']) || $store['maxStateBytes'] < 1024 || $store['maxStateBytes'] > 1_228_800)) {
                        $errors[] = ['path' => $path.'/stores/'.$name.'/maxStateBytes', 'message' => 'maxStateBytes : entier de 1024 à 1228800 (état avec images).'];
                    }
                    if (isset($store['historySkip']) && (!\is_string($store['historySkip']) || !preg_match('/^[a-zA-Z][\w:-]{0,63}(,[a-zA-Z][\w:-]{0,63}){0,15}$/', $store['historySkip']))) {
                        $errors[] = ['path' => $path.'/stores/'.$name.'/historySkip', 'message' => 'historySkip : types d\'action séparés par des virgules.'];
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
        if (isset($data['persist'])) {
            $this->validatePersist($data['persist'], $data, $path.'/persist', $errors);
        }
        if (isset($data['sinks'])) {
            $this->validateSinks($data['sinks'], \is_array($data['sources'] ?? null) ? $data['sources'] : [], $path.'/sinks', $errors);
        }
        if (isset($data['places'])) {
            $this->validatePlaces($data['places'], $path.'/places', $errors);
        }
    }

    /**
     * `data.places` : lieux partagés posés par les visiteurs à une position GPS (ArtifactPlaceService).
     *   "places": {"balises": {"maxBytes": 150000, "maxRecords": 1000, "radius": 300, "near": "geoState", "fetch": 6,
     *                          "from": "balises.outbox", "ack": "balises"}}
     *
     * @param list<array{path: string, message: string}> $errors
     */
    private function validatePlaces(mixed $places, string $path, array &$errors): void
    {
        if (!\is_array($places) || ($places !== [] && array_is_list($places))) {
            $errors[] = ['path' => $path, 'message' => 'places doit être un objet {nom: {…}}.'];

            return;
        }
        if (\count($places) > 4) {
            $errors[] = ['path' => $path, 'message' => 'Trop de collections de lieux (max 4).'];
        }
        $dp = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}(\.[a-zA-Z0-9_]{1,64}){0,4}$/';
        $allowed = ['maxBytes', 'maxRecords', 'radius', 'near', 'fetch', 'from', 'ack', 'refresh', 'collection', 'area', 'thread'];
        foreach ($places as $name => $def) {
            $p = $path.'/'.$name;
            if (!\is_string($name) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $name) !== 1) {
                $errors[] = ['path' => $p, 'message' => 'Nom de collection de lieux : lettre puis lettres, chiffres, _ ou -.'];
                continue;
            }
            if (!\is_array($def) || ($def !== [] && array_is_list($def))) {
                $errors[] = ['path' => $p, 'message' => 'Objet attendu.'];
                continue;
            }
            foreach (array_keys($def) as $k) {
                if (!\in_array($k, $allowed, true)) {
                    $errors[] = ['path' => $p.'/'.$k, 'message' => 'Clé non autorisée ('.implode(', ', $allowed).').'];
                }
            }
            $ints = ['maxBytes' => [1024, ArtifactPlaceService::HARD_MAX_BYTES], 'maxRecords' => [1, ArtifactPlaceService::HARD_MAX_RECORDS], 'radius' => [10, ArtifactPlaceService::MAX_RADIUS], 'fetch' => [0, 20], 'refresh' => [5, 3600]];
            foreach ($ints as $k => [$min, $max]) {
                if (isset($def[$k]) && (!\is_int($def[$k]) || $def[$k] < $min || $def[$k] > $max)) {
                    $errors[] = ['path' => $p.'/'.$k, 'message' => sprintf('%s : entier %d…%d.', $k, $min, $max)];
                }
            }
            if (isset($def['collection']) && (!\is_string($def['collection']) || !\is_array($places[$def['collection']] ?? null) || isset($places[$def['collection']]['collection']))) {
                $errors[] = ['path' => $p.'/collection', 'message' => 'collection : nom d’une autre entrée de data.places (sans collection elle-même), dont ce DataProvider relit les lieux.'];
            }
            foreach (['near', 'from', 'area', 'thread'] as $k) {
                if (isset($def[$k]) && (!\is_string($def[$k]) || preg_match($dp, $def[$k]) !== 1)) {
                    $errors[] = ['path' => $p.'/'.$k, 'message' => $k.' : chemin DataProvider.'];
                }
            }
            if (isset($def['ack']) && (!\is_string($def['ack']) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $def['ack']) !== 1)) {
                $errors[] = ['path' => $p.'/ack', 'message' => 'ack : id du store à notifier.'];
            }
        }
    }

    /**
     * `data.persist` : le viewer garde dans le localStorage du visiteur les stores / transforms listés.
     *   "persist": {"providers": ["chambre"], "version": 1}
     *
     * @param array<string, mixed> $data
     * @param list<array{path: string, message: string}> $errors
     */
    private function validatePersist(mixed $persist, array $data, string $path, array &$errors): void
    {
        if (!\is_array($persist) || array_is_list($persist)) {
            $errors[] = ['path' => $path, 'message' => 'persist doit être un objet {providers, version}.'];

            return;
        }
        $providers = $persist['providers'] ?? null;
        if (!\is_array($providers) || !array_is_list($providers) || $providers === [] || \count($providers) > 8) {
            $errors[] = ['path' => $path.'/providers', 'message' => 'providers : liste de 1 à 8 noms de stores ou transforms.'];
        } else {
            $known = array_merge(
                \is_array($data['stores'] ?? null) ? array_keys($data['stores']) : [],
                \is_array($data['transforms'] ?? null) ? array_keys($data['transforms']) : [],
            );
            foreach ($providers as $i => $name) {
                if (!\is_string($name) || !\in_array($name, $known, true)) {
                    $errors[] = ['path' => $path.'/providers/'.$i, 'message' => 'providers : chaque nom doit être un store ou un transform déclaré.'];
                }
            }
        }
        if (isset($persist['online']) && !\is_bool($persist['online'])) {
            $errors[] = ['path' => $path.'/online', 'message' => 'online : booléen (sauvegarde en ligne, retrouvée sur un autre appareil).'];
        }
        if (isset($persist['status']) && (!\is_string($persist['status']) || !preg_match('/^[A-Za-z][\w-]{0,63}$/', $persist['status']))) {
            $errors[] = ['path' => $path.'/status', 'message' => 'status : nom du DataProvider qui reçoit l’état de la sauvegarde en ligne.'];
        }
        if (isset($persist['version']) && (!\is_int($persist['version']) || $persist['version'] < 0 || $persist['version'] > 9999)) {
            $errors[] = ['path' => $path.'/version', 'message' => 'version : entier 0…9999 (à incrémenter quand la forme de l\'état change).'];
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

    /** GLB embarqué : base64 valide, taille bornée, en-tête « glTF ». */
    private function validateGlbBase64(string $b64): ?string
    {
        $b64 = preg_replace('/\s+/', '', $b64) ?? '';
        if ($b64 === '') {
            return null;
        }
        if (\strlen($b64) > self::MAX_GLB_BASE64) {
            return sprintf('glb trop volumineux (max %d Ko en base64).', self::MAX_GLB_BASE64 / 1024);
        }
        if (preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $b64) !== 1) {
            return 'glb : base64 invalide.';
        }
        $head = base64_decode(substr($b64, 0, 16), false);
        if ($head === false || !str_starts_with($head, 'glTF')) {
            return 'glb : fichier GLB attendu (en-tête glTF).';
        }

        return null;
    }

    /**
     * Liaison `::prop` (data-bind de Concorde). La valeur est un gabarit où `$chemin` est
     * remplacé par la donnée de la ligne ou du DataProvider ancêtre (ex. "$nom", "https://…?q=$adresse").
     */
    private function validateBinding(string $prop, mixed $value): ?string
    {
        if (!\is_string($value)) {
            return 'Liaison : valeur texte attendue (ex. "$nom").';
        }
        $p = strtolower($prop);
        if (preg_match(self::BINDING_PROP_PATTERN, $p) !== 1) {
            return 'Liaison : nom de propriété invalide.';
        }
        if (preg_match(self::BINDING_FORBIDDEN_PROP, $p) === 1) {
            return sprintf('Liaison ::%s interdite (HTML, événement ou style). Pour du texte : ::text-content ; pour une couleur : ::class.', $prop);
        }
        if (\strlen($value) > self::MAX_BINDING) {
            return 'Liaison trop longue (max 1 Ko).';
        }
        $trim = ltrim($value);
        // Formats Concorde : "|expr" et "js|expr" évaluent du JavaScript (Function), interdits.
        if (str_starts_with($trim, '|') || preg_match('/(^|[^A-Za-z0-9_])js\|/', $trim) === 1) {
            return 'Liaison : les formats "|…" et "js|…" exécutent du code, interdits.';
        }
        if (str_contains(strtolower($value), 'javascript:')) {
            return 'Valeur javascript: interdite.';
        }
        if (\in_array($p, self::BINDING_URL_PROPS, true)
            && preg_match('#^(https://[^\s$]+|/[^/\s$]|\#)#i', $trim) !== 1) {
            return sprintf('Liaison ::%s : la valeur doit commencer par une URL https littérale (ex. "https://www.google.com/maps/search/?api=1&query=$adresse"), jamais par une donnée.', $prop);
        }

        return null;
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
            if ($tagName === 'sonic-timeline' && $attrLower === 'descriptor') {
                if (\strlen($value) > self::MAX_TIMELINE_DESCRIPTOR) {
                    return sprintf('descriptor trop volumineux (max %d Ko).', self::MAX_TIMELINE_DESCRIPTOR / 1024);
                }
                $decoded = json_decode($value, true);
                if (!\is_array($decoded)) {
                    return 'descriptor : objet JSON attendu ({"root": …}).';
                }
                $bad = $this->findForbiddenKey($decoded, self::TIMELINE_FORBIDDEN_KEYS);
                if ($bad !== null) {
                    return sprintf('descriptor : clé "%s" interdite (sécurité).', $bad);
                }
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
            $ok = array_key_exists($value, self::HF_MODEL_ALIASES)
                || (bool) preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $value)
                || (bool) preg_match('#^https://(www\.)?huggingface\.co/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+/?$#i', $value);
            if (!$ok) {
                return 'model HF : org/nom, https://huggingface.co/org/nom, ou raccourci ('.implode(', ', array_keys(self::HF_MODEL_ALIASES)).').';
            }
        }
        if ($tagName === 'sonic-3d' && $attrLower === 'glb') {
            return $this->validateGlbBase64(preg_replace('#^data:model/gltf-binary(?:;[^,]*)?;base64,#i', '', trim($value)) ?? '');
        }
        if ($tagName === 'sonic-3d' && $attrLower === 'src') {
            $trim = trim($value);
            if (preg_match('#^data:model/gltf-binary(?:;[^,]*)?;base64,#i', $trim, $m) === 1) {
                return $this->validateGlbBase64(substr($trim, \strlen($m[0])));
            }
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
        if ($tagName === 'sonic-sprite' && $attrLower === 'src') {
            $trim = trim($value);
            if (preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=\s]+$#i', $trim) === 1) {
                if (\strlen($trim) > self::MAX_SPRITE_IMAGE) {
                    return sprintf('src du sprite trop volumineux (max %d Ko ; le mettre dans "files").', self::MAX_SPRITE_IMAGE / 1024);
                }
            } elseif (preg_match('#^https://[^\s"\'<>]+$#i', $trim) !== 1) {
                return 'src d’un sonic-sprite : image data:image/…;base64 ou URL https://.';
            }
        }
        if ($tagName === 'sonic-2d' && $attrLower === 'layers') {
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $tok) {
                if ($tok !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]*(:\d*\.?\d+)?(:y)?$/', $tok) !== 1) {
                    return sprintf('layers : "%s" invalide (nom:parallaxe[:y], ex. "ciel:0 monde:1:y hud:0").', $tok);
                }
            }
        }
        if ($tagName === 'sonic-physics' && $attrLower === 'nodes-plane' && !\in_array(strtolower(trim($value)), ['', 'screen', 'xz', 'xy'], true)) {
            return 'nodes-plane : screen (2D), xz (sol 3D) ou xy (mur 3D).';
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
                $mlSeen = false;
                foreach ($caps as $i => $cap) {
                    if (\is_array($cap) && !array_is_list($cap)) {
                        $this->validateMlCapability($cap, '/capabilities/'.$i, $mlSeen, $errors);
                        continue;
                    }
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
        $limits = ['sonic-camera' => 2, 'sonic-hand-tracker' => 1, 'sonic-face-tracker' => 1, 'sonic-pose-tracker' => 1, 'sonic-object-detector' => 1, 'sonic-segmenter' => 1, 'sonic-click-segmenter' => 1, 'sonic-image-embedder' => 1, 'sonic-example-classifier' => 2, 'sonic-landmarks-overlay' => 4, 'sonic-mic' => 2, 'sonic-video' => 6, 'sonic-frame-sync' => 2, 'sonic-puppet' => 1, 'sonic-audio-recorder' => 4, 'sonic-media-recorder' => 2, 'sonic-midi' => 2, 'sonic-screen' => 1, 'sonic-physics' => 4, 'sonic-2d' => 4, 'sonic-procgen' => 2, 'sonic-controller' => 2,
            // Un seul salon (connexion Mercure) et un seul verrou par page (même liste que le garde du viewer).
            'sonic-p2p' => 1, 'sonic-pointer-lock' => 1, 'sonic-wake-lock' => 1, 'sonic-orientation' => 1,
            // Un appareil Bluetooth = un sélecteur du navigateur et une connexion.
            'sonic-bluetooth' => 2, 'sonic-label-printer' => 2,
            // Port série : un sélecteur et une connexion par port ; une seule machine pilotée par page.
            'sonic-serial' => 2, 'sonic-grbl' => 1, 'sonic-gcode' => 4, 'sonic-laser-pattern' => 4];
        foreach ($limits as $tag => $max) {
            if (($this->seenTags[$tag] ?? 0) > $max) {
                $errors[] = ['path' => '/views', 'message' => sprintf('Trop de %s (max %d).', $tag, $max)];
            }
        }
    }

    /**
     * Entrée `{"ml": [origines https]}` de `capabilities` : origines d'où le suivi ML (sonic-hand-tracker…)
     * peut télécharger un modèle, avec consentement de l'utilisateur. Une seule entrée par document.
     *
     * @param array<mixed> $cap
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateMlCapability(array $cap, string $path, bool &$seen, array &$errors): void
    {
        if ($seen) {
            $errors[] = ['path' => $path, 'message' => 'capabilities : une seule entrée {"ml": [...]} attendue.'];

            return;
        }
        $seen = true;
        $list = $cap['ml'] ?? null;
        if (array_keys($cap) !== ['ml'] || !\is_array($list) || !array_is_list($list) || [] === $list || \count($list) > self::MAX_ML_ORIGINS) {
            $errors[] = ['path' => $path, 'message' => sprintf('capabilities : {"ml": [origines https]} attendu (1 à %d origines).', self::MAX_ML_ORIGINS)];

            return;
        }
        foreach ($list as $j => $origin) {
            if (!\is_string($origin) || !self::isValidMlOrigin($origin)) {
                $errors[] = ['path' => $path.'/ml/'.$j, 'message' => 'Origine de modèle : URL https avec hôte complet, sans identifiants, requête ni fragment (ex. https://hote.example/modeles/).'];
            }
        }
    }

    /** URL https d'un vrai nom d'hôte (ni localhost, ni IP), sans identifiants, requête ni fragment. */
    public static function isValidMlOrigin(string $value): bool
    {
        if (\strlen($value) > 200) {
            return false;
        }
        $parts = parse_url($value);
        if (!\is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || !isset($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = $parts['host'];

        return str_contains($host, '.') && !str_starts_with($host, '[') && false === filter_var($host, \FILTER_VALIDATE_IP) && 1 !== preg_match('/^[\d.]+$/', $host);
    }

    /**
     * Première clé interdite rencontrée dans une structure décodée (objets et listes imbriqués).
     *
     * @param array<mixed> $data
     * @param list<string> $forbidden
     */
    private function findForbiddenKey(array $data, array $forbidden, int $depth = 0): ?string
    {
        if ($depth > 32) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, $forbidden, true)) {
                return $key;
            }
            if (\is_array($value)) {
                $found = $this->findForbiddenKey($value, $forbidden, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
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
        // Asset de l'artefact (`@asset:nom`), remplacé à la lecture par son URL https.
        if (preg_match('/^@asset:[a-z0-9][a-z0-9._-]{0,63}$/', $trimmed) === 1) {
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

    public const MAX_FONT_FILES = 4;

    /**
     * `fontFiles` : polices fournies en assets `.woff2` (variables comprises). `src` est une référence exacte
     * `@asset:nom.woff2` (remplacée à la lecture par l'URL de l'asset) ; jamais d'URL libre.
     *
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateFontFiles(mixed $files, array &$errors): void
    {
        if (!\is_array($files) || !array_is_list($files)) {
            $errors[] = ['path' => '/fontFiles', 'message' => 'fontFiles doit être une liste de {family, src, weight?, style?}.'];

            return;
        }
        if (\count($files) > self::MAX_FONT_FILES) {
            $errors[] = ['path' => '/fontFiles', 'message' => sprintf('%d polices maximum.', self::MAX_FONT_FILES)];
        }
        foreach ($files as $i => $f) {
            $at = '/fontFiles/'.$i;
            if (!\is_array($f)) {
                $errors[] = ['path' => $at, 'message' => 'Attendu {family, src, weight?, style?}.'];
                continue;
            }
            foreach (array_keys($f) as $k) {
                if (!\in_array($k, ['family', 'src', 'weight', 'style'], true)) {
                    $errors[] = ['path' => $at.'/'.$k, 'message' => 'Clé non autorisée (family, src, weight, style).'];
                }
            }
            if (!\is_string($f['family'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9 ]{0,39}$/', $f['family'])) {
                $errors[] = ['path' => $at.'/family', 'message' => 'Nom de famille invalide (lettres, chiffres, espaces ; 40 max ; commence par une lettre).'];
            }
            if (!\is_string($f['src'] ?? null) || !preg_match('/^@asset:[a-z0-9][a-z0-9._-]{0,57}\.woff2$/', $f['src'])) {
                $errors[] = ['path' => $at.'/src', 'message' => 'src doit être une référence "@asset:nom.woff2" (asset envoyé avec upload_artifact_asset).'];
            }
            if (\array_key_exists('weight', $f) && (!\is_string($f['weight']) || !preg_match('/^[1-9]00( [1-9]00)?$/', $f['weight']))) {
                $errors[] = ['path' => $at.'/weight', 'message' => 'weight : "400" ou une plage de police variable "100 900".'];
            }
            if (\array_key_exists('style', $f) && !\in_array($f['style'], ['normal', 'italic'], true)) {
                $errors[] = ['path' => $at.'/style', 'message' => 'style : normal ou italic.'];
            }
        }
    }

}
