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

Mode headless (seul mode exécuté par l'API pour l'instant) : même moteur que `simulate_store`
(sous-processus Node bridé : 8 s, 128 Mo, sans réseau ni fichiers). Le calcul doit donc vivre
dans le réducteur JSONata ; ce qui dépend d'un composant (caméra, shader, ML…) relève des modes
`interactive` / `embed`.

Statuts : `completed` (sortie conforme), `incomplete` (`when` jamais vrai dans le budget de pas),
`failed` (erreur du réducteur ou sortie non conforme).

### MCP

| Outil | Rôle |
|-------|------|
| `list_artifact_tools` | contrats des artefacts lisibles : `{slug, title, description, modes, inputSchema, outputSchema}` |
| `run_artifact` | `{slug, input, mode?}` → `{runId, status, output, errors, metrics}` (aussi en `structuredContent`) |
| `get_artifact_run` | `{runId}` → run complet ; `{slug}` → derniers runs |

### API

| Route | Rôle |
|-------|------|
| `GET /api/artifacts/{id}/io` | contrat (404 sans bloc `io`) |
| `POST /api/artifacts/{id}/runs` | `{"input": {…}, "mode": "headless"}` → run (201 si `completed`) |
| `GET /api/artifacts/{id}/runs` | derniers runs (sans entrées ni sorties) |
| `GET /api/artifacts/{id}/runs/{runId}` | run complet |

### Webhooks

`artifact.run.completed` et `artifact.run.failed` (statut `failed` ou `incomplete`), sur le jeu de
l'artefact ; `data` = le run sérialisé.

## Suite prévue

- Modes `interactive` et `embed` : runs `pending`, URL signée portant le `runId`, entrée
  dispatchée par le viewer, sortie publiée quand `when` devient vrai ; pont JSON-RPC MCP Apps
  (`io.modelcontextprotocol/ui`) pour l'intégration et les hôtes IA.
- Sorties binaires (assets) et jeton par run.
- Format Standard Webhooks pour les signatures.
