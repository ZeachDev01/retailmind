#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
php src/backend/scripts/build_styles.php >&2

release=.release
rm -rf "$release"
mkdir -p "$release/src/backend" "$release/src/frontend"

cp index.php .htaccess .user.ini "$release/"
cp src/.htaccess "$release/src/"
cp -a src/frontend/. "$release/src/frontend/"

for directory in app bootstrap config database includes; do
  mkdir -p "$release/src/backend/$directory"
  cp -a "src/backend/$directory/." "$release/src/backend/$directory/"
done
mkdir -p "$release/src/backend/legacy/routes"
cp -a src/backend/legacy/routes/. "$release/src/backend/legacy/routes/"

# The bootstrap resolves Composer dependencies from the project root.
test -f vendor/autoload.php || { echo 'Run composer install before building the release.' >&2; exit 1; }
mkdir -p "$release/vendor"
cp -a vendor/. "$release/vendor/"

# InfinityFree silently removes files above its per-file limits.
while IFS= read -r -d '' file; do
  case "$file" in
    *.php|*.html|*.htm|*.js) limit=1048576 ;;
    */.htaccess) limit=10240 ;;
    *) limit=10485760 ;;
  esac
  size=$(stat -c %s "$file")
  if (( size > limit )); then
    echo "File exceeds InfinityFree limit: $file ($size bytes > $limit bytes)" >&2
    exit 1
  fi
done < <(find "$release" -type f -print0)

test -f "$release/index.php"
test -f "$release/src/backend/bootstrap/app.php"
test -f "$release/vendor/autoload.php"
echo 'InfinityFree release ready.'
