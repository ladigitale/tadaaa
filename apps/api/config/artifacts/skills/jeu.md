---
name: jeu
description: Jeux vidéo plein écran (arcade, plateau, puzzle, physique, 2.5D, 3D, multijoueur) : choix du moteur de rendu, machine à états, écrans titre/pause/fin, HUD, commandes clavier/tactile/manette, son, « juice », tests par simulate_store et render_artifact.
---
Prérequis : la compétence `design` (demande `rules=["design","jeu"]` ensemble), puis la règle du moteur choisi (["2d"], ["physique"], ["3d"], ["son"], ["procgen"]). Un jeu se juge à la sensation dans les 10 premières secondes : on comprend, on bouge, ça répond.

## 1. Choisir le rendu (le plus simple qui suffit)
| Jeu | Rendu | Mouvement |
|---|---|---|
| grille (snake, tetris, memory, démineur, vie) | kit `grid` (`sonic-matrix`) | réducteur |
| arcade, plateforme vue de côté, shoot, esquive, 2.5D parallaxe, beaucoup d'objets | `sonic-2d` + `sonic-sprite` | `nodes` du réducteur ou `sonic-physics nodes-plane="screen"` |
| rebonds, empilements, billard, flipper, casse-briques | `sonic-physics` (+ `sonic-2d` pour l'habillage) | moteur physique |
| cartes, plateau, UI riche animée (pièces = éléments HTML) | `sonic-stage` | `nodes` (ease) |
| plateau 3D, objet à manipuler | `sonic-3d` (GLB en asset) | `nodes` + `pick` |
| décor illustré, carte du monde, ville | `sonic-procgen` en fond + `sonic-stage`/`sonic-2d` par-dessus | |
| effet plein écran, jeu « shader » | `sonic-shader` (params pilotés) | réducteur → param0..3 |
Jamais de JSONata entre un producteur haute fréquence (physique) et le rendu : branche `dataProvider="worldState"` directement.

## 2. Architecture (toujours la même)
- `page: {"padding":"none","background":"<couleur du jeu>"}` ; palette de zone sur le conteneur racine (design §1).
- **Un store `jeu`** (`data.stores.jeu`, `budgetMs: 50`) : `phase` (`title` | `play` | `pause` | `over` | `win`), `running` (booléen lu par `sonic-ticker running-key`), score, vies, niveau, `seed`, `sound` (compteurs), `nodes` (positions publiées), `camera`. Tout le jeu est dans le réducteur ; pas de `$random`/`$now` : `$rand(seed)`, `$randInt(seed,n)`, `$clamp`, `$range`.
- Normalise les entrées en tête de réducteur : `$t := $action.type = 'swipe-left' ? 'left' : … ` pour que clavier, geste, manette et boutons donnent les mêmes actions.
- Une fonction `$mk($st)` qui recalcule `nodes` depuis l'état logique, appelée à chaque branche qui bouge quelque chose.
- Horloge : `sonic-ticker target="jeu" interval="33" running-key="running"` (≈30 Hz suffit pour un réducteur ; `ease` des nodes lisse à 60 i/s). Physique : c'est le monde qui avance, le store reçoit les événements (`collide`, `out`, `rest`).
- Cadre de scène qui garde ses proportions : `position:relative;width:min(100vw,calc(100svh*W/H));aspect-ratio:W/H;margin:auto;overflow:hidden` ; le rendu en `display:block;width:100%;height:100%`.

## 3. Calques à l'écran (de bas en haut, tous dans le cadre)
1. Scène (`sonic-2d`/`stage`/`3d`/`shader`), enveloppée par `sonic-gesture target="jeu"` pour le tactile.
2. HUD : `position:absolute;inset:0 0 auto 0;display:flex;justify-content:space-between;padding:14px 16px;pointer-events:none`, score en `sonic-value format="0000"` + `tabular-nums`, vies en icônes. 2–3 informations, pas plus.
3. Commandes tactiles (seulement si `pageState.touch`) : `sonic-if dataProvider="pageState" key="touch" equals="true"` + boutons ronds `sonic-button shape="circle" size="xl"` dans `sonic-action` (`hold-repeat` pour tenir) en bas, hors de la zone de jeu utile.
4. Écrans : un `sonic-if dataProvider="jeu" key="phase" equals="title"` (puis un pour `pause`, un pour `over`) → `position:absolute;inset:0;display:grid;place-content:center;gap:16px;text-align:center` sur un voile (`rgb(…/.8)` + `backdrop-filter:blur(6px)`). Titre du jeu énorme, une ligne d'aide (« ← → ou glisse »), UN bouton primaire (`sonic-action type="start"`), le record.
Le bouton Jouer est aussi le geste qui débloque le son : pas besoin d'invite séparée si `sonic-sound` ne joue qu'après.

## 4. Commandes
- Clavier : `sonic-keyboard target="jeu" scope="document" keymap='{"ArrowLeft":"left","ArrowRight":"right","Space":"jump","KeyP":"pause","Enter":"start"}'` ; `keyup` si l'action est tenue (`left` puis `left:up`). Ajoute ZQSD/WASD (`KeyA`, `KeyD`…).
- Manette : `sonic-controller store="jeu" keymap='{"a":"jump","start":"pause","left":"left"}' release`, `analog-action="stick"` pour l'analogique.
- Tactile : `sonic-gesture` (swipe, tap, `pan` pour viser/glisser).
- Pause automatique : `sonic-page-state` → si `visible` faux, le réducteur ne doit plus avancer (passe `running` à faux via une action `blur`, ou garde le jeu en `pause` à la reprise).
- Plein écran / écran allumé : `sonic-fullscreen` (bouton `for`), `sonic-wake-lock auto` pour les jeux longs ; masque le bouton si `capsState` dit non supporté.

## 5. Sensation (« juice ») sans code
- Son : `sonic-sound control="jeu.sound"` ; le réducteur incrémente `sound.play.<preset>` (coin, jump, hit, explosion, powerup, gameover, select…) ; musique `sound.music` avec une `songs` de la banque ; volumes ≤ 0.6. Chaque action du joueur a un son court, chaque récompense un son plus riche.
- Mouvement : `ease` `{ms:80–140,curve:"out"}` sur le joueur (réactif) ; `lift` pour les sauts de pions 3D ; `scale` 1.2→1 sur le score qui change (nodes sur `sonic-stage`).
- Impact : secousse = 3–4 publications de `camera` décalées de ±6 px puis retour (`ease` 40 ms) ; flash = un sprite plein écran `opacity` 0.6→0 ; vibration `sonic-vibrate` (Android).
- Difficulté : vitesse = f(score) ; première minute facile ; mort lisible (on voit pourquoi).
- Lisibilité : joueur = la couleur la plus saturée, dangers = une couleur réservée, décor désaturé et sombre ; contours (`stroke`) sur les sprites clés ; parallaxe `layers="ciel:0 decor:0.35 monde:1 hud:0"`.

## 6. Multijoueur et scores
- Classement : collecte `intake.permanent` + `unique:{field:"pseudo",keep:"max:score"}`, lu avec `sort:"-score",limit:10` (rules.collecte) ; écran de fin = score, record, champ pseudo, tableau.
- Temps réel : rules=["salons"] (`sonic-p2p`) ; interface obligatoire : liste des salons, créer, code, attente, salon plein ; état partagé ≤ 20 Hz, positions des autres lues dans `netPeers` par un rendu séparé.

## 7. Vérifier (ne publie pas un jeu non joué)
1. `simulate_store` : `[{type:"start"},{type:"tick",repeat:3000}]`, `until:"phase='over'"`, snapshots `["phase","score"]` → la partie se termine, le score monte, `maxMsStep` < 10 ms.
2. `render_artifact` : `steps` `[{"click":"#jouer"},{"wait":1500},{"press":"ArrowLeft"},{"wait":500}]` puis capture, avec `fps:true` (viser 60, p95 ≤ 20 ms). Donne des `id` aux boutons pour les cliquer.
3. Vérifie à 390×844 (portrait) et 1280×800 : la scène tient sans défiler, le HUD ne masque rien, les commandes tactiles n'apparaissent que sur écran tactile.
Si une action ne fait rien : `lastError` du store (budget dépassé, erreur JSONata), noms d'actions du keymap, `{}` vide dans `initial`.
