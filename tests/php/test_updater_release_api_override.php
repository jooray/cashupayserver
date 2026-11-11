<?php
/**
 * CASHUPAY_RELEASE_API_BASE — the operator/rig override of the releases API.
 *
 * The updater fetches release metadata from api.github.com. LAN mirrors and
 * the upgrade test rig (tests/upgrade/, which must point a REAL installed
 * release at a local release server) need to redirect that, and old releases
 * had no runtime seam for it. The override is a supply-chain-relevant knob, so
 * its contract is pinned here:
 *
 *   1. Unset (constant and env both absent)        -> null, GitHub is used.
 *   2. Set, but private endpoints NOT allowed      -> ignored (null).
 *   3. Set + private endpoints allowed             -> honoured, trailing
 *      slash trimmed, and checkForUpdate() really fetches from it.
 *   4. Malformed values (no scheme, non-http scheme) -> ignored (null).
 *   5. The $releaseApiUrlBase test seam still wins over the env override.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// Make sure nothing from the outer shell leaks in.
putenv('CASHUPAY_RELEASE_API_BASE');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');
Config::delete('allow_private_endpoints');

// --- 1. Unset -> null ---
assert_null(Updater::releaseApiBaseOverride(), 'no override when unset');

// --- 2. Set but private endpoints not allowed -> ignored ---
putenv('CASHUPAY_RELEASE_API_BASE=http://127.0.0.1:1/releases');
assert_null(Updater::releaseApiBaseOverride(), 'override ignored without the private-endpoints opt-in');

// --- 3. Allowed -> honoured (and the fetch really goes there) ---
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
putenv('CASHUPAY_RELEASE_API_BASE=http://127.0.0.1:1/releases/');
assert_eq('http://127.0.0.1:1/releases', Updater::releaseApiBaseOverride(), 'trailing slash trimmed');

$remoteSha = 'oversha-' . str_repeat('b', 34);
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => $remoteSha,
    'CHANNEL' => 'main',
    'VERSION' => '0.9-mirror',
], [
    'admin.php' => 'MIRROR_ADMIN',
]);
Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = null; // the seam stays off: the env override must carry the fetch
putenv('CASHUPAY_RELEASE_API_BASE=' . $fixture['baseUrl']);
Config::set('update_channel', 'main');

$res = Updater::checkForUpdate(true);
assert_true((bool)$res['available'], 'checkForUpdate fetched from the overridden base and found the mirror build');
assert_eq($remoteSha, $res['latest_sha'], 'latest_sha came from the mirror');
assert_eq('0.9-mirror', $res['latest_version'], 'latest_version came from the mirror');

// --- 4. Malformed values are ignored ---
foreach (['mirror.lan/releases', 'ftp://mirror.lan/releases', 'http://', '   '] as $bad) {
    putenv('CASHUPAY_RELEASE_API_BASE=' . $bad);
    assert_null(Updater::releaseApiBaseOverride(), "malformed value ignored: '$bad'");
}

// --- 5. The test seam wins over the env override ---
putenv('CASHUPAY_RELEASE_API_BASE=http://127.0.0.1:1/releases');
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Config::set('updater_available', null);
$res2 = Updater::checkForUpdate(true);
assert_eq($remoteSha, $res2['latest_sha'], 'seam took precedence over the env override');

Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;
putenv('CASHUPAY_RELEASE_API_BASE');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');
echo "ok\n";
