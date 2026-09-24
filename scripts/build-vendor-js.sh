#!/usr/bin/env bash
# Rebuild the third-party browser libraries in assets/js/vendor/ from pinned npm versions.
#
# They used to load from jsDelivr and Skypack at runtime, on the same admin page that can
# show the seed phrase; a compromised CDN could have read it. Now they are files in this
# repository: readable (not minified), licensed, and reproducible with this script.
#
#   qrcode-generator  (MIT, Kazuhiko Arase)  -> vendor/qrcode-generator.js, copied as-is
#   @gandlaf21/bc-ur  (MIT)                  -> vendor/bc-ur.bundle.js, esbuild IIFE that
#                                               sets window.bcur = { UR, UREncoder }
#
# Needs node/npm. Usage: scripts/build-vendor-js.sh
set -euo pipefail

QRCODE_VERSION=2.0.4
BCUR_VERSION=1.1.12
BUFFER_VERSION=6.0.3
ESBUILD_VERSION=0.25.10

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/assets/js/vendor"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$WORK"
npm init -y >/dev/null
npm install --silent --no-audit --no-fund \
    "qrcode-generator@$QRCODE_VERSION" "@gandlaf21/bc-ur@$BCUR_VERSION" \
    "buffer@$BUFFER_VERSION" "esbuild@$ESBUILD_VERSION"

mkdir -p "$OUT"
cp node_modules/qrcode-generator/dist/qrcode.js "$OUT/qrcode-generator.js"

cat > entry.js <<'EOF'
import { UR, UREncoder } from '@gandlaf21/bc-ur';
window.bcur = { UR, UREncoder };
EOF
cat > buffer-shim.js <<'EOF'
export { Buffer } from 'buffer';
EOF
npx esbuild entry.js --bundle --format=iife --platform=browser --target=es2018 \
    --inject:./buffer-shim.js --define:global=globalThis --legal-comments=inline \
    --banner:js="/* @gandlaf21/bc-ur $BCUR_VERSION (MIT) + buffer $BUFFER_VERSION (MIT), bundled by scripts/build-vendor-js.sh */" \
    --outfile="$OUT/bc-ur.bundle.js" --log-level=warning

{
    echo "Third-party browser libraries, built by scripts/build-vendor-js.sh. Do not edit."
    echo
    echo "- qrcode-generator.js: qrcode-generator $QRCODE_VERSION (MIT, Kazuhiko Arase)"
    echo "- bc-ur.bundle.js: @gandlaf21/bc-ur $BCUR_VERSION (MIT) with buffer $BUFFER_VERSION (MIT)"
    echo
    echo "SHA-256:"
    (cd "$OUT" && shasum -a 256 qrcode-generator.js bc-ur.bundle.js)
} > "$OUT/README.txt"

echo "Built into $OUT"
