<?php
/**
 * Updater::checkAndApply end-to-end against a local fixture server.
 *
 * What this test exercises that the unit tests don't:
 *   - The HTTP fetch path (GitHub-shaped JSON response with assets)
 *   - The zip download + extraction
 *   - The full overlay/backup/recovery-token/Config-write sequence
 *   - The "live BUILD_INFO updates and matches the freshly-shipped one"
 *     contract that prevents an infinite update loop
 *
 * We DON'T verify network failure modes here — those are best covered
 * with explicit unit tests that stub httpGet*. Adding them is a
 * follow-up if the auto-update path gets reworked.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// .htaccess in the shipped tree, plus its hash that BUILD_INFO carries.
$shippedHtaccess = "# new htaccess\nRewriteEngine On\n";
$htaccessSha = hash('sha256', $shippedHtaccess);

$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => 'newsha-' . str_repeat('a', 35),
    'CHANNEL' => 'main',
    'BUILT_AT' => '2026-06-02T07:00:00Z',
    'VERSION' => '0.2-new',
    'HTACCESS_SHA256' => $htaccessSha,
], [
    'admin.php' => 'NEW_ADMIN',
    'includes/updater.php' => '<?php // NEW_UPDATER',
    '.htaccess' => $shippedHtaccess,
]);

// Point Updater at the fixture install + fixture server, and opt into
// auto-updates for this test (default for fresh installs is OFF).
Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Updater::$autoUpdateEnabledOverride = true;

// Pre-flight: install root has the "old" content.
assert_eq("COMMIT_SHA=0000000000000000000000000000000000000000\nVERSION=0.0-old\n",
    file_get_contents($fixture['installRoot'] . '/BUILD_INFO'));
assert_eq('OLD_ADMIN', file_get_contents($fixture['installRoot'] . '/admin.php'));

// Force a check by resetting the daily gate.
Config::set('updater_last_check', 0);
Config::set('update_channel', 'main');

$applied = Updater::checkAndApply();
assert_true($applied, 'checkAndApply returned true');

// --- Post-update assertions ---

$root = $fixture['installRoot'];

// BUILD_INFO updated → new SHA → next call won't re-trigger.
$info = Updater::getLocalBuildInfo();
assert_eq('newsha-' . str_repeat('a', 35), $info['COMMIT_SHA'], 'BUILD_INFO advanced');
assert_eq('0.2-new', $info['VERSION'], 'VERSION advanced');

// admin.php overlaid.
assert_eq('NEW_ADMIN', file_get_contents($root . '/admin.php'));
assert_eq('<?php // NEW_UPDATER', file_get_contents($root . '/includes/updater.php'));

// .htaccess overwritten (was pristine — install root never had a real one).
assert_eq($shippedHtaccess, file_get_contents($root . '/.htaccess'));
assert_true(!is_file($root . '/.htaccess.new'), 'no .htaccess.new');

// User data preserved.
assert_eq('preserve_me', file_get_contents($root . '/data/MARKER'));
assert_eq('USER_CONFIG', file_get_contents($root . '/user_config.php'));

// Backup created with snapshot of pre-update install.
$backups = Updater::listBackups();
assert_eq(1, count($backups), 'one backup created');
$backupAdmin = $root . '/data/updates/backup/' . $backups[0] . '/admin.php';
assert_eq('OLD_ADMIN', file_get_contents($backupAdmin), 'backup has old admin.php');

// Recovery token written + non-empty.
$tokenPath = $root . '/data/updates/recovery_token.txt';
assert_true(is_file($tokenPath), 'recovery token file exists');
$token = trim((string)file_get_contents($tokenPath));
assert_eq(64, strlen($token), 'recovery token is 32 bytes hex (64 chars)');

// Config last_update set.
$last = Config::get('updater_last_update');
assert_eq('0.0-old', $last['from_version']);
assert_eq('0.2-new', $last['to_version']);
assert_eq('main', $last['channel']);
assert_true($last['htaccess_held_back'] === false, 'htaccess not held back');

// --- Re-running checkAndApply is a no-op ---
Config::set('updater_last_check', 0);
$applied = Updater::checkAndApply();
assert_true(!$applied, 'second checkAndApply is a no-op (already current)');

// Clean up overrides for any later tests in the same process.
Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;
Updater::$autoUpdateEnabledOverride = null;

echo "ok\n";
