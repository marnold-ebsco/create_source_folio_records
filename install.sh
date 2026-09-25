#!/usr/bin/env bash
set -euo pipefail

# install.sh
#
# Fetches a runtime-only copy of source-folio-records (bin/, src/,
# mapping/, composer files, README — no tests/, no sample .tsv fixtures,
# no git history) into target-dir, then runs `composer install --no-dev`
# there. Lets you use bin/build-inventory and bin/load-inventory
# anywhere without manually cloning
# https://github.com/marnold-ebsco/create_source_folio_records every time.
#
# If target-dir already exists, this updates it in place instead of
# doing a fresh install: bin/, src/, mapping/, composer.json/lock, and
# README.md are replaced wholesale with the latest versions (so a
# file removed or renamed upstream doesn't linger), then
# `composer install --no-dev` is re-run to match. tenant.ini, output/,
# and logs/ are never touched either way.
#
# Usage:
#   bash install.sh [target-dir]
#
# target-dir defaults to ./create_source_folio_records if omitted.

REPO_SSH_URL="git@github.com:marnold-ebsco/create_source_folio_records.git"
TARGET_DIR="${1:-./create_source_folio_records}"

if [ -e "$TARGET_DIR" ] && [ ! -d "$TARGET_DIR" ]; then
    echo "Error: '$TARGET_DIR' exists and is not a directory." >&2
    exit 1
fi

if [ -d "$TARGET_DIR" ]; then
    MODE="update"
else
    MODE="install"
fi

TMP_CLONE="$(mktemp -d)"
trap 'rm -rf "$TMP_CLONE"' EXIT

echo "Fetching latest source-folio-records..."
git clone --depth 1 --quiet "$REPO_SSH_URL" "$TMP_CLONE"

if [ "$MODE" = "update" ]; then
    echo "Updating existing install at '$TARGET_DIR' (tenant.ini, output/, logs/ left untouched)..."
    rm -rf "$TARGET_DIR"/bin "$TARGET_DIR"/src "$TARGET_DIR"/mapping
else
    mkdir -p "$TARGET_DIR"
fi

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
if [ "$MODE" = "update" ]; then
    echo "Done. '$TARGET_DIR' is updated to the latest version."
else
    echo "Done. '$TARGET_DIR' is ready (no git history, no tests, no dev dependencies):"
fi
echo "  php $TARGET_DIR/bin/build-inventory --help"
echo "  php $TARGET_DIR/bin/load-inventory --help"
