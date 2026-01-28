#!/bin/bash
# Build CashuPay WordPress plugin zip

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupay"
rm -rf build/cashupay build/cashupay-wordpress.zip

mkdir -p "$BUILD_DIR"

# Copy WordPress-specific files
cp -r wordpress/ "$BUILD_DIR/wordpress/"

# Copy plugin entry point to root of plugin directory
cp "$BUILD_DIR/wordpress/cashupay.php" "$BUILD_DIR/cashupay.php"

# Copy uninstall.php to plugin root (WordPress expects it there)
cp "$BUILD_DIR/wordpress/uninstall.php" "$BUILD_DIR/uninstall.php"

# Copy shared core
cp -r includes/ "$BUILD_DIR/includes/"
cp admin.php setup.php api.php payment.php receive.php cron.php "$BUILD_DIR/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"

# Copy assets
cp -r assets/ "$BUILD_DIR/assets/"

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

# Create zip
cd build && zip -r cashupay-wordpress.zip cashupay/ && cd ..

echo "WordPress plugin built: build/cashupay-wordpress.zip"
