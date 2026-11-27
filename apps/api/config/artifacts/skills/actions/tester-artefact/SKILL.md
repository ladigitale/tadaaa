---
name: tester-artefact
description: "Vérifier un artefact Tadaaa avant de le livrer : validation et avertissements, réducteurs rejoués sans navigateur (simulate_store jusqu'à la fin de partie), rendu réel dans le viewer (render_artifact : erreurs console, GLSL, texte affiché, captures mobile et bureau, scénario de clics et touches, fps). À utiliser après chaque création ou modification, et quand une page s'affiche vide ou ne réagit pas."
metadata:
  famille: action
  outils: validate_artifact simulate_store render_artifact
---
# Tester un artefact

## 1. Validation (statique)
- `validate_artifact(document)` → `{valid, errors: [{path, message}], warnings}`. Les messages nomment souvent la correction (recette, icône proche, prop attendue). Voir `corriger-erreurs`.
- Un document valide peut porter des `warnings` (texte d'aide estompé, champs en vrac, onglet en action, pointerdown) : les traiter comme des erreurs avant de livrer (`corriger-erreurs`).
- La validation ne voit pas : noms de DataProvider mal écrits, JSONata faux à l'exécution, GLSL qui ne compile pas, mise en page.

## 2. Réducteurs (sans navigateur)
`simulate_store(slug | document, store, actions, until?, snapshots?, tickMs?, maxSteps?)`
- Chaque type d'action au moins une fois, y compris les cas limites (liste vide, valeur maximale, double clic).
- Jeu : `[{type: "start"}, {type: "tick", repeat: 3000}]` avec `until: "phase='over'"` : la partie doit finir. `snapshots: ["phase", "score"]`.
- `metrics.msPerStep` : un store a 50 ms de budget par action ; au-delà l'action est ignorée en vrai.
- `$rand(seed)` est disponible ; `$random`, `$shuffle`, `$now` sont refusés dans un réducteur.

## 3. Rendu réel
`render_artifact(slug | document, viewport, steps, settleMs, fps, fullPage, view)`
- Deux passes : `{"width": 390, "height": 844}` puis `{"width": 1280, "height": 800}`.
- `steps` : `{"click": "sélecteur", "index": 0}`, `{"press": "Enter"}`, `{"hold": "Space", "ms": 300}`, `{"wait": ms}`, `{"shot": "nom"}` (3 captures intermédiaires), assertions `{"waitText"}`, `{"expectText"}`, `{"expectNoText": "undefined"}`, `{"expect": "sonic-shader", "count": 1}`.
- `ok: false` dès qu'une erreur console ou une assertion échoue ; lire `errors`, `shaderErrors` (numéros de ligne), `failedRequests`, `text`.
- `fps: true` pour un jeu ou une scène : viser p95 < 20 ms.
- Brouillon (`draft: true`) : `render_artifact(slug)` le voit. État difficile à atteindre : brouillon avec un `initial` simulé, rendu, puis remise en état.

## 4. Limites connues
- `render_artifact` échoue (Bad Gateway) dès qu'un `sonic-procgen` est affiché : vérifier ces scènes autrement (capture par l'utilisateur, ou viewer local).
- Aucun accès réseau externe depuis la page testée ; durée max 45 s ; caméra, micro, Bluetooth non disponibles.

## 5. Checklist avant de livrer
- Rien de vide, pas de « undefined » ni de « null » affiché, textes lisibles sur le fond, cibles tactiles ≥ 44 px.
- Le premier geste produit un effet visible ; état vide et état d'erreur prévus.
- Checklist de la skill `design`.
