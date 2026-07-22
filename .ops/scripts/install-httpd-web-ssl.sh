#!/usr/bin/env bash
# Vhost httpd devops : siteSslHtml + certificat local (SPA).
# Prérequis navigateur : cert accepté, ou chrome://flags
#   « Insecure origins treated as secure » = https://tada.julien.test
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITES_CONF="${SITES_CONF:-/usr2/httpd/sites.conf}"
DOMAIN="tada.julien.test"
DOCROOT="/sites/poc/tada/apps/web/dist"
SITE_HTTP="Use siteHtml ${DOMAIN} ${DOCROOT}"
SITE_SSL="Use siteSslHtml ${DOMAIN} ${DOCROOT}"

if [ ! -f "$SITES_CONF" ]; then
  echo "Fichier introuvable : $SITES_CONF" >&2
  exit 1
fi

if [ ! -f "${ROOT}/../apps/web/dist/index.html" ]; then
  echo "dist absent — lancez d’abord : .ops/scripts/build-web.sh" >&2
  exit 1
fi

"${ROOT}/scripts/install-ssl-cert.sh" "$DOMAIN"

if grep -Fq "$SITE_SSL" "$SITES_CONF"; then
  echo "siteSslHtml déjà présent dans $SITES_CONF"
elif grep -Fq "$SITE_HTTP" "$SITES_CONF"; then
  sed -i "s|^Use siteHtml ${DOMAIN} .*|${SITE_SSL}|" "$SITES_CONF"
  echo "Remplacé siteHtml → siteSslHtml dans $SITES_CONF"
else
  printf '\n%s\n' "$SITE_SSL" >> "$SITES_CONF"
  echo "Ajouté dans $SITES_CONF : $SITE_SSL"
fi

echo "Pensez à : docker restart httpd"
echo "URL : https://${DOMAIN}"
echo "Chrome : chrome://flags → Insecure origins treated as secure → ${DOMAIN} (https)"
