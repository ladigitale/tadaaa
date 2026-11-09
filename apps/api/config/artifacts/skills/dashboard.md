---
name: dashboard
description: Tableaux de bord, KPI, rapports chiffrés, suivi en direct : hiérarchie des chiffres, grille de panneaux, graphiques sonic-plot (line, area, bar, barh, scatter, pie, donut, histogram, heatmap, sparkline) pilotés par DataProvider, filtres en radios, survol et clic publiés, formats de nombres, données collectées.
---
Prérequis : la compétence `design` (demande `rules=["design","dashboard"]` ensemble) et la règle `["listes"]`. Un dashboard répond à 3 questions en 5 secondes : ça va ? qu'est-ce qui a changé ? où regarder ? Les bibliothèques de graphiques du catalogue `scripts` se chargent mais ne peuvent pas être appelées (aucun JS) : les graphiques sont des `sonic-plot` (§2), les autres recettes servent aux visuels sur mesure.

## 1. Plan
- Suit le thème (jetons). Titre + période (« Octobre 2026 », « mis à jour le … ») + 1 filtre global (période, site) en haut : radios Concorde sur un DataProvider (segments `sonic-group` ou `sonic-pop` + `sonic-menu-item radio`, voir `design` §1 bis), dont les graphiques dérivent par `sonic-jsonata`.
- **Bandeau KPI** : 3–5 tuiles FIXES dans une `div` en grille `repeat(auto-fit,minmax(11rem,1fr))` (une `sonic-list` ignore le `display:grid` posé sur elle : ses items sont dans un conteneur interne ; pour lier un `::type` dans une tuile, mets une `sonic-list` d'un seul item dans chaque tuile). Hiérarchie : le KPI principal plus grand (`grid-column:span 2`) que les secondaires ; sur téléphone 2×2, pas 4 tuiles empilées. Tuile : étiquette petites capitales (`font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6`), valeur 28–40 px 700–800 `tabular-nums` (`sonic-value format="intl:fr-FR"`, `format="intl:fr-FR:currency:EUR"` ou `format="intl:fr-FR:percent"` ; dans un gabarit de liste, `"::format":"$f"` fonctionne aussi avec la creative-stack à jour), variation en `sonic-badge variant="ghost" size="sm"` (`::type` success/danger selon le signe, calculé dans la transform), mini-courbe facultative.
- **Grille de panneaux** : `display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,Xrem),1fr))` avec X choisi pour que le nombre de colonnes à 1280 px égale le nombre de panneaux secondaires (2 panneaux → 28rem, 3 → 20rem), sinon une colonne reste vide ; le graphique principal en `grid-column:1/-1`. Panneau = surface `color-mix(in oklab,var(--sc-base-content) 3%,var(--sc-base))` + bordure 1 px 10 %, rayon `--sc-rounded-lg`, padding 16–20, titre 14–15 px 600 + phrase de lecture (« +12 % sur un an, porté par la billetterie »).
- Ordre : ce qui demande une action d'abord (alertes, seuils dépassés), puis tendances, puis détail (table).

## 2. Graphiques (recettes vérifiées)
### 2.1 `sonic-plot` : le choix par défaut
Un composant, dix types : `line`, `area`, `bar`, `barh`, `scatter`, `pie`, `donut`, `histogram`, `heatmap`, `sparkline`. SVG dessiné avec les jetons `--sc-*` (couleurs des séries `--sc-primary|info|success|warning|danger`, grille `--sc-base-300`, axes `--sc-base-400`, texte et police du thème) : une palette de zone (design §1) recolore donc tous les graphiques d'un coup.
- **Données** : `data-provider` = un DP qui est un tableau de lignes, ou un objet `{data|rows|items: […]}` (on peut y ajouter d'autres clés, ex. `total`, lues par `sonic-value`). Champs : `x`, `y` (une ou plusieurs colonnes : `"y":"ventes,moyenne 7 j"`), `series` (colonne qui sépare les séries), `value` (pie, donut, heatmap), `size` (rayon du scatter). Axe X détecté : nombres, dates ISO `2026-03-14` (axe de dates), sinon catégories dans l'ordre des lignes.
- **Réglages** : `curve` (`linear|monotone|step`), `stack`, `points`, `bins` (histogramme), `aggregate` (`sum|mean|count|min|max`, ex. remplissage moyen par lieu), `height`, `heading`, `x-label`, `y-label`, formats nommés `format-x|y|value` (`compact`, `integer`, `percent` (0..1), `currency:EUR`, `date`, `auto-date`). Descripteur JSON `spec` pour le reste : `rules: [{value, label}]` (objectif, cible, zéro), `colors: ["primary","info","#1b1f3b"]` (jetons ou #hex), `yMin`, `yMax`, `legend`, `grid`.
- **Recettes** : KPI → `sparkline` (`height="44"`, `spec='{"colors":["#1b1f3b"]}'` sur une tuile colorée) ; évolution → `line`/`area` avec `rules` pour l'objectif ; écart à N-1 → `bar` (valeurs négatives gérées) + `format-y="percent"` ; classement → `barh` (données déjà triées) ; part d'un tout ≤ 3 parts → `donut` (total au centre) ; distribution → `histogram` (`bins` 5–15) ; corrélation → `scatter` (`size`, `series`) ; jour × heure → `heatmap`.
- **Interactif sans code** :
  - filtre → radios sur un DP `filtre` → `sonic-jsonata inputProvider="filtre" outputProvider="ventesF"` qui renvoie `{data: lignes filtrées, total, libellés}` → `sonic-plot data-provider="ventesF"` ;
  - type de graphique au choix → une 2e `sonic-jsonata` sur le même DP publie le descripteur (`{type, stack, points, colors}`) lu par `spec-data-provider` ;
  - survol et clic → `out-data-provider="ventesOut"` publie `{hover, selected, count, status, error}` avec `{series, label, value, share?, row}` : affiche `sonic-value dataProvider="ventesOut" key="hover.label"` / `"selected.value"` au-dessus du graphique (« survol → juin 1 320 »).
- **Pièges** :
  - `spec-data-provider` REMPLACE `spec` (pas de fusion) : remets les `colors`, `rules`, `yMax` dans le descripteur publié par le DP.
  - Les couleurs suivent l'ordre des séries, pas leur nom : filtrer une série change la couleur des suivantes ; garde le même ordre de lignes, ou passe `colors` calculées dans le descripteur dérivé.
  - `bins` > 15 ou un `barh` sur toute la largeur d'un écran large : graduations serrées ; garde le `barh` dans une demi-largeur.
  - Un `sonic-plot` vide = DP mal nommé ou forme inattendue : vérifie `status`/`error` via `out-data-provider`.

### 2.2 Visuels sur mesure (quand `sonic-plot` ne suffit pas)
**Préparer** : une `sonic-jsonata inputProvider="ds" outputProvider="graphe" expression="…"` calcule les formes à partir des données (le store garde les nombres bruts). Le DP de sortie contient des listes de sprites. Ratio du canevas ≈ 2:1 (`width="600" height="300"`) : il garde ses proportions, un 800×220 ne fait plus que 85 px de haut sur téléphone ; pour un graphe très large, deux versions alternées par `sonic-page-state` + `sonic-if`.

**Barres verticales / courbe / aire** → `sonic-2d width="600" height="200" background="transparent" sprites="graphe.formes"` (le `sonic-2d` remplit la largeur et garde le ratio ; 4 par page max) :
- barre : `{"name":"b"&$i,"shape":"box","w":$bw*.6,"h":$v/$max*($H-20),"x":$bw*$i+$bw/2,"y":$H-($v/$max*($H-20))/2,"radius":3,"fill":…}` ; la dernière (ou la sélection) en accent, les autres en `color-mix` pâle — la couleur porte le message.
- courbe/aire : un sprite `polygon` centré (`x:$W/2,y:$H/2`) dont `points` = `"x,y x,y …"` relatifs au centre, fermé par les deux coins bas pour une aire (`fill` = accent en hex 8 chiffres pour la transparence, ex. `#4f1fff2e` ≈ 18 %, + `stroke` accent, `line:2`) ; points en `circle` r 3, le dernier r 6. Laisse 8 px de marge horizontale pour ne pas couper les extrémités.
- étiquettes : en HTML autour du `sonic-2d` (ligne de dates dessous en `display:flex;justify-content:space-between`, max et dernière valeur au-dessus) ; les sprites `text` (nets et dans la police du conteneur avec la creative-stack à jour) restent figés à leur taille logique : l'HTML s'adapte mieux. Pas de survol : écris les valeurs clés.
- annotations : marque l'événement qui explique la courbe (un `circle` plus gros + un `box` fin vertical au point, et le libellé en HTML au-dessus : « pic : ouverture de la billetterie ») ; comparaison à la période précédente = une seconde courbe « fantôme » (même polygone, `fill` transparent, `stroke` texte à 25 %) sous la courbe actuelle.
- clic sur une barre : `target="ds" pick-names="^b"` → action `pick` `{objectId:"b7"}` ; le réducteur sélectionne, un texte `sonic-value` affiche le détail.
- couleurs : un sprite ne lit pas les variables CSS, il faut des #hex littéraux. Accorde-les aux composants voisins : si la page suit le thème, ne mets pas `sonic-progress type="primary"` (couleur du thème du lecteur) à côté d'une courbe d'une autre couleur ; utilise `type="neutral"` pour les barres et réserve l'accent hex à la courbe, ou adopte une palette d'auteur (design §1) et reprends ses hex dans les sprites.

**Barres horizontales / répartition / progression** → `sonic-list` (DP = le tableau) dont le gabarit est une grille `7rem 1fr 3rem` : libellé, `sonic-progress max="100" size="sm" type="primary" "data-bind":"" "::value":"$v"`, valeur. Le plus lisible pour classer des catégories ; trie dans la transform.

**Jauge / objectif** : `sonic-progress` grand + « 72 % de l'objectif » ; ou anneau en `sonic-shader` (param0 = ratio) si le style l'exige.

**Heatmap / calendrier d'activité** → `sonic-matrix dataProvider="ds" key="cells" cols="16" gap="3" palette='{"1":"#e8e4f8","2":"#b8aee0","3":"#6e64a0","4":"#4f1fff"}'` : la valeur 0 n'est pas dessinée (trou = donnée absente) : 1 = « aucune activité » (teinte très pâle), puis 2, 3, 4… ; 0 seulement pour une case réellement inexistante ; échelle séquentielle d'une seule teinte ; légende en HTML.

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
`render_artifact` à 1280×800 et 390×844 : tous les graphiques visibles (un `sonic-plot` ou un `sonic-2d` vide = DP mal nommé ou liste vide), extrémités non coupées, barres triées, nombres formatés, pas de « undefined » (`expectNoText`). Joue chaque filtre (`click` sur le déclencheur du `sonic-pop` puis sur `sonic-menu-item[value='…']`, ou sur `sonic-button[value='…']`) et vérifie le total et le libellé (`expectText`). Lis chaque graphique à voix haute : sa conclusion doit être dans son titre.
