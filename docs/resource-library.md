# Bibliothèque de ressources

Ressources réutilisables du compte (shaders, modèles 3D, données, images, sons) pour ne pas refaire ce qui existe, avec versions, recherche et ressources de base livrées par Creative-stack.

## Modèle

- `resources` : une ligne par ressource du compte (`user_id`, `slug` unique par compte), contenu courant, `version`, `prompt` d'origine, tags, et `base_id` / `base_version` / `base_sha256` quand elle est copiée d'une ressource de base.
- `resource_versions` : versions précédentes (20 conservées). Compte dans le quota de stockage.
- Références : `mine:<slug>` et `base:<addon>/<id>` (ex. `base:shader/plasma`).
- `updateAvailable` : la ressource de base a une `version` plus récente que celle copiée.

## Ressources de base

Source unique : `src/addons/<addon>/library/` de Creative-stack, agrégé dans `dist/library.json`. Synchro : `node scripts/sync-resource-library.mjs <creative-stack>` → `apps/api/config/artifacts/library.json` (lu par `BaseResources`). À relancer à chaque montée de version de creative-stack.

## Accès

- MCP : `search_resources`, `get_resource`, `save_resource`, `delete_resource` (l'atelier a les trois premiers ; pas de suppression).
- REST (`ROLE_USER`) : `GET /api/resources?q=&kind=&tag=&base=`, `GET /api/resources/{ref}`, `GET /api/resources/{ref}/content?version=`, `POST /api/resources`, `DELETE /api/resources/{slug}`.
- Contenu contrôlé par `ResourceContent` : shader = `mainImage(out vec4, in vec2)`, UTF-8, 64 Ko, sans `#version` ; données = JSON ≤ 1 Mo ; 3D = `.glb` ou JSON ; images / sons : mêmes vérifications d'octets que les assets d'artefact.

## Suivi dans les artefacts

Un fichier copié dans `files` garde sa provenance au niveau du document :
`"resources": {"scene.glsl": {"ref": "base:shader/plasma", "version": 2, "sha256": "…"}}` (validé par `ArtifactFiles`, jamais transmis au viewer). `ArtifactResourceSync` compare avec la version courante :

- `GET /api/artifacts/{slug}/resource-updates` → `{member: [{file, ref, usedVersion, latestVersion, updateAvailable, modified, missing}]}` ;
- `POST /api/artifacts/{slug}/resource-updates` `{files?: [nom], force?: bool}` remplace le contenu des fichiers concernés et crée une version de l'artefact. Un fichier modifié dans l'artefact (sha256 différent) n'est écrasé qu'avec `force`.

L'agent renseigne `resources` d'après la fiche renvoyée par `get_resource` (consigne dans l'outil et dans le guide `fichiers`).

## À faire ensuite

- ressources de base 3D et données dans Creative-stack ;
- badge « ressources à mettre à jour » dans la liste de la bibliothèque (aujourd'hui : bandeau dans l'atelier).
