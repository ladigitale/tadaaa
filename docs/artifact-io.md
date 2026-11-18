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
l'`import-from` du dessin ; pour *peindre* au lieu d'importer, `gestures-from="store.cle"` rejoue
des traits de pinceau (pression, fusion, gomme) donnés en entrée. Exemple : Abysse (`abysse-zoom-infini`).

**Fichiers d'entrée (assets)** — un gros contenu (image, jeu de gestes, données) ne passe pas dans
l'entrée : il est ajouté à l'artefact (`upload_artifact_asset`) et l'entrée le désigne par
`"@asset:<nom>"`. La création du run refuse une référence vers un asset absent ; quand le viewer lit
le run, chaque référence est remplacée par l'URL publique de l'asset (comme dans le document).

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

## Flux en direct (`io.stream`)

Un run interactive ou embed peut rester **ouvert à un flux** : n'importe quel émetteur (script,
service, capteur, autre agent, Claude via MCP…) pousse des messages, le viewer qui exécute le
run et les spectateurs les appliquent en direct. Trois niveaux, cumulables :

1. **État** — `action` (réducteur du store), `state.snapshot` / `state.delta` (JSON Patch sur
   l'état) : rapide, quelques dizaines de messages par seconde ;
2. **Structure** — `document.patch` (JSON Patch sur le document affiché) : le serveur applique le
   patch et valide le document comme une publication avant de le relayer ; `/io`, `/files` et
   `/schema` ne bougent pas pendant un run ;
3. **Partition** — `score` : états clés et événements joués *localement* par chaque viewer à
   pleine vitesse, à partir de l'heure serveur du message (tous les spectateurs sont synchrones,
   même arrivés en cours). C'est la bonne forme pour des évolutions longues ou rapides : on ne
   streame que les changements de partition.

```json
"io": {
  "modes": ["interactive", "embed"],
  "output": {"schema": {"type": "number"}, "from": "scene.hue"},
  "stream": {
    "store": "scene",
    "actions": {"palette": {"schema": {"type": "object", "properties": {"hue": {"type": "number"}}}}, "pulse": {}},
    "state": true, "document": true, "score": true,
    "rate": 30,
    "progress": {"from": "scene.progress", "every": 1000}
  }
}
```

Le run (`run_artifact`, `POST /api/artifacts/{slug}/runs`) renvoie alors, une seule fois :

```json
"stream": {"emitToken": "…", "watchToken": "…", "eventsUrl": "/api/public/artifacts/{slug}/runs/{id}/events",
           "joinUrl": "…/stream", "watchUrl": "https://…/{slug}?run=…&wt=…", "seq": 0, "messages": 0}
```

**Émettre** — `POST eventsUrl` avec `Authorization: Bearer <emitToken>` (ou l'outil MCP
`stream_artifact_events`, ou `POST /api/artifacts/{slug}/runs/{id}/events` avec un compte) ;
corps : un message, une liste ou `{"events": […]}` (64 au plus, 60 Ko). Un envoi est accepté ou
refusé en entier (`202 {accepted, seq, firstSeq}`) ; débit limité à `io.stream.rate` messages/s
par run (rafale de 2 s). Chaque message reçoit un numéro (`seq`) et l'heure serveur (`t`, ms).

| Message | Effet |
|---|---|
| `{"type":"action","name":"palette","payload":{…}}` | `{type: name, payload}` envoyé au réducteur du store |
| `{"type":"state.snapshot","state":{…}}` | remplace l'état du store (si `state`) |
| `{"type":"state.delta","patch":[…]}` | JSON Patch sur l'état du store (si `state`) |
| `{"type":"document.patch","patch":[…]}` | JSON Patch sur le document (si `document`) |
| `{"type":"score","score":{…}}` / `{"type":"score.stop"}` | lance / arrête une partition (si `score`) |
| `{"type":"end"}` | fin du flux : le viewer publie sa sortie, webhook `artifact.run.stream_ended` |

AG-UI est accepté tel quel : `CUSTOM {name, value}`, `STATE_SNAPSHOT {snapshot}`,
`STATE_DELTA {delta}`.

**Partition** :

```json
{"duration": 8000, "loop": true, "speed": 1,
 "events": [{"at": 0, "action": "pulse"}, {"at": 4000, "action": "palette", "payload": {"hue": 0.7}}],
 "tracks": [{"path": "/hue", "keys": [{"at": 0, "value": 0.1}, {"at": 4000, "value": 0.9, "ease": "inOut"}, {"at": 8000, "value": 0.1}]}]}
```

`tracks` interpolent des nombres et des couleurs `#rrggbb` (le reste change par paliers) et les
écrivent dans l'état du store au pointeur `path` ; `ease` : linear, in, out, inOut, step.

**Suivre** — `GET joinUrl?wt=<watchToken>` (spectateur), `?rt=` (viewer du run) ou `?et=`
(émetteur) : identifiants Mercure (`mercure.hubUrl`, `token`, `topics.in` / `topics.out`),
document courant, partition en cours, dernier instantané d'état du viewer et messages à rejouer
depuis cet instantané (`complete: false` si le journal ne remonte pas assez loin).
`?after=<seq>` ne renvoie que les messages suivants : rattrapage après une coupure, ou suivi sans
Mercure. Le topic `out` porte la progression publiée par le viewer (`io.stream.progress`),
relayée aussi en webhook `artifact.run.progress` (au plus toutes les 10 s).

**Kit `direct`** — une scène shader déjà câblée : `build_artifact_from_kit {kit: "direct", params:
{preset: "plasma", title: "…"}}`, publier, `run_artifact {mode: "interactive"}`, puis piloter
`param0…3` (action `set`, `state.delta`, partitions sur `/param0`…) et la légende
(`document.patch` sur le texte de `#legende`).

**Démo « Régie en direct »** — `docs/examples/regie-en-direct.json` (scène, fil, compteurs,
cartes) pilotée par un programme quelconque : `scripts/demo-direct-stream.mjs --events
<origine API + eventsUrl> --token <emitToken> [--minutes 3]` envoie deux partitions de 60 s,
des chapitres, annonces et réactions, ajoute des cartes et réécrit le titre, puis `end`.

**Budget du store** — une action qui dépasse le budget du store est ignorée, et l'écran qui l'a
sautée n'est plus synchrone : le viewer donne 50 ms au store du flux déclaré dans `data.stores` ;
pour un `sonic-store` écrit dans la vue, mettre `budget-ms="50"`.

**Durée** — chaque message prolonge le run de 24 h, dans la limite de 7 jours après sa création ;
500 000 messages par run au plus (le serveur garde les 1 000 derniers pour les rattrapages).

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
