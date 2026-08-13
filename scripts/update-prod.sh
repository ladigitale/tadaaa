#!/usr/bin/env bash
# Production update for an existing Tadaaa Compose deploy.
# From repo root on the VPS:
#   bash scripts/update-prod.sh
#   bash scripts/update-prod.sh --pull   # git pull --ff-only first
#
# Merges missing .env keys (mail, quotas, …), rebuilds the SPA,
# recreates containers, runs Doctrine migrations.
#
# Optional cohost (.env) — re-applied on edge after the main stack up:
#   GLANE_ROOT=/root/glane
#   GLANE_APP_SERVER_NAME=glane.tadaaa.space
#   GLANE_API_SERVER_NAME=glane-api.tadaaa.space
#   BELTS_DIST=/opt/belt/dist
#   BELTS_SERVER_NAME=belts.tadaaa.space

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
ENV_FILE="$ROOT/.env"

# shellcheck source=lib/prod-env.sh
source "$ROOT/scripts/lib/prod-env.sh"

COMPOSE=(docker compose -f compose.prod.yaml)
DO_PULL=0

# Recreate edge with Glane / Belts overlays when configured in .env.
recreate_cohost_edge() {
  local edge_files=()
  local need=0

  if [[ -n "${GLANE_ROOT:-}" ]]; then
    local glane_overlay="${GLANE_ROOT}/deploy/tadaaa-cohost/compose.prod.glane-cohost.yaml"
    local glane_snippet="${GLANE_ROOT}/deploy/tadaaa-cohost/glane.caddy"
    [[ -f "$glane_overlay" ]] || die "GLANE_ROOT set but missing overlay: $glane_overlay"
    mkdir -p "$ROOT/deploy/cohost"
    if [[ -f "$glane_snippet" ]]; then
      cp "$glane_snippet" "$ROOT/deploy/cohost/glane.caddy"
      ok "Synced deploy/cohost/glane.caddy from Glane"
    elif [[ ! -f "$ROOT/deploy/cohost/glane.caddy" ]]; then
      die "Missing deploy/cohost/glane.caddy (and no snippet under GLANE_ROOT)"
    fi
    docker network create "${WEB_NETWORK:-web}" >/dev/null 2>&1 || true
    export GLANE_ROOT
    export GLANE_APP_SERVER_NAME="${GLANE_APP_SERVER_NAME:-glane.tadaaa.space}"
    export GLANE_API_SERVER_NAME="${GLANE_API_SERVER_NAME:-glane-api.tadaaa.space}"
    export WEB_NETWORK="${WEB_NETWORK:-web}"
    edge_files+=(-f "$glane_overlay")
    need=1
    info "Cohost Glane: ${GLANE_APP_SERVER_NAME} / ${GLANE_API_SERVER_NAME}"
  fi

  if [[ -n "${BELTS_DIST:-}" ]]; then
    local belts_overlay="$ROOT/compose.prod.belts-cohost.yaml"
    [[ -f "$belts_overlay" ]] || die "BELTS_DIST set but missing $belts_overlay"
    [[ -d "$BELTS_DIST" ]] || die "BELTS_DIST is not a directory: $BELTS_DIST"
    [[ -f "$BELTS_DIST/index.html" ]] || warn "No index.html in BELTS_DIST=$BELTS_DIST (expect 404 until built)"
    mkdir -p "$ROOT/deploy/cohost"
    if [[ ! -f "$ROOT/deploy/cohost/belts.caddy" ]]; then
      die "Missing deploy/cohost/belts.caddy (needed for BELTS_DIST)"
    fi
    export BELTS_DIST
    export BELTS_SERVER_NAME="${BELTS_SERVER_NAME:-belts.tadaaa.space}"
    edge_files+=(-f "$belts_overlay")
    need=1
    info "Cohost Belts: ${BELTS_SERVER_NAME} → ${BELTS_DIST}"
  fi

  if [[ "$need" -eq 0 ]]; then
    return 0
  fi

  info "Recreating edge with cohost overlay(s)…"
  docker compose -f compose.prod.yaml "${edge_files[@]}" up -d --force-recreate edge
  ok "Edge cohost ready."
}

