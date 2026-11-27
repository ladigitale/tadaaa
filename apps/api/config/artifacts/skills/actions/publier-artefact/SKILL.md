---
name: publier-artefact
description: "Publier et partager un artefact Tadaaa : visibilité private / link / public, brouillon puis promote_artifact, lien secret et sa rotation, icône d'application, image de partage og:image, intégration sur un autre site (embed.js), suppression. À utiliser au moment de mettre en ligne, de rendre public ou de partager un artefact."
metadata:
  famille: action
  outils: publish_artifact update_artifact promote_artifact delete_artifact
---
# Publier et partager

## Visibilité
| Valeur | Qui voit |
|---|---|
| `private` | les membres du jeu de données (défaut conseillé pendant la construction) |
| `link` | quiconque a l'URL avec son jeton secret (`?k=`) : partage discret |
| `public` | tout le monde ; peut apparaître dans la vitrine |

Changer : `update_artifact(slug, visibility: "link")`. Jamais `public` sans accord explicite de l'utilisateur.

## Brouillon puis mise en ligne
- `update_artifact(…, draft: true)` enregistre sans mettre en ligne ; `promote_artifact(slug)` publie la dernière version (et applique les collections, collecte et lecture publique).
- Le résultat donne `version` (dernière) et `liveVersion` (en ligne), `hasDraft`.

## Identité du lien
- `icon` : `{"emoji": "🎲", "background": "#111", "color": "#fff"}`, `{"text": "AB"}` ou `{"src": "@asset:icone.png"}` (carré 512 px) : favicon, couleur du navigateur, icône d'écran d'accueil.
- `image` : `{"view": "id", "wait": 2000}` (capture d'une vue), `{"mode": "carte"}`, ou `{"src": "@asset:apercu.png", "alt": "…"}` (1200 × 630). Sans `image`, Tadaaa capture la vue par défaut.
- Détails : brique `partage`.

## Intégrer ailleurs
- `<script src="https://artifacts.tadaaa.space/embed.js" data-artifact="slug" data-height="600" async></script>` (`data-key` = jeton d'un artefact `link`, `data-view`, `data-theme`) ou `<artifact-embed slug="…">`. Un artefact plein écran a besoin d'une hauteur.
- En intégration, pas de sauvegarde en ligne ni de lecture de l'adresse de la page.

## Supprimer
- `delete_artifact(slug)` est irréversible (versions, assets, données) : demander confirmation.

## Après publication
Donner l'URL renvoyée (et l'URL `?k=` pour `link`). Pour un artefact à collecte, rappeler qu'elle est fermée tant que `open_artifact_intake` n'est pas appelé (skill `donnees-collecte`).
