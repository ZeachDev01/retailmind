#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
php "$ROOT/src/backend/scripts/build_styles.php" >&2
OUT="${1:-$ROOT/../inventory_system_release.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/inventory_system"
EXCLUDES=(
  '.git/' '.env' '.kilo/worktrees/' 'vendor/'
  'src/backend/storage/logs/*' 'src/backend/storage/backups/*'
  'src/backend/storage/backups/**'
  'src/backend/storage/sessions/*' 'src/backend/storage/imports/*'
  'src/backend/storage/profile-images/*' 'src/backend/storage/exports/*'
  'src/backend/storage/receipts/*'
  '**/__pycache__/'
  '*.pyc' '*.pyo' '*.joblib'
  '.release/'
)
if command -v rsync >/dev/null 2>&1; then
  RSYNC_EXCLUDES=()
  for pattern in "${EXCLUDES[@]}"; do
    RSYNC_EXCLUDES+=(--exclude="$pattern")
  done
  rsync -a "${RSYNC_EXCLUDES[@]}" "$ROOT/" "$TMP/inventory_system/"
else
  TAR_EXCLUDES=()
  for pattern in "${EXCLUDES[@]}"; do
    TAR_EXCLUDES+=(--exclude="$pattern")
  done
  tar -C "$ROOT" "${TAR_EXCLUDES[@]}" -cf - . | tar -C "$TMP/inventory_system" -xf -
fi
rm -rf "$TMP/inventory_system/.git" "$TMP/inventory_system/.kilo/worktrees" \
  "$TMP/inventory_system/vendor" "$TMP/inventory_system/.release"
rm -f "$TMP/inventory_system/.env"
find "$TMP/inventory_system" -type d -name '__pycache__' -prune -exec rm -rf {} +
find "$TMP/inventory_system" -type f \( -name '*.pyc' -o -name '*.pyo' -o -name '*.joblib' \) -delete
for directory in logs backups sessions imports exports receipts profile-images; do
  rm -rf "$TMP/inventory_system/src/backend/storage/$directory"
  mkdir -p "$TMP/inventory_system/src/backend/storage/$directory"
  touch "$TMP/inventory_system/src/backend/storage/$directory/.gitkeep"
done
rm -f "$OUT"
if command -v zip >/dev/null 2>&1; then
  (cd "$TMP" && zip -qr "$OUT" inventory_system)
else
  python - "$TMP" "$OUT" <<'PY'
from pathlib import Path
import sys
from zipfile import ZIP_DEFLATED, ZipFile

source = Path(sys.argv[1])
destination = Path(sys.argv[2])
with ZipFile(destination, "w", ZIP_DEFLATED) as archive:
    for path in sorted((source / "inventory_system").rglob("*")):
        relative = path.relative_to(source).as_posix()
        if path.is_file():
            archive.write(path, relative)
PY
fi
echo "$OUT"
