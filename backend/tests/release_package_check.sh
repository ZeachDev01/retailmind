#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ZIP="$(mktemp --suffix=.zip)"
trap 'rm -f "$ZIP"' EXIT
bash "$ROOT/backend/scripts/build_release.sh" "$ZIP" >/dev/null
CONTENTS="$(unzip -Z1 "$ZIP")"
if grep -Fxq 'inventory_system/.env' <<<"$CONTENTS"; then
  echo 'Release package contains forbidden path: inventory_system/.env' >&2
  exit 1
fi
for forbidden in \
  'inventory_system/backend/storage/logs/app.log' \
  'inventory_system/backend/storage/sessions/sess_' \
  'inventory_system/backend/storage/backups/backup_' \
  '.joblib' \
  'model_metrics.json'; do
  if grep -Fq "$forbidden" <<<"$CONTENTS"; then
    echo "Release package contains forbidden path: $forbidden" >&2
    exit 1
  fi
done
for required in \
  'inventory_system/backend/storage/logs/.gitkeep' \
  'inventory_system/backend/storage/sessions/.gitkeep' \
  'inventory_system/backend/storage/backups/.gitkeep' \
  'inventory_system/backend/legacy/demandForcasting/models/.gitkeep'; do
  if ! grep -Fxq "$required" <<<"$CONTENTS"; then
    echo "Release package is missing placeholder: $required" >&2
    exit 1
  fi
done
echo 'Release package check: passed'
