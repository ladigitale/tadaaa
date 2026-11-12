# Artefacts utilisés comme outils : contrat `io` et runs

Un artefact peut déclarer un contrat d'entrées / sorties (bloc `io` du document `artifacts/1`).
Il devient alors un outil appelable par un workflow ou un agent : l'entrée est validée par un
JSON Schema, envoyée à un store (DataProvider), et la sortie est lue dans un DataProvider puis
validée à son tour.

## Déclaration

```json
"data": {
  "stores": {
    "calc": {
      "initial": {"phase": "idle"},
      "reducer": "$action.type='io:input' ? {'phase':'done','total':$sum($action.payload.values)} : $state"
    }
  }
},
"io": {
  "title": "Somme",
  "modes": ["headless", "interactive"],
  "input": {
    "schema": {"type": "object", "properties": {"values": {"type": "array", "items": {"type": "number"}}}, "required": ["values"]},
    "store": "calc",
    "action": "io:input"
  },
  "output": {
    "schema": {"type": "number"},
    "from": "calc.total",
    "when": "phase='done'"
  },
  "headless": {"actions": [{"type": "tick", "repeat": 200}], "maxSteps": 2000}
}
```

| Clé | Rôle |
|-----|------|
| `modes` | `headless` (sans navigateur), `interactive` (un humain intervient), `embed` (page hôte) |
| `input.schema` | JSON Schema de l'entrée (objet) |
| `input.store` | store qui reçoit l'entrée (`data.stores` ou `sonic-store id`) |
| `input.action` | type de l'action envoyée, défaut `io:input` ; `payload` = l'entrée |
| `output.from` | DataProvider lu : `store`, `store.chemin.pointé`, transform ou source |
| `output.when` | JSONata évalué sur l'état du store lu ; la sortie est prise quand il est vrai |
| `output.schema` | JSON Schema de la sortie |
| `output.files` | fichiers de sortie (modes interactive / embed) : `{"dessin": "sketch.file"}`, chemin d'un DataProvider qui donne l'URL du fichier (`blob:`, `data:` ou `{url}`) ; 4 max, formats des assets, 20 Mo |
| `headless.actions` | actions rejouées après l'entrée (horloge, calcul en plusieurs pas) |
| `headless.maxSteps` | plafond de pas (≤ 20000, défaut 2000) |

Schémas : sous-ensemble de JSON Schema 2020-12 — `type`, `properties`, `required`,
`additionalProperties` (booléen), `items`, `enum`, `const`, `minimum`, `maximum`,
`exclusiveMinimum`, `exclusiveMaximum`, `minLength`, `maxLength`, `pattern`, `minItems`,
`maxItems`, `title`, `description`, `default`, `examples`, `format` (informatif). Pas de `$ref` ni
de combinateurs (`allOf`, `anyOf`, `oneOf`).

Le validateur vérifie que `input.store` et la racine de `output.from` existent : une faute de
frappe est une erreur, pas un composant vide. En mode headless, `output.from` doit lire le store
d'entrée, seul réducteur exécuté.

## Exécution

Un run s'exécute toujours sur la **version en ligne** (celle que sert le viewer).

**headless** — même moteur que `simulate_store` (sous-processus Node bridé : 8 s, 128 Mo, sans
réseau ni fichiers). Le calcul doit donc vivre dans le réducteur JSONata. Statut final immédiat :
`completed` (sortie conforme), `incomplete` (`when` jamais vrai dans le budget de pas), `failed`
(erreur du réducteur ou sortie non conforme).

**interactive** — un humain fait l'étape (composants compris : caméra, dessin, formulaire…).
Le run est créé `pending` avec un jeton (24 h, seule son empreinte est stockée) et une `url` du
viewer portant `?run=<id>&rt=<jeton>`. Le viewer lit l'entrée, l'envoie au store, puis publie la
sortie dès que `when` est vrai (sans `when` : au premier changement non vide de `from`). La sortie
est validée par `output.schema` côté API ; un refus est affiché et la personne peut corriger.
Un artefact privé ne s'ouvre que pour les membres connectés de son jeu.

