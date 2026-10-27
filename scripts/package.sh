#!/usr/bin/env bash
#
# Build the distributable module archive.
#
# Ships only what a client practice runs. Development tooling, tests, internal
# status notes and vendor/ are deliberately excluded — nothing in the runtime
# path depends on them.
#
#   ./scripts/package.sh [output-dir]
#
# license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3

set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE_NAME="oe-module-logicoy-erx"
OUT_DIR="${1:-$MODULE_DIR/dist}"

# Everything a client practice needs at runtime, and nothing else.
SHIP=(
    openemr.bootstrap.php
    ModuleManagerListener.php
    moduleConfig.php
    templates
    info.txt
    version.php
    composer.json
    LICENSE
    src
    public
    sql
    scripts/autoload.php
    scripts/background_service.php
    scripts/cli_bootstrap.php
    scripts/load_credential.php
    scripts/queue_worker.php
    scripts/backfill_prescriptions.php
)

# Check completeness before anything else, so a missing file reports cleanly
# rather than surfacing as a PHP warning from the version read below.
for item in "${SHIP[@]}"; do
    if [ ! -e "$MODULE_DIR/$item" ]; then
        echo "Refusing to package: missing $item" >&2
        exit 1
    fi
done
for doc in docs/INSTALL.md docs/COMPATIBILITY.md; do
    if [ ! -e "$MODULE_DIR/$doc" ]; then
        echo "Refusing to package: missing $doc" >&2
        exit 1
    fi
done

# Read the version the same way OpenEMR does, so the archive name always
# matches what Manage Modules will display.
VERSION=$(php -r '
    include "'"$MODULE_DIR"'/version.php";
    echo $v_major . "." . $v_minor . "." . $v_patch;
')

STAGE="$OUT_DIR/$MODULE_NAME"
ARCHIVE="$OUT_DIR/${MODULE_NAME}-${VERSION}.tar.gz"

rm -rf "$STAGE" "$ARCHIVE"
mkdir -p "$STAGE"

for item in "${SHIP[@]}"; do
    mkdir -p "$STAGE/$(dirname "$item")"
    cp -R "$MODULE_DIR/$item" "$STAGE/$item"
done

mkdir -p "$STAGE/docs"
cp "$MODULE_DIR/docs/INSTALL.md" "$STAGE/docs/INSTALL.md"
cp "$MODULE_DIR/docs/COMPATIBILITY.md" "$STAGE/docs/COMPATIBILITY.md"

# A credential or key in the archive would be shipped to every client.
#
# Test for the PEM header *followed by a real base64 body*, not just the header
# string — the credentials screen legitimately shows "-----BEGIN PRIVATE KEY-----"
# as a textarea placeholder, and matching on the header alone blocks releases
# over a UI hint.
leaked=$(grep -rlIE '^[A-Za-z0-9+/]{60,}={0,2}$' "$STAGE" 2>/dev/null || true)
for candidate in $leaked; do
    if grep -qE 'BEGIN [A-Z ]*PRIVATE KEY' "$candidate"; then
        echo "Refusing to package: $candidate looks like it contains a private key" >&2
        exit 1
    fi
done
if [ -e "$STAGE/vendor" ] || [ -e "$STAGE/tests" ] || [ -e "$STAGE/.env" ]; then
    echo "Refusing to package: development files leaked into the archive" >&2
    exit 1
fi

# macOS tar embeds extended attributes (com.apple.provenance, AppleDouble ._
# files). GNU and busybox tar on the client's server warn loudly about every one
# of them on extraction, which reads like a failed install. Strip them.
TAR_FLAGS=()
if tar --no-mac-metadata --version >/dev/null 2>&1; then
    TAR_FLAGS+=(--no-mac-metadata --no-xattrs)
fi

COPYFILE_DISABLE=1 tar "${TAR_FLAGS[@]}" -czf "$ARCHIVE" -C "$OUT_DIR" "$MODULE_NAME"
rm -rf "$STAGE"

echo "Built $ARCHIVE"
echo
echo "Contents:"
tar -tzf "$ARCHIVE" | sed 's/^/  /'
echo
echo "Size: $(du -h "$ARCHIVE" | cut -f1)"
