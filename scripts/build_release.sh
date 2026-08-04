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
  --exclude='demandForcasting/logs/*' --exclude='demandForcasting/models/*' \
  --exclude='demandForcasting/__pycache__/' --exclude='**/__pycache__/' \
  --exclude='*.pyc' --exclude='*.pyo' --exclude='*.joblib' \
  --exclude='demandForcasting/model_metrics.json' \
  --exclude='demandForcasting/.training.lock' --exclude='demandForcasting/.api-retrain.lock' \
  --exclude='.release/'
for directory in logs backups sessions imports exports receipts; do
  mkdir -p "$TMP/inventory_system/storage/$directory"
  touch "$TMP/inventory_system/storage/$directory/.gitkeep"
done
mkdir -p "$TMP/inventory_system/demandForcasting/logs" "$TMP/inventory_system/demandForcasting/models"
touch "$TMP/inventory_system/demandForcasting/logs/.gitkeep" "$TMP/inventory_system/demandForcasting/models/.gitkeep"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" inventory_system)
echo "$OUT"
