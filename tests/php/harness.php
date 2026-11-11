<?php
/**
 * Tiny test harness for the PHP unit tests. Each `test_*.php` file in this
 * directory is one test case, run as its own PHP subprocess by run.php.
 *
 * The harness provides assertion helpers and a fresh_db() bootstrap that
 * sets CASHUPAY_DATA_DIR to a per-process tempdir and initializes the schema.
 */

declare(strict_types=1);

// The PHP test suite uses local mock HTTP servers on 127.0.0.1 — LNURL
// hosts, mint stubs, updater simulators. SafeHttp's default posture
// rejects loopback to defeat SSRF in production; the test harness has to
// flip the same opt-in flag tests/fixtures/swap_stack.py uses.
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');

function fail(string $msg): void {
    fwrite(STDERR, $msg . "\n");
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
    exit(1);
}

function assert_true(bool $cond, string $msg = ''): void {
    if (!$cond) {
        fail('assert_true failed: ' . $msg);
    }
}

function assert_false(bool $cond, string $msg = ''): void {
    if ($cond) {
        fail('assert_false failed: ' . $msg);
    }
}

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        fail(sprintf(
            "assert_eq failed (%s): expected %s, got %s",
            $msg,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_neq($notExpected, $actual, string $msg = ''): void {
    if ($notExpected === $actual) {
        fail(sprintf(
            "assert_neq failed (%s): both are %s",
            $msg,
            var_export($actual, true)
        ));
    }
}

function assert_null($actual, string $msg = ''): void {
    if ($actual !== null) {
        fail(sprintf(
            "assert_null failed (%s): got %s",
            $msg,
            var_export($actual, true)
        ));
    }
}

function assert_not_null($actual, string $msg = ''): void {
    if ($actual === null) {
        fail('assert_not_null failed: ' . $msg);
    }
}

/**
 * Set up a fresh on-disk SQLite database in a tempdir; define
 * CASHUPAY_DATA_DIR to point at it. Returns the dir path.
 */
function fresh_db(): string {
    if (defined('CASHUPAY_DATA_DIR')) {
        fail('fresh_db must be called before CASHUPAY_DATA_DIR is defined');
    }
    $dir = sys_get_temp_dir() . '/cashupay_test_' . bin2hex(random_bytes(6));
    mkdir($dir, 0750, true);
    define('CASHUPAY_DATA_DIR', $dir);
    // Never let a unit test ask the live BareBits endpoint for a dev-fee
    // branch (includes/fee_branch.php). Tests that exercise the client point
    // this at a local php -S copy of scripts/xpub-endpoint/xpub.php BEFORE
    // calling fresh_db().
    if (getenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT') === false) {
        putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled');
    }
    // Same for the opt-in usage-statistics report (includes/usage_stats.php).
    if (getenv('CASHUPAY_USAGE_STATS_ENDPOINT') === false) {
        putenv('CASHUPAY_USAGE_STATS_ENDPOINT=disabled');
    }

    require_once dirname(__DIR__, 2) . '/app/includes/database.php';
    Database::ensureExists();
    Database::initialize();
    // Mark setup_complete so cron-like flows don't bail.
    require_once dirname(__DIR__, 2) . '/app/includes/config.php';
    Config::set('setup_complete', true);
    register_shutdown_function(function() use ($dir) {
        @cleanup_db($dir);
    });
    return $dir;
}

function cleanup_db(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Create a store with the given id and minimum required fields. */
function make_store(string $id, ?string $primaryMint = null, string $unit = 'sat', string $source = 'manual'): void {
    Database::insert('stores', [
        'id' => $id,
        'name' => 'test ' . $id,
        'mint_url' => $primaryMint,
        'mint_unit' => $unit,
        'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
        'primary_mint_source' => $source,
        'created_at' => Database::timestamp(),
    ]);
}

/** Add a backup mint row directly (skipping Config::addStoreBackupMint deduplication). */
function add_backup_mint(string $storeId, string $mintUrl, int $priority = 100, string $unit = 'sat'): void {
    Database::insert('store_mints', [
        'store_id' => $storeId,
        'mint_url' => rtrim($mintUrl, '/'),
        'unit' => $unit,
        'priority' => $priority,
        'enabled' => 1,
        'created_at' => Database::timestamp(),
    ]);
}
