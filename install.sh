#!/usr/bin/env bash
set -euo pipefail

# install.sh
#
# Fetches a runtime-only copy of source-folio-records (bin/, src/,
# mapping/, composer files, README — no tests/, no sample .tsv fixtures,
# no git history) into a fresh directory, then runs
# `composer install --no-dev` there. Lets you use bin/build-inventory
# and bin/load-inventory anywhere without manually cloning
# https://github.com/marnold-ebsco/create_source_folio_records every time.
#
# Usage:
#   bash install.sh [target-dir]
#
# target-dir defaults to ./create_source_folio_records if omitted, and
# must not already exist.

REPO_SSH_URL="git@github.com:marnold-ebsco/create_source_folio_records.git"
TARGET_DIR="${1:-./create_source_folio_records}"

if [ -e "$TARGET_DIR" ]; then
    echo "Error: '$TARGET_DIR' already exists." >&2
    exit 1
fi

TMP_CLONE="$(mktemp -d)"
trap 'rm -rf "$TMP_CLONE"' EXIT

echo "Fetching latest source-folio-records..."
git clone --depth 1 --quiet "$REPO_SSH_URL" "$TMP_CLONE"

mkdir -p "$TARGET_DIR"
cp -r "$TMP_CLONE"/bin "$TARGET_DIR"/
cp -r "$TMP_CLONE"/src "$TARGET_DIR"/
cp -r "$TMP_CLONE"/mapping "$TARGET_DIR"/
cp "$TMP_CLONE"/composer.json "$TARGET_DIR"/
cp "$TMP_CLONE"/composer.lock "$TARGET_DIR"/
cp "$TMP_CLONE"/README.md "$TARGET_DIR"/

echo "Installing dependencies..."
(cd "$TARGET_DIR" && composer install --no-dev --quiet)
chmod +x "$TARGET_DIR"/bin/*

echo ""
echo "Done. $TARGET_DIR is ready (no git history, no tests, no dev dependencies):"
echo "  php $TARGET_DIR/bin/build-inventory --help"
echo "  php $TARGET_DIR/bin/load-inventory --help"
