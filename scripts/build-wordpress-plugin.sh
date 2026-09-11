#!/bin/bash
# Build the BareBits WordPress plugin zip.
#
# The plugin is GPL-licensed WordPress glue ONLY: it contains no BareBits
# server code. The server is either connected by URL or downloaded by the
# plugin from GitHub releases at onboarding time, so this build is a plain
# copy of wordpress/ — no composer install, no core files, no vendor/.

set -e

cd "$(dirname "$0")/.."

# Staged under build/wp/ because build/barebits is the standalone SERVER
# build's staging dir (build-standalone.sh) — the plugin zip's top-level
# directory must itself be barebits/ (the WP plugin slug; `wp plugin install`
# derives the install folder from it).
BUILD_DIR="build/wp/barebits"
rm -rf build/wp build/barebits_wordpress_plugin.zip

mkdir -p "$BUILD_DIR"

cp wordpress/*.php wordpress/readme.txt wordpress/license.txt "$BUILD_DIR/"
cp -r wordpress/assets "$BUILD_DIR/assets"

# Channel stamp: a testing-channel plugin build must install a testing-channel
# server (GitHub's /releases/latest only ever answers with stable releases,
# which may predate features the plugin's install flow depends on). The
# release workflow exports CASHUPAY_PLUGIN_CHANNEL=testing for prerelease
# tags; anything else leaves the in-tree 'stable' untouched.
if [ "${CASHUPAY_PLUGIN_CHANNEL:-stable}" = "testing" ]; then
    sed -i.bak "s/const BAREBITS_PLUGIN_RELEASE_CHANNEL = 'stable';/const BAREBITS_PLUGIN_RELEASE_CHANNEL = 'testing';/" \
        "$BUILD_DIR/installer.php"
    rm -f "$BUILD_DIR/installer.php.bak"
    grep -q "const BAREBITS_PLUGIN_RELEASE_CHANNEL = 'testing';" "$BUILD_DIR/installer.php" \
        || { echo "ERROR: failed to stamp the testing release channel into installer.php" >&2; exit 1; }
fi

# Stamp the readme's Stable tag from the plugin header's Version so the two
# can never drift apart again (a mismatch blocks wordpress.org submission).
PLUGIN_VERSION=$(sed -n 's/^ \* Version: //p' "$BUILD_DIR/barebits.php" | head -1 | tr -d '[:space:]')
if [ -z "$PLUGIN_VERSION" ]; then
    echo "ERROR: could not read the Version header from barebits.php" >&2
    exit 1
fi
sed -i.bak "s/^Stable tag: .*/Stable tag: $PLUGIN_VERSION/" "$BUILD_DIR/readme.txt"
rm -f "$BUILD_DIR/readme.txt.bak"

# Create zip. The archive FILE is named barebits_wordpress_plugin.zip (the release asset
# name, later version-stamped by the release workflow); the top-level
# directory inside it is `barebits/` — the WordPress plugin slug.
cd build/wp && zip -r ../barebits_wordpress_plugin.zip barebits/ && cd ../..

echo "WordPress plugin built: build/barebits_wordpress_plugin.zip"

# The wordpress.org variant: identical, minus installer.php — the directory's
# guidelines forbid plugins fetching executable code, which the install-
# alongside flow exists to do. barebits.php requires installer.php only when
# present, and onboarding degrades to connect-by-URL (see
# barebits_installer_available). The zip's top-level directory stays
# `barebits/` (the slug), so the two variants stage in separate parents.
# Never channel-stamped: the only channel consumer is the excluded installer.
WPORG_STAGE="build/wporg"
rm -rf "$WPORG_STAGE" build/barebits_wordpress_plugin_wporg.zip
mkdir -p "$WPORG_STAGE"
cp -r "$BUILD_DIR" "$WPORG_STAGE/barebits"
rm -f "$WPORG_STAGE/barebits/installer.php"

# The header description must not advertise the install-alongside flow this
# variant does not ship.
sed -i.bak "s|^ \* Description: .*| * Description: Accept Bitcoin payments (on-chain and lightning) in WooCommerce through your self-hosted BareBits server. No approval process, no middlemen.|" \
    "$WPORG_STAGE/barebits/barebits.php"
rm -f "$WPORG_STAGE/barebits/barebits.php.bak"
if grep -q "install one alongside" "$WPORG_STAGE/barebits/barebits.php"; then
    echo "ERROR: install-alongside copy survived in the wporg header" >&2
    exit 1
fi
cd "$WPORG_STAGE" && zip -r ../barebits_wordpress_plugin_wporg.zip barebits/ && cd ../..

echo "WordPress plugin (wordpress.org variant) built: build/barebits_wordpress_plugin_wporg.zip"
