<?php
/**
 * windows/cron-runner.php: the Windows desktop package's CLI cron entry.
 *
 * Against an initialized, setup-complete database it must authenticate via
 * the seeded cron_key and run a full cron.php pass in-process — observable
 * as the external-cron stamp (last_external_cron_at) landing, which is what
 * keeps the admin dashboard's "cron wired up" indicator green on desktop
 * installs. Against an unconfigured data dir it must bail politely (exit 0)
 * so the desktop helper's tick loop stays quiet before setup finishes.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$dataDir = fresh_db();
$repoRoot = dirname(__DIR__, 2);
$runner = $repoRoot . '/windows/cron-runner.php';

/** Run cron-runner.php in a subprocess against the given data dir. */
function run_cron_runner(string $runner, string $repoRoot, string $dataDir): array {
    putenv('CASHUPAY_DESKTOP_APP_DIR=' . $repoRoot . '/app');
    putenv('CASHUPAY_DATA_DIR=' . $dataDir);
    // Mirrors the desktop launcher (and keeps the update-check task from
    // reaching out to GitHub mid-test).
    putenv('CASHUPAY_UPDATER_DISABLED=1');
    $out = [];
    $rc = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1',
        $out,
        $rc
    );
    return [$rc, implode("\n", $out)];
}

// --- 1. Configured install: full pass runs, external-cron stamp lands -------
$stampBefore = Database::fetchOne(
    "SELECT value FROM config WHERE key = 'last_external_cron_at'"
);
assert_null($stampBefore, 'no external cron stamp before the runner');

[$rc, $output] = run_cron_runner($runner, $repoRoot, $dataDir);
assert_eq(0, $rc, "runner exit code (output: $output)");

// Bypass Config::get's static cache — the subprocess wrote this row.
$stampAfter = Database::fetchOne(
    "SELECT value FROM config WHERE key = 'last_external_cron_at'"
);
assert_not_null($stampAfter, 'runner stamped last_external_cron_at (external-key auth path)');
$stamp = json_decode($stampAfter['value'], true);
assert_true(is_int($stamp) && $stamp > time() - 300, 'stamp is a recent timestamp');

// A pass must have produced cron.php's task report, not an auth error.
assert_true(strpos($output, 'Invalid') === false, "no auth failure in output: $output");

// --- 2. Unconfigured data dir: polite no-op ---------------------------------
$emptyDir = sys_get_temp_dir() . '/cashupay_wincron_' . bin2hex(random_bytes(6));
mkdir($emptyDir, 0750, true);
[$rc, $output] = run_cron_runner($runner, $repoRoot, $emptyDir);
assert_eq(0, $rc, 'unconfigured install exits 0');
assert_true(
    strpos($output, 'setup not complete') !== false,
    "unconfigured install reports skip, got: $output"
);
cleanup_db($emptyDir);

// --- 3. Missing app dir: hard failure ---------------------------------------
[$rc, $output] = run_cron_runner($runner, $repoRoot . '/nonexistent', $emptyDir);
assert_eq(1, $rc, 'missing app dir exits nonzero');

echo "ok\n";
