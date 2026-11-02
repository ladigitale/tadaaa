---
name: design
description: À lire en premier avant toute interface visible. Ce qui marche vraiment pour styler un artefact (style inline, jetons, palettes de zone), le brief, le choix de l'archétype (vitrine, jeu, outil, dashboard, expérience), la checklist et le contrôle visuel.
---
Fondations communes à tous les artefacts. Lis ensuite UNE compétence d'archétype (§3). Tout ce qui suit a été vérifié dans le viewer (Concorde 5.1, creative-stack).

## 1. Comment on style ici (faits vérifiés)
- **Le style inline est la voie normale.** `attributes.style` accepte tout le CSS moderne : grid, flex, `clamp()`, `min()`, `color-mix()`, dégradés, `backdrop-filter`, `box-shadow`, `aspect-ratio`, `position:sticky`, `text-wrap:balance`, `font-variant-numeric`, `container-type` + unités `cqi`. Pas de `<style>`, pas de feuille CSS.
- **Les classes utilitaires (Tailwind) sont quasi absentes** : seules celles que le viewer utilise lui-même existent (≈50 : `flex`, `flex-col`, `grid`, `gap-2`, `gap-3`, `p-2`, `p-3`, `p-4`, `m-0`, `text-sm`, `text-xl`, `font-semibold`, `opacity-70`, `rounded-md`, `hidden`, `sr-only`…). `gap-6`, `text-4xl`, `bg-primary`, `rounded-xl`, `md:` etc. **ne font rien**, sans erreur. N'utilise `class` que pour `sr-only` ou les `::class` de liste.
- **Inline = ni `:hover`, ni media queries, ni `@keyframes`.** Donc :
  - survol / focus / appui → composants qui les ont (`sonic-button` toutes variantes, `sonic-menu-item`, `sonic-sfx` pour le son) ; une carte cliquable = `sonic-button variant="unstyled" shape="block"` qui enveloppe la carte ;
  - responsive → grilles fluides (`repeat(auto-fit,minmax(16rem,1fr))`), `flex-wrap`, `clamp()`, `min()`, unités `svh`/`cqi` ; pour un vrai changement de structure : `<sonic-page-state id="page">` puis `sonic-if dataProvider="pageState" key="width" lt="720"` / `gt="719"` (aussi `touch`, `hover`, `reducedMotion`) ;
  - mouvement → `sonic-animate`, `sonic-timeline`, `ease` des nodes, shaders (voir rules.animation).
- **Le thème appartient au lecteur.** Le viewer applique le thème choisi par la personne (≈14 thèmes clairs et sombres) ; le champ `theme` du document n'est pas utilisé. Deux stratégies, choisis-en une :
  1. **Suivre le thème** (outils, dashboards, formulaires) : uniquement des jetons — `var(--sc-base)` (fond), `--sc-base-content` (texte), `--sc-base-50…900` (surfaces et bordures, du plus proche du fond au plus proche du texte), `--sc-primary` / `-content`, `--sc-success|warning|danger|info` / `-content`, `--sc-rounded`, `--sc-rounded-lg`, `--sc-font-family-base`, `--sc-font-family-headings`. Mélanges sûrs : `color-mix(in oklab, var(--sc-base-content) 8%, var(--sc-base))` pour une surface, `… 12%, transparent` pour une bordure. Jamais de gris ou de blanc codé en dur.
  2. **Palette d'auteur** (vitrine à identité forte, jeu, expérience) : `page.background` + une **palette de zone** sur le conteneur racine, qui recolore aussi les composants Concorde (les variables traversent leur shadow DOM) :
     `--sc-base:#101014;--sc-base-content:#ece8e1;--sc-base-100:#1c1c22;--sc-base-200:#26262e;--sc-base-300:#34343e;--sc-primary:#e8ff59;--sc-primary-content:#141600;--sc-input-bg:#1c1c22;--sc-input-color:#ece8e1;--sc-input-c:#ece8e1;--sc-input-border-color:#34343e;background:var(--sc-base);color:var(--sc-base-content)`
     Les champs de formulaire ne suivent PAS `--sc-base-100` redéfini localement : redéfinis les 4 `--sc-input-*`. Un `style="color:…"` posé sur un `sonic-button` est ignoré : passe par les jetons de zone. `--sc-btn-rounded` règle l'arrondi des boutons.
