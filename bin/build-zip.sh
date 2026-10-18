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

# Prüfskripte werden mitgeliefert, damit sie auf der Website laufen können.
mkdir -p "$BUILD/bin"
cp bin/machbarkeitstest.sh bin/machbarkeitstest-etappe3.sh bin/mvp-pruefung.sh "$BUILD/bin/"

# Backend-Oberfläche bauen (React über @wordpress/scripts) und mitliefern.
npm ci --no-audit --no-fund --silent
npm run build --silent
mkdir -p "$BUILD/assets"
cp -r assets/dist "$BUILD/assets/dist"

( cd "$BUILD" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )

rm -f "${SLUG}-${VERSION}.zip"
( cd build && zip -qr "../${SLUG}-${VERSION}.zip" "$SLUG" )

echo "Fertig: ${SLUG}-${VERSION}.zip"
