<?php
/**
 * Self-contained update.php orchestrator.
 *
 * update.php is the crash-isolated auto-update endpoint: it depends on nothing
 * in includes/ for its recovery path, so it keeps working when a bad update
 * breaks the main code. This test loads it with the CASHUPAY_UPDATE_PHP_NO_RUN
 * guard (so only the upd_* helpers are defined, the request flow is skipped)
 * and exercises:
 *
 *   - upd_config_get/set parity with the real Config (same SQLite, same JSON
 *     encoding) — the inlined reader must see what the app wrote and vice versa
 *   - the opt-in / disable gating (default OFF; sentinel file disables)
 *   - blocked-SHA accessors agree with the Updater class
 *   - upd_health_status() classification: healthy / unhealthy / inconclusive
 *   - upd_verify_pending(): healthy -> clear marker; unhealthy -> roll back +
 *     block SHA + record; inconclusive -> keep marker, no rollback
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php'; // for updater_fixture_pick_free_port()
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

define('CASHUPAY_UPDATE_PHP_NO_RUN', true);
require dirname(__DIR__, 2) . '/app/update.php';

// ---------------------------------------------------------------------------
// Config read/write parity with the real Config layer
// ---------------------------------------------------------------------------
Config::set('parity_string', 'hello');
assert_eq('hello', upd_config_get('parity_string'), 'upd_config_get reads a Config string');

Config::set('parity_array', ['a' => 1, 'b' => [2, 3]]);
assert_eq(['a' => 1, 'b' => [2, 3]], upd_config_get('parity_array'), 'upd_config_get decodes a Config array');

upd_config_set('from_update', ['x' => 9]);
assert_eq(['x' => 9], Config::get('from_update'), 'Config reads what upd_config_set wrote');

upd_config_set('from_update_str', 'raw');
assert_eq('raw', Config::get('from_update_str'), 'string stored raw, not double-encoded');

assert_eq('fallback', upd_config_get('does_not_exist', 'fallback'), 'default returned for missing key');

// ---------------------------------------------------------------------------
// Gating
// ---------------------------------------------------------------------------
assert_true(upd_is_enabled() === false, 'auto-update OFF by default');
putenv('CASHUPAY_AUTO_UPDATE_ENABLED=1');
assert_true(upd_is_enabled() === true, 'env opt-in enables');
putenv('CASHUPAY_AUTO_UPDATE_ENABLED');
assert_true(upd_is_enabled() === false, 'unsetting env disables again');

// Sentinel file (the iterate.py / pytest kill switch) disables.
assert_true(upd_is_disabled_for_tests() === false, 'not disabled without a sentinel');
$sentinel = upd_data_dir() . '/.updater_disabled';
file_put_contents($sentinel, '');
assert_true(upd_is_disabled_for_tests() === true, 'sentinel file disables');
@unlink($sentinel);
assert_true(upd_is_disabled_for_tests() === false, 'removing sentinel re-enables');

// ---------------------------------------------------------------------------
// Blocked-SHA accessors agree across the two readers
// ---------------------------------------------------------------------------
upd_block_sha('deadbeef');
assert_true(in_array('deadbeef', upd_get_blocked_shas(), true), 'upd reader sees the block');
assert_true(in_array('deadbeef', Updater::getBlockedShas(), true), 'Updater reader sees the same block');
Config::set('updater_blocked_shas', []);

// ---------------------------------------------------------------------------
// Health probe classification against a controllable stub server
// ---------------------------------------------------------------------------
$serve = sys_get_temp_dir() . '/cashupay_health_' . bin2hex(random_bytes(6));
mkdir($serve, 0755, true);
file_put_contents($serve . '/health.php', <<<'PHP'
<?php
$d = __DIR__;
if (file_exists($d . '/UNHEALTHY'))  { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'boom']); exit; }
if (file_exists($d . '/FORBIDDEN'))  { http_response_code(403); echo json_encode(['ok' => false]); exit; }
echo json_encode(['ok' => true]);
PHP);

$port = updater_fixture_pick_free_port();
$cmd = sprintf('%s -S 127.0.0.1:%d -t %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($serve));
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $serve . '/srv.log', 'a'], 2 => ['file', $serve . '/srv.log', 'a']];
$proc = proc_open($cmd, $descriptors, $pipes);
assert_true(is_resource($proc), 'health stub server started');
register_shutdown_function(static function () use ($proc, $serve) {
    if (is_resource($proc)) { @proc_terminate($proc, SIGKILL); @proc_close($proc); }
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($serve, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    if ($it) { foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); } }
    @rmdir($serve);
});
// Wait for readiness.
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $fp = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.2);
    if ($fp) { fclose($fp); break; }
    usleep(50_000);
}

// Point upd_base_url() at the stub (it appends /health.php).
Config::set('base_url', "http://127.0.0.1:$port");
$key = 'testkey';

assert_eq('healthy', upd_health_status($key), 'clean stub -> healthy');

file_put_contents($serve . '/UNHEALTHY', '');
assert_eq('unhealthy', upd_health_status($key), '500 -> unhealthy');
@unlink($serve . '/UNHEALTHY');

file_put_contents($serve . '/FORBIDDEN', '');
assert_eq('inconclusive', upd_health_status($key), '403 -> inconclusive (auth, not a health signal)');
@unlink($serve . '/FORBIDDEN');

// ---------------------------------------------------------------------------
// upd_verify_pending end-to-end against a temp install root
// ---------------------------------------------------------------------------
$inst = sys_get_temp_dir() . '/cashupay_inst_' . bin2hex(random_bytes(6));
$backupName = '20260101-000000-oldoldoldold';
mkdir($inst . '/data/updates/backup/' . $backupName, 0755, true);
$GLOBALS['UPD_ROOT_OVERRIDE'] = $inst;
register_shutdown_function(static function () use ($inst) {
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($inst, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    if ($it) { foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); } }
    @rmdir($inst);
});

$badSha = 'badbadbad' . str_repeat('0', 31);
$seedInstall = static function () use ($inst, $backupName) {
    file_put_contents($inst . '/admin.php', 'BROKEN');              // live (bad) code
    file_put_contents($inst . '/data/MARKER', 'keep');             // must survive rollback
    file_put_contents($inst . '/user_config.php', 'USERCFG');      // must survive rollback
    file_put_contents($inst . '/data/updates/backup/' . $backupName . '/admin.php', 'GOOD'); // known-good snapshot
};

// --- healthy: marker cleared, no rollback ---
$seedInstall();
upd_config_set('updater_pending_verify', ['sha' => $badSha, 'version' => '0.9-bad', 'backup' => $backupName]);
$res = upd_verify_pending(['sha' => $badSha, 'version' => '0.9-bad', 'backup' => $backupName], $key);
assert_eq('healthy', $res['result'], 'healthy verdict');
assert_null(upd_config_get('updater_pending_verify'), 'marker cleared on healthy');
assert_eq('BROKEN', file_get_contents($inst . '/admin.php'), 'no rollback when healthy');
assert_true(!in_array($badSha, upd_get_blocked_shas(), true), 'SHA not blocked when healthy');

// --- unhealthy: rollback + block + record ---
$seedInstall();
file_put_contents($serve . '/UNHEALTHY', '');
upd_config_set('updater_pending_verify', ['sha' => $badSha, 'version' => '0.9-bad', 'backup' => $backupName]);
$res = upd_verify_pending(['sha' => $badSha, 'version' => '0.9-bad', 'backup' => $backupName], $key);
@unlink($serve . '/UNHEALTHY');
assert_eq('rolled_back', $res['result'], 'rolled_back verdict');
assert_true($res['rolled_back'] === true, 'rollback succeeded');
assert_eq('GOOD', file_get_contents($inst . '/admin.php'), 'live code restored from backup');
assert_eq('keep', file_get_contents($inst . '/data/MARKER'), 'data/ preserved through rollback');
assert_eq('USERCFG', file_get_contents($inst . '/user_config.php'), 'user_config.php preserved');
assert_true(in_array($badSha, upd_get_blocked_shas(), true), 'bad SHA is now blocked');
assert_null(upd_config_get('updater_pending_verify'), 'marker cleared after rollback');
$ar = upd_config_get('updater_last_auto_rollback');
assert_eq($badSha, $ar['bad_sha'] ?? null, 'auto-rollback record carries the bad SHA');
assert_true(($ar['rolled_back'] ?? null) === true, 'auto-rollback record marks success');

// --- inconclusive: keep marker, do NOT roll back ---
Config::set('updater_blocked_shas', []);
$seedInstall();
file_put_contents($serve . '/FORBIDDEN', '');
upd_config_set('updater_pending_verify', ['sha' => $badSha, 'version' => '0.9-bad', 'backup' => $backupName]);
$res = upd_verify_pending(['sha' => $badSha, 'version' => '0.9-bad', 'backup' => $backupName], $key);
@unlink($serve . '/FORBIDDEN');
assert_eq('inconclusive', $res['result'], 'inconclusive verdict');
assert_eq('BROKEN', file_get_contents($inst . '/admin.php'), 'no rollback when inconclusive');
assert_not_null(upd_config_get('updater_pending_verify'), 'marker kept for re-check when inconclusive');
assert_true(!in_array($badSha, upd_get_blocked_shas(), true), 'SHA not blocked when inconclusive');

echo "ok\n";
