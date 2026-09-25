#!/usr/bin/env bash
set -euo pipefail

# install.sh
#
# Fetches a runtime-only copy of source-folio-records (bin/, src/,
# mapping/, composer files, README, update.sh — no tests/, no sample
# .tsv fixtures, no git history) into target-dir, then runs
# `composer install --no-dev` there. Lets you use bin/build-inventory
# and bin/load-inventory anywhere without manually cloning
# https://github.com/marnold-ebsco/create_source_folio_records every time.
#
# If target-dir already exists, this updates it in place instead of
# doing a fresh install: bin/, src/, mapping/, composer.json/lock,
# README.md, and update.sh are replaced wholesale with the latest
# versions (so a file removed or renamed upstream doesn't linger), then
# `composer install --no-dev` is re-run to match. tenant.ini, output/,
# and logs/ are never touched either way. If target-dir has no
# tenant.ini yet (a fresh install, or an update where one was never
# created), tenant.ini.example is installed as tenant.ini, ready to
# fill in with real values — an existing tenant.ini is never replaced.
#
# Usage:
#   bash install.sh [target-dir]
#
# target-dir defaults to ./create_source_folio_records if omitted. Once
# installed, update.sh (copied into target-dir) can update it in place
# from inside that directory — see update.sh's own docblock — instead of
# re-running this command with target-dir spelled out again.

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
cp "$TMP_CLONE"/update.sh "$TARGET_DIR"/
chmod +x "$TARGET_DIR"/update.sh

if [ ! -e "$TARGET_DIR"/tenant.ini ]; then
    cp "$TMP_CLONE"/tenant.ini.example "$TARGET_DIR"/tenant.ini
    NEW_TENANT_INI=1
else
    NEW_TENANT_INI=0
fi

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
echo "  php $TARGET_DIR/bin/test-connection --help"
if [ "$NEW_TENANT_INI" -eq 1 ]; then
    echo ""
    echo "A blank $TARGET_DIR/tenant.ini was installed - fill in your tenant's real"
    echo "okapiUrl/tenant_id/username/password before using --config with either script."
fi
