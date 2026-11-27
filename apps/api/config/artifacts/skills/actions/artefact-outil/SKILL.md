---
name: artefact-outil
description: "Utiliser un artefact Tadaaa comme outil : écrire son contrat io (entrée, sortie, fichiers, modes headless / interactive / embed), l'essayer sans l'enregistrer (run_artifact dryRun), l'exécuter, suivre ses runs, et enchaîner plusieurs artefacts en workflow (save_artifact_workflow, run_artifact_workflow). À utiliser quand un agent, un programme ou une personne doit fournir une entrée à un artefact et en récupérer un résultat."
metadata:
  famille: action
  outils: list_artifact_tools run_artifact get_artifact_run cancel_artifact_run save_artifact_workflow run_artifact_workflow get_artifact_workflow_run
---
# Artefact utilisé comme outil

Format du contrat : brique `io`.

## 1. Écrire le contrat
`io: {title, description, modes: ["headless" | "interactive" | "embed"], input: {schema, store, action?}, output: {schema, from, when?, files?}, headless?: {actions, maxSteps}}`
- `input.schema` / `output.schema` : sous-ensemble JSON Schema ; chaque propriété avec une `description` (pas d'objet vide).
- L'entrée arrive au store `input.store` en action `io:input` (ou `input.action`) ; la sortie est lue dans `output.from` quand `output.when` devient vrai.
- `files` : fichiers produits (image, SVG, son) lus dans des DataProviders (`blob:` / `data:`).

## 2. Choisir le mode
| Mode | Quand | Résultat |
|---|---|---|
| `headless` | calcul pur par le réducteur | statut immédiat `completed` / `incomplete` / `failed` |
| `interactive` | une personne fait l'étape (signer, choisir, dessiner, filmer) | `{runId, url, runToken}` : donner `url`, suivre avec `get_artifact_run` |
| `embed` | dans un hôte MCP Apps (Claude, ChatGPT…) | l'artefact s'affiche dans la conversation, la sortie revient en message |

## 3. Essayer puis exécuter
- Brouillon : `run_artifact(document, input, dryRun: true)` (headless, rien n'est enregistré).
- En ligne : `run_artifact(slug, input, mode)` ; `get_artifact_run(runId)` → status, output, files (`file` : contenu en base64, 256 Ko max) ; `get_artifact_run(slug)` sans runId : derniers runs.
- `cancel_artifact_run(runId)` : annule un run en attente.
- `list_artifact_tools` : artefacts disponibles comme outils, avec leurs schémas.
- Webhooks `artifact.run.completed` / `artifact.run.failed` (`create_webhook`) pour un programme qui attend.

## 4. Workflows
- `save_artifact_workflow(slug, definition: {title, input: {schema}, steps: [{id, artifact, mode, input (JSONata), if, optional}], output})` ; expressions évaluées sur `{input, steps: {<id>: {status, output, files}}, previous}` ; 20 étapes max.
- `run_artifact_workflow(slug, input)` : les étapes headless s'enchaînent ; à une étape interactive, `waiting.url` est à donner à la personne (24 h), le workflow reprend seul.
- `get_artifact_workflow_run(workflowRunId)` (`cancel: true` pour arrêter) ; `list_artifact_workflows(slug)` : définition et 10 derniers runs.

## 5. Vérifier
`simulate_store` avec une action `io:input` d'exemple, puis `run_artifact dryRun` : la sortie doit passer `output.schema`.
