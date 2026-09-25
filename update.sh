#!/usr/bin/env bash
set -euo pipefail

# update.sh
#
# Updates an existing no-clone install (see install.sh's Option 1 in
# README.md) in place, from *inside* that install — no need to remember
# or type its path as an argument the way `install.sh target-dir` does.
# install.sh copies this file into every install it creates, so it's
# already sitting right there alongside bin/, src/, mapping/.
#
# Usage (from inside the install directory, or anywhere - it finds its
# own location either way):
#   bash update.sh
#
# This fetches the latest install.sh from GitHub and runs it against this
# script's own directory as target-dir, which install.sh detects as an
# existing directory and updates in place: bin/, src/, mapping/, the
# composer files, README.md, and this script itself are replaced with the
# latest versions; tenant.ini, output/, and logs/ are left untouched.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TMP_INSTALL_SH="$(mktemp)"
trap 'rm -f "$TMP_INSTALL_SH"' EXIT

curl -fsSL https://raw.githubusercontent.com/marnold-ebsco/create_source_folio_records/main/install.sh -o "$TMP_INSTALL_SH"
bash "$TMP_INSTALL_SH" "$SCRIPT_DIR"
