#!/bin/bash
# Build CashuPay WordPress plugin zip

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupay"
rm -rf build/cashupay build/cashupay-wordpress.zip

mkdir -p "$BUILD_DIR"

# Rebuild the mint-discovery bundle from its lockfile. `npm ci` installs exactly what
# package-lock.json pins (and fails instead of silently re-resolving), and the result goes
# into the build tree only: the committed assets/js/mint-discovery.bundle.js is never
# overwritten, so the zip never carries an unreviewed bundle by accident.
build_mint_discovery() {
    local dest="$1"
    if [ -d "mint-discovery" ] && [ -f "mint-discovery/package-lock.json" ]; then
        (cd mint-discovery && npm ci --silent && npm run build --silent)
        cp mint-discovery/dist/mint-discovery.bundle.js "$dest"
        if ! cmp -s mint-discovery/dist/mint-discovery.bundle.js assets/js/mint-discovery.bundle.js; then
            echo "Warning: the rebuilt mint-discovery bundle differs from the committed" >&2
            echo "         assets/js/mint-discovery.bundle.js. Review and commit it before releasing." >&2
        fi
    fi
}

# Zip with fixed timestamps and file order, so two builds of the same commit produce the
# same bytes, then record the SHA-256 in build/SHA256SUMS for the release notes.
make_zip() {
    local dir="$1" zipname="$2"
    local epoch="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || date +%s)}"
    local stamp
    stamp="$(php -r 'echo gmdate("YmdHi.s", (int) $argv[1]);' "$epoch")"
    (
        cd build
        rm -f "$zipname"
        find "$dir" -exec env TZ=UTC touch -h -t "$stamp" {} +
        find "$dir" -print | LC_ALL=C sort | TZ=UTC zip -X -q "$zipname" -@
    )
    local sum
    if command -v sha256sum >/dev/null 2>&1; then
        sum="$(cd build && sha256sum "$zipname")"
    else
        sum="$(cd build && shasum -a 256 "$zipname")"
    fi
    touch build/SHA256SUMS
    grep -v "  $zipname\$" build/SHA256SUMS > build/SHA256SUMS.tmp || true
    echo "$sum" >> build/SHA256SUMS.tmp
    LC_ALL=C sort -k2 build/SHA256SUMS.tmp > build/SHA256SUMS
    rm -f build/SHA256SUMS.tmp
    echo "SHA-256: $sum"
}

# Flatten the WordPress support files into the plugin root, matching the layout
# docker/Dockerfile.wordpress builds. cashupay.php requires bootstrap.php, activation.php,
# rewrite-rules.php and admin-menu.php as siblings, and bootstrap.php defines its own
# directory as CASHUPAY_PLUGIN_DIR — so a nested copy cannot load either way.
cp wordpress/*.php "$BUILD_DIR/"

# Copy shared core. RELEASE.md promises no local configuration ships; enforce it here
# rather than relying on the build tree being clean.
cp -r includes/ "$BUILD_DIR/includes/"
rm -f "$BUILD_DIR/includes/config.local.php" "$BUILD_DIR/includes/data-location.txt"
find "$BUILD_DIR/includes" -name '*.local.php' -delete
cp admin.php setup.php api.php payment.php receive.php cron.php "$BUILD_DIR/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"

# Copy assets
cp -r assets/ "$BUILD_DIR/assets/"

# Copy favicon and images
cp favicon.ico "$BUILD_DIR/"
# MIT licence text; the vendored JS libraries carry their own headers (assets/js/vendor/).
cp LICENSE "$BUILD_DIR/"
cp -r images/ "$BUILD_DIR/images/"

# Copy cashu-wallet-php (excluding .git, tests, examples)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"
cp cashu-wallet-php/bip39-english.txt "$BUILD_DIR/cashu-wallet-php/"

# Build the mint-discovery bundle into the plugin's assets
build_mint_discovery "$BUILD_DIR/assets/js/"

# Fail the build rather than shipping an artifact that cannot load.
for required in cashupay.php uninstall.php bootstrap.php activation.php rewrite-rules.php \
                admin-menu.php btcpay-integration.php btcpay-dependency.php includes/database.php; do
    if [ ! -f "$BUILD_DIR/$required" ]; then
        echo "Build error: missing $required in plugin root" >&2
        exit 1
    fi
done

# Create zip
make_zip cashupay cashupay-wordpress.zip

echo "WordPress plugin built: build/cashupay-wordpress.zip"
