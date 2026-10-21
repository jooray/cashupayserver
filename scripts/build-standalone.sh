#!/bin/bash
# Build standalone CashuPayServer distribution zip

set -e

cd "$(dirname "$0")/.."

# A release must be built from the commit that was reviewed and tagged. Building the
# working tree meant uncommitted fixes could ship under an already-published tag with a
# green checksum (MINIMAX 4.13). Set CASHUPAY_ALLOW_DIRTY_BUILD=1 only for local tests.
if [ "${CASHUPAY_ALLOW_DIRTY_BUILD:-0}" != "1" ]; then
    if [ -n "$(git status --porcelain --untracked-files=normal 2>/dev/null)" ]; then
        echo "Refusing to build: the working tree has uncommitted or untracked files." >&2
        git status --short --untracked-files=normal >&2
        echo "Commit them, or set CASHUPAY_ALLOW_DIRTY_BUILD=1 for a throwaway build." >&2
        exit 1
    fi
    if git submodule status --recursive 2>/dev/null | grep -Eq '^[+-U]'; then
        echo "Refusing to build: a submodule is not at the committed revision." >&2
        git submodule status --recursive >&2
        exit 1
    fi
    if [ -n "${CASHUPAY_RELEASE_TAG:-}" ]; then
        tag_commit="$(git rev-parse "${CASHUPAY_RELEASE_TAG}^{commit}" 2>/dev/null || true)"
        head_commit="$(git rev-parse HEAD)"
        if [ "$tag_commit" != "$head_commit" ]; then
            echo "Refusing to build: $CASHUPAY_RELEASE_TAG is not HEAD ($head_commit)." >&2
            exit 1
        fi
    fi
fi

BUILD_DIR="build/cashupayserver"
rm -rf build/cashupayserver build/cashupayserver.zip

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
    # What the bytes above were built from, so a checksum can be tied to a commit and tag.
    printf 'commit=%s\ntag=%s\n' "$(git rev-parse HEAD)" "${CASHUPAY_RELEASE_TAG:-}" > build/SHA256SUMS.commit
    echo "SHA-256: $sum"
}

# Copy core files. RELEASE.md promises the release never ships local configuration, so
# exclude it explicitly rather than relying on the build tree being clean.
cp -r includes/ "$BUILD_DIR/includes/"
rm -f "$BUILD_DIR/includes/config.local.php" "$BUILD_DIR/includes/data-location.txt"
find "$BUILD_DIR/includes" -name '*.local.php' -delete
cp -r assets/ "$BUILD_DIR/assets/"
build_mint_discovery "$BUILD_DIR/assets/js/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"
cp admin.php setup.php api.php payment.php receive.php cron.php router.php index.php "$BUILD_DIR/"
# MIT licence text; the vendored JS libraries carry their own headers (assets/js/vendor/).
cp LICENSE "$BUILD_DIR/"
cp .htaccess manifest.json favicon.ico "$BUILD_DIR/"
cp -r images/ "$BUILD_DIR/images/"

# Copy cashu-wallet-php (clean, no .git)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"
cp cashu-wallet-php/bip39-english.txt "$BUILD_DIR/cashu-wallet-php/"

# Create data directory with protection. The application only writes its own protection
# files when it creates the directory, which an unzipped release already has, so ship the
# same Apache 2.2 + 2.4 form Database::createDataDirectory() writes. A bare
# `deny from all` is a 500 on Apache 2.4 without mod_access_compat.
mkdir -p "$BUILD_DIR/data"
cat > "$BUILD_DIR/data/.htaccess" <<'HTACCESS'
# Deny all access to this directory
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS
printf '%s' "<?php http_response_code(403); exit('Forbidden');" > "$BUILD_DIR/data/index.php"

# Create zip
make_zip cashupayserver cashupayserver.zip

echo "Standalone build: build/cashupayserver.zip"
