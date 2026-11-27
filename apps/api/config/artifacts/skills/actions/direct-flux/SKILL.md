---
name: direct-flux
description: "Piloter un artefact Tadaaa en direct : flux d'un run (io.stream), messages poussés par stream_artifact_events (actions, état, patch de document, partition), spectateurs, appairage de téléphones par code, télécommande reliée à une scène (link_artifact_runs), présence. À utiliser pour un quiz en direct, une régie, un écran de salle, une télécommande ou un spectacle piloté par un agent."
metadata:
  famille: action
  outils: run_artifact stream_artifact_events link_artifact_runs unlink_artifact_runs get_artifact_run
---
# Direct et flux

Format de `io.stream` : brique `io` (chaque action déclarée avec une `description`).

## 1. Lancer un direct
- Contrat avec `io.stream` (actions, `state`, `document`, `score`, `rate`, `pair`, `emit`).
- `run_artifact(slug, mode: "interactive" | "embed", input)` → `stream: {emitToken, watchToken, eventsUrl, watchUrl}` (jetons rendus une seule fois : les garder).
- `watchUrl` ouvre le viewer en spectateur (QR possible : `sonic-qr text-from="artifactRun.watchUrl"`).

## 2. Pousser des messages
`stream_artifact_events(runId, events)` (64 par appel, débit max `io.stream.rate`/s) :
- `{"type": "action", "name": "<déclarée>", "payload": {…}}` → réducteur du store du flux ;
- `{"type": "state.delta", "patch": [...]}` / `{"type": "state.snapshot", "state": {…}}` si `io.stream.state` ;
- `{"type": "document.patch", "patch": [...]}` si `io.stream.document` (validé comme une publication) ;
- `{"type": "score", "score": {duration, loop, events, tracks}}` : partition jouée localement, synchrone, à préférer pour des évolutions longues ou rapides ; `{"type": "score.stop"}` ;
- `{"type": "end"}` : fin, le viewer publie sa sortie.
Un programme sans compte : `POST eventsUrl` avec `Authorization: Bearer <emitToken>`.

## 3. Appairage et présence
- `io.stream.pair` : le viewer hôte publie un code (`artifactRun.pair.code`, `url`) ; une page ouverte avec `?pair=<code>` prend la place. Rôles multiples : `pair: [{role, …}]`. Guichet d'inscription : `io.stream.desk`.
- Présence : `artifactRun.presence = {count, members}` et action `presence` vers le store du flux.

## 4. Télécommande
- La télécommande déclare `io.stream.emit = {from: "store.file", ack?}` ; `link_artifact_runs(fromRunId, toRunId, map?)` pousse chaque élément de sa file dans le flux de la scène (`map` JSONata → message). 8 liens par source. `unlink_artifact_runs(linkId)` ; compteurs dans `get_artifact_run`.

## 5. Pièges
- Le store du flux a 50 ms par action : une action hors budget est ignorée et l'écran se désynchronise.
- Tester d'abord avec `simulate_store` (mêmes actions), puis un run interactive ouvert dans deux onglets (hôte et spectateur).
