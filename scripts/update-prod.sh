#!/usr/bin/env bash
# Production update for an existing Tadaaa Compose deploy.
# From repo root on the VPS:
#   bash scripts/update-prod.sh
#   bash scripts/update-prod.sh --pull   # git pull --ff-only first
#   bash scripts/update-prod.sh --pull --only glane,artifacts   # only these sites
#   bash scripts/update-prod.sh --force  # rebuild even when nothing changed
#
# Each site (tadaaa, artifacts, belts, glane) is rebuilt only when its commit differs
# from the one last deployed (recorded under OPS_HOST_DIR/deployed/, default
# var/ops/deployed/). A change that is not a commit (.env, base image) needs --force.
#
# Merges missing .env keys (mail, quotas, …), rebuilds SPAs via ephemeral
# node:22 (no host yarn), recreates containers, Doctrine migrate.
# Checks free disk (MIN_FREE_GB, default 10) and free inodes (MIN_FREE_INODES_PCT,
# default 10) before building and again right before the image build: prunes
# dangling images and old build cache, then all build cache and unused images if
# still short, stops if still short. A failed image build is retried once after
# a full Docker cleanup, with df -h / df -i / docker system df in the log.
# At the end, prunes again and caps the build cache (BUILD_CACHE_GB, default 8).
# node_modules left on the host by the SPA builds are removed right after each
# build (and first when the disk is short): they are reinstalled every build.
#
# Optional cohost (.env) — re-applied on edge after the main stack up:
#   GLANE_ROOT=/root/glane
#   GLANE_APP_SERVER_NAME=glane.tadaaa.space
#   GLANE_API_SERVER_NAME=glane-api.tadaaa.space
#   BELTS_DIST=/opt/belt/dist
#   BELTS_SERVER_NAME=belts.tadaaa.space
#   BELTS_SRC=… / BELTS_GIT_URL=…   # optional: build Belts like Artefacts
#   ARTIFACTS_DIST=/opt/artifacts/dist
#   ARTIFACTS_SERVER_NAME=artifacts.tadaaa.space
#   ARTIFACTS_PUBLIC_URL=https://artifacts.tadaaa.space
#   ARTIFACTS_SRC=… / ARTIFACTS_GIT_URL=…  # default: apps/artifacts + GitHub

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
ENV_FILE="$ROOT/.env"

# shellcheck source=lib/prod-env.sh
source "$ROOT/scripts/lib/prod-env.sh"

COMPOSE=(docker compose -f compose.prod.yaml)
DO_PULL=0
FORCE=0
ONLY=""
TARGETS=(tadaaa artifacts belts glane)
# 1 dès qu'un site a été reconstruit : l'edge est alors recréé (Caddyfile, snippets, volumes).
CHANGED=0
RESULTS=()

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

  if [[ -n "${ARTIFACTS_DIST:-}" ]]; then
    local artifacts_overlay="$ROOT/compose.prod.artifacts-cohost.yaml"
    [[ -f "$artifacts_overlay" ]] || die "ARTIFACTS_DIST set but missing $artifacts_overlay"
    [[ -d "$ARTIFACTS_DIST" ]] || die "ARTIFACTS_DIST is not a directory: $ARTIFACTS_DIST"
    [[ -f "$ARTIFACTS_DIST/index.html" ]] || warn "No index.html in ARTIFACTS_DIST=$ARTIFACTS_DIST (expect 404 until built)"
    mkdir -p "$ROOT/deploy/cohost"
    if [[ ! -f "$ROOT/deploy/cohost/artifacts.caddy" ]]; then
      die "Missing deploy/cohost/artifacts.caddy (needed for ARTIFACTS_DIST)"
    fi
    export ARTIFACTS_DIST
    export ARTIFACTS_SERVER_NAME="${ARTIFACTS_SERVER_NAME:-artifacts.tadaaa.space}"
    edge_files+=(-f "$artifacts_overlay")
    need=1
    info "Cohost Artefacts: ${ARTIFACTS_SERVER_NAME} → ${ARTIFACTS_DIST}"
  fi

  if [[ "$need" -eq 0 ]]; then
    return 0
  fi

  info "Recreating edge with cohost overlay(s)…"
  docker compose -f compose.prod.yaml "${edge_files[@]}" up -d --force-recreate edge
  ok "Edge cohost ready."
}

