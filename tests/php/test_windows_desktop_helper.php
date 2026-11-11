<?php
/**
 * windows/desktop-helper.php: the Windows desktop package's sidecar.
 *
 * Behaviors under test, driven through its documented env hooks:
 *   1. Waits for the server port to accept connections, then fires the
 *      browser command exactly once with {url} substituted.
 *   2. Its tick loop actually runs cron: against a configured install the
 *      external-cron stamp must land — invoice polling, webhook delivery and
 *      auto-melt on desktop installs depend entirely on this ticking.
 *   3. After the server goes away, shuts itself down within three ticks
 *      (so closing the server window doesn't strand a background process).
 *
 * The "server" here is just a listening socket — the helper only probes
 * connectability, it never speaks HTTP.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$repoRoot = dirname(__DIR__, 2);
$helper = $repoRoot . '/windows/desktop-helper.php';

// A configured install (schema + setup_complete), so the helper's cron ticks
// run full passes rather than bailing at the setup gate.
$dataDir = fresh_db();
$marker = $dataDir . '/browser-opened.txt';

// "Server": a listening socket that never accepts — connect() still succeeds.
// It must live in a SEPARATE process: if this test held the socket itself,
// the helper (a proc_open child) would inherit the listening fd, and closing
// the socket here would not actually release the port. (Production has no
// such aliasing — the launcher starts the helper before the server.)
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
assert_not_null($probe ?: null, "probe socket: $errstr");
$port = (int) explode(':', stream_socket_get_name($probe, false))[1];
fclose($probe); // free the port for the listener child (benign test-only race)

$listener = proc_open(
    [PHP_BINARY, '-r', '$srv = stream_socket_server("tcp://127.0.0.1:" . $argv[1]) or exit(1); while (true) sleep(60);', (string) $port],
    [2 => ['file', '/dev/null', 'w']],
    $listenerPipes
);
assert_true(is_resource($listener), 'listener spawned');
// Wait until the listener child actually holds the port.
$deadline = microtime(true) + 5.0;
$listening = false;
while (microtime(true) < $deadline) {
    if ($fp = @fsockopen('127.0.0.1', $port, $e2, $s2, 1)) {
        fclose($fp);
        $listening = true;
        break;
    }
    usleep(50_000);
}
assert_true($listening, 'listener child bound the port');

putenv('CASHUPAY_HELPER_TICK=1');
putenv('CASHUPAY_HELPER_BOOT_TIMEOUT=5');
putenv('CASHUPAY_BROWSER_CMD=echo {url} > ' . $marker);
putenv('CASHUPAY_DESKTOP_APP_DIR=' . $repoRoot . '/app');
putenv('CASHUPAY_DATA_DIR=' . $dataDir);
// Keep the ticked cron passes from reaching out to GitHub mid-test.
putenv('CASHUPAY_UPDATER_DISABLED=1');

$proc = proc_open(
    [PHP_BINARY, $helper, (string) $port],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
assert_true(is_resource($proc), 'helper spawned');

// --- 1. Browser hook fires with the substituted URL -------------------------
$deadline = microtime(true) + 5.0;
while (!is_file($marker) && microtime(true) < $deadline) {
    usleep(100_000);
}
assert_true(is_file($marker), 'browser command ran after server came up');
assert_true(
    strpos((string) file_get_contents($marker), "http://127.0.0.1:$port/") !== false,
    '{url} substituted into the browser command'
);

// --- 2. Tick loop actually runs cron: the external stamp lands ---------------
// The helper spawns cron-runner.php every tick; a pass against this configured
// data dir stamps last_external_cron_at (the desktop "cron wired up" signal).
// Direct query, not Config::get — the stamp is written by the subprocess and
// Config's static cache would hide it.
$deadline = microtime(true) + 30.0;
$stamp = null;
while (microtime(true) < $deadline) {
    $stamp = Database::fetchOne(
        "SELECT value FROM config WHERE key = 'last_external_cron_at'"
    );
    if ($stamp !== null) {
        break;
    }
    usleep(200_000);
}
assert_not_null($stamp, 'a helper tick ran a cron pass (last_external_cron_at stamped)');

// --- 3. Self-shutdown after the server disappears ---------------------------
proc_terminate($listener, 9);
proc_close($listener);

// 3 one-second ticks plus slack for a cron pass that may be mid-flight.
$deadline = microtime(true) + 20.0;
$exited = false;
while (microtime(true) < $deadline) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        $exited = true;
        break;
    }
    usleep(200_000);
}
if (!$exited) {
    proc_terminate($proc, 9);
}
proc_close($proc);
assert_true($exited, 'helper exited on its own after the server went away');

cleanup_db($dataDir);
echo "ok\n";
