#!/usr/bin/env bash
#
# Builds a clean WordPress.org-ready zip in build/licensekit-<version>.zip.
#
# Mirrors the exclusions in .distignore and .github/workflows/release.yml.
# Run from the plugin root:  bash bin/build-zip.sh
#
set -euo pipefail

PLUGIN_SLUG="licensekit"
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_ROOT}"

VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${PLUGIN_SLUG}.php" | head -1 | awk '{print $NF}')"
if [[ -z "${VERSION}" ]]; then
	echo "Could not determine plugin version from ${PLUGIN_SLUG}.php" >&2
	exit 1
fi

STAGE_DIR="${PLUGIN_ROOT}/build/${PLUGIN_SLUG}"
ZIP_PATH="${PLUGIN_ROOT}/build/${PLUGIN_SLUG}-${VERSION}.zip"

rm -rf "${PLUGIN_ROOT}/build"
mkdir -p "${STAGE_DIR}"

if command -v composer >/dev/null 2>&1; then
	composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader
fi

rsync -a \
	--exclude='/.git' \
	--exclude='/.github' \
	--exclude='/.gitignore' \
	--exclude='/.distignore' \
	--exclude='.DS_Store' \
	--exclude='Thumbs.db' \
	--exclude='/.idea' \
	--exclude='/.vscode' \
	--exclude='/.claude' \
	--exclude='/composer.json' \
	--exclude='/composer.lock' \
	--exclude='/.phpunit.cache' \
	--exclude='/.phpunit.result.cache' \
	--exclude='/build' \
	--exclude='/dist' \
	--exclude='/coverage' \
	--exclude='/tests' \
	--exclude='/docs' \
	--exclude='/phpunit.xml.dist' \
	--exclude='/phpcs.xml.dist' \
	--exclude='/client-sdk' \
	--exclude='/CONTRIBUTING.md' \
	--exclude='/SECURITY.md' \
	--exclude='/CHANGELOG.md' \
	--exclude='/README.md' \
	--exclude='/_debug*' \
	--exclude='/.env' \
	--exclude='/.env.*' \
	--exclude='/bin' \
	./ "${STAGE_DIR}/"

cd "${PLUGIN_ROOT}/build"
rm -f "${ZIP_PATH}"
zip -rq "${ZIP_PATH}" "${PLUGIN_SLUG}"

echo "Built: ${ZIP_PATH}"