**embed** — comme interactive, dans une page hôte :
`<artifact-embed slug="…" run="<runId>" run-token="<runToken>">` (ou `data-run` /
`data-run-token` avec embed.js). Sans run serveur, `input='{…}'` (ou `data-input`) donne une
entrée locale : rien n'est envoyé à l'API. Dans tous les cas la sortie est émise sur l'élément en
événement DOM `artifact-output` (`detail` : `{output, runId, slug}`).

Statuts d'un run : `completed`, `incomplete`, `failed` (headless) ; `pending` puis `completed`,
`expired` (24 h sans sortie) ou `cancelled` (modes interactive / embed).

**Dessin comme outil** — `sonic-sketch` avec `export-to="<store>"` envoie chaque export
(`save` en SVG, `savePng`) en action `exported` `{url, mime, width, height, size}` : le réducteur
garde ces objets (ex. `ioFSvg`, `ioFPng`) et passe `when` à vrai quand les deux sont là ;
`output.files` les désigne (`{"dessin": "store.ioFSvg"}`). Un dessin de départ donné en entrée
(texte SVG) se lit avec `sonic-vectorize svg-from="store.cle" keep-colors auto`, branché sur
l'`import-from` du dessin. Exemple : Abysse (`abysse-zoom-infini`).

**Fichiers de sortie** — avant la sortie, le viewer envoie chaque fichier de `output.files`
(`<clé>.<ext>`) ; le run les liste dans `files` (`{name, contentType, sizeBytes, sha256, url}`),
téléchargeables par `GET /api/artifacts/{id}/runs/{runId}/files/{name}` ou `get_artifact_run`
avec `file` (base64 jusqu'à 256 Ko). Ils comptent dans le quota de stockage et disparaissent avec
le run.

**Essai d'un brouillon** — `run_artifact` avec `document` (au lieu de `slug`) exécute le contrat
en headless sans rien enregistrer ; dans l'atelier, `check_preview` mode `io`.

**Ménage et limites** — chaque création de run expire quelques runs échus ;
`bin/console app:artifact-runs:sweep` (à mettre en cron, ex. toutes les 10 minutes) expire le
reste (webhook `artifact.run.failed`, statut `expired`) et supprime les runs terminés de plus de
90 jours (runs de workflow compris). Création de runs limitée à 60 par minute et par compte
(`limiter.artifact_runs`), comptée dans l'usage (`artifact_runs`).

**Kits** — `quiz` (sortie `{score, good, total}` en fin de partie) et `survey` (réponses à
l'envoi du formulaire) déclarent un contrat io (modes interactive et embed).

### MCP

| Outil | Rôle |
|-------|------|
| `list_artifact_tools` | contrats des artefacts lisibles : `{slug, title, description, modes, inputSchema, outputSchema}` |
| `run_artifact` | `{slug, input, mode?}` → `{runId, status, output, errors, metrics}` ; en interactive / embed : `{runId, status: "pending", url?, runToken, expiresAt}` |
| `get_artifact_run` | `{runId}` → run complet ; `{slug}` → derniers runs ; `{runId, file}` → contenu d'un fichier |
| `cancel_artifact_run` | `{runId}` → annule un run en attente |

### API

| Route | Rôle |
|-------|------|
| `GET /api/artifacts/{id}/io` | contrat (404 sans bloc `io`) |
| `POST /api/artifacts/{id}/runs` | `{"input": {…}, "mode": "headless"}` → run (201 si `completed`) |
| `GET /api/artifacts/{id}/runs` | derniers runs (sans entrées ni sorties) |
| `GET /api/artifacts/{id}/runs/{runId}` | run complet |
| `POST /api/artifacts/{id}/runs/{runId}/cancel` | annule un run en attente |
| `GET /api/artifacts/{id}/runs/{runId}/files/{name}` | fichier de sortie |
| `GET /api/artifacts/{id}/openapi.json` | description OpenAPI 3.1 de l'outil (n8n, Make, client généré) |
| `POST /api/public/artifacts/{slug}/runs/{runId}/files/{name}?rt=` | viewer : fichier de sortie (corps brut) |
| `GET /api/public/artifacts/{slug}/runs/{runId}?rt=` | viewer : statut et entrée d'un run `pending` (jeton du run) |
| `POST /api/public/artifacts/{slug}/runs/{runId}/output` | viewer : `{"rt": "…", "output": …}` → run `completed` (409 si déjà terminé, 410 si expiré) |

### Webhooks

`artifact.run.completed` et `artifact.run.failed` (`failed`, `incomplete`, `expired`,
`cancelled`), sur le jeu de l'artefact ; `data` = le run sérialisé. Signature au format
[Standard Webhooks](https://www.standardwebhooks.com/) (`webhook-id`, `webhook-timestamp`,
`webhook-signature`) en plus de `X-Tadaaa-Signature` ; à la création d'un webhook,
`standardSecret` (`whsec_…`) est le secret à donner aux bibliothèques Standard Webhooks.

### MCP Apps (hôtes IA)

`run_artifact` porte `_meta.ui.resourceUri = ui://tadaaa/artifact-runner` (extension
`io.modelcontextprotocol/ui`, plus la clé plate `ui/resourceUri` pour les hôtes antérieurs).
Un hôte qui affiche les MCP Apps (Claude, ChatGPT…) charge cette interface avec le résultat :

- mode `embed` (ou `interactive`) : l'artefact est intégré dans la conversation via embed.js avec
  le run (`data-run`, `data-run-token`, `data-key` pour un artefact « lien ») ; quand la personne a
  terminé, le viewer publie la sortie sur l'API, puis l'interface la renvoie au modèle
  (`ui/update-model-context` et `ui/message`) ;
- mode `headless` : l'artefact est affiché avec la même entrée (`data-input`), rien n'est renvoyé.

Le HTML vient de `apps/api/src/Mcp/Ui/artifact-runner.html` (origines du viewer et de l'API
injectées par `ArtifactRunnerUiProcessor`) ; la CSP déclarée (`_meta.ui.csp`) liste le viewer,
l'API, les assets et les CDN épinglés ; caméra, micro et presse-papiers sont demandés (l'hôte peut
refuser). Un artefact privé ne peut pas être intégré. Smoke : `scripts/smoke-mcp-app.mjs` dans
ladigitale/artifacts (hôte simulé).

## Workflows : enchaîner des artefacts

Un workflow joue des artefacts-outils dans l'ordre ; la sortie d'une étape alimente la suivante.

```json
{
  "title": "Quiz puis diplôme",
  "input": {"schema": {"type": "object", "properties": {"nom": {"type": "string"}}}},
  "steps": [
    {"id": "quiz", "artifact": "quiz-capitales", "mode": "interactive"},
    {"id": "diplome", "artifact": "diplome", "if": "steps.quiz.output.good >= 3",
     "input": "{'nom': input.nom, 'score': steps.quiz.output.score}"}
  ],
  "output": "{'score': steps.quiz.output.score, 'diplome': steps.diplome.output}"
}
```

- Expressions JSONata (`if`, `input`, `output`) évaluées sur
  `{input, steps: {<id>: {status, output, files}}, previous}` ; `input` absent : l'entrée du
  workflow pour la première étape, `previous` (sortie de la dernière étape réussie) ensuite.
- `if` faux : étape sautée (`skipped`) ; `optional: true` : un échec n'arrête pas le workflow.
- Étapes headless jouées aussitôt ; à une étape interactive / embed, le workflow passe en
  `waiting` (`waiting.url` à donner à la personne, webhook `artifact.workflow.waiting`) et
  reprend quand son run se termine ; un run expiré ou annulé fait échouer l'étape.
- La définition est vérifiée à l'enregistrement (syntaxe JSONata, artefacts lisibles, contrat io
  et mode déclarés dans la version en ligne) et figée dans chaque run.
- Runs exécutés au nom de la personne qui lance le workflow ; 20 étapes max.

| MCP | API | Rôle |
|-----|-----|------|
| `save_artifact_workflow` | `PUT /api/artifact-workflows/{slug}` `{definition}` | créer / remplacer |
| `list_artifact_workflows` | `GET /api/artifact-workflows[/{slug}]` | lister / lire (+ derniers runs) |
| `delete_artifact_workflow` | `DELETE /api/artifact-workflows/{slug}` | supprimer |
| `run_artifact_workflow` | `POST /api/artifact-workflows/{slug}/runs` `{input}` | lancer |
| `get_artifact_workflow_run` | `GET /api/artifact-workflows/runs/{id}` (`POST …/cancel`) | suivre / annuler |

Webhooks : `artifact.workflow.waiting`, `artifact.workflow.completed`, `artifact.workflow.failed`.
