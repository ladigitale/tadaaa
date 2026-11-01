---
name: dashboard
description: Tableaux de bord, KPI, rapports chiffrés, suivi en direct : hiérarchie des chiffres, grille de panneaux, graphiques sans bibliothèque (barres, courbes, aires, jauges, heatmap, carte) avec sonic-2d, sonic-progress, sonic-matrix, formats de nombres, données collectées.
---
Prérequis : rules=["design"], ["listes"]. Un dashboard répond à 3 questions en 5 secondes : ça va ? qu'est-ce qui a changé ? où regarder ? Les bibliothèques de graphiques du catalogue `scripts` se chargent mais ne peuvent pas être appelées (aucun JS) : dessine avec les composants ci-dessous.

## 1. Plan
- Suit le thème (jetons). Titre + période (« Octobre 2026 », « mis à jour le … ») + 1 filtre global (période, site) en haut.
- **Bandeau KPI** : 3–5 tuiles `repeat(auto-fit,minmax(13rem,1fr))`. Tuile : étiquette petites capitales (`font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6`), valeur 28–40 px 700–800 `tabular-nums` (`sonic-value format="intl:fr-FR"` | `:currency:EUR` | `:percent`), variation en `sonic-badge variant="ghost" size="sm"` (`::type` success/danger selon le signe, calculé dans la transform), mini-courbe facultative.
- **Grille de panneaux** : `display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr))` ; le graphique principal en `grid-column:1/-1`. Panneau = surface `color-mix(in oklab,var(--sc-base-content) 3%,var(--sc-base))` + bordure 1 px 10 %, rayon `--sc-rounded-lg`, padding 16–20, titre 14–15 px 600 + phrase de lecture (« +12 % sur un an, porté par la billetterie »).
- Ordre : ce qui demande une action d'abord (alertes, seuils dépassés), puis tendances, puis détail (table).

## 2. Graphiques (recettes vérifiées)
**Préparer** : une `sonic-jsonata inputProvider="ds" outputProvider="graphe" expression="…"` calcule les formes à partir des données (le store garde les nombres bruts). Le DP de sortie contient des listes de sprites.

**Barres verticales / courbe / aire** → `sonic-2d width="600" height="200" background="transparent" sprites="graphe.formes"` (le `sonic-2d` remplit la largeur et garde le ratio ; 4 par page max) :
- barre : `{"name":"b"&$i,"shape":"box","w":$bw*.6,"h":$v/$max*($H-20),"x":$bw*$i+$bw/2,"y":$H-($v/$max*($H-20))/2,"radius":3,"fill":…}` ; la dernière (ou la sélection) en accent, les autres en `color-mix` pâle — la couleur porte le message.
- courbe/aire : un sprite `polygon` centré (`x:$W/2,y:$H/2`) dont `points` = `"x,y x,y …"` relatifs au centre, fermé par les deux coins bas pour une aire (`fill` accent 18 % + `stroke` accent, `line:2`) ; points en `circle` r 3, le dernier r 6. Laisse 8 px de marge horizontale pour ne pas couper les extrémités.
- étiquettes : sprites `shape:"text"` (`font-size` 11–12, `fill` texte à 60 %) pour 3–5 graduations max ; valeur exacte au survol impossible → affiche la dernière valeur et le max en texte HTML à côté.
- clic sur une barre : `target="ds" pick-names="^b"` → action `pick` `{objectId:"b7"}` ; le réducteur sélectionne, un texte `sonic-value` affiche le détail.
- couleurs : en sprite il faut des couleurs littérales (#hex) ; prends-les dans la palette du thème par défaut ou fixe une palette dans le store.

**Barres horizontales / répartition / progression** → `sonic-list` (DP = le tableau) dont le gabarit est une grille `7rem 1fr 3rem` : libellé, `sonic-progress max="100" size="sm" type="primary" "data-bind":"" "::value":"$v"`, valeur. Le plus lisible pour classer des catégories ; trie dans la transform.

**Jauge / objectif** : `sonic-progress` grand + « 72 % de l'objectif » ; ou anneau en `sonic-shader` (param0 = ratio) si le style l'exige.

**Heatmap / calendrier d'activité** → `sonic-matrix dataProvider="ds" key="cells" cols="16" gap="3" palette='{"1":"#e8e4f8","2":"#b8aee0","3":"#6e64a0","4":"#4f1fff"}'` : la valeur 0 = case vide (non dessinée), commence l'échelle à 1 ; échelle séquentielle d'une seule teinte ; légende en HTML.

**Carte choroplèthe / points** → rules=["carte"] (`sonic-map`).

**Table** : voir la compétence `outil` (§3) ; colonnes numériques alignées à droite, `tabular-nums`, total en pied.

Règles de lecture : un graphique = une question (titre = la réponse) ; axe des barres à zéro ; 1 couleur + 1 accent ; pas de camembert au-delà de 3 parts (barres horizontales) ; pas de 3D ; les unités dans le titre ou la valeur, pas sur chaque point.

## 3. Données
- Instantané figé : `data.stores.ds.initial` (atelier : APIs publiques du catalogue, dis la date de la donnée).
- Données vivantes des visiteurs : `data.sources.x = {collection, sort, limit}` (+ `intake` pour les recevoir) et `data.transforms` qui agrègent (`$count`, `$sum`, `$average`, groupements `{clé: $sum(v)}`) ; la page se met à jour quand la collecte change.
- Variation : calcule `delta` et son `type` (success/danger) dans la transform, jamais en dur dans le gabarit.
- Formats : `intl:fr-FR` (séparateur de milliers), `:currency:EUR`, `:percent` (0.124 → 12 %), `format="00"` pour des compteurs.

## 4. Vivant sans fatiguer
- `sonic-animate recipe="count-up"` sur les KPI au chargement (une fois) ; `ease` des nodes si les barres changent avec un filtre.
- Indicateur de fraîcheur (« il y a 2 min ») plutôt qu'un clignotement ; nouvelle valeur = flash discret du badge, pas de la tuile.
- Mode écran mural : `sonic-wake-lock auto`, `sonic-fullscreen`, polices +30 %, une vue sans filtres.

## 5. Contrôle spécifique
`render_artifact` à 1280×800 et 390×844 : tous les graphiques visibles (un `sonic-2d` vide = DP mal nommé ou liste vide), extrémités non coupées, barres triées, nombres formatés, pas de « undefined » (`expectNoText`). Lis chaque graphique à voix haute : sa conclusion doit être dans son titre.
