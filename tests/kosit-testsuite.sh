#!/usr/bin/env bash
#
# Runs the e-invoice reader tests against the official KoSIT XRechnung
# testsuite (all sample invoices, CII and UBL). The bundle is downloaded once
# from projekte.kosit.org and checked against its SHA-256.
#
# Usage (from the module root):  bash tests/kosit-testsuite.sh
# Optional: KOSIT_CACHE_DIR  download cache (default ~/.cache/kosit)
#
set -euo pipefail

BUNDLE_NAME="xrechnung-3.0.2-bundle-2026-08-31"
BUNDLE_URL="https://projekte.kosit.org/api/v4/projects/356/packages/maven/de/xeinkauf/xrechnung-3.0.2-bundle/2026-08-31/${BUNDLE_NAME}.zip"
BUNDLE_SHA256="5f5426741a03e0b9abc7de83acd97309c09498241d132fc035f9c830be7203de"
TESTSUITE_ZIP="xrechnung-3.0.2-testsuite-2026-08-31.zip"

CACHE_DIR="${KOSIT_CACHE_DIR:-$HOME/.cache/kosit}"
MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUNDLE_DIR="$CACHE_DIR/$BUNDLE_NAME"
SUITE_DIR="$CACHE_DIR/$BUNDLE_NAME-testsuite"

unzip_to() { python3 -I -c 'import sys, zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])' "$1" "$2"; }

if [ ! -f "$SUITE_DIR/.ready" ]; then
	mkdir -p "$CACHE_DIR"
	if [ ! -f "$BUNDLE_DIR/$TESTSUITE_ZIP" ]; then
		echo "Lade KoSIT-Bundle $BUNDLE_NAME"
		curl -fsSL --retry 3 -o "$CACHE_DIR/$BUNDLE_NAME.zip" "$BUNDLE_URL"
		echo "$BUNDLE_SHA256  $CACHE_DIR/$BUNDLE_NAME.zip" | sha256sum -c -
		rm -rf "$BUNDLE_DIR"
		mkdir -p "$BUNDLE_DIR"
		unzip_to "$CACHE_DIR/$BUNDLE_NAME.zip" "$BUNDLE_DIR"
	fi
	rm -rf "$SUITE_DIR"
	mkdir -p "$SUITE_DIR"
	unzip_to "$BUNDLE_DIR/$TESTSUITE_ZIP" "$SUITE_DIR"
	touch "$SUITE_DIR/.ready"
fi

php "$MODULE_DIR/tests/einvoice-tests.php" "$SUITE_DIR/instances"
