---
name: fichiers-assets
description: "Ajouter des fichiers à un artefact Tadaaa : assets binaires (images, sons, vidéos, modèles .glb, polices .woff2, gros JSON) envoyés par upload_artifact_asset et référencés par @asset:nom, et fichiers texte du document (files + @file:nom pour shaders, réducteurs, longs textes). À utiliser dès qu'un artefact a besoin d'une image, d'un son, d'un modèle 3D, d'une police ou d'un gros contenu."
metadata:
  famille: action
  outils: upload_artifact_asset list_artifact_assets delete_artifact_asset update_artifact
---
# Fichiers et assets

## Deux mécanismes
| Contenu | Mécanisme | Référence |
|---|---|---|
| Texte : GLSL, JSONata, Markdown, JSON lisible | `files: {"jeu.jsonata": "…"}` dans le document | valeur exacte `"@file:jeu.jsonata"` |
| Binaire : png jpg webp gif avif svg, ogg mp3 wav flac, webm mp4, glb, woff2, gros json | asset de l'artefact | valeur exacte `"@asset:heros.png"` |

## Envoyer un asset
1. L'artefact doit exister : publier d'abord (avec un repli : couleur, forme, texte).
2. `upload_artifact_asset(slug, name, fetch: {url, sha256})` : le serveur télécharge depuis raw.githubusercontent.com ou gist.githubusercontent.com. À préférer dès quelques Ko : recopier du base64 à la main altère les octets.
3. `contentBase64` seulement pour un tout petit fichier.
4. `name` : a-z, 0-9, `.`, `_`, `-` (64 max) ; l'extension doit correspondre au contenu (signature vérifiée ; SVG avec script refusé).
5. Placer `@asset:nom` là où une URL est attendue (`src` d'un sprite, modèle de `sonic-3d`, `fontFiles[].src`, `icon.src`).

Limites : 20 Mo par fichier, 200 par artefact, dans le quota du propriétaire. `list_artifact_assets` donne `ref`, taille, sha256. `delete_artifact_asset` laisse les références dans le document : les retirer.

## Fichiers texte du document
- Lisibles et retouchables par `update_artifact edits` (`path: "/files/scene.glsl"`).
- Gros fichier texte déjà dans un dépôt public : `update_artifact fetch: [{name, url, sha256, encoding: "text"}]`.
- Les `@file:` sont résolus par le serveur avant validation et affichage ; un document testé hors serveur doit les avoir intégrés.

## Polices
`fonts: ["Fredoka:wght@400;700"]` (Google Fonts, 4 max) ou `fontFiles: [{family, src: "@asset:titre.woff2"}]` ; toujours une police de repli dans `font-family`.

## Réutilisable ailleurs ?
Un shader, un modèle, un son ou un jeu de données qui servira à d'autres artefacts va dans la bibliothèque (skill `ressources`).
