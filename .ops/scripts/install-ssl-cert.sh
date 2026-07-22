#!/usr/bin/env bash
# Certificat auto-signé devops pour tada-api.julien.test (volume devops_letsencrypt_vol).
set -euo pipefail

DOMAIN="${1:-tada-api.julien.test}"
LIVE="/etc/letsencrypt/live/${DOMAIN}"

docker exec mariadb bash /scripts/cmd.sh /root mkdir -p "$LIVE"

docker exec mariadb bash /scripts/cmd.sh /root openssl req -new -x509 -days 365 -nodes \
  -out "${LIVE}/fullchain.pem" \
  -keyout "${LIVE}/privkey.pem" \
  -subj "/CN=${DOMAIN}"

echo "Certificat créé : ${LIVE}/fullchain.pem"
ls -la "/var/lib/docker/volumes/devops_letsencrypt_vol/_data/live/${DOMAIN}/" 2>/dev/null || true
