---
name: multivue
description: Interfaces à plusieurs panneaux ou vues (onglets, liste + détail, modal, carte + liste) avec un état partagé. À lire avant d'écrire des onglets, un modal ou une sélection.
---
## Principe : un store `etat`, des vues qui le lisent
Un store (`data.stores.etat = {initial, reducer}`) porte l'état d'interface ; les actions sont envoyées par `sonic-action` (`target:"etat"`, `type`, `payload`). Le réducteur gère les événements ; les simples choix (onglet sans modal, filtre, sélection dans une liste) s'écrivent directement dans le même store par des radios Concorde (`formDataProvider="etat" name="…" value="…" radio`, voir `design` §1 bis), dont l'état actif s'affiche seul. Ne stocke que des identifiants (`sel`) ; les fiches se retrouvent par filtre JSONata (sonic-jsonata, outputProvider "fiche").

## Onglets (une vue, plusieurs panneaux)
- Barre : `sonic-menu direction="row"` > par onglet `sonic-menu-item radio formDataProvider="etat" name="onglet" value="carte"` (onglet actif rendu par Concorde). Si changer d'onglet doit aussi fermer un modal (patron ci-dessous), garde à la place `sonic-action type="tab"` pour que le réducteur le sache.
- Panneau : `sonic-if dataProvider="etat" key="onglet" equals="carte"`. ATTENTION : sonic-if garde son contenu dans le DOM (il ne le détruit pas). Un composant à état interne (modal, carte) y garde cet état.
- Plusieurs vraies vues (hash URL) : `views[]` ; une vue `hidden:true` reste accessible par son #id.

## Liste + détail
- Une ligne de liste ne sélectionne pas : elle contient un bouton « Détails » (`sonic-action type="select" data-bind "::payload":"$id"`). Ajoute « Voir sur la carte » (`type="locate"`) si une carte existe.
- Le détail est un `sonic-modal` ; titre en `sonic-modal-title`, champs en `sonic-modal-content`. La croix est intégrée : pas de bouton Fermer en double.

## Modal rouvrable (piège classique)
Un modal se ferme tout seul (croix, fond, Échap) sans que le store le sache. Un `sonic-if` sur `detOn` ne le rouvre alors jamais. Patron fiable : DEUX modals (a et b), chacun dans un `sonic-subscriber dataProvider="etat"`, avec `data-bind` et `"::visible":"$visA"` (resp. `$visB`). Réducteur : `select` bascule `detOn` a<->b et met `visA = ($n='a')`, `visB = ($n='b')` (booléens) ; `close`, `tab`, `fit`, `locate` remettent les deux à false. Chaque ouverture réécrit un `true` explicite sur la copie qui était fermée.

## Carte + liste (sonic-map)
Voir `rules.carte`. Le point cliqué envoie `pick-action-type="select"` au store ; une vue publiée (`view-provider`) recadre ou vole vers la sélection. Un seul résultat JSONata est un objet : enveloppe les listes avec `$append([], …)`.

## Réducteur : conventions
`$t := $action.type; $p := $action.payload; $s := $state.seq + 1` ; chaque branche renvoie `$merge([$state, {...}])`. `seq` s'incrémente à chaque changement de vue pour forcer les composants qui réagissent à un « nouvel ordre ». Gros réducteur (plusieurs Ko) : le mettre dans `files` ("@file:etat.jsonata").

## Mobile
Une colonne ; les onglets remplacent les panneaux côte à côte ; modal `maxWidth="min(100vw, 36rem)"`. Cibles tactiles ≥ 40 px (size="md" ou plus pour les boutons principaux).

## À tester (render_artifact)
Ouvrir/fermer le détail TROIS fois d'affilée (sélecteur `sonic-modal-close`), changer d'onglet puis revenir, un filtre à un seul résultat, un filtre à zéro résultat.