for arg in "$@"; do
  case "$arg" in
    --pull) DO_PULL=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: bash scripts/update-prod.sh [--pull]

  --pull   git pull --ff-only before building (fails if dirty/non-ff)

Optional .env cohost (re-applied on edge after stack up):
  GLANE_ROOT=…  GLANE_APP_SERVER_NAME=…  GLANE_API_SERVER_NAME=…
  BELTS_DIST=…  BELTS_SERVER_NAME=…
EOF
      exit 0
      ;;
    *)
      die "Unknown option: $arg (try --help)"
      ;;
  esac
done

[[ -f "$ENV_FILE" ]] || die "Missing ${ENV_FILE}. Run scripts/install-prod.sh first."

say ""
say "${BLD}Tadaaa — production update${RST}"
say "Repo root: $ROOT"
say ""

# shellcheck disable=SC1090
set -a
# shellcheck source=/dev/null
source "$ENV_FILE"
set +a

app_host="${APP_SERVER_NAME:-}"
api_host="${API_SERVER_NAME:-}"
[[ -n "$app_host" && -n "$api_host" ]] || die "APP_SERVER_NAME / API_SERVER_NAME missing in .env"

if [[ "$DO_PULL" -eq 1 ]]; then
  need_cmd git
  info "git pull --ff-only…"
  git pull --ff-only
  ok "Git up to date."
fi

info "Ensuring new .env defaults (mail, quotas, …)…"
ensure_prod_env_defaults "$app_host" "$api_host" "${ACME_EMAIL:-noreply@${app_host#app.}}"
# re-source after possible appends
set -a
# shellcheck source=/dev/null
source "$ENV_FILE"
set +a

if [[ "${MAILER_DSN:-null://null}" == "null://null" || -z "${MAILER_DSN:-}" ]]; then
  warn "MAILER_DSN is null://null — email verification / moderation mails will not send."
  warn "Set MAILER_DSN + MAIL_FROM in .env (Infomaniak SMTP), then re-run or recreate php."
fi

need_cmd docker
need_cmd curl

info "Building front (VITE_API_BASE_URL=https://${api_host})…"
# Use monorepo root yarn.lock (Concorde 4.9.x). Do not install from apps/web alone —
# apps/web/yarn.lock can pin an older Concorde and break tsc (ApiResult, @post, …).
rm -rf "$ROOT/node_modules" "$ROOT/apps/web/node_modules"
docker run --rm \
  -v "$ROOT:/repo" \
  -w /repo \
  -e VITE_API_BASE_URL="https://${api_host}" \
  node:22-bookworm \
  bash -lc 'corepack enable && yarn install --frozen-lockfile && yarn --cwd apps/web build'
[[ -f "$ROOT/apps/web/dist/index.html" ]] || die "Front build failed."
ok "Front build ready."

info "Recreating stack…"
"${COMPOSE[@]}" up -d --build
ok "Containers up."

recreate_cohost_edge

info "Migrations…"
"${COMPOSE[@]}" exec -T php bin/console doctrine:migrations:migrate --no-interaction
ok "Migrations done."

if curl -fsS --max-time 10 "https://${api_host}/api/health" >/dev/null 2>&1; then
  ok "API healthy: https://${api_host}/api/health"
else
  warn "API not reachable yet — check DNS / logs: ${COMPOSE[*]} logs -f edge php"
fi

say ""
say "${BLD}Update complete.${RST}"
say "  Front: https://${app_host}"
say "  API:   https://${api_host}/api"
[[ -n "${GLANE_ROOT:-}" ]] && say "  Glane: https://${GLANE_APP_SERVER_NAME:-glane.tadaaa.space}"
[[ -n "${BELTS_DIST:-}" ]] && say "  Belts: https://${BELTS_SERVER_NAME:-belts.tadaaa.space}"
say ""
