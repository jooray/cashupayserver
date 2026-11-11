<?php
/**
 * The cached "update available" verdict (Config key updater_available) is
 * refreshed at most daily by cron, and nothing used to touch it when an update
 * actually landed. Result: for up to a day after updating to 1.6 the admin
 * banner kept saying "A software update is available (version 1.6)" while an
 * "Update now" click reported "You're already on the latest version."
 *
 * Contract under test:
 *   1. getAvailableUpdate() self-heals: a cached "available" verdict whose
 *      latest_sha IS the running build's SHA is reported AND persisted as
 *      not-available. A verdict for a different SHA is left alone.
 *   2. The self-heal never fabricates availability: a cached "current"
 *      verdict stays "current" even if the local SHA differs from it.
 *   3. A manual "Update now" on an already-current install (checkAndApply
 *      finds local == remote) refreshes the cache from the remote BUILD_INFO
 *      it just fetched — even when the stale cache advertises some OTHER SHA
 *      the self-heal cannot recognise.
 *   4. The apply path itself (checkAndApply → applyUpdate) rewrites the
 *      verdict against the new local SHA (also pinned in
 *      test_updater_force_apply.php).
 *   5. With no local BUILD_INFO (dev checkout) the self-heal is inert.
 *   6. A verdict computed by a DIFFERENT build (its current_sha is not the
 *      running SHA — the "1.6 updated me to 1.6.1" shape) is not advertised
 *      and has its checked_at zeroed so the next check refetches past the
 *      daily throttle; getAvailableUpdateRefreshingStale() does that refetch
 *      inline for the admin status endpoint.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

$remoteSha = 'healsha-' . str_repeat('c', 32);
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => $remoteSha,
    'CHANNEL' => 'main',
    'VERSION' => '1.6',
], [
    'admin.php' => 'NEW_ADMIN',
]);
$root = $fixture['installRoot'];
Updater::$installRootOverride = $root;
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Config::set('update_channel', 'main');

// $currentSha is the SHA the verdict was computed against; pass the running
// SHA to model "this build's own daily check", the default to model a verdict
// left behind by an older build.
function seed_verdict(bool $available, string $latestSha, string $latestVersion = '1.6', ?string $currentSha = null): void {
    Config::set('updater_available', [
        'available' => $available,
        'channel' => 'main',
        'current_version' => '1.5.3',
        'current_sha' => $currentSha ?? str_repeat('0', 40),
        'latest_version' => $latestVersion,
        'latest_sha' => $latestSha,
        'blocked' => false,
        'checked_at' => time() - 3600, // an hour old: well inside the daily window
        'error' => null,
    ]);
}

// --- 1. Self-heal on read -------------------------------------------------
// Install is already on the advertised SHA (e.g. the operator just updated).
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=$remoteSha\nVERSION=1.6\n");
seed_verdict(true, $remoteSha);

$v = Updater::getAvailableUpdate();
assert_true(is_array($v), 'verdict returned');
assert_false((bool)$v['available'], 'cached "available" for the SHA we run is reported as not available');
assert_eq($remoteSha, $v['current_sha'], 'current_sha updated to the running SHA');
assert_eq('1.6', $v['current_version'], 'current_version updated to the running version');
assert_eq($remoteSha, $v['latest_sha'], 'latest_sha preserved');
assert_eq('1.6', $v['latest_version'], 'latest_version preserved');
$stored = Config::get('updater_available');
assert_true(is_array($stored) && $stored['available'] === false, 'healed verdict persisted (email notifier reads the same key)');

// A verdict for a genuinely newer SHA is untouched.
$newer = 'newersha-' . str_repeat('d', 31);
seed_verdict(true, $newer, '1.7', $remoteSha);
$v = Updater::getAvailableUpdate();
assert_true((bool)$v['available'], 'a verdict advertising a different SHA stays available');
assert_eq('1.7', $v['latest_version'], 'and keeps its version');

// --- 2. Never flips current → available ----------------------------------
seed_verdict(false, $newer, '1.7', $remoteSha);
$v = Updater::getAvailableUpdate();
assert_false((bool)$v['available'], 'a "current" verdict is never promoted to available by the read path');

// --- 3. Manual run on an already-current install refreshes the cache ------
// Stale cache advertises some SHA that is neither local nor the remote head
// (e.g. an interim testing build), so the read-side self-heal does nothing…
$ghost = 'ghostsha-' . str_repeat('e', 31);
seed_verdict(true, $ghost, '1.6-rc', $remoteSha);
assert_true((bool)Updater::getAvailableUpdate()['available'], 'precondition: self-heal does not recognise the ghost SHA');
// …but "Update now" fetches the remote BUILD_INFO, sees local == remote, and
// re-derives the verdict from it.
Config::set('updater_last_check', 0);
assert_false(Updater::checkAndApply(true), 'manual run on a current install applies nothing');
assert_eq('OLD_ADMIN', file_get_contents($root . '/admin.php'), 'install untouched');
$v = Config::get('updater_available');
assert_true(is_array($v) && $v['available'] === false, 'manual no-op run refreshed the cache to not-available');
assert_eq($remoteSha, $v['latest_sha'], 'refreshed verdict carries the real remote head');
assert_eq($remoteSha, $v['current_sha'], 'refreshed verdict carries the local SHA');
assert_true((time() - (int)$v['checked_at']) < 30, 'checked_at stamped now');

// --- 4. Apply path rewrites the verdict against the new local SHA ---------
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=" . str_repeat('0', 40) . "\nVERSION=1.5.3\n");
seed_verdict(true, $remoteSha);
assert_true((bool)Config::get('updater_available')['available'], 'precondition: update advertised');
Config::set('updater_last_check', 0);
assert_true(Updater::checkAndApply(true), 'manual run applies the newer build');
assert_eq('NEW_ADMIN', file_get_contents($root . '/admin.php'), 'install overlaid');
$v = Config::get('updater_available');
assert_true(is_array($v) && $v['available'] === false, 'apply refreshed the cache to not-available');
assert_eq($remoteSha, $v['current_sha'], 'post-apply current_sha is the applied SHA');
assert_eq('1.6', $v['current_version'], 'post-apply current_version is the applied version');

// --- 6. Verdict computed by a DIFFERENT build is stale --------------------
// The real-world shape of the bug report: the 1.6 build's daily check cached
// "1.6 is available" (current_sha = 1.5.3's SHA), then the operator updated
// to 1.6.1 — but the apply ran inside the 1.6 build's updater code, so the
// new build's post-apply refresh never ran, and latest_sha (1.6) is not the
// SHA now running (1.6.1), so the catch-up rule above cannot match either.
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=$remoteSha\nVERSION=1.6\n");
Config::set('updater_available', [
    'available' => true, 'channel' => 'main',
    'current_version' => '1.5.3', 'current_sha' => str_repeat('0', 40),
    'latest_version' => '1.6-old', 'latest_sha' => $ghost,
    'blocked' => false, 'checked_at' => time() - 600, 'error' => null,
]);
$v = Updater::getAvailableUpdate();
assert_false((bool)$v['available'], 'verdict from another build is not advertised');
assert_eq($remoteSha, $v['current_sha'], 'current_sha rewritten to the running SHA');
assert_eq('1.6', $v['current_version'], 'current_version rewritten to the running version');
assert_eq(0, (int)$v['checked_at'], 'checked_at zeroed so the next check refetches');
$stored = Config::get('updater_available');
assert_true(is_array($stored) && $stored['available'] === false && (int)$stored['checked_at'] === 0, 'invalidation persisted');
// A non-forced check now refetches instead of honouring the daily throttle…
$v = Updater::checkForUpdate(false);
assert_false((bool)$v['available'], 'refetched: local == remote head → not available');
assert_eq($remoteSha, $v['latest_sha'], 'refetched verdict names the real remote head');
assert_true((time() - (int)$v['checked_at']) < 30, 'refetched verdict is freshly stamped');
// …and a verdict that is merely "current" for a different build is refreshed
// too (it may be hiding a newer release), without being promoted on read.
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=midsha-" . str_repeat('f', 33) . "\nVERSION=1.5.9\n");
Config::set('updater_available', [
    'available' => false, 'channel' => 'main',
    'current_version' => '1.5.3', 'current_sha' => str_repeat('0', 40),
    'latest_version' => '1.5.3', 'latest_sha' => str_repeat('0', 40),
    'blocked' => false, 'checked_at' => time() - 600, 'error' => null,
]);
$v = Updater::getAvailableUpdate();
assert_false((bool)$v['available'], 'read path never promotes to available');
assert_eq(0, (int)$v['checked_at'], 'but it still forces a refetch');
$v = Updater::getAvailableUpdateRefreshingStale();
assert_true((bool)$v['available'], 'status-endpoint helper refetched and found the newer head');
assert_eq($remoteSha, $v['latest_sha'], 'helper verdict names the remote head');
assert_true((time() - (int)$v['checked_at']) < 30, 'helper verdict freshly stamped');
// The helper is a plain read when the verdict is fresh.
$before = Config::get('updater_available');
assert_eq($before, Updater::getAvailableUpdateRefreshingStale(), 'fresh verdict returned as-is');
// A legacy verdict without current_sha cannot be judged and is left alone.
Config::set('updater_available', ['available' => true, 'latest_sha' => $ghost, 'latest_version' => 'x', 'checked_at' => time()]);
$v = Updater::getAvailableUpdate();
assert_true((bool)$v['available'], 'verdict without current_sha is returned untouched');

// --- 5. No local BUILD_INFO → self-heal is inert --------------------------
@unlink($root . '/BUILD_INFO');
seed_verdict(true, $remoteSha);
$v = Updater::getAvailableUpdate();
assert_true((bool)$v['available'], 'without a local SHA the cached verdict is returned as-is');
assert_true((int)$v['checked_at'] > 0, 'and its checked_at is not zeroed (no SHA to compare against)');

Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;

echo "test_updater_available_self_heal: ok\n";
