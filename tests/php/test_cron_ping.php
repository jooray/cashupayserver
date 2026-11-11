<?php
/**
 * cron.php reachability ping (?ping=1).
 *
 * Orchestrators that drive cron.php on the operator's behalf (the WordPress
 * plugin's onboarding proves the heartbeat synchronously while the merchant's
 * page is blocked on the answer) need a "can I reach cron.php with this key?"
 * probe that does NOT trigger a task run — the install's first-ever full pass
 * (updater check, IP-geo download, mint syncs) can hold a PHP worker for
 * minutes and starve tight per-site pools. Pinned here:
 *
 *   - an authenticated ping answers {"mode":"ping","ok":true} and runs no
 *     tasks,
 *   - it never stamps last_external_cron_at (it is not a task run; stamping
 *     would mask a scheduler that never actually ticks),
 *   - it answers BEFORE the overlap lock, so an in-flight full run cannot
 *     lock-bounce the interactive proof,
 *   - authentication still gates it: a wrong key gets the plain 403.
 *
 * cron.php echoes and exits, so each scenario runs in a subprocess through a
 * driver that fakes the request superglobals (same pattern as
 * test_provision_endpoint.php).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/background.php';

/**
 * Run cron.php once in a subprocess with the given $_GET.
 *
 * @return array{status:int, json:?array}
 */
function run_cron_subprocess(array $get): array {
    global $dataDir;
    static $n = 0;
    $driver = $dataDir . '/cron_driver_' . (++$n) . '.php';
    $code = "<?php\n";
    $code .= "define('CASHUPAY_DATA_DIR', " . var_export($dataDir, true) . ");\n";
    $code .= "\$_SERVER['REQUEST_METHOD'] = 'GET';\n";
    $code .= "\$_SERVER['REQUEST_URI'] = '/cron.php';\n";
    $code .= "\$_GET = " . var_export($get, true) . ";\n";
    $code .= "register_shutdown_function(function () {\n"
        . "    \$c = http_response_code();\n"
        . "    echo \"\\nHTTP_STATUS:\" . (\$c === false ? 200 : \$c);\n"
        . "});\n";
    $code .= "require " . var_export(dirname(__DIR__, 2) . '/app/cron.php', true) . ";\n";
    file_put_contents($driver, $code);

    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driver) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("cron driver produced unparseable output (rc=$rc): $raw");
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

$cronKey = (string)Config::get('cron_key');
assert_true($cronKey !== '', 'Database::initialize seeded a cron_key');

// --- 1. Authenticated ping: reachability answer, no tasks, no stamp ----------
$res = run_cron_subprocess(['ping' => '1', 'key' => $cronKey]);
assert_eq(200, $res['status'], 'an authenticated ping answers 200');
assert_eq('ping', $res['json']['mode'] ?? null, "and carries mode 'ping' (the plugin requires a mode key)");
assert_eq(true, $res['json']['ok'] ?? null, 'and ok:true');
assert_false(isset($res['json']['tasks']), 'a ping runs no tasks');
// Re-read raw — this process's Config cache must not mask the subprocess.
$row = Database::fetchOne("SELECT value FROM config WHERE key = 'last_external_cron_at'");
assert_null($row, 'a ping never stamps last_external_cron_at — it is not a task run');

// --- 2. Authentication still gates the ping ----------------------------------
$res = run_cron_subprocess(['ping' => '1', 'key' => 'wrong-key']);
assert_eq(403, $res['status'], 'a wrong key is refused before the ping answers');

// --- 3. An in-flight run cannot lock-bounce the ping --------------------------
//
// The interactive proof happens right when the first scheduled full run may
// already be working; the ping answers before the overlap lock, so it must
// come back as 'ping', never as the lock-bounce 'skipped' answer.
$lockHandle = fopen($dataDir . '/cron.lock', 'c');
assert_true($lockHandle !== false && flock($lockHandle, LOCK_EX), 'test holds the cron overlap lock');
$res = run_cron_subprocess(['ping' => '1', 'key' => $cronKey]);
assert_eq('ping', $res['json']['mode'] ?? null, 'a held overlap lock does not bounce the ping');
assert_false(isset($res['json']['skipped']), 'no lock-bounce answer for a ping');
// Contrast: a normal external run against the held lock IS bounced.
$res = run_cron_subprocess(['key' => $cronKey]);
assert_eq('another cron run in progress', $res['json']['skipped'] ?? null,
    'a full run against the held lock gets the lock-bounce');
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// --- 4. The internal-auth branch pings too ------------------------------------
$res = run_cron_subprocess(['ping' => '1', 'internal' => '1', 'key' => Background::getInternalKey()]);
assert_eq('ping', $res['json']['mode'] ?? null, 'internal auth also reaches the ping answer');

echo "test_cron_ping: ok\n";
