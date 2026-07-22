#!/usr/bin/env bash
# API + front Tadaaa sur httpd (HTTPS devops).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

"${ROOT}/scripts/build-web.sh"
"${ROOT}/scripts/install-httpd-site-ssl.sh"
"${ROOT}/scripts/install-httpd-web-ssl.sh"

echo ""
echo "Terminé. Redémarrez httpd si besoin : docker restart httpd"
echo "  Front : https://tada.julien.test"
echo "  API   : https://tada-api.julien.test"
echo "  Chrome flag : Insecure origins treated as secure → https://tada.julien.test"
