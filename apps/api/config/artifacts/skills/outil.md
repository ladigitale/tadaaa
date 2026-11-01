---
name: outil
description: Interfaces expertes et multi-pages (back-office, catalogue filtrable, recherche, CRUD, configurateur, formulaire long, gestion de stock ou de planning) : densité, barre d'outils, filtres, liste/détail, tables, raccourcis, formulaires. À combiner avec multivue.
---
Prérequis : les compétences `design` et `multivue` (demande `rules=["design","outil","multivue","listes"]` ensemble : mécanique onglets, modal, état partagé), la règle `listes` (sonic-list, gabarits, filtres). Un outil se juge à la vitesse d'une tâche répétée : trouver, comparer, modifier, recommencer.

## 1. Partis pris
- Suit le thème du lecteur (jetons uniquement). Densité « standard » à « dense » : corps 14–15 px, interlignes 1.4, espacements 8/12/16.
- Une seule police neutre (celle du thème) ; la mono pour identifiants, codes, montants alignés.
- Profondeur par bordures fines `1px solid color-mix(in oklab,var(--sc-base-content) 12%,transparent)`, fonds de surface `color-mix(… 3–5%, var(--sc-base))`, pas d'ombres sauf modal et menus.
- Couleur = information : l'accent pour l'action et la sélection, les `type` de statut pour les états métier, rien de décoratif.

## 2. Plan d'écran
- **Pages** : une vue par domaine (`views[]` : Tableau, Commandes, Produits, Réglages) ; le viewer affiche les onglets. Plus de 5 vues ou sous-sections : un menu `sonic-menu direction="column"` en colonne gauche (`display:grid;grid-template-columns:minmax(12rem,15rem) 1fr`), qui devient un `sonic-menu direction="row" scrollable` en haut sous 720 px (`sonic-page-state` + `sonic-if lt`).
- **En-tête de page** : titre (20–24 px, 600), compteur (« 128 commandes »), action primaire à droite (« Nouvelle commande »). En dessous, la barre d'outils.
- **Barre d'outils** (filtres) : un formulaire `formDataProvider="filtres"` en ligne `display:flex;flex-wrap:wrap;gap:8px;align-items:end` : recherche (`sonic-input type="search"` + icône `search`, en premier, la plus large), 2–4 `sonic-select` (statut, catégorie, tri), bouton ghost « Réinitialiser ». Filtres avancés au-delà de 4 : dans un `sonic-pop` « Plus de filtres ».
- **Résultat** : `sonic-jsonata inputProvider="donnees" formDataProvider="filtres" outputProvider="resultats"` (filtre + tri + pagination dans l'expression) → `sonic-list dataProvider="resultats"`. Affiche le nombre de résultats et, à zéro, un gabarit `no-item` avec « Aucun résultat pour ces filtres » + bouton réinitialiser.
- Filtres par boutons (segments, puces) : le DP `filtres` peut être un store (`reducer` : `$action.type = 'set' ? $merge([$state, $action.payload]) : $state`) écrit par `sonic-action type="set" payload='{"statut":"Payée"}'` ; l'expression lit `$statut`. Enveloppe toujours le résultat : `$append([], $input.all[$statut = '' or statut = $statut])` (un seul résultat sinon = objet, pas liste).
- **Puces de filtres actifs** sous la barre : `sonic-badge variant="outline"` par filtre non vide, cliquable pour le retirer (action `clear` avec payload du champ).

## 3. Liste, table ou cartes ?
- **Table** (comparer des attributs, trier, beaucoup de lignes) : gabarit de ligne en grille CSS plutôt que `sonic-table` quand la liste est dynamique : `display:grid;grid-template-columns:minmax(10rem,2fr) 1fr 8rem 7rem 3rem;gap:12px;align-items:center;padding:10px 12px;border-bottom:1px solid …`. Même `grid-template-columns` dans l'en-tête (petites capitales). Nombres alignés à droite, `tabular-nums`. Statut en `sonic-badge size="sm" style="justify-self:start"` avec `"data-bind":"","::type":"$t"` (la donnée porte `t` = success|warning|danger|info|neutral ; les props de composant se lient comme `::type`, `::value`, `::variant`, `::payload`). Sous 720 px, la ligne passe en carte (deuxième gabarit via `sonic-if` sur la largeur).
- **Cartes** (contenus visuels : produits, lieux, personnes) : `repeat(auto-fill,minmax(15rem,1fr))`, image en haut ratio fixe, 2 lignes d'info, 1 action.
- **Liste compacte** (messages, tâches) : une ligne = titre + méta en `opacity:.65` + action à droite.
Toujours : sélection visible (fond accent 10 %), action de ligne explicite (« Détails ») plutôt que ligne entière cliquable si la ligne contient d'autres boutons.

## 4. Détail et édition
- Détail en `sonic-modal` (patron des deux modals de multivue) sur téléphone ; panneau latéral sur grand écran (`grid-template-columns:1fr minmax(20rem,28rem)`, panneau `position:sticky;top:12px`).
- Formulaires : `sonic-form-layout` + `sonic-fieldset label="…"` par groupe ; champs en 2 colonnes `repeat(auto-fit,minmax(14rem,1fr))` ; `label` sur chaque champ, aide sous le champ (`description`), erreurs en `status="error"`. Actions en bas à droite : primaire (« Enregistrer »), secondaire ghost (« Annuler »). Action destructrice : `type="danger" variant="outline"`, isolée à gauche, confirmation par modal.
- Formulaire long : étapes (store `{etape:1}` + `sonic-progress` + « Étape 2 sur 4 »), une question de décision par écran sur mobile.
- Enregistrement : boîte d'envoi + `data.sinks` (rules.collecte) ; retour `sink:ok` → `sonic-toast`/`sonic-alert status="success"` ; brouillon local via le store.

## 5. Expert = clavier
- `sonic-keyboard scope="document"` : `KeyN` (nouveau), `KeyJ`/`KeyK` (ligne suivante/précédente dans le store), `Escape` (fermer). Les indiquer en `code` gris dans les boutons ou une aide `?`.
- Persistance de la vue : la vue active est dans le hash (`#commandes`), l'état de filtres dans le store.
- Actions groupées : case `sonic-checkbox` par ligne + barre d'action qui apparaît (`sonic-if` sur le nombre sélectionné `gt="0"`).

## 6. Données
- Données de référence figées dans `data.stores.<nom>.initial` (atelier : APIs publiques du catalogue) ; données saisies par les visiteurs dans une collecte (`data.sources`) ; ne mélange pas les deux dans un même DP.
- Volumétrie : la transform pagine (`$filter` puis sous-tableau de 50) ; `sonic-queue` pour du chargement progressif d'une API.
- Formats : `sonic-value format="intl:fr-FR:currency:EUR"`, `intl:fr-FR:percent`, `intl:fr-FR` ; dates avec `sonic-date`.

## 7. Contrôle spécifique
Scénario `render_artifact` : saisir dans la recherche n'est pas simulable, mais clique un filtre (`click` sur un `sonic-select` ou un bouton de filtre), vérifie le compteur (`expectText`), ouvre/ferme le détail 3 fois, filtre à zéro résultat. Captures 390 px (table devenue cartes, barre d'outils qui passe à la ligne) et 1440 px (densité, alignements).
