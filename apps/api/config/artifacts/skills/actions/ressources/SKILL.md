---
name: ressources
description: "Bibliothèque de ressources Tadaaa (shaders, modèles 3D, sons, images, jeux de données) : chercher avant de générer (search_resources), lire et adapter (get_resource), enregistrer une création réutilisable avec sa demande d'origine (save_resource), versions et mises à jour des ressources de base. À utiliser avant d'écrire un shader, de modéliser, de composer un son ou de produire des données."
metadata:
  famille: action
  outils: search_resources get_resource save_resource delete_resource
---
# Ressources réutilisables

## Chercher d'abord
`search_resources(kind, query, tag?, includeBase: true)` ; `kind` : `shader` | `model3d` | `data` | `image` | `audio`.
- Ressources de base livrées : `base:<addon>/<id>` (ex. `base:shader/plasma`, `base:shader/sobel`, `base:shader/pixel-art`, `base:shader/hough-lines`, `smoke`, `feu`, `bloom`, `transition`…).
- Ressources du compte : `mine:<slug>`.
- Fiche d'un shader : `channels` (ce qu'il attend sur iChannel0…) et `params` (uParam0…3 : label, min, max, default) : câbler directement des curseurs.

## Lire, adapter
- `get_resource(ref)` : texte (`content`) pour shader, JSON, scène ; base64 pour image, son, glb. `version` pour une version précédente (liste avec `versions: true`).
- Le contenu est copié dans l'artefact (`files` ou asset) : l'artefact ne change pas si la ressource change ensuite.

## Enregistrer
`save_resource(kind, name, description, tags, prompt, from?, content | contentBase64 + filename)`
- `prompt` = la demande d'origine : À RENSEIGNER (l'utilisateur la reprend dans l'atelier).
- `from: "base:shader/plasma"` quand on part d'une ressource de base : on est prévenu de ses mises à jour (`updateAvailable`).
- Même slug + contenu changé = nouvelle version (20 gardées).
- Contrôles : shader = `void mainImage(out vec4 c, in vec2 p)`, sans `#version`, 64 Ko ; données = JSON valide 1 Mo ; modèle = .glb ou JSON de scène ; son et image avec signature vérifiée.

## Supprimer
`delete_resource(slug)` (avec ses versions) ; les artefacts qui l'ont copiée ne changent pas ; les ressources de base ne se suppriment pas.
