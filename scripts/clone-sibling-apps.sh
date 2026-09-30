#!/usr/bin/env bash
# Clone sibling SPAs into apps/ (gitignored in tadaaa). Idempotent.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

clone_one() {
  local dir="$1"
  local url="${2:-}"
  local name="$3"
  if [[ -d "$dir/.git" ]]; then
    echo "✓ $name already present at $dir"
    return 0
  fi
  if [[ -z "$url" ]]; then
    echo "⚠ skip $name — set $4 to clone into $dir"
    return 0
  fi
  echo "→ cloning $name into $dir"
  git clone "$url" "$dir"
}

# MorseAttack (already often present under apps/morseattack when developing)
clone_one "apps/morseattack" "${MORSEATTACK_GIT_URL:-}" "morseattack" "MORSEATTACK_GIT_URL"

# Belts / Outils — default to local atelier path via file:// if remote unset
BELTS_URL="${BELTS_GIT_URL:-}"
if [[ -z "$BELTS_URL" && -d "/home/julien/sites/atelier/belts/.git" ]]; then
  BELTS_URL="/home/julien/sites/atelier/belts"
fi
clone_one "apps/belts" "$BELTS_URL" "belts" "BELTS_GIT_URL"

echo "Done. Workspaces: use yarn belts:dev / yarn morse:dev when the folder exists."

# Artefacts viewer — GitHub by default, local atelier if present
ARTIFACTS_URL="${ARTIFACTS_GIT_URL:-}"
if [[ -z "$ARTIFACTS_URL" && -d "/usr2/sites/atelier/artifacts/.git" ]]; then
  ARTIFACTS_URL="/usr2/sites/atelier/artifacts"
elif [[ -z "$ARTIFACTS_URL" && -d "/home/julien/sites/atelier/artifacts/.git" ]]; then
  ARTIFACTS_URL="/home/julien/sites/atelier/artifacts"
elif [[ -z "$ARTIFACTS_URL" ]]; then
  ARTIFACTS_URL="https://github.com/ladigitale/artifacts.git"
fi
clone_one "apps/artifacts" "$ARTIFACTS_URL" "artifacts" "ARTIFACTS_GIT_URL"
