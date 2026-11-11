<?php
/**
 * The updater flushes the compiled-code cache whenever it changes the files
 * on disk — once after an overlay (before the health probe runs) and once
 * after a rollback — and the isolated update.php does the same after its
 * self-contained rollback. Observed through the test hooks; the real
 * opcache_reset() is a no-op in the CLI.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => 'opc-' . str_repeat('c', 36),
    'CHANNEL' => 'main',
    'VERSION' => '0.3-opc',
], ['admin.php' => 'NEW_ADMIN']);
Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Updater::$autoUpdateEnabledOverride = true;
Config::set('update_channel', 'main');

$resets = [];
Updater::$opcacheResetHook = static function (string $why) use (&$resets): void {
    $resets[] = $why;
};

// --- apply: exactly one reset, naming the applied SHA ---
assert_true(Updater::checkAndApply(), 'update applied');
assert_eq(1, count($resets), 'one opcache reset after the overlay');
assert_true(str_starts_with($resets[0], 'apply opc-'), "reset reason names the apply ({$resets[0]})");

// --- rollback: one more ---
assert_true(Updater::rollbackToMostRecent(), 'rolled back');
assert_eq(2, count($resets), 'one opcache reset after the rollback');
assert_true(str_starts_with($resets[1], 'rollback 20'), "reset reason names the backup ({$resets[1]})");

// --- update.php's inline rollback resets too ---
define('CASHUPAY_UPDATE_PHP_NO_RUN', true);
require dirname(__DIR__, 2) . '/app/update.php';
$inst = sys_get_temp_dir() . '/cashupay_updphp_' . bin2hex(random_bytes(6));
$backupName = '20260101-000000-abcdefabcdef';
mkdir($inst . '/data/updates/backup/' . $backupName, 0755, true);
file_put_contents($inst . '/data/updates/backup/' . $backupName . '/admin.php', 'GOOD');
file_put_contents($inst . '/admin.php', 'BAD');
register_shutdown_function(static function () use ($inst) { @cleanup_db($inst); });
$GLOBALS['UPD_ROOT_OVERRIDE'] = $inst;
$updResets = [];
$GLOBALS['UPD_OPCACHE_RESET_HOOK'] = static function (string $why) use (&$updResets): void {
    $updResets[] = $why;
};
assert_true(upd_rollback($backupName), 'update.php rollback succeeded');
assert_eq('GOOD', file_get_contents($inst . '/admin.php'), 'live code restored');
assert_eq(["rollback $backupName"], $updResets, 'update.php reset opcache after its rollback');

Updater::$opcacheResetHook = null;
Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;
Updater::$autoUpdateEnabledOverride = null;
echo "ok\n";
