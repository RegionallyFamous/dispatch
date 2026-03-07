#!/usr/bin/env bash
# Builds a distributable release zip for Dispatch for Telex.
# Produces dispatch-for-telex.zip in the repo root.
# Usage: bash bin/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="dispatch-for-telex"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${REPO_ROOT}/build"
TMP_DIR="${REPO_ROOT}/.tmp-zip"
ZIP_FILE="${REPO_ROOT}/${PLUGIN_SLUG}.zip"

echo "→ Building production assets..."
cd "${REPO_ROOT}"
npm run build:production

echo "→ Installing Composer dependencies (no-dev)..."
composer install --no-dev --optimize-autoloader --quiet

echo "→ Assembling release package..."
rm -rf "${TMP_DIR}"
mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

rsync -a \
  --exclude=".git" \
  --exclude=".github" \
  --exclude=".gitignore" \
  --exclude=".DS_Store" \
  --exclude=".env" \
  --exclude=".idea" \
  --exclude=".vscode" \
  --exclude=".phpunit.cache" \
  --exclude="coverage" \
  --exclude="node_modules" \
  --exclude="src" \
  --exclude="tests" \
  --exclude="bin" \
  --exclude="docs" \
  --exclude=".tmp-zip" \
  --exclude="*.zip" \
  --exclude="*.log" \
  --exclude="Makefile" \
  --exclude="package.json" \
  --exclude="package-lock.json" \
  --exclude="composer.json" \
  --exclude="composer.lock" \
  --exclude="phpcs.xml.dist" \
  --exclude="phpunit.xml.dist" \
  --exclude="vendor/bin" \
  --exclude="vendor/phpunit" \
  --exclude="vendor/squizlabs" \
  --exclude="vendor/yoast" \
  --exclude="vendor/dealerdirect" \
  --exclude="vendor/phpcsstandards" \
  --exclude="vendor/wp-coding-standards" \
  "${REPO_ROOT}/" "${TMP_DIR}/${PLUGIN_SLUG}/"

echo "→ Creating ${PLUGIN_SLUG}.zip..."
cd "${TMP_DIR}"
zip -r "${ZIP_FILE}" "${PLUGIN_SLUG}" --quiet

echo "→ Cleaning up..."
rm -rf "${TMP_DIR}"

echo "→ Restoring Composer dependencies (with dev)..."
composer install --quiet

echo ""
echo "✓ Release zip created: ${ZIP_FILE}"
echo "  Size: $(du -sh "${ZIP_FILE}" | cut -f1)"
