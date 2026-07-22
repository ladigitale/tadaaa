#!/usr/bin/env bash
# Vhost httpd devops : siteSsl + certificat local.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITES_CONF="${SITES_CONF:-/usr2/httpd/sites.conf}"
DOMAIN="tada-api.julien.test"
DOCROOT="/sites/poc/tada/apps/api/public"
SITE_HTTP="Use site 8.4 ${DOMAIN} ${DOCROOT}"
SITE_SSL="Use siteSsl 8.4 ${DOMAIN} ${DOCROOT}"

if [ ! -f "$SITES_CONF" ]; then
  echo "Fichier introuvable : $SITES_CONF" >&2
  exit 1
fi

"${ROOT}/scripts/install-ssl-cert.sh" "$DOMAIN"
"${ROOT}/scripts/install-jwt-keys.sh"

if grep -Fq "$SITE_SSL" "$SITES_CONF"; then
  echo "siteSsl déjà présent dans $SITES_CONF"
else
  if grep -Fq "$SITE_HTTP" "$SITES_CONF"; then
    sed -i "s|^Use site 8.4 ${DOMAIN} .*|${SITE_SSL}|" "$SITES_CONF"
    echo "Remplacé site → siteSsl dans $SITES_CONF"
  else
    printf '\n%s\n' "$SITE_SSL" >> "$SITES_CONF"
    echo "Ajouté dans $SITES_CONF : $SITE_SSL"
  fi
fi

echo "Pensez à : docker restart httpd"
