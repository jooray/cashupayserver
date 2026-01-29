#!/bin/bash
# Build standalone CashuPayServer distribution zip

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupayserver"
rm -rf build/cashupayserver build/cashupayserver.zip

mkdir -p "$BUILD_DIR"

# Build mint-discovery bundle first
if [ -d "mint-discovery" ]; then
    cd mint-discovery && npm install --silent && npm run build --silent && cd ..
    cp mint-discovery/dist/mint-discovery.bundle.js assets/js/
fi

# Copy core files
cp -r includes/ "$BUILD_DIR/includes/"
cp -r assets/ "$BUILD_DIR/assets/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"
cp admin.php setup.php api.php payment.php receive.php cron.php router.php index.php "$BUILD_DIR/"
cp .htaccess manifest.json favicon.ico "$BUILD_DIR/"
cp -r images/ "$BUILD_DIR/images/"

# Copy cashu-wallet-php (clean, no .git)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"
cp cashu-wallet-php/bip39-english.txt "$BUILD_DIR/cashu-wallet-php/"

# Create data directory with protection
mkdir -p "$BUILD_DIR/data"
echo 'deny from all' > "$BUILD_DIR/data/.htaccess"
echo '<!DOCTYPE html><html><body></body></html>' > "$BUILD_DIR/data/index.html"

# Create zip
cd build && zip -r cashupayserver.zip cashupayserver/ && cd ..

echo "Standalone build: build/cashupayserver.zip"
