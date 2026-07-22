#!/usr/bin/env bash
# Génère les clés Lexik JWT et les rend lisibles par php-fpm (user ssks dans php84).
set -euo pipefail

API_DIR="/sites/poc/tada/apps/api"
JWT_DIR="${API_DIR}/config/jwt"
PHP_CONTAINER="${PHP_CONTAINER:-php84}"

if ! docker ps --format '{{.Names}}' | grep -qx "$PHP_CONTAINER"; then
  echo "Conteneur ${PHP_CONTAINER} introuvable." >&2
  exit 1
fi

run_php() {
  docker exec "$PHP_CONTAINER" bash /scripts/cmd.sh "$API_DIR" php "$@"
}

if [ ! -f "${JWT_DIR}/private.pem" ]; then
  echo "Génération des clés JWT…"
  run_php bin/console lexik:jwt:generate-keypair --skip-if-exists
fi

echo "Permissions JWT pour php-fpm (ssks)…"
docker exec -u root "$PHP_CONTAINER" chown ssks:ssks \
  "${JWT_DIR}/private.pem" "${JWT_DIR}/public.pem"
docker exec -u root "$PHP_CONTAINER" chmod 640 "${JWT_DIR}/private.pem"
docker exec -u root "$PHP_CONTAINER" chmod 644 "${JWT_DIR}/public.pem"

run_php bin/console lexik:jwt:check-config

echo "Clés JWT OK."
