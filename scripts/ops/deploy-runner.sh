#!/usr/bin/env bash
# Runner hôte des mises à jour de production demandées via le MCP (trigger_prod_update).
#
# Lancé par tadaaa-deploy.service (unité systemd .path sur OPS_DIR/requests/*.json, voir
# scripts/ops/install-deploy-runner.sh). Tourne sur l'hôte, pas dans un conteneur : la
# recréation du conteneur PHP pendant update-prod ne l'interrompt pas.
#
# Pour chaque demande OPS_DIR/requests/<id>.json, du plus ancien au plus récent :
#   runs/<id>.request.json  demande d'origine (auteur, motif) — écrite par l'API
#   runs/<id>.json          état (status, startedAt, finishedAt, fromSha, toSha, exitCode, health, targets)
#   runs/<id>.log           sortie de scripts/update-prod.sh --pull [--only …] [--force]
#   runs/<id>.targets       résultat par site écrit par update-prod.sh (repris dans l'état)
#
# Le contenu de la demande n'est jamais passé tel quel au shell. Deux options seulement en sont
# lues, sur des lignes au format strict : `only` (sites pris dans une liste fermée) et `force`.
# Une demande dont `only` n'est pas conforme échoue sans rien lancer.

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

# write_state <id> <status> <startedAt> <fromSha> [finishedAt toSha exitCode health targetsJson]
# Valeurs produites par ce script uniquement (statuts, dates, SHA, entiers) : pas d'échappement JSON requis.
write_state() {
  local id="$1" status="$2" started="$3" from="$4"
  local finished="${5:-}" to="${6:-}" code="${7:-}" health="${8:-}" targets="${9:-}"
  local file="$RUN_DIR/$id.json" tmp
  tmp="$(mktemp "$RUN_DIR/.state.XXXXXX")"
  {
    printf '{\n  "status": "%s",\n  "startedAt": "%s",\n  "fromSha": "%s"' "$status" "$started" "$from"
    [[ -n "$finished" ]] && printf ',\n  "finishedAt": "%s"' "$finished"
    [[ -n "$to" ]] && printf ',\n  "toSha": "%s"' "$to"
    [[ -n "$code" ]] && printf ',\n  "exitCode": %d' "$code"
    [[ -n "$health" ]] && printf ',\n  "health": "%s"' "$health"
    [[ -n "$targets" ]] && printf ',\n  "targets": %s' "$targets"
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

# request_args <demande> : remplit ARGS pour update-prod.sh. Retourne 1 si `only` est présent
# mais non conforme (on ne retombe pas sur « tout mettre à jour » par erreur).
request_args() {
  local file="$1" only t
  ARGS=(--pull)
  if grep -Eq '^ *"only":' "$file"; then
    only="$(sed -n 's/^ *"only": "\([a-z,]*\)",\{0,1\}$/\1/p' "$file" | head -1)"
    [[ -n "$only" ]] || return 1
    local IFS=,
    for t in $only; do
      case "$t" in
        tadaaa | artifacts | belts | glane) ;;
        *) return 1 ;;
      esac
    done
    ARGS+=(--only "$only")
  fi
  if grep -Eq '^ *"force": true,?$' "$file"; then
    ARGS+=(--force)
  fi
  return 0
}

# targets_json <fichier> : lignes « site statut from to » de update-prod.sh → objet JSON.
# Chaque ligne est validée (liste fermée, SHA hexadécimaux) ; le reste est ignoré.
targets_json() {
  local file="$1" site st from to out="" entry
  [[ -f "$file" ]] || return 0
  while read -r site st from to _; do
    [[ "$site" =~ ^(tadaaa|artifacts|belts|glane)$ ]] || continue
    [[ "$st" =~ ^(updated|unchanged|skipped|failed)$ ]] || continue
    [[ "$from" =~ ^[0-9a-f]{7,40}$ ]] || from=""
    [[ "$to" =~ ^[0-9a-f]{7,40}$ ]] || to=""
    entry="$(printf '"%s": {"status": "%s"' "$site" "$st")"
    [[ -n "$from" ]] && entry+="$(printf ', "fromSha": "%s"' "${from:0:12}")"
    [[ -n "$to" ]] && entry+="$(printf ', "toSha": "%s"' "${to:0:12}")"
    out+="${out:+, }${entry}}"
  done <"$file"
  [[ -n "$out" ]] && printf '{%s}' "$out"
  return 0
}

run_one() {
  local req="$1" id started from code to health status log targets
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

  if ! request_args "$RUN_DIR/$id.request.json"; then
    printf '✗ Demande refusée : `only` doit lister des sites parmi tadaaa, artifacts, belts, glane.\n' >>"$log"
    write_state "$id" failed "$started" "$from" "$(now)" "$from" 2 ""
    return
  fi
  printf '# update-prod.sh %s\n\n' "${ARGS[*]}" >>"$log"

  : >"$RUN_DIR/$id.targets"
  UPDATE_PROD_RESULT="$RUN_DIR/$id.targets" bash "$ROOT/scripts/update-prod.sh" "${ARGS[@]}" >>"$log" 2>&1 </dev/null
  code=$?
  to="$(sha)"
  targets="$(targets_json "$RUN_DIR/$id.targets")"

  if [[ "$code" -eq 0 ]]; then
    printf '\n→ Healthcheck…\n' >>"$log"
    health="$(api_health)"
    if [[ "$health" != "ok" ]]; then
      status=unhealthy
    elif grep -Eq '^(artifacts|belts|glane) failed( |$)' "$RUN_DIR/$id.targets"; then
      status=partial
    else
      status=succeeded
    fi
  else
    health=""
    status=failed
  fi

  printf '\n# %s — exit %d — HEAD après : %s%s\n' "$(now)" "$code" "$to" "${health:+ — health $health}" >>"$log"
  if [[ "$status" != "succeeded" && "$status" != "partial" && -n "$from" && "$from" != "$to" ]]; then
    printf '# Retour arrière manuel : git checkout %s && bash scripts/update-prod.sh\n' "$from" >>"$log"
  fi
  write_state "$id" "$status" "$started" "$from" "$(now)" "$to" "$code" "$health" "$targets"
}

prune() {
  local ids old
  mapfile -t ids < <(find "$RUN_DIR" -maxdepth 1 -name '*.log' -printf '%f\n' | sed 's/\.log$//' | sort -r)
  ((${#ids[@]} > KEEP_RUNS)) || return 0
  for old in "${ids[@]:KEEP_RUNS}"; do
    rm -f -- "$RUN_DIR/$old.json" "$RUN_DIR/$old.request.json" "$RUN_DIR/$old.log" "$RUN_DIR/$old.targets"
  done
}

mark_interrupted

while :; do
  next="$(find "$REQ_DIR" -maxdepth 1 -name '*.json' -printf '%f\n' | sort | head -1)"
  [[ -n "$next" ]] || break
  run_one "$REQ_DIR/$next"
done

prune
