#!/bin/bash
# Build standalone BareBits distribution zip

set -e

cd "$(dirname "$0")/.."

# The application tree lives under app/ in the repository (so the GitHub
# landing page shows the README instead of a wall of PHP files). The zip
# flattens it: build/barebits/ has the exact layout an installed server has,
# and the auto-updater overlays that layout onto the install root.
APP="app"
BUILD_DIR="build/barebits"
rm -rf build/barebits build/barebits.zip

mkdir -p "$BUILD_DIR"

# Install Composer dependencies for the on-chain Bitcoin payment support
# (bitwasp/bitcoin and friends). The lockfile pins exact versions; we use
# --ignore-platform-reqs because a transitive dep (lastguest/murmurhash) has
# a stale PHP 7 pin even though it runs fine on PHP 8.
if [ ! -f "$APP/composer.phar" ]; then
    PHP_BIN="${PHP_BIN:-php}"
    curl -sS https://getcomposer.org/installer | "$PHP_BIN" -- --quiet --install-dir="$APP" --filename=composer.phar
fi
"${PHP_BIN:-php}" "$APP/composer.phar" --working-dir="$APP" install --no-progress --no-dev --optimize-autoloader --ignore-platform-reqs

# Build mint-discovery bundle first
if [ -d "$APP/mint-discovery" ]; then
    (cd "$APP/mint-discovery" && npm install --silent && npm run build --silent)
    cp "$APP/mint-discovery/dist/mint-discovery.bundle.js" "$APP/assets/js/"
fi

# Copy core files
cp -r "$APP/includes/" "$BUILD_DIR/includes/"
cp -r "$APP/vendor/" "$BUILD_DIR/vendor/"
cp -r "$APP/assets/" "$BUILD_DIR/assets/"
cp -r "$APP/api-keys/" "$BUILD_DIR/api-keys/"
# Every top-level entry point router.php can dispatch to must be present, or a
# fresh install fatals the moment that route is hit. health.php (updater probe),
# pay.php (self-serve /pay), product-image.php and update.php (the auto-updater
# itself) were previously omitted — a from-zip install had no working updater.
# scripts/verify-plugin-build.php enforces this against router.php's requires.
for f in admin.php setup.php api.php payment.php receive.php cron.php router.php index.php \
         recover.php health.php pay.php product-image.php update.php provision.php sso.php; do
    cp "$APP/$f" "$BUILD_DIR/"
done
# Operator config template (the real user_config.php is gitignored dev-local and
# is preserved across updates by includes/updater.php's keep-list).
cp "$APP/user_config.example.php" "$BUILD_DIR/"
# License terms travel with the artifact (the wordpress/ GPL carve-out does not
# apply here — the server zip contains no wordpress/ code).
cp LICENSE.md USE_POLICY.md PRIVACY.md "$BUILD_DIR/"
cp "$APP/.htaccess" "$APP/favicon.ico" "$BUILD_DIR/"
cp -r "$APP/images/" "$BUILD_DIR/images/"

# Copy cashu-wallet-php (clean, no .git)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp "$APP/cashu-wallet-php/CashuWallet.php" "$BUILD_DIR/cashu-wallet-php/"
cp "$APP/cashu-wallet-php/bip39-english.txt" "$BUILD_DIR/cashu-wallet-php/"

# Create data directory with protection
mkdir -p "$BUILD_DIR/data"
echo 'deny from all' > "$BUILD_DIR/data/.htaccess"
echo '<!DOCTYPE html><html><body></body></html>' > "$BUILD_DIR/data/index.html"

# Write BUILD_INFO so the auto-updater can identify what's installed and
# decide whether the live .htaccess is still pristine enough to overwrite.
# CI sets COMMIT_SHA and CHANNEL via env; local builds fall back to git.
COMMIT_SHA="${COMMIT_SHA:-$(git rev-parse HEAD 2>/dev/null || echo unknown)}"
CHANNEL="${CHANNEL:-}"
BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
VERSION="$(grep -E "define\('CASHUPAY_VERSION'" "$APP/includes/config.php" | sed -E "s/.*'([^']+)'\);.*/\1/" | head -1)"
HTACCESS_SHA256="$(sha256sum "$BUILD_DIR/.htaccess" | awk '{print $1}')"
{
  echo "COMMIT_SHA=$COMMIT_SHA"
  echo "CHANNEL=$CHANNEL"
  echo "BUILT_AT=$BUILT_AT"
  echo "VERSION=$VERSION"
  echo "HTACCESS_SHA256=$HTACCESS_SHA256"
} > "$BUILD_DIR/BUILD_INFO"

# Create zip
cd build && zip -r barebits.zip barebits/ && cd ..

echo "Standalone build: build/barebits.zip"
