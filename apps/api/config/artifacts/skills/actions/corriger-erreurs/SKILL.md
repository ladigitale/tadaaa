---
name: corriger-erreurs
description: "Diagnostiquer et corriger un artefact Tadaaa refusé ou cassé : lire les erreurs de validation (chemin + message), les familles d'erreurs fréquentes et leur correction (composant hors liste, prop inconnue, icône inexistante, liaison interdite, capacité manquante, limite par page, objet vide enregistré en tableau), et les pannes invisibles à la validation (DataProvider mal nommé, JSONata, GLSL, page blanche). À utiliser dès qu'une validation échoue ou qu'un rendu affiche une erreur ou rien."
metadata:
  famille: action
  outils: validate_artifact render_artifact simulate_store get_artifact
---
# Corriger les erreurs

## Méthode
1. Lire TOUTES les erreurs : `path` (JSON Pointer dans le document) + `message`. Les corriger en une seule réécriture ou un seul jeu d'`ops`.
2. Revalider ; puis `render_artifact` (la validation ne voit pas tout).
3. Ne jamais contourner une règle de sécurité (HTML brut, script, URL libre) : elle est voulue ; utiliser le composant ou la recette proposés.

## Erreurs de validation fréquentes
| Message | Correction |
|---|---|
| « Composant hors liste blanche » | composant inexistant ou inventé : chercher dans `get_artifact_catalog` ; HTML : seulement les balises sûres du catalogue |
| `select`, `button`, `input`, `form`… refusés | le message nomme la recette (`get_artifact_catalog(rules=["recettes"])`) : `sonic-pop` pour un choix, `sonic-button`, `sonic-input`, `sonic-fieldset` + `sonic-form-layout` |
| prop / valeur non permise | valeurs permises dans le catalogue compact (`components=[…]`) |
| icône inconnue | `find_icons(query)` (ex. `xmark` → `cancel` en iconoir) |
| liaison refusée (`::…-html`, `::on…`, `::style`, format `js|…`) | lier une valeur simple ; URL liée : https littéral en tête |
| attribut commençant par `on` | refusé comme gestionnaire d'événement : renommer |
| capacité manquante (camera, microphone, bluetooth, serial, xr…) | `"capabilities": [...]` à la racine |
| limite par document (stores 8, sonic-2d 4, segmenter 1, p2p 1…) | regrouper (une clé de plus dans un store existant) ou retirer |
| erreur `sonic-patch : …` | câblage du patch audio : brique `audio` |
| « attributes doit être un objet » au rendu | un `{}` enregistré en `[]` : mettre au moins un attribut (`{"style": ""}`) |
| « Action : {schema?, description?} » | action de flux vide : lui donner une `description` |

## Pannes invisibles à la validation
- Composant vide : nom de `dataProvider` / `target` / `key` faux. Relire contre `data.stores`, `data.transforms`, `data.sources` et les ids de `sonic-store`.
- Store visé par un composant du même id : renommer l'un des deux.
- Erreur JSONata : `render_artifact` → `errors` ; `simulate_store` pour un réducteur (`$input` dans `sonic-jsonata`, `$state` / `$action` dans un réducteur).
- GLSL : `shaderErrors` avec numéros de ligne ; pas de `#version`, `void mainImage(out vec4, in vec2)`.
- Texte dans un store relié à un `sonic-shader` : recopié en propriétés ; le mettre dans un autre store.
- Page trop haute ou rognée : `page.padding`, `max-distance` d'une scène 3D large.

## Lire une partie du document
`get_artifact(slug, path: "/views/0/root/nodes/2")` plutôt que tout le document.
