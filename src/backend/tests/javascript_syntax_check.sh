#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
mapfile -d '' FILES < <(find src -type f -name '*.js' -print0 | sort -z)
if ((${#FILES[@]} == 0)); then
  echo 'JavaScript syntax: passed (no files)'
  exit 0
fi
for file in "${FILES[@]}"; do
  node --check "$file"
done
printf 'JavaScript syntax: passed (%d files)\n' "${#FILES[@]}"
