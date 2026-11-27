# Outils du MCP Tadaaa par tâche

Lecture seule (sans effet) : marquées `readOnlyHint` dans les annotations des outils.

## Découvrir
- `get_artifact_skill` : une skill par nom (ou le sommaire sans nom) ; même contenu que `skill://…`.
- `get_artifact_catalog` : composants autorisés, enveloppe, règles (`rules=[…]`), kits, recettes, exemples.
- `find_icons` : noms exacts d'icônes (`iconoir` par défaut, `heroicons`).
- `search_resources` / `get_resource` : bibliothèque (shaders, modèles, sons, images, données).
- `list_artifacts`, `list_datasets`, `activate_dataset` : artefacts accessibles et jeu cloud actif.

## Écrire
- `build_artifact_from_kit` : document complet depuis un kit et ses paramètres.
- `validate_artifact` : validation sans enregistrer (`{valid, errors, warnings}`).
- `publish_artifact` : premier enregistrement (valide d'abord).
- `update_artifact` : document complet, ou `ops` (JSON Patch), `edits` (sous-chaînes), `fetch` / `opsFrom` (gros contenus depuis GitHub), `draft: true`.
- `get_artifact` : document ou une partie (`path`, `keysOnly`), une version (`version`), l'historique (`versions: true`).
- `restore_artifact_version` : revenir à une version précédente (nouvelle version identique).
- `promote_artifact` : mettre en ligne le brouillon.
- `delete_artifact` : supprimer (irréversible).

## Tester
- `simulate_store` : rejouer des actions sur un réducteur, sans navigateur.
- `render_artifact` : vrai viewer headless, erreurs console, GLSL, texte, captures, scénario `steps`, `fps`.
- `run_artifact` avec `document` + `dryRun` : essai d'un contrat io sans enregistrer.

## Fichiers
- `upload_artifact_asset` (`fetch` {url, sha256} de préférence), `list_artifact_assets`, `delete_artifact_asset`.
- `save_resource`, `delete_resource` : bibliothèque du compte.

## Données
- `open_artifact_intake` / `close_artifact_intake` : ouvrir ou fermer une collecte.
- `read_artifact_data` / `write_artifact_data` : records d'une collection.

## Outils et workflows
- `list_artifact_tools`, `run_artifact`, `get_artifact_run`, `cancel_artifact_run`.
- `save_artifact_workflow`, `list_artifact_workflows`, `run_artifact_workflow`, `get_artifact_workflow_run`, `delete_artifact_workflow`.

## Direct
- `stream_artifact_events`, `link_artifact_runs`, `unlink_artifact_runs`.

## Hors artefacts
- Tâches et étiquettes du cloud : `list_todos`, `create_todo`, `update_todo`… ; webhooks : `create_webhook`, `list_webhook_events`… ; détecteurs de liens.
- Administration (super admin) : `get_artifact_validation_stats`, `trigger_prod_update`, `get_prod_update_status`.

## Prompts du serveur
`creer_artefact`, `modifier_artefact`, `tester_artefact`, `artefact_outil` : amorces qui chargent les bonnes skills pour la tâche.
