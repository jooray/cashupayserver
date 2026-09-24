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
# To upgrade a library: edit scripts/vendor-js/package.json, run
# `npm install --package-lock-only` there, rebuild, and review the diff.
set -euo pipefail

# Exact versions, including every transitive dependency, are pinned with integrity
# hashes in scripts/vendor-js/package-lock.json; `npm ci` refuses anything else.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/assets/js/vendor"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cp "$ROOT/scripts/vendor-js/package.json" "$ROOT/scripts/vendor-js/package-lock.json" "$WORK/"
cd "$WORK"
npm ci --silent --no-audit --no-fund
version() { node -p "require('./node_modules/$1/package.json').version"; }
QRCODE_VERSION=$(version qrcode-generator)
BCUR_VERSION=$(version @gandlaf21/bc-ur)
BUFFER_VERSION=$(version buffer)

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

# Every drawn code must decode back to exactly its input.
cp "$ROOT/scripts/vendor-js/qr-roundtrip.test.js" .
node qr-roundtrip.test.js "$ROOT"

echo "Built into $OUT"