expect_only=0
for arg in "$@"; do
  if [[ "$expect_only" -eq 1 ]]; then
    ONLY="$arg"
    expect_only=0
    continue
  fi
  case "$arg" in
    --pull) DO_PULL=1 ;;
    --force) FORCE=1 ;;
    --only) expect_only=1 ;;
    --only=*) ONLY="${arg#--only=}" ;;
    -h|--help)
      cat <<'EOF'
Usage: bash scripts/update-prod.sh [--pull] [--only LIST] [--force]

  --pull        git pull --ff-only before building (fails if dirty/non-ff)
  --only LIST   comma-separated sites to update: tadaaa, artifacts, belts, glane
                (default: every configured site)
  --force       rebuild the selected sites even when their commit is already deployed

A site whose commit is already deployed is skipped. Glane (GLANE_ROOT) is updated
with its own scripts/update-prod.sh; a Glane failure does not stop the Tadaaa update.

Optional .env cohost (re-applied on edge after stack up):
  GLANE_ROOT=…  GLANE_APP_SERVER_NAME=…  GLANE_API_SERVER_NAME=…
  BELTS_DIST=…  BELTS_SERVER_NAME=…  [BELTS_SRC|BELTS_GIT_URL]
  ARTIFACTS_DIST=…  ARTIFACTS_SERVER_NAME=…  ARTIFACTS_PUBLIC_URL=…
  ARTIFACTS_SRC=… (défaut apps/artifacts)  ARTIFACTS_GIT_URL=…

With ARTIFACTS_DIST set, update-prod clones/pulls + builds Artefacts
(docker run node:22) then syncs into ARTIFACTS_DIST — no host yarn.
EOF
      exit 0
      ;;
    *)
      die "Unknown option: $arg (try --help)"
      ;;
  esac
done
[[ "$expect_only" -eq 0 ]] || die "--only attend une liste (ex. --only glane,artifacts)"
if [[ -n "$ONLY" ]]; then
  IFS=, read -r -a only_list <<<"$ONLY"
  for t in "${only_list[@]}"; do
    [[ " ${TARGETS[*]} " == *" $t "* ]] || die "Site inconnu pour --only : « $t » (attendu : ${TARGETS[*]})"
  done
fi

wants() { [[ -z "$ONLY" || ",$ONLY," == *",$1,"* ]]; }

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
  script_before="$(git rev-parse HEAD:scripts/update-prod.sh 2>/dev/null || true)"
  info "git pull --ff-only…"
  git pull --ff-only
  ok "Git up to date."
  # Bash lit ce fichier au fil de l'exécution : après un pull qui le modifie, on relance la
  # nouvelle version (une seule fois) au lieu de finir avec l'ancienne.
  script_after="$(git rev-parse HEAD:scripts/update-prod.sh 2>/dev/null || true)"
  if [[ -z "${UPDATE_PROD_REEXEC:-}" && "$script_before" != "$script_after" ]]; then
    info "update-prod.sh a changé : relance avec la nouvelle version…"
    UPDATE_PROD_REEXEC=1 exec bash "$ROOT/scripts/update-prod.sh" "$@"
  fi
fi

