#!/usr/bin/env bash
# Production update for an existing Tadaaa Compose deploy.
# From repo root on the VPS:
#   bash scripts/update-prod.sh
#   bash scripts/update-prod.sh --pull   # git pull --ff-only first
#
# Merges missing .env keys (mail, quotas, …), rebuilds the SPA,
# recreates containers, runs Doctrine migrations.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
ENV_FILE="$ROOT/.env"

# shellcheck source=lib/prod-env.sh
source "$ROOT/scripts/lib/prod-env.sh"

COMPOSE=(docker compose -f compose.prod.yaml)
DO_PULL=0

for arg in "$@"; do
  case "$arg" in
    --pull) DO_PULL=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: bash scripts/update-prod.sh [--pull]

  --pull   git pull --ff-only before building (fails if dirty/non-ff)
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
say ""
