---
name: creer-artefact
description: "Créer un nouvel artefact Tadaaa de bout en bout : cadrer la demande, choisir kit ou composition libre, lire les bonnes skills et le catalogue, écrire le document artifacts/1, valider, publier en privé, tester, livrer l'URL. À utiliser dès qu'on demande une page, un jeu, un outil, un tableau de bord ou une expérience à publier sur Tadaaa."
metadata:
  famille: action
  outils: get_artifact_catalog build_artifact_from_kit validate_artifact publish_artifact render_artifact
---
# Créer un artefact

## 1. Cadrer (une question au plus)
- Pour qui, sur quel écran (téléphone d'abord ?), quelle action principale, quelle ambiance.
- Données réelles ? Elles seront figées dans `data.stores.<nom>.initial` (la page n'appelle pas d'API) ; une donnée envoyée par les visiteurs = collecte (skill `donnees-collecte`).
- Sera-t-il appelé par un agent ou un workflow ? Alors contrat `io` dès le départ (skill `artefact-outil`).

## 2. Kit ou composition libre
- `get_artifact_catalog(examples=false)` renvoie `kits` (signatures). Un kit seulement si la demande correspond à son usage (quiz, sondage, inscription, grille de jeu, shader, direct, régie, one-page…). `build_artifact_from_kit(kit, params)` → `{valid, errors, document}` ; on le personnalise ensuite.
- Demande originale, mise en page ou ambiance précise, « sur mesure » : composition libre. Ne jamais réduire la demande à ce que sait faire un kit.

## 3. Lire avant d'écrire
- Conception : `design` + UN archétype (+ `multivue`, `carte` si besoin).
- Briques des composants utilisés (`briques/donnees` presque toujours ; `listes` dès qu'une liste varie).
- Détail : `get_artifact_catalog(components=[…], rules=[…], examples=false)` ; icônes par `find_icons` ; `search_resources` avant tout shader, modèle, son, image ou jeu de données.

## 4. Écrire le document
- Enveloppe : `{"schema": "artifacts/1", "title", "defaultView", "views": [{id, title, root: {nodes: […]}}], "data": {stores, transforms, sources}}` + selon besoin `page`, `icon`, `image`, `fonts`, `files`, `capabilities`, `io`, `persist`.
- Texte fixe en `textContent` ; texte qui change par `sonic-value` sur un store ou une transform.
- Gros réducteur, shader ou texte : `files` + `"@file:nom"` (lisible, retouchable par `edits`).
- Binaires : assets à envoyer APRÈS la première publication (skill `fichiers-assets`) ; publier d'abord avec un repli.
- Chaque nom de `dataProvider` / `target` relu contre les stores déclarés.

## 5. Valider puis publier
- `validate_artifact` → corriger TOUTES les erreurs en une réécriture, recommencer jusqu'à `valid: true` ; lire aussi `warnings`.
- `publish_artifact(title, document, visibility: "private", tags: ["Jeu"])` → `{id, slug, url, version}`. Privé tant que les tests ne sont pas passés. `tags` : la catégorie (première étiquette) affichée sur les cartes de la vitrine (voir `publier-artefact`).
- Gros document : publier un squelette (data + une vue minimale), puis remplir par `update_artifact` + `ops` : une erreur ne force pas à tout renvoyer.

## 6. Tester, puis livrer
- Skill `tester-artefact` : `simulate_store` pour chaque réducteur, `render_artifact` en 390×844 et 1280×800, scénario pour un jeu.
- Corriger par `update_artifact` (`ops` / `edits`), passer la visibilité demandée (`update_artifact visibility`) ou `link` pour un partage discret.
- Réponse : une phrase sur ce que fait la page + l'URL. Ne pas recopier le JSON.
