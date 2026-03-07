#!/usr/bin/env bash
# Builds a distributable release zip for Dispatch for Telex.
# Produces dispatch-for-telex-<version>.zip in the repo root.
# Usage: bash bin/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="dispatch-for-telex"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(grep -m1 'Version:' "${REPO_ROOT}/telex.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"

# Guard against malformed version strings that could corrupt the zip filename
# or the rsync/zip commands (e.g. path injection via a crafted telex.php).
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?(-[a-zA-Z0-9._-]+)?$ ]]; then
  echo "ERROR: VERSION '${VERSION}' does not look like a valid semver tag." >&2
  exit 1
fi
TMP_DIR="${REPO_ROOT}/.tmp-zip"
ZIP_FILE="${REPO_ROOT}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "→ Building production assets..."
cd "${REPO_ROOT}"
npm run build:production

echo "→ Installing Composer dependencies (no-dev)..."
composer install --no-dev --optimize-autoloader --quiet

echo "→ Assembling release package (${VERSION})..."
rm -rf "${TMP_DIR}"
mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

rsync -r \
  --exclude-from="${REPO_ROOT}/.distignore" \
  "${REPO_ROOT}/" "${TMP_DIR}/${PLUGIN_SLUG}/"

echo "→ Creating ${PLUGIN_SLUG}-${VERSION}.zip..."
cd "${TMP_DIR}"
zip -r "${ZIP_FILE}" "${PLUGIN_SLUG}" --quiet

echo "→ Cleaning up..."
cd "${REPO_ROOT}"
rm -rf "${TMP_DIR}"

echo "→ Restoring Composer dev dependencies..."
composer install --quiet

echo ""
echo "✓ Release zip created: ${ZIP_FILE}"
echo "  Size: $(du -sh "${ZIP_FILE}" | cut -f1)"
