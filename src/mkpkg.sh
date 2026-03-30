#!/bin/bash
#
# Build script for Uptime Kuma Unraid Plugin
#
# Usage: Run on an Unraid server or any Linux system with Slackware 'makepkg'.
#   cd /path/to/UptimeKumaPlugin/src
#   bash mkpkg.sh
#
# Output: ../pkg/uptime-kuma-<version>-x86_64-1.txz

set -e

PLUGIN="uptime-kuma"
VERSION=$(date +%Y.%m.%d)
ARCH="x86_64"
BUILD="1"
PKG_NAME="${PLUGIN}-${VERSION}-${ARCH}-${BUILD}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PKG_DIR="${SCRIPT_DIR}/../pkg"
SRC_DIR="${SCRIPT_DIR}/${PLUGIN}"
TMPDIR=$(mktemp -d)

echo "Building ${PKG_NAME}.txz ..."

# Create directory structure in temp build area
mkdir -p "${TMPDIR}/usr/local/emhttp/plugins/${PLUGIN}"

# Copy plugin files
cp -r "${SRC_DIR}/usr/local/emhttp/plugins/${PLUGIN}/"* \
    "${TMPDIR}/usr/local/emhttp/plugins/${PLUGIN}/"

# Create output directory
mkdir -p "${PKG_DIR}"

# Build the Slackware package
cd "${TMPDIR}"
makepkg -l y -c n "${PKG_DIR}/${PKG_NAME}.txz"

# Generate MD5 checksum
cd "${PKG_DIR}"
md5sum "${PKG_NAME}.txz" > "${PKG_NAME}.txz.md5"

# Cleanup
rm -rf "${TMPDIR}"

echo ""
echo "Package built successfully:"
echo "  ${PKG_DIR}/${PKG_NAME}.txz"
echo "  ${PKG_DIR}/${PKG_NAME}.txz.md5"
echo ""
echo "Next steps:"
echo "  1. Update the version in uptime-kuma.plg to match: ${VERSION}"
echo "  2. Commit and push to GitHub"
echo "  3. Install via Unraid Plugins tab using the .plg URL"
