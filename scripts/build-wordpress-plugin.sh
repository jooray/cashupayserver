#!/bin/bash
# Build CashuPay WordPress plugin zip

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupay"
rm -rf build/cashupay build/cashupay-wordpress.zip

mkdir -p "$BUILD_DIR"

# Flatten the WordPress support files into the plugin root, matching the layout
# docker/Dockerfile.wordpress builds. cashupay.php requires bootstrap.php, activation.php,
# rewrite-rules.php and admin-menu.php as siblings, and bootstrap.php defines its own
# directory as CASHUPAY_PLUGIN_DIR — so a nested copy cannot load either way.
cp wordpress/*.php "$BUILD_DIR/"

# Copy shared core. RELEASE.md promises no local configuration ships; enforce it here
# rather than relying on the build tree being clean.
cp -r includes/ "$BUILD_DIR/includes/"
rm -f "$BUILD_DIR/includes/config.local.php"
find "$BUILD_DIR/includes" -name '*.local.php' -delete
cp admin.php setup.php api.php payment.php receive.php cron.php "$BUILD_DIR/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"

# Copy assets
cp -r assets/ "$BUILD_DIR/assets/"

# Copy favicon and images
cp favicon.ico "$BUILD_DIR/"
cp -r images/ "$BUILD_DIR/images/"

# Copy cashu-wallet-php (excluding .git, tests, examples)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"
cp cashu-wallet-php/bip39-english.txt "$BUILD_DIR/cashu-wallet-php/"

# Build and copy mint-discovery bundle
if [ -d "mint-discovery" ]; then
    cd mint-discovery && npm install --silent && npm run build --silent && cd ..
    mkdir -p "$BUILD_DIR/mint-discovery/dist"
    cp mint-discovery/dist/mint-discovery.bundle.js "$BUILD_DIR/mint-discovery/dist/"
    # Also copy the bundle to assets
    cp mint-discovery/dist/mint-discovery.bundle.js "$BUILD_DIR/assets/js/"
fi

# Fail the build rather than shipping an artifact that cannot load.
for required in cashupay.php uninstall.php bootstrap.php activation.php rewrite-rules.php \
                admin-menu.php btcpay-integration.php includes/database.php; do
    if [ ! -f "$BUILD_DIR/$required" ]; then
        echo "Build error: missing $required in plugin root" >&2
        exit 1
    fi
done

# Create zip
cd build && zip -r cashupay-wordpress.zip cashupay/ && cd ..

echo "WordPress plugin built: build/cashupay-wordpress.zip"