- **Interdit : `select` natif et `sonic-select`.** Ne les utilise jamais dans un artefact (rendu et thème non maîtrisés, popup native hors du viewer). Tout choix dans une liste (filtre, tri, catégorie, réglage) = `sonic-pop` : le déclencheur (`sonic-button` qui affiche la valeur courante + `sonic-icon name="nav-arrow-down"` en `slot="suffix"`) puis un `sonic-menu slot="content" direction="column" align="left" size="sm" min-width="12rem"` dont chaque choix est un `sonic-menu-item` (documenté dans Concorde : sonic-pop / sonic-menu). Le choix écrit dans le store via `sonic-action type="set" payload={"statut":"Payée"}` qui enveloppe le `sonic-menu-item` ; le libellé du déclencheur et le `type`/`variant` actif du `sonic-menu-item` se lient à l'état (`"::variant"`, comme les onglets de `multivue`). Un menu ouvert se referme au clic sur un item. Le bouton du déclencheur porte le `label` visible (pas de champ sans libellé).
- **Titres** : `h1…h3` n'ont pas de style marqué par défaut. Donne-leur toujours `font-family:var(--sc-font-family-headings)` (ou ta police), une graisse, un `line-height` serré (0.95–1.15) et un `letter-spacing` négatif au-delà de 32 px.
- **Polices** : `fonts` (4 max, Google Fonts) puis `font-family:'Nom',repli`. Une paire suffit : une de caractère pour les titres, une neutre pour le texte ; mono pour chiffres/code si l'archétype le veut.
- **`sonic-animate` est transparent pour la mise en page** : ses enfants deviennent ceux du parent. Mets-le dans un conteneur `display:flex;flex-direction:column;gap:…`, jamais directement dans une grille.

