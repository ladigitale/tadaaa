---
name: corriger-erreurs
description: "Diagnostiquer et corriger un artefact Tadaaa refusé, cassé ou valide mais signalé : erreurs de validation classées par fréquence réelle (icône inconnue, collecte mal déclarée, limites par page, URL et liaisons interdites, composant inventé), avertissements à traiter même quand le document passe (texte gris, champs en vrac, onglet en action, pointerdown), et pannes invisibles à la validation (DataProvider mal nommé, JSONata, GLSL, page blanche). À utiliser dès qu'une validation échoue, renvoie des warnings, ou qu'un rendu affiche une erreur ou rien."
metadata:
  famille: action
  outils: validate_artifact find_icons get_artifact_catalog render_artifact simulate_store get_artifact
---
# Corriger les erreurs

## Méthode
1. Lire TOUTES les erreurs **et tous les `warnings`** : `path` (JSON Pointer dans le document) + `message`. Les corriger en une seule réécriture ou un seul jeu d'`ops`.
2. Revalider, puis `render_artifact` (la validation ne voit pas tout).
3. Ne jamais contourner une règle de sécurité (HTML brut, script, URL libre, liaison de style) : utiliser le composant ou la recette que le message nomme.

## Erreurs mesurées, de la plus fréquente à la plus rare
Relevé des refus réels (documents enregistrés et `validate_artifact`, novembre 2026).

| Message | Cause | Correction |
|---|---|---|
| « Icône «…» inconnue dans iconoir ; proches … » | nom d'icône écrit de mémoire | `find_icons(query)` AVANT d'écrire chaque icône ; reprendre un des noms proches du message (`xmark` → `cancel`) |
| « hors-liste : sonic-… » (`sonic-tabs`, ou un composant trop récent) | composant inventé ou absent du catalogue | `get_artifact_catalog(components=[…])` ; onglets = radios + `sonic-states` (recette `onglets`) ; un composant creative-stack récent non listé : le signaler, il manque au catalogue |
| `select`, `sonic-select`, `button`, `input`, `form` refusés | balise remplacée par une recette | le message nomme la recette (`rules=["recettes"]`) : liste de choix en `sonic-pop` + `sonic-menu-item radio`, `sonic-button`, `sonic-input`, `sonic-fieldset` + `sonic-form-layout` |
| « collection doit être une source déclarée avec `intake` » | `data.sinks.<x>.collection` vise une source sans collecte | déclarer `data.sources.<nom> = {collection, intake: {fields…}}` et viser ce nom |
| « intake.fields.<champ>.max requis » | champ texte sans longueur maximale | `{"type": "string", "max": 200}` (1…max permis), `required` si besoin |
| « writeMode : none \| members \| authenticated » | valeur inventée (`anonymous`, `public`…) | une collecte anonyme passe par `intake`, pas par `writeMode` |
| « URL non autorisée (https uniquement…) » | `http:`, `javascript:`, `data:` hors image, URL relative | URL `https://` littérale ; fichier de l'artefact : `@asset:nom` |
| « Liaison ::style interdite » / `::…-html` / `::on…` | style ou HTML lié à une donnée | texte : `::text-content` ; couleur ou état : `::class` ou `::type` d'un composant |
| « Liaison ::src : la valeur doit commencer par une URL https littérale » | URL entièrement tirée d'une donnée | écrire le début de l'URL en dur (`"https://…/$id"`) ; images de l'artefact : `@asset:` |
| « Attribut événement / srcdoc interdit » | attribut `on…` ou `srcdoc` | renommer l'attribut ; agir par `sonic-action` ou radios |
| « Trop de stores (max 8) » | un store par sujet | regrouper des clés dans un store existant |
| « Trop de sinks (max 4) » | un sink par formulaire | un sink pour plusieurs envois, ou `channels` |
| « Trop de sonic-p2p (max 1) » / limites par page (sonic-2d 4, segmenter 1…) | composant dupliqué par vue | un seul, en tête du document, partagé par les vues |
| « Document trop volumineux (max 512 Ko) » | données ou base64 dans le JSON | binaires en assets (`fichiers-assets`), gros textes en `files` ; données répétitives générées par une transform |
| capacité manquante (camera, microphone, bluetooth, serial, xr…) | `capabilities` absent | `"capabilities": [...]` à la racine |
| erreur `sonic-patch : …` | câblage du patch audio | brique `audio` |
| « attributes doit être un objet » (au rendu) | `{}` enregistré en `[]` | au moins un attribut (`{"style": ""}`) |
| « Action : {schema?, description?} » | action de flux vide | lui donner une `description` |

## Avertissements : le document passe, mais il est raté
Ils figurent dans `warnings` des documents **valides**. Ils sont très fréquents (relevé de novembre 2026 : texte gris dans plus d'un document enregistré sur quatre) : les traiter avant de livrer.

| Avis | Ce qui est signalé | Correction |
|---|---|---|
| `avis:texte-gris` | phrase de 25 caractères ou plus en `opacity` ≤ 0,7, affichée en permanence | la retirer ; ou libellé de bouton explicite ; ou message au moment utile : `sonic-notify watch="store.notice"` (toast) ou `sonic-modal`. L'opacité réduite ne sert qu'aux étiquettes courtes |
| `avis:champs-en-vrac` | 2 champs ou plus dans un `sonic-modal` / `sonic-pop` sans rangement | les envelopper dans `sonic-fieldset` + `sonic-form-layout` (recette `formulaire`) |
| `avis:onglet-par-action` | `sonic-action type="tab…"` pour changer d'onglet | `sonic-button radio formDataProvider name value` + `sonic-states` (recette `onglets`) |
| `avis:pointerdown` | `trigger="pointerdown"` sur un bouton d'interface | retirer `trigger` (départ au clic) ; pointerdown seulement pour un jeu ou un pad |

## Pannes invisibles à la validation
- Composant vide : nom de `dataProvider` / `target` / `key` faux. Relire contre `data.stores`, `data.transforms`, `data.sources` et les ids de `sonic-store`.
- Store visé par un composant du même id : renommer l'un des deux.
- Erreur JSONata : `render_artifact` → `errors` ; `simulate_store` pour un réducteur (`$input` dans `sonic-jsonata`, `$state` / `$action` dans un réducteur).
- GLSL : `shaderErrors` avec numéros de ligne ; pas de `#version`, `void mainImage(out vec4, in vec2)`.
- Texte dans un store relié à un `sonic-shader` : recopié en propriétés ; le mettre dans un autre store.
- Page trop haute ou rognée : `page.padding`, `max-distance` d'une scène 3D large.

## Lire une partie du document
`get_artifact(slug, path: "/views/0/root/nodes/2")` plutôt que tout le document.
