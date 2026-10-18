#!/usr/bin/env bash
#
# Baut die installierbare Plugin-ZIP aus dem letzten Commit.
# Ergebnis: local-seo-entity-plugin-<Version>.zip im Repo-Ordner (von Git ignoriert).
#
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(grep -m1 -E '^\s*\*\s*Version:' local-seo-entity-plugin.php | awk '{print $NF}')
SLUG=local-seo-entity-plugin
BUILD="build/${SLUG}"

rm -rf build
mkdir -p "$BUILD"

# Dateien aus dem letzten Commit; .gitattributes (export-ignore) hält Tests und Doku heraus.
git archive HEAD | tar -x -C "$BUILD"

# Während des Machbarkeitstests wird das Prüfskript mitgeliefert.
mkdir -p "$BUILD/bin"
cp bin/machbarkeitstest.sh "$BUILD/bin/"

( cd "$BUILD" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )

rm -f "${SLUG}-${VERSION}.zip"
( cd build && zip -qr "../${SLUG}-${VERSION}.zip" "$SLUG" )

echo "Fertig: ${SLUG}-${VERSION}.zip"
