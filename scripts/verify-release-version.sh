#!/bin/bash
# Verify the in-tree version strings match the release tag being cut.
#
# The versioned-release workflow derives a release's version from the pushed
# git tag (vX.Y[.Z][-suffix]). Two in-tree fields must agree with it, or a
# release would ship advertising the wrong version:
#
#   - CASHUPAY_VERSION in includes/config.php   (standalone app + BUILD_INFO)
#   - the `Version:` header in wordpress/barebits.php (what WordPress displays)
#
# Enforcing the match here means the version bump has to be committed BEFORE
# the tag is pushed — the tag can never silently disagree with the code.
#
# Usage: scripts/verify-release-version.sh <tag>
#   <tag> is the raw ref name, e.g. "v0.4-alpha". The leading "v" is stripped
#   before comparison.

set -euo pipefail

cd "$(dirname "$0")/.."

TAG="${1:-}"
if [ -z "$TAG" ]; then
    echo "usage: scripts/verify-release-version.sh <tag>" >&2
    exit 2
fi

# Strip a single leading "v" (v0.4-alpha -> 0.4-alpha). Tags without one are
# taken as-is.
EXPECTED="${TAG#v}"

# Auto testing releases (.github/workflows/testing-release.yml) append a
# "-testing.<run#>" suffix to the in-tree version so each testing build gets a
# unique prerelease tag (e.g. 1.2 -> 1.2-testing.5). The in-tree version isn't
# bumped for these, so compare against the base version with that suffix
# stripped. Stable tags (1.2) and manual prereleases (1.3-rc1) have no
# "-testing." segment, so they are compared verbatim exactly as before.
EXPECTED="${EXPECTED%%-testing.*}"

fail=0

config_version="$(grep -oE "define\('CASHUPAY_VERSION',\s*'[^']+'\)" includes/config.php \
    | sed -E "s/.*'([^']+)'\)/\1/" | head -1)"
if [ -z "$config_version" ]; then
    echo "ERROR: could not read CASHUPAY_VERSION from includes/config.php" >&2
    fail=1
elif [ "$config_version" != "$EXPECTED" ]; then
    echo "ERROR: CASHUPAY_VERSION ('$config_version') != tag ('$EXPECTED')" >&2
    echo "       Bump define('CASHUPAY_VERSION', ...) in includes/config.php and re-tag." >&2
    fail=1
fi

# WordPress plugin header: `* Version: X.Y.Z` in the plugin's docblock.
wp_version="$(grep -oE "^\s*\*?\s*Version:\s*\S+" wordpress/barebits.php \
    | sed -E "s/.*Version:\s*//" | head -1)"
if [ -z "$wp_version" ]; then
    echo "ERROR: could not read Version header from wordpress/barebits.php" >&2
    fail=1
elif [ "$wp_version" != "$EXPECTED" ]; then
    echo "ERROR: wordpress/barebits.php Version ('$wp_version') != tag ('$EXPECTED')" >&2
    echo "       Update the 'Version:' plugin header and re-tag." >&2
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    exit 1
fi

echo "version check passed: tag=$TAG, CASHUPAY_VERSION=$config_version, wp header=$wp_version"