## 2. Le brief (5 lignes, avant le JSON)
1. Qui, sur quel écran (téléphone d'abord ?), pour quelle tâche (découvrir, jouer, comparer, saisir, surveiller, ressentir) ?
2. L'objet principal (scène, liste, chiffres, carte, formulaire, visuel) : il prend la place, le reste l'entoure.
3. Archétype (§3) et stratégie de thème (§1 : suivre ou palette d'auteur).
4. Direction en 3 mots concrets (ex. « papier, encre, serif », « néon, nuit, mono », « clinique, dense, calme ») → police, palette, arrondis, densité.
5. Mouvement : aucun / discret / signature (un seul moment fort, pas dix).
Ne pose une question que si l'objet principal ou le public est inconnu.

## 3. Choisir l'archétype puis lire sa compétence
| Demande | Compétence | Squelette |
|---|---|---|
| site vitrine, landing, one-page, événement, portfolio, CV, récit qui défile, diaporama | `vitrine` | une vue qui défile, sections pleine largeur |
| jeu, plein écran, arcade, plateau, puzzle, physique, 3D, multijoueur | `jeu` | `page.padding:"none"`, scène + HUD + écrans superposés |
| outil, back-office, CRUD, catalogue filtrable, recherche, configurateur, formulaire long, multi-pages | `outil` (+ `multivue`) | barre d'outils + liste/détail, store `etat` |
| tableau de bord, KPI, suivi, rapport chiffré, graphiques | `dashboard` | bandeau KPI + grille de panneaux |
| expérience, art génératif, audio-réactif, caméra, IA locale, instrument, installation | `experience` | une scène plein cadre + commandes discrètes |
| carte | `carte` en plus de l'archétype | |
Une demande mixte prend l'archétype de son objet principal (un quiz « jeu télé » = jeu ; une page d'événement avec carte = vitrine + carte).

## 4. Système visuel (valeurs par défaut sûres)
- **Espacements** : échelle 4 / 8 / 12 / 16 / 24 / 32 / 48 / 72 px. Intérieur d'un composant 12–20, entre éléments liés 8–12, entre groupes 24–32, entre sections `clamp(48px,8vw,112px)`. Plus c'est lié, plus c'est proche.
- **Typo** : 3–4 tailles par écran, rapport ≈1.25 (outil) à ≈1.5+ (vitrine). Corps 15–18 px, `line-height` 1.5–1.65, mesure ≤ 70ch (`max-width:65ch`). Petites capitales d'étiquette : 11–13 px, `letter-spacing:.08em`, `text-transform:uppercase`, `opacity:.6`. Chiffres : `font-variant-numeric:tabular-nums`.
- **Couleur** : 60/30/10 — fond, surfaces, accent. Un seul accent ; les statuts passent par `type` (success, warning, danger, info) des composants. Texte secondaire = `opacity:.6–.75`, pas un gris inventé. Contraste ≥ 4.5:1 pour le texte.
- **Profondeur** : une seule logique par page — bordures fines (outil), ombres douces et grandes (`0 20px 50px -20px rgb(0 0 0/.35)`, vitrine), ou aplats (jeu, brut). Pas d'ombre + bordure + fond sur le même élément.
- **Arrondis** : un rayon pour les surfaces, un pour les petits éléments, cohérents avec `--sc-rounded`.
- **Icônes** : `sonic-icon library="iconoir"`, noms vérifiés par `find_icons`, une taille par groupe, porteuses de sens. Jamais d'emoji comme icône d'interface (sauf jeu ou ton enfantin assumé).

## 5. États et accessibilité (sinon c'est inachevé)
Vide (gabarit `no-item` : icône + phrase + action), chargement (`sonic-loader mode="inline"`), erreur (`sonic-alert status="error"` + marche à suivre), sélection, désactivé, zéro résultat, première visite (invite son / caméra / plein écran). Cibles tactiles ≥ 44 px. Chaque champ a un `label`. Ordre du document = ordre de lecture. `aria-label` (via `::aria-label` en liste) sur les boutons-icônes. Respecter `reducedMotion` (les recettes d'animation le font, tes shaders doivent ralentir via un param).

## 6. Pièges techniques qui cassent le rendu sans erreur
- Store à réducteur non trivial : `budgetMs` (data.stores) ou `budget-ms` (sonic-store) ≥ 30, 50 pour un jeu. Au-delà du budget (défaut 8 ms, 60 ms pour les 5 premières actions), l'action est ignorée ; l'avertissement « [sonic-store …] budget … dépassé » apparaît dans les `warnings` du rendu. Pendant la mise au point, affiche `sonic-value dataProvider="<store>" key="lastError"`.
- Pas d'objet vide `{}` dans un `initial` : il revient en tableau `[]`. Mets au moins une clé (`{"play":{"select":0}}`).
- `sonic-list` veut un DataProvider qui EST le tableau (`sonic-jsonata` → outputProvider), jamais `ds.items`. Elle ignore un `display:grid/flex` posé sur elle (items dans un conteneur interne) : mets la mise en page dans le gabarit, ou des éléments fixes dans une `div` en grille.
- Les props de composant se lient dans un gabarit (`"::type"`, `"::variant"`, `"::value"`, `"::payload"`), et `::format` de `sonic-value` (creative-stack à jour ; sinon écris `format` en littéral).
- Ancres internes : `href="#id"` désigne d'abord une VUE ; sinon le viewer fait défiler jusqu'à l'élément `attributes.id` de la vue courante (viewer à jour). Donne des id simples (`programme`, `tarifs`) distincts des id de vues.
- `sonic-if` garde son contenu dans le DOM (masqué) : un test « texte absent » échoue, teste plutôt l'état.
- Noms de dataProvider / target non vérifiés par la validation : relis-les.

## 7. Anti-générique (refuse ces réflexes)
`select` / `sonic-select` (toujours `sonic-pop` + `sonic-menu` + `sonic-menu-item`, §1), Dégradé violet→bleu, trois cartes identiques à icône ronde, hero centré titre + sous-titre + bouton sans objet, tout centré, ombres partout, emojis-icônes, lorem, chiffres inventés, « Bienvenue sur… », boutons « En savoir plus » en série, plus de deux accents, animations sur chaque bloc. À la place : un parti pris (typo, cadrage, couleur ou mouvement) tenu partout, de l'asymétrie maîtrisée, du vrai contenu, un moment fort.

## 8. Contrôle avant publication
1. `validate_artifact` (ou `preview_artifact` dans l'atelier) sans erreur.
2. Si `render_artifact` est disponible : captures à 390×844 ET 1280×800, `settleMs` 1500, puis `fullPage:true` pour voir toute la page (sinon seule la fenêtre est capturée) ; `steps` = tableau d'OBJETS (`{"click":"#id"}`), une étape inconnue est signalée ; `{"shot":"nom"}` renvoie une capture intermédiaire ; les chiffres en count-up sous la ligne de flottaison valent encore « 0 » dans `text`. Regarde vraiment (hiérarchie, débordements, contrastes, états). Interactif : `steps` (clics, touches) + `expectText`. Jeu ou réducteur : `simulate_store` jusqu'à la fin de partie, puis `fps:true`.
3. Dans l'atelier : `check_preview` joue le brouillon courant de la même façon (mêmes options, mode `simulate` pour un réducteur) ; il renvoie erreurs, texte affiché et fps mais pas l'image : relis la checklist ligne à ligne et demande à l'utilisateur de regarder l'aperçu sur téléphone.
Checklist : [ ] brief fait, archétype lu [ ] stratégie de thème tenue (jetons OU palette de zone complète) [ ] aucune classe Tailwind inexistante [ ] aucun `select` / `sonic-select` (liste de choix = `sonic-pop` + `sonic-menu` + `sonic-menu-item`) [ ] objet principal visible sans défiler sur téléphone [ ] une action primaire par zone [ ] tous les états [ ] contraste et cibles tactiles [ ] noms de DP relus, `budgetMs` réglé [ ] aucune donnée inventée.
