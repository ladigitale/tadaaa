#!/usr/bin/env bash
# Installe le runner hôte des mises à jour prod déclenchées par le MCP (trigger_prod_update).
#
# À lancer en root sur le VPS, depuis la racine du dépôt :
#   sudo bash scripts/ops/install-deploy-runner.sh            # installe / met à jour les unités
#   sudo bash scripts/ops/install-deploy-runner.sh --uninstall
#
# Puis dans .env : OPS_DEPLOY_USERS=ton.email@exemple.fr (compte ROLE_ADMIN)
# et recréer php : docker compose -f compose.prod.yaml up -d php

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../lib/prod-env.sh
source "$ROOT/scripts/lib/prod-env.sh"

UNIT=tadaaa-deploy
UNIT_DIR=/etc/systemd/system

[[ "$(id -u)" -eq 0 ]] || die "À lancer en root (systemd + docker)."
need_cmd systemctl
need_cmd flock

if [[ "${1:-}" == "--uninstall" ]]; then
  systemctl disable --now "$UNIT.path" 2>/dev/null || true
  rm -f "$UNIT_DIR/$UNIT.path" "$UNIT_DIR/$UNIT.service"
  systemctl daemon-reload
  ok "Runner désinstallé (OPS_DIR conservé)."
  exit 0
fi

OPS_HOST_DIR="$(sed -n 's/^OPS_HOST_DIR=//p' "$ROOT/.env" 2>/dev/null | tail -1 | tr -d '"'"'"' ')"
OPS_HOST_DIR="${OPS_HOST_DIR:-$ROOT/var/ops}"
[[ "$OPS_HOST_DIR" == /* ]] || OPS_HOST_DIR="$ROOT/${OPS_HOST_DIR#./}"

mkdir -p "$OPS_HOST_DIR/requests" "$OPS_HOST_DIR/runs"
chmod 0750 "$OPS_HOST_DIR"

cat >"$UNIT_DIR/$UNIT.service" <<EOF
[Unit]
Description=Tadaaa — mise à jour prod demandée via MCP
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
WorkingDirectory=$ROOT
Environment=OPS_DIR=$OPS_HOST_DIR
ExecStart=/usr/bin/env bash $ROOT/scripts/ops/deploy-runner.sh
TimeoutStartSec=45min
Nice=5
EOF

cat >"$UNIT_DIR/$UNIT.path" <<EOF
[Unit]
Description=Tadaaa — surveille les demandes de mise à jour prod

[Path]
PathExistsGlob=$OPS_HOST_DIR/requests/*.json
Unit=$UNIT.service

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now "$UNIT.path"
ok "Runner installé : $UNIT.path → $UNIT.service"
info "OPS_DIR hôte : $OPS_HOST_DIR (monté dans php sur /srv/ops)"

if ! grep -qE '^OPS_DEPLOY_USERS=.+' "$ROOT/.env" 2>/dev/null; then
  warn "OPS_DEPLOY_USERS absent de .env : personne ne peut déclencher la mise à jour."
  warn "Ajoute OPS_DEPLOY_USERS=<email du compte ROLE_ADMIN> puis : docker compose -f compose.prod.yaml up -d php"
fi
say "Suivi : journalctl -u $UNIT.service -f   |   ls $OPS_HOST_DIR/runs"
