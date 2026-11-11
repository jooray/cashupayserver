<?php
/**
 * Updater::checkAndApply end-to-end across BOTH channels, against a local
 * fixture serving VERSIONED releases (the model that replaced the moving
 * channel-<x> tags).
 *
 * The fixture publishes two releases, newest-first:
 *   - v0.3.0-rc1  (PRERELEASE) — newer
 *   - v0.2.0      (STABLE)     — older
 *
 * Contract under test:
 *   - main channel applies the newest STABLE release (v0.2.0), deliberately
 *     NOT the newer prerelease — /releases/latest excludes prereleases.
 *   - testing channel applies the newest release of ANY kind (v0.3.0-rc1).
 *
 * This is the real download → extract → backup → overlay → BUILD_INFO-advance
 * path for each channel, not a unit stub.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

$stableSha = 'stablesha-' . str_repeat('a', 30);
$rcSha     = 'rcsha-' . str_repeat('b', 34);

$fixture = updater_fixture_start_releases([
    // Newest first: the prerelease is newer than the stable.
    [
        'tag' => 'v0.3.0-rc1',
        'prerelease' => true,
        'build_info' => [
            'COMMIT_SHA' => $rcSha,
            'CHANNEL' => 'testing',
            'VERSION' => '0.3.0-rc1',
        ],
        'extra' => ['admin.php' => 'RC_ADMIN'],
    ],
    [
        'tag' => 'v0.2.0',
        'prerelease' => false,
        'build_info' => [
            'COMMIT_SHA' => $stableSha,
            'CHANNEL' => 'main',
            'VERSION' => '0.2.0',
        ],
        'extra' => ['admin.php' => 'STABLE_ADMIN'],
    ],
]);

$root = $fixture['installRoot'];
Updater::$installRootOverride = $root;
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Updater::$autoUpdateEnabledOverride = true;

/** Reset the install + updater state back to the pristine "old" build. */
$resetInstall = static function () use ($root): void {
    file_put_contents($root . '/BUILD_INFO',
        "COMMIT_SHA=0000000000000000000000000000000000000000\nVERSION=0.0-old\n");
    file_put_contents($root . '/admin.php', 'OLD_ADMIN');
    Config::set('updater_last_check', 0);
    Config::set('updater_pending_verify', null);
    Config::set('updater_blocked_shas', []);
    Config::set('updater_last_update', null);
};

// ---- main channel: must apply the newest STABLE, skipping the prerelease ----
$resetInstall();
Config::set('update_channel', 'main');
assert_true(Updater::checkAndApply(), 'main: checkAndApply applied an update');

$info = Updater::getLocalBuildInfo();
assert_eq($stableSha, $info['COMMIT_SHA'], 'main applied the STABLE build (not the prerelease)');
assert_eq('0.2.0', $info['VERSION'], 'main VERSION is the stable one');
assert_eq('STABLE_ADMIN', file_get_contents($root . '/admin.php'), 'main overlaid the stable admin.php');
$lastMain = Config::get('updater_last_update');
assert_eq('0.2.0', $lastMain['to_version'], 'main last_update to_version');
assert_eq('main', $lastMain['channel'], 'main last_update channel');

// ---- testing channel: must apply the newest release of ANY kind ----
$resetInstall();
Config::set('update_channel', 'testing');
assert_true(Updater::checkAndApply(), 'testing: checkAndApply applied an update');

$info = Updater::getLocalBuildInfo();
assert_eq($rcSha, $info['COMMIT_SHA'], 'testing applied the PRERELEASE build');
assert_eq('0.3.0-rc1', $info['VERSION'], 'testing VERSION is the prerelease one');
assert_eq('RC_ADMIN', file_get_contents($root . '/admin.php'), 'testing overlaid the rc admin.php');
$lastTesting = Config::get('updater_last_update');
assert_eq('0.3.0-rc1', $lastTesting['to_version'], 'testing last_update to_version');
assert_eq('testing', $lastTesting['channel'], 'testing last_update channel');

// Clean up overrides for any later tests in the same process.
Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;
Updater::$autoUpdateEnabledOverride = null;

echo "test_updater_channels_e2e: ok\n";
