---
name: modifier-artefact
description: "Modifier un artefact Tadaaa déjà publié sans le casser : lire seulement la partie utile (get_artifact path), patcher par ops JSON Patch ou edits de sous-chaînes, gros contenus par fetch / opsFrom, brouillon puis promotion, historique des versions et retour arrière. À utiliser pour toute retouche, correction, ajout de vue ou refonte d'un artefact existant."
metadata:
  famille: action
  outils: get_artifact update_artifact promote_artifact restore_artifact_version
---
# Modifier un artefact

## 1. Lire juste ce qu'il faut
- `get_artifact(slug, path: "/views/0/root/nodes/3")` → `{path, value}` ; `keysOnly: true` sur un gros objet pour voir ses clés (`/data/stores`, `/files`).
- Document entier seulement s'il est petit ou pour une refonte.
- `get_artifact(slug, versions: true)` : historique (`version`, `note`, `current`, `live`) ; `version: n` lit une ancienne version.

## 2. Choisir le bon patch
| Changement | Outil |
|---|---|
| Une valeur, un nœud, une clé | `ops` : `{"op": "replace", "path": "/data/stores/jeu/initial/vies", "value": 5}` |
| Ajouter en fin de liste | `{"op": "add", "path": "/views/0/root/nodes/-", "value": {…}}` |
| Déplacer, dupliquer | `move`, `copy` (RFC 6902) |
| Retoucher un shader, un réducteur, un texte long | `edits: [{path: "/files/jeu.jsonata", old: "…", new: "…"}]` (`old` unique, sinon `all: true`) |
| Gros fichier déjà sur GitHub | `fetch: [{name, url, sha256}]` (raw.githubusercontent.com, gist) |
| Refonte de centaines de nœuds | `opsFrom: {url, sha256}` (liste d'ops hébergée) |
| Titre, visibilité, description | champs `title`, `visibility`, `description` |

Tout ou rien : le résultat est validé, rien n'est enregistré en cas d'erreur. Toujours une `note` courte (elle apparaît dans l'historique).

## 3. Ne pas casser la page en ligne
- `draft: true` : nouvelle version de travail, le public garde la précédente ; `render_artifact(slug)`, `simulate_store(slug)` et le viewer avec `?draft=1` voient le brouillon.
- Tester (skill `tester-artefact`), puis `promote_artifact(slug)`.
- Changer la forme d'un état sauvegardé (`data.persist`) : incrémenter `persist.version`, sinon l'ancien état revient.

## 4. Revenir en arrière
- `restore_artifact_version(slug, version: n)` : crée une nouvelle version identique à la version n (l'historique est gardé).

## 5. Pièges
- Indices de `nodes` décalés après un `add` / `remove` : enchaîner les ops dans l'ordre, ou viser par `nodeId` après relecture.
- `"attributes": {}` → `[]` à l'enregistrement : mettre au moins un attribut.
- Ne jamais renvoyer le document entier pour un petit changement (coûteux, risque d'écraser un changement fait entre-temps).
