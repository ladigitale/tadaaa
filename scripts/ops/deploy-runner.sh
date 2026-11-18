#!/usr/bin/env bash
# Runner hôte des mises à jour de production demandées via le MCP (trigger_prod_update).
#
# Lancé par tadaaa-deploy.service (unité systemd .path sur OPS_DIR/requests/*.json, voir
# scripts/ops/install-deploy-runner.sh). Tourne sur l'hôte, pas dans un conteneur : la
# recréation du conteneur PHP pendant update-prod ne l'interrompt pas.
#
# Pour chaque demande OPS_DIR/requests/<id>.json, du plus ancien au plus récent :
#   runs/<id>.request.json  demande d'origine (auteur, motif) — écrite par l'API
#   runs/<id>.json          état (status, startedAt, finishedAt, fromSha, toSha, exitCode, health)
#   runs/<id>.log           sortie de scripts/update-prod.sh --pull
#
# La commande est fixe : le contenu de la demande n'est jamais interprété ni passé au shell.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OPS_DIR="${OPS_DIR:-$ROOT/var/ops}"
REQ_DIR="$OPS_DIR/requests"
RUN_DIR="$OPS_DIR/runs"
KEEP_RUNS="${OPS_KEEP_RUNS:-30}"
HEALTH_TRIES="${OPS_HEALTH_TRIES:-12}"

mkdir -p "$REQ_DIR" "$RUN_DIR"

# Un seul runner à la fois.
exec 9>"$OPS_DIR/.runner.lock"
flock -n 9 || exit 0

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
sha() { git -C "$ROOT" rev-parse --short=12 HEAD 2>/dev/null || echo ""; }

# write_state <id> <status> <startedAt> <fromSha> [finishedAt toSha exitCode health]
# Valeurs produites par ce script uniquement (statuts, dates, SHA, entiers) : pas d'échappement JSON requis.
write_state() {
  local id="$1" status="$2" started="$3" from="$4"
  local finished="${5:-}" to="${6:-}" code="${7:-}" health="${8:-}"
  local file="$RUN_DIR/$id.json" tmp
  tmp="$(mktemp "$RUN_DIR/.state.XXXXXX")"
  {
    printf '{\n  "status": "%s",\n  "startedAt": "%s",\n  "fromSha": "%s"' "$status" "$started" "$from"
    [[ -n "$finished" ]] && printf ',\n  "finishedAt": "%s"' "$finished"
    [[ -n "$to" ]] && printf ',\n  "toSha": "%s"' "$to"
    [[ -n "$code" ]] && printf ',\n  "exitCode": %d' "$code"
    [[ -n "$health" ]] && printf ',\n  "health": "%s"' "$health"
    printf '\n}\n'
  } >"$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$file"
}

# Runs restés « running » (hôte redémarré en cours de route) : le verrou est à nous, ils sont morts.
mark_interrupted() {
  local f id started from
  for f in "$RUN_DIR"/*.json; do
    [[ -e "$f" ]] || continue
    [[ "$f" == *.request.json ]] && continue
    grep -q '"status": "running"' "$f" || continue
    id="$(basename "$f" .json)"
    started="$(sed -n 's/.*"startedAt": "\([^"]*\)".*/\1/p' "$f")"
    from="$(sed -n 's/.*"fromSha": "\([^"]*\)".*/\1/p' "$f")"
    write_state "$id" interrupted "$started" "$from" "$(now)" "" "" ""
  done
}

api_health() {
  local host i
  host="$(sed -n 's/^API_SERVER_NAME=//p' "$ROOT/.env" 2>/dev/null | tail -1 | tr -d '"'"'"' ')"
  [[ -n "$host" ]] || { echo "ko"; return; }
  for ((i = 0; i < HEALTH_TRIES; i++)); do
    if curl -fsS --max-time 10 "https://${host}/api/health" >/dev/null 2>&1; then
      echo "ok"
      return
    fi
    sleep 5
  done
  echo "ko"
}

run_one() {
  local req="$1" id started from code to health status log
  id="$(basename "$req" .json)"
  if [[ ! "$id" =~ ^[A-Za-z0-9-]{8,64}$ ]]; then
    rm -f -- "$req"
    return
  fi
  log="$RUN_DIR/$id.log"
  started="$(now)"
  from="$(sha)"

  mv -f -- "$req" "$RUN_DIR/$id.request.json"
  write_state "$id" running "$started" "$from"

  {
    printf '# runId %s — %s — %s\n' "$id" "$started" "$ROOT"
    printf '# HEAD avant : %s\n\n' "$from"
  } >"$log"

  bash "$ROOT/scripts/update-prod.sh" --pull >>"$log" 2>&1 </dev/null
  code=$?
  to="$(sha)"

  if [[ "$code" -eq 0 ]]; then
    printf '\n→ Healthcheck…\n' >>"$log"
    health="$(api_health)"
    if [[ "$health" == "ok" ]]; then status=succeeded; else status=unhealthy; fi
  else
    health=""
    status=failed
  fi

  printf '\n# %s — exit %d — HEAD après : %s%s\n' "$(now)" "$code" "$to" "${health:+ — health $health}" >>"$log"
  if [[ "$status" != "succeeded" && -n "$from" && "$from" != "$to" ]]; then
    printf '# Retour arrière manuel : git checkout %s && bash scripts/update-prod.sh\n' "$from" >>"$log"
  fi
  write_state "$id" "$status" "$started" "$from" "$(now)" "$to" "$code" "$health"
}

prune() {
  local ids old
  mapfile -t ids < <(find "$RUN_DIR" -maxdepth 1 -name '*.log' -printf '%f\n' | sed 's/\.log$//' | sort -r)
  ((${#ids[@]} > KEEP_RUNS)) || return 0
  for old in "${ids[@]:KEEP_RUNS}"; do
    rm -f -- "$RUN_DIR/$old.json" "$RUN_DIR/$old.request.json" "$RUN_DIR/$old.log"
  done
}

mark_interrupted

while :; do
  next="$(find "$REQ_DIR" -maxdepth 1 -name '*.json' -printf '%f\n' | sort | head -1)"
  [[ -n "$next" ]] || break
  run_one "$REQ_DIR/$next"
done

prune
