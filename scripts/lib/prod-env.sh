#!/usr/bin/env bash
# Shared helpers for install-prod.sh / update-prod.sh (sourced, not executed).

# Expects ROOT to be set by the caller. ENV_FILE defaults to $ROOT/.env.

: "${ROOT:?}"
: "${ENV_FILE:=$ROOT/.env}"

RED=${RED:-$'\033[0;31m'}
GRN=${GRN:-$'\033[0;32m'}
YLW=${YLW:-$'\033[1;33m'}
CYN=${CYN:-$'\033[0;36m'}
BLD=${BLD:-$'\033[1m'}
RST=${RST:-$'\033[0m'}

say() { printf '%s\n' "$*"; }
info() { printf '%s→%s %s\n' "$CYN" "$RST" "$*"; }
ok() { printf '%s✓%s %s\n' "$GRN" "$RST" "$*"; }
warn() { printf '%s!%s %s\n' "$YLW" "$RST" "$*"; }
die() { printf '%s✗%s %s\n' "$RED" "$RST" "$*" >&2; exit 1; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Missing command: $1"
}

# Append KEY=VALUE to .env only if KEY is absent (commented or missing).
ensure_env_key() {
  local key="$1" value="$2"
  if grep -qE "^${key}=" "$ENV_FILE" 2>/dev/null; then
    return 0
  fi
  printf '\n%s=%s\n' "$key" "$value" >>"$ENV_FILE"
  ok "Added ${key} to .env"
}

# Keys introduced for email verification + free-tier quotas.
ensure_prod_env_defaults() {
  local app_host="$1" api_host="$2" notice_email="${3:-}"
  local mail_from="${notice_email:-app@${app_host#app.}}"

  ensure_env_key "REGISTRATION_AUTO_APPROVE" "0"
  ensure_env_key "MAILER_DSN" "null://null"
  ensure_env_key "MAIL_FROM" "$mail_from"
  ensure_env_key "APP_PUBLIC_URL" "https://${app_host}"
  ensure_env_key "DEFAULT_STORAGE_QUOTA_BYTES" "5242880"
  ensure_env_key "GLOBAL_MONTHLY_TRANSFER_BYTES" "5368709120"
  ensure_env_key "FLOOR_PER_USER_MONTH_BYTES" "52428800"
  ensure_env_key "CEIL_PER_USER_MONTH_BYTES" "524288000"
  ensure_env_key "FLOOR_PER_USER_DAY_BYTES" "2097152"
  ensure_env_key "CEIL_PER_USER_DAY_BYTES" "26214400"

  # Legal identity (fill manually — empty placeholders)
  ensure_env_key "LEGAL_PUBLISHER_NAME" ""
  ensure_env_key "LEGAL_PUBLISHER_EMAIL" ""
  ensure_env_key "LEGAL_PUBLISHER_ADDRESS" ""
  ensure_env_key "LEGAL_SIRET" ""
  ensure_env_key "LEGAL_HOST_NAME" ""
  ensure_env_key "LEGAL_HOST_ADDRESS" ""
  ensure_env_key "LEGAL_HOST_CONTACT" ""
  ensure_env_key "LEGAL_PRIVACY_EMAIL" ""

  # Keep Mercure public URL coherent if missing
  ensure_env_key "MERCURE_PUBLIC_URL" "https://${api_host}/.well-known/mercure"
}

urlencode() {
  python3 -c 'import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"
}

# Build smtp://user:pass@mail.infomaniak.com:587 (URL-encoded).
build_infomaniak_dsn() {
  local user="$1" pass="$2"
  printf 'smtp://%s:%s@mail.infomaniak.com:587' "$(urlencode "$user")" "$(urlencode "$pass")"
}
