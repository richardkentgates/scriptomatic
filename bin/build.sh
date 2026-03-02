#!/usr/bin/env bash
set -e

# ---------------------------------------------------------------------------
# build.sh — Create a clean distribution ZIP for WordPress.org / Freemius
#
# Usage:
#   bin/build.sh [version]
#
# Examples:
#   bin/build.sh          # uses version from scriptomatic.php header
#   bin/build.sh 3.0.1    # overrides with a specific version
#
# Output:  dist/scriptomatic.zip
#          (internal folder is always named "scriptomatic" to match Text Domain)
# ---------------------------------------------------------------------------

PLUGIN_SLUG="scriptomatic"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$DIST_DIR/$PLUGIN_SLUG"
DISTIGNORE="$ROOT_DIR/.distignore"

# Determine version
if [[ -n "$1" ]]; then
    VERSION="$1"
else
    VERSION=$(grep -m1 "^ \* Version:" "$ROOT_DIR/scriptomatic.php" | sed "s/.*Version: *//")
fi

ZIP_NAME="scriptomatic.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

echo "Building $PLUGIN_SLUG v$VERSION → $ZIP_PATH"

# Clean and recreate build dir
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Copy files, honouring .distignore exclusions
rsync -a --exclude-from="$DISTIGNORE" \
    --exclude="dist/" \
    "$ROOT_DIR/" "$BUILD_DIR/"

# Stamp the version in the copies (safety check)
echo "  Copied $(find "$BUILD_DIR" -type f | wc -l) files"

# Create the ZIP with the internal folder named "scriptomatic"
rm -f "$ZIP_PATH"
cd "$DIST_DIR"
zip -rq "$ZIP_NAME" "$PLUGIN_SLUG/"

echo "✓ Built: $ZIP_PATH"
echo "  Internal folder : $PLUGIN_SLUG/"
echo "  Size            : $(du -sh "$ZIP_PATH" | cut -f1)"
