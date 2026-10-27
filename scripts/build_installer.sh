#!/usr/bin/env bash
#
# Build the single-file installer a client practice runs on their OpenEMR
# server.
#
# The installer is scripts/installer_header.sh with the release archive from
# package.sh appended to it as base64, so what ships is one text file that
# carries the whole module.
#
#   ./scripts/build_installer.sh [output-dir]
#
# license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3

set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE_NAME="oe-module-logicoy-erx"
OUT_DIR="${1:-$MODULE_DIR/dist}"
HEADER="$MODULE_DIR/scripts/installer_header.sh"

[ -f "$HEADER" ] || { echo "Missing $HEADER" >&2; exit 1; }

VERSION=$(php -r '
    include "'"$MODULE_DIR"'/version.php";
    echo $v_major . "." . $v_minor . "." . $v_patch;
')

# Always repackage rather than reusing whatever is sitting in dist/ — an
# installer built around a stale archive is indistinguishable from a good one.
BUILD_DIR=$(mktemp -d "${TMPDIR:-/tmp}/logicoy-installer.XXXXXX")
trap 'rm -rf "$BUILD_DIR"' EXIT

"$MODULE_DIR/scripts/package.sh" "$BUILD_DIR" > "$BUILD_DIR/package.log" || {
    cat "$BUILD_DIR/package.log" >&2
    echo "Refusing to build the installer: packaging failed" >&2
    exit 1
}

ARCHIVE="$BUILD_DIR/${MODULE_NAME}-${VERSION}.tar.gz"
[ -f "$ARCHIVE" ] || { echo "package.sh did not produce $ARCHIVE" >&2; exit 1; }

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}
CHECKSUM=$(sha256 "$ARCHIVE")

mkdir -p "$OUT_DIR"
INSTALLER="$OUT_DIR/install-${MODULE_NAME}-${VERSION}.sh"

sed -e "s/@@MODULE_VERSION@@/$VERSION/g" \
    -e "s/@@PAYLOAD_SHA256@@/$CHECKSUM/g" \
    "$HEADER" > "$INSTALLER"

# Encoded from stdin and folded here rather than with base64's own wrap flag,
# which is spelled --wrap on GNU and -b on BSD. Wrapped at 76 columns so the
# file stays ordinary text and survives a mail gateway that reflows long lines.
base64 < "$ARCHIVE" | tr -d '\n' | fold -w 76 >> "$INSTALLER"
printf '\n' >> "$INSTALLER"

chmod 755 "$INSTALLER"

# A truncated or mis-encoded payload only shows up on the client's server, so
# prove here that the installer can unpack itself.
VERIFY_DIR="$BUILD_DIR/verify"
sh "$INSTALLER" --extract-only="$VERIFY_DIR" >/dev/null
[ -f "$VERIFY_DIR/$MODULE_NAME/openemr.bootstrap.php" ] \
    || { echo "Self-extraction check failed" >&2; exit 1; }
sh "$INSTALLER" --version >/dev/null

echo "Built $INSTALLER"
echo
echo "  version   $VERSION"
echo "  payload   $CHECKSUM"
echo "  size      $(( $(wc -c < "$INSTALLER") / 1024 )) KB"
echo "  extracts  $(find "$VERIFY_DIR/$MODULE_NAME" -type f | wc -l | tr -d ' ') files"
