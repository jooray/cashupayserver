<?php
/**
 * includes/locks.php — the per-store funds lock every money-moving path takes
 * (manual withdraw, export, reclaim, cron auto-cashout, fee melt).
 *
 *   - a second acquire of the same name while the first is held returns null
 *     (flock locks are per open file description, so this holds in-process too);
 *   - releasing makes it acquirable again;
 *   - a different store's lock is unaffected;
 *   - the name is sanitised: a store id with path characters cannot escape the
 *     data directory.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/locks.php';

$a = cashupay_acquire_lock('funds_s1');
assert_true(is_resource($a), 'first acquire succeeds');
assert_null(cashupay_acquire_lock('funds_s1'), 'second acquire while held returns null');

$b = cashupay_acquire_lock('funds_s2');
assert_true(is_resource($b), 'another store is unaffected');
cashupay_release_lock($b);

cashupay_release_lock($a);
$a2 = cashupay_acquire_lock('funds_s1');
assert_true(is_resource($a2), 're-acquirable after release');
cashupay_release_lock($a2);
cashupay_release_lock($a2); // double release is harmless
cashupay_release_lock(null); // null is harmless

// Sanitised path: next to the DB, no traversal.
$path = cashupay_lock_path('funds_../../etc/passwd');
assert_eq(dirname(Database::getDbPath()) . '/.funds_etcpasswd.lock', $path, 'lock name is sanitised');
assert_true(str_starts_with(realpath(dirname($path)) ?: '', realpath($dataDir) ?: '/nonexistent'), 'lock lives in the data dir');

fwrite(STDERR, "test_funds_lock: all assertions passed\n");