# Commit déployé par site : écrit seulement après une mise à jour réussie, donc un build
# qui échoue après le pull sera retenté au prochain passage.
STATE_DIR="${OPS_HOST_DIR:-$ROOT/var/ops}"
[[ "$STATE_DIR" == /* ]] || STATE_DIR="$ROOT/${STATE_DIR#./}"
STATE_DIR="$STATE_DIR/deployed"
deployed_sha() { cat "$STATE_DIR/$1" 2>/dev/null || true; }
mark_deployed() {
  [[ -n "$2" ]] || return 0
  mkdir -p "$STATE_DIR"
  printf '%s\n' "$2" >"$STATE_DIR/$1"
}
head_sha() { git -C "$1" rev-parse HEAD 2>/dev/null || true; }
# needs_update <site> <commit> : vrai si --force, commit inconnu ou différent du commit déployé.
needs_update() {
  [[ "$FORCE" -eq 1 || -z "$2" || "$2" != "$(deployed_sha "$1")" ]]
}
# report <site> <updated|unchanged|skipped|failed> [from] [to] — repris dans le résumé final et,
# quand le runner hôte le demande (UPDATE_PROD_RESULT), dans l'état du run.
report() {
  local line="$1 $2 ${3:--} ${4:--}"
  RESULTS+=("$line")
  if [[ -n "${UPDATE_PROD_RESULT:-}" ]]; then
    printf '%s\n' "$line" >>"$UPDATE_PROD_RESULT"
  fi
}

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

# Espace disque : chaque déploiement laisse l'ancienne image (sans nom) et du cache de build.
# On les retire, on vérifie qu'il reste assez de place AVANT de compiler, et on nettoie à la fin.
MIN_FREE_GB="${MIN_FREE_GB:-10}"
BUILD_CACHE_GB="${BUILD_CACHE_GB:-8}"
MIN_FREE_INODES_PCT="${MIN_FREE_INODES_PCT:-10}"
docker_dir() {
  local dir
  dir="$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || true)"
  [[ -d "$dir" ]] || dir="$ROOT"
  printf '%s' "$dir"
}
free_gb() {
  df -Pk "$(docker_dir)" "$ROOT" | awk 'NR > 1 { if (min == "" || $4 < min) min = $4 } END { printf "%d", min / 1048576 }'
}
# Inodes libres (%) : un disque peut refuser de créer des fichiers (« No space left on device ») avec
# des gigaoctets libres quand les couches Docker et les node_modules ont épuisé les inodes.
# Un système de fichiers sans compte d'inodes (btrfs, zfs : 0 au total) compte comme 100 %.
free_inodes_pct() {
  df -Pi "$(docker_dir)" "$ROOT" | awk 'NR > 1 { p = ($2 > 0) ? int($4 * 100 / $2) : 100; if (min == "" || p < min) min = p } END { printf "%d", min }'
}
space_ok() {
  (( $(free_gb) >= MIN_FREE_GB && $(free_inodes_pct) >= MIN_FREE_INODES_PCT ))
}
disk_report() {
  warn "État du disque :"
  df -h "$(docker_dir)" "$ROOT" 2>&1 | sed 's/^/    /' >&2 || true
  df -i "$(docker_dir)" "$ROOT" 2>&1 | sed 's/^/    /' >&2 || true
  docker system df 2>&1 | sed 's/^/    /' >&2 || true
}
docker_cleanup() {
  docker image prune -f >/dev/null 2>&1 || warn "docker image prune a échoué."
  docker builder prune -f --filter until=72h >/dev/null 2>&1 || warn "docker builder prune a échoué."
  # Plusieurs déploiements par jour : le cache récent grossit sans limite si on ne le plafonne pas.
  docker builder prune -f --reserved-space "${BUILD_CACHE_GB}gb" >/dev/null 2>&1 \
    || docker builder prune -f --keep-storage "${BUILD_CACHE_GB}gb" >/dev/null 2>&1 \
    || warn "plafond du cache de build non appliqué."
}
# Nettoyage complet : tout le cache de build, les conteneurs arrêtés et les images qu'aucun conteneur
# n'utilise (celles de la pile en marche restent ; les images de base seront retéléchargées).
docker_deep_cleanup() {
  docker container prune -f >/dev/null 2>&1 || true
  docker builder prune -af >/dev/null 2>&1 || warn "docker builder prune -a a échoué."
  docker image prune -af >/dev/null 2>&1 || warn "docker image prune -a a échoué."
}
# node_modules laissés sur l'hôte par les builds des SPA (conteneur node éphémère, dépôt monté) : réinstallés
# à chaque build, inutiles entre deux déploiements, et des centaines de milliers de fichiers chacun (inodes).
build_leftovers_cleanup() {
  local d
  for d in "$ROOT" "$ROOT/apps/web" \
    ${ARTIFACTS_DIST:+"${ARTIFACTS_SRC:-$ROOT/apps/artifacts}"} \
    ${BELTS_DIST:+"${BELTS_SRC:-$ROOT/apps/belts}"}; do
    [[ -d "$d/node_modules" ]] && rm -rf "$d/node_modules"
  done
  return 0
}
ensure_space() {
  local when="$1"
  info "Disque ${when} : $(free_gb) Go et $(free_inodes_pct) % d'inodes libres (minimum ${MIN_FREE_GB} Go, ${MIN_FREE_INODES_PCT} %)."
  space_ok && return 0
  warn "Disque court : retrait des node_modules des builds, des images orphelines et du vieux cache de build…"
  build_leftovers_cleanup
  docker_cleanup
  info "Disque après nettoyage : $(free_gb) Go, $(free_inodes_pct) % d'inodes libres."
  space_ok && return 0
  warn "Toujours court : retrait de tout le cache de build et des images inutilisées…"
  docker_deep_cleanup
  info "Disque après nettoyage complet : $(free_gb) Go, $(free_inodes_pct) % d'inodes libres."
  space_ok && return 0
  disk_report
  die "Pas assez de place sur le disque ($(free_gb) Go, $(free_inodes_pct) % d'inodes libres) : libérer de l'espace (docker system df, yarn cache, journaux) puis relancer."
}
ensure_space "avant la mise à jour"

tadaaa_sha="$(head_sha "$ROOT")"
tadaaa_from="$(deployed_sha tadaaa)"
BUILD_TADAAA=0
if ! wants tadaaa; then
  report tadaaa skipped
elif needs_update tadaaa "$tadaaa_sha" || [[ ! -f "$ROOT/apps/web/dist/index.html" ]]; then
  BUILD_TADAAA=1
else
  ok "Tadaaa déjà à jour (${tadaaa_sha:0:12}) : rien à reconstruire."
  report tadaaa unchanged "$tadaaa_sha" "$tadaaa_sha"
fi

if [[ "$BUILD_TADAAA" -eq 1 ]]; then
  info "Building front (VITE_API_BASE_URL=https://${api_host})…"
  # Use monorepo root yarn.lock (Concorde 7.x). Do not install from apps/web alone —
  # apps/web/yarn.lock can pin an older Concorde and break tsc (ApiResult, @post, …).
  rm -rf "$ROOT/node_modules" "$ROOT/apps/web/node_modules"
  docker run --rm \
    -v "$ROOT:/repo" \
    -w /repo \
    -e VITE_API_BASE_URL="https://${api_host}" \
    --pull always \
    node:22-bookworm \
    bash -lc 'corepack enable && yarn install --frozen-lockfile && yarn --cwd apps/web build'
  [[ -f "$ROOT/apps/web/dist/index.html" ]] || die "Front build failed."
  rm -rf "$ROOT/node_modules" "$ROOT/apps/web/node_modules"
  ok "Front build ready."
fi

# Sibling SPAs (Artefacts / Belts) — même pattern node:22 éphémère, pas de yarn hôte.
ensure_sibling_git() {
  local dir="$1" url="$2" name="$3"
  if [[ -d "$dir/.git" ]]; then
    if [[ "$DO_PULL" -eq 1 ]]; then
      # Le build régénère le routeur (ordre des routes propre à la machine) : sans ce retour à
      # la version suivie, un commit qui touche ce fichier bloque le pull.
      git -C "$dir" checkout -- src/app/routes/router.ts 2>/dev/null || true
      info "git pull $name…"
      git -C "$dir" pull --ff-only
    fi
    return 0
  fi
  if [[ -z "$url" ]]; then
    die "Missing source for $name (set $4 or clone into $dir)"
  fi
  info "Cloning $name → $dir"
  mkdir -p "$(dirname "$dir")"
  git clone "$url" "$dir"
}

# Build SPA in $src → sync to $dist (souvent le volume Caddy).
build_sibling_spa() {
  local name="$1" src="$2" dist="$3" api_base="$4"
  [[ -d "$src" ]] || die "$name source missing: $src"
  info "Building $name (VITE_API_BASE_URL=${api_base})…"
  rm -rf "$src/node_modules"
  docker run --rm \
    -v "$src:/repo" \
    -w /repo \
    -e VITE_API_BASE_URL="$api_base" \
    --pull always \
    node:22-bookworm \
    bash -lc 'corepack enable && yarn install --frozen-lockfile && yarn build'
  [[ -f "$src/dist/index.html" ]] || die "$name build failed ($src/dist/index.html missing)."
  rm -rf "$src/node_modules"
  mkdir -p "$dist"
  if [[ "$(cd "$src/dist" && pwd -P)" != "$(cd "$dist" && pwd -P)" ]]; then
    if command -v rsync >/dev/null 2>&1; then
      rsync -a --delete "$src/dist/" "$dist/"
    else
      rm -rf "${dist:?}/"*
      cp -a "$src/dist/." "$dist/"
    fi
  fi
  ok "$name → $dist"
}

# update_sibling_spa <site> <label> <src> <git url> <env hint> <dist> : pull puis build si le
# commit a changé (ou si le dist est vide).
update_sibling_spa() {
  local site="$1" label="$2" src="$3" url="$4" hint="$5" dist="$6" from sha
  if ! wants "$site"; then
    report "$site" skipped
    return 0
  fi
  ensure_sibling_git "$src" "$url" "$site" "$hint"
  from="$(deployed_sha "$site")"
  sha="$(head_sha "$src")"
  if needs_update "$site" "$sha" || [[ ! -f "$dist/index.html" ]]; then
    build_sibling_spa "$label" "$src" "$dist" "https://${api_host}"
    mark_deployed "$site" "$sha"
    report "$site" updated "$from" "$sha"
    CHANGED=1
  else
    ok "$label déjà à jour (${sha:0:12}) : rien à reconstruire."
    report "$site" unchanged "$sha" "$sha"
  fi
}

if [[ -n "${ARTIFACTS_DIST:-}" ]]; then
  ARTIFACTS_SRC="${ARTIFACTS_SRC:-$ROOT/apps/artifacts}"
  ARTIFACTS_GIT_URL="${ARTIFACTS_GIT_URL:-https://github.com/ladigitale/artifacts.git}"
  update_sibling_spa artifacts "Artefacts" "$ARTIFACTS_SRC" "$ARTIFACTS_GIT_URL" "ARTIFACTS_GIT_URL / ARTIFACTS_SRC" "$ARTIFACTS_DIST"
fi

if [[ -n "${BELTS_DIST:-}" && -n "${BELTS_SRC:-${BELTS_GIT_URL:-}}" ]]; then
  BELTS_SRC="${BELTS_SRC:-$ROOT/apps/belts}"
  update_sibling_spa belts "Belts" "$BELTS_SRC" "${BELTS_GIT_URL:-}" "BELTS_GIT_URL / BELTS_SRC" "$BELTS_DIST"
elif [[ -n "${BELTS_DIST:-}" ]]; then
  warn "BELTS_DIST set but no BELTS_SRC/BELTS_GIT_URL — skip Belts build (using existing dist)."
fi

# Glane a sa propre stack (API, MCP, base) et son propre scripts/update-prod.sh : on le lance
# quand son dépôt a avancé. Environnement vidé : les variables du .env de Tadaaa exportées ici
# (hôtes, base, secrets) passeraient sinon devant celles du .env de Glane dans docker compose.
# Un échec n'arrête pas la mise à jour de Tadaaa ; il est signalé dans le résumé.
update_glane() {
  [[ -n "${GLANE_ROOT:-}" ]] || return 0
  if ! wants glane; then
    report glane skipped
    return 0
  fi
  local script="$GLANE_ROOT/scripts/update-prod.sh" from target to args=()
  if [[ ! -d "$GLANE_ROOT/.git" || ! -f "$script" ]]; then
    warn "GLANE_ROOT sans dépôt git ou sans scripts/update-prod.sh : Glane n'est pas mis à jour ici."
    return 0
  fi
  from="$(deployed_sha glane)"
  target="$(head_sha "$GLANE_ROOT")"
  if [[ "$DO_PULL" -eq 1 ]]; then
    args+=(--pull)
    if git -C "$GLANE_ROOT" fetch --quiet; then
      target="$(git -C "$GLANE_ROOT" rev-parse '@{upstream}' 2>/dev/null || printf '%s' "$target")"
    else
      warn "git fetch Glane a échoué : comparaison avec le commit local."
    fi
  fi
  if ! needs_update glane "$target"; then
    ok "Glane déjà à jour (${target:0:12}) : rien à reconstruire."
    report glane unchanged "$target" "$target"
    return 0
  fi
  info "Mise à jour de Glane (${from:0:12} → ${target:0:12})…"
  if env -i PATH="$PATH" HOME="${HOME:-/root}" bash "$script" ${args[@]+"${args[@]}"}; then
    to="$(head_sha "$GLANE_ROOT")"
    mark_deployed glane "$to"
    report glane updated "$from" "$to"
    CHANGED=1
    ok "Glane à jour (${to:0:12})."
  else
    warn "La mise à jour de Glane a échoué (voir plus haut) : Tadaaa continue, Glane sera retenté au prochain passage."
    report glane failed "$from" "$target"
  fi
}
update_glane

# --pull : récupère l'image de base à jour (correctifs de sécurité PHP, ex. PHP2Shell CVE-2026-109618/109619).
# Sans lui, `up --build` réutilise l'image FrankenPHP déjà en cache, donc un PHP non corrigé.
if [[ "$BUILD_TADAAA" -eq 1 ]]; then
  info "Building images (base images pulled)…"
  ensure_space "avant la construction des images"
  if ! "${COMPOSE[@]}" build --pull; then
    # Souvent un disque plein en cours de construction (cache, couches, inodes) : nettoyage complet, un essai de plus.
    disk_report
    warn "Construction échouée : nettoyage complet de Docker puis nouvel essai…"
    docker_deep_cleanup
    info "Disque après nettoyage complet : $(free_gb) Go, $(free_inodes_pct) % d'inodes libres."
    if ! "${COMPOSE[@]}" build --pull; then
      disk_report
      die "La construction des images a échoué deux fois (voir l'état du disque ci-dessus)."
    fi
  fi
  info "Recreating stack…"
  "${COMPOSE[@]}" up -d --build
  ok "Containers up."
  CHANGED=1
fi

if [[ "$CHANGED" -eq 1 ]]; then
  recreate_cohost_edge
else
  ok "Aucun site modifié : edge et conteneurs inchangés."
fi

if [[ "$BUILD_TADAAA" -eq 1 ]]; then
  info "Migrations…"
  "${COMPOSE[@]}" exec -T php bin/console doctrine:migrations:migrate --no-interaction
  ok "Migrations done."
  mark_deployed tadaaa "$tadaaa_sha"
  report tadaaa updated "$tadaaa_from" "$tadaaa_sha"
fi

if curl -fsS --max-time 10 "https://${api_host}/api/health" >/dev/null 2>&1; then
  ok "API healthy: https://${api_host}/api/health"
else
  warn "API not reachable yet — check DNS / logs: ${COMPOSE[*]} logs -f edge php"
fi

info "Nettoyage Docker (images orphelines, cache de build de plus de 72 h, cache plafonné à ${BUILD_CACHE_GB} Go)…"
docker_cleanup
ok "Disque : $(free_gb) Go et $(free_inodes_pct) % d'inodes libres."

say ""
say "${BLD}Update complete.${RST}"
for line in ${RESULTS[@]+"${RESULTS[@]}"}; do
  read -r r_site r_status r_from r_to <<<"$line"
  case "$r_status" in
    updated) say "  ${r_site}: mis à jour (${r_from:0:12} → ${r_to:0:12})" ;;
    unchanged) say "  ${r_site}: déjà à jour (${r_to:0:12})" ;;
    skipped) say "  ${r_site}: non demandé" ;;
    failed) say "  ${r_site}: ÉCHEC — voir le journal" ;;
  esac
done
say "  Front: https://${app_host}"
say "  API:   https://${api_host}/api"
[[ -n "${GLANE_ROOT:-}" ]] && say "  Glane: https://${GLANE_APP_SERVER_NAME:-glane.tadaaa.space}"
[[ -n "${BELTS_DIST:-}" ]] && say "  Belts: https://${BELTS_SERVER_NAME:-belts.tadaaa.space}"
[[ -n "${ARTIFACTS_DIST:-}" ]] && say "  Artefacts: https://${ARTIFACTS_SERVER_NAME:-artifacts.tadaaa.space}"
say ""
