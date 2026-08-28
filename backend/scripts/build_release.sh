#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$ROOT/../inventory_system_release.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/inventory_system"
rsync -a "$ROOT/" "$TMP/inventory_system/" \
  --exclude='.git/' --exclude='.env' --exclude='vendor/' \
  --exclude='backend/storage/logs/*' --exclude='backend/storage/backups/*' \
  --exclude='backend/storage/sessions/*' --exclude='backend/storage/imports/*' \
  --exclude='backend/storage/exports/*' --exclude='backend/storage/receipts/*' \
  --exclude='backend/legacy/demandForcasting/logs/*' --exclude='backend/legacy/demandForcasting/models/*' \
  --exclude='backend/legacy/demandForcasting/__pycache__/' --exclude='**/__pycache__/' \
  --exclude='*.pyc' --exclude='*.pyo' --exclude='*.joblib' \
  --exclude='backend/legacy/demandForcasting/model_metrics.json' \
  --exclude='backend/legacy/demandForcasting/.training.lock' --exclude='backend/legacy/demandForcasting/.api-retrain.lock' \
  --exclude='.release/'
for directory in logs backups sessions imports exports receipts; do
  mkdir -p "$TMP/inventory_system/backend/storage/$directory"
  touch "$TMP/inventory_system/backend/storage/$directory/.gitkeep"
done
mkdir -p "$TMP/inventory_system/backend/legacy/demandForcasting/logs" "$TMP/inventory_system/backend/legacy/demandForcasting/models"
touch "$TMP/inventory_system/backend/legacy/demandForcasting/logs/.gitkeep" "$TMP/inventory_system/backend/legacy/demandForcasting/models/.gitkeep"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" inventory_system)
echo "$OUT"
