#!/usr/bin/env bash
# Build SPA Concorde → apps/web/dist (conteneur nodejs devops).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONTAINER="${NODE_CONTAINER:-nodejs}"
SITE_PATH="/sites/poc/tada"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "Conteneur ${CONTAINER} introuvable." >&2
  exit 1
fi

echo "Build front Tadaaa…"
# Vite emptyOutDir=false (Concorde) : on purge les vieux assets hashés
rm -rf "${ROOT}/apps/web/dist/assets"
docker exec "$CONTAINER" bash /scripts/cmd.sh "$SITE_PATH" yarn build

if [ ! -f "${ROOT}/apps/web/dist/index.html" ]; then
  echo "Build échoué : apps/web/dist/index.html absent." >&2
  exit 1
fi

# Copie .htaccess public → dist si Vite ne l’a pas repris
if [ -f "${ROOT}/apps/web/public/.htaccess" ]; then
  cp -f "${ROOT}/apps/web/public/.htaccess" "${ROOT}/apps/web/dist/.htaccess"
fi

echo "OK → ${ROOT}/apps/web/dist"
