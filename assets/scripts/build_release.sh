#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/../inventory_system_release.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/inventory_system"
rsync -a "$ROOT/" "$TMP/inventory_system/" \
  --exclude='.git/' --exclude='.env' --exclude='vendor/' \
  --exclude='storage/logs/*' --exclude='storage/backups/*' \
  --exclude='storage/sessions/*' --exclude='storage/imports/*' \
  --exclude='storage/exports/*' --exclude='storage/receipts/*' \
  --exclude='legacy/demandForcasting/logs/*' --exclude='legacy/demandForcasting/models/*' \
  --exclude='legacy/demandForcasting/__pycache__/' --exclude='**/__pycache__/' \
  --exclude='*.pyc' --exclude='*.pyo' --exclude='*.joblib' \
  --exclude='legacy/demandForcasting/model_metrics.json' \
  --exclude='legacy/demandForcasting/.training.lock' --exclude='legacy/demandForcasting/.api-retrain.lock' \
  --exclude='.release/'
for directory in logs backups sessions imports exports receipts; do
  mkdir -p "$TMP/inventory_system/storage/$directory"
  touch "$TMP/inventory_system/storage/$directory/.gitkeep"
done
mkdir -p "$TMP/inventory_system/legacy/demandForcasting/logs" "$TMP/inventory_system/legacy/demandForcasting/models"
touch "$TMP/inventory_system/legacy/demandForcasting/logs/.gitkeep" "$TMP/inventory_system/legacy/demandForcasting/models/.gitkeep"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" inventory_system)
echo "$OUT"
