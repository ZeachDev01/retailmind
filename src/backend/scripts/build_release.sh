#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$ROOT/../inventory_system_release.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/inventory_system"
rsync -a "$ROOT/" "$TMP/inventory_system/" \
  --exclude='.git/' --exclude='.env' --exclude='vendor/' \
  --exclude='src/backend/storage/logs/*' --exclude='src/backend/storage/backups/*' \
  --exclude='src/backend/storage/sessions/*' --exclude='src/backend/storage/imports/*' \
  --exclude='src/backend/storage/exports/*' --exclude='src/backend/storage/receipts/*' \
  --exclude='src/backend/legacy/demandForcasting/logs/*' --exclude='src/backend/legacy/demandForcasting/models/*' \
  --exclude='src/backend/legacy/demandForcasting/__pycache__/' --exclude='**/__pycache__/' \
  --exclude='*.pyc' --exclude='*.pyo' --exclude='*.joblib' \
  --exclude='src/backend/legacy/demandForcasting/model_metrics.json' \
  --exclude='src/backend/legacy/demandForcasting/.training.lock' --exclude='src/backend/legacy/demandForcasting/.api-retrain.lock' \
  --exclude='.release/'
for directory in logs backups sessions imports exports receipts; do
  mkdir -p "$TMP/inventory_system/src/backend/storage/$directory"
  touch "$TMP/inventory_system/src/backend/storage/$directory/.gitkeep"
done
mkdir -p "$TMP/inventory_system/src/backend/legacy/demandForcasting/logs" "$TMP/inventory_system/src/backend/legacy/demandForcasting/models"
touch "$TMP/inventory_system/src/backend/legacy/demandForcasting/logs/.gitkeep" "$TMP/inventory_system/src/backend/legacy/demandForcasting/models/.gitkeep"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" inventory_system)
echo "$OUT"
