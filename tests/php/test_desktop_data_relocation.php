<?php
/**
 * The Windows desktop package's update-and-move contract.
 *
 * Desktop installs update by extracting a whole new zip and carrying app/data
 * over from the old package; merchants also just move the extracted folder
 * (the launcher re-renders php.ini each start to allow exactly that). Both
 * flows reduce to the same invariant: a data dir created at one absolute path
 * must come up healthy at a different absolute path — nothing in the database
 * may bake in where it used to live.
 *
 * Exercised through windows/cron-runner.php, the same entry the desktop
 * helper ticks: a full pass must run against the relocated copy, and the
 * store + config written before the move must survive it.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$dataDir = fresh_db();
$repoRoot = dirname(__DIR__, 2);
$runner = $repoRoot . '/windows/cron-runner.php';

function run_cron_runner(string $runner, string $repoRoot, string $dataDir): array {
    putenv('CASHUPAY_DESKTOP_APP_DIR=' . $repoRoot . '/app');
    putenv('CASHUPAY_DATA_DIR=' . $dataDir);
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

// --- 1. A lived-in install at the original path ------------------------------
make_store('reloc-store-1');
[$rc, $output] = run_cron_runner($runner, $repoRoot, $dataDir);
assert_eq(0, $rc, "cron pass at the original path (output: $output)");
assert_not_null(
    Database::fetchOne("SELECT value FROM config WHERE key = 'last_external_cron_at'"),
    'cron stamp landed at the original path'
);

// --- 2. Carry the data dir to a new absolute path ----------------------------
// Copy every file (the SQLite -wal/-shm sidecars included, if present) — the
// merchant flow is a plain folder copy, not a graceful export.
$newDir = sys_get_temp_dir() . '/cashupay_reloc_' . bin2hex(random_bytes(6));
mkdir($newDir, 0750, true);
foreach (scandir($dataDir) as $f) {
    if (is_file($dataDir . '/' . $f)) {
        copy($dataDir . '/' . $f, $newDir . '/' . $f);
    }
}

// --- 3. The relocated copy runs a full pass ----------------------------------
[$rc, $output] = run_cron_runner($runner, $repoRoot, $newDir);
assert_eq(0, $rc, "cron pass at the new path (output: $output)");
assert_true(
    strpos($output, 'setup not complete') === false,
    "relocated install must not look unconfigured, got: $output"
);
assert_true(
    strpos($output, 'Invalid') === false,
    "no auth failure against the relocated copy: $output"
);

// --- 4. State survived the move ----------------------------------------------
// Raw PDO: the harness's Database class is pinned to the ORIGINAL dir via the
// CASHUPAY_DATA_DIR constant, so inspect the copy directly.
$db = new PDO('sqlite:' . $newDir . '/cashupay.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stores = $db->query("SELECT id FROM stores")->fetchAll(PDO::FETCH_COLUMN);
assert_eq(['reloc-store-1'], $stores, 'the store carried over');

$stamp = $db->query(
    "SELECT value FROM config WHERE key = 'last_external_cron_at'"
)->fetchColumn();
assert_true(is_string($stamp) && $stamp !== '', 'cron stamp present in the relocated copy');

// Nothing in config may embed the pre-move absolute path — that is precisely
// what would rot on the next update or folder move.
$rows = $db->query("SELECT key, value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($rows as $key => $value) {
    assert_true(
        strpos((string) $value, $dataDir) === false,
        "config '$key' bakes in the old data dir path: $value"
    );
}

cleanup_db($newDir);
echo "ok\n";
