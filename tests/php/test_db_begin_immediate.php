<?php
/**
 * Database::beginImmediate() must take the write lock UP FRONT (SQLite
 * BEGIN IMMEDIATE semantics), unlike beginTransaction() which is DEFERRED and
 * only locks on the first write. This is what makes "read, decide, then write"
 * flows (allocators, reconcilers, cap checks) safe against interleaving and
 * SQLITE_BUSY lock-upgrade failures.
 *
 * We prove three things:
 *   1. commit()/rollback()/inTransaction() still behave (the helper uses a real
 *      PDO transaction under the hood, not a manual BEGIN that PDO can't track).
 *   2. The write lock is genuinely held from the start: a *second* connection
 *      to the same database file cannot write while the transaction is open,
 *      even though the transaction has not issued any app write yet.
 *   3. user_version is left untouched (an earlier version of the helper
 *      re-wrote it as its lock-grab; the current one uses a no-op DELETE —
 *      see test_db_busy_retry for why the write has to come first).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

// --- 1. transaction semantics ---------------------------------------------
assert_false(Database::inTransaction(), 'no tx initially');
assert_true(Database::beginImmediate(), 'beginImmediate returns true');
assert_true(Database::inTransaction(), 'inTransaction true after beginImmediate');
assert_true(Database::commit(), 'commit works after beginImmediate');
assert_false(Database::inTransaction(), 'no tx after commit');

Database::beginImmediate();
assert_true(Database::rollback(), 'rollback works after beginImmediate');
assert_false(Database::inTransaction(), 'no tx after rollback');

// --- 3. user_version preserved (set a sentinel, then round-trip a tx) ------
$path = Database::getDbPath();
Database::getInstance()->exec('PRAGMA user_version = 4242');
Database::beginImmediate();
Database::commit();
$ver = (int)Database::getInstance()->query('PRAGMA user_version')->fetchColumn();
assert_eq(4242, $ver, 'beginImmediate leaves user_version untouched');

// --- 2. the lock is held up front -----------------------------------------
// A second, independent connection with a zero busy-timeout must fail to write
// while our transaction is open — that can only happen if beginImmediate took
// the RESERVED lock immediately (a DEFERRED begin would NOT have).
$other = new PDO('sqlite:' . $path);
$other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$other->exec('PRAGMA busy_timeout = 0');

// Need a table the other connection can attempt to write. config exists.
Database::beginImmediate();
$blocked = false;
try {
    $other->exec("INSERT INTO config (key, value, created_at, updated_at) VALUES ('lock_probe', '1', 0, 0)");
} catch (Throwable $e) {
    $blocked = (strpos($e->getMessage(), 'locked') !== false || strpos($e->getMessage(), 'busy') !== false);
}
assert_true($blocked, 'second connection is blocked while beginImmediate tx is open');

// After we commit, the other connection can write.
Database::commit();
$other->exec('PRAGMA busy_timeout = 2000');
$other->exec("INSERT INTO config (key, value, created_at, updated_at) VALUES ('lock_probe', '1', 0, 0)");
$cnt = (int)$other->query("SELECT COUNT(*) FROM config WHERE key='lock_probe'")->fetchColumn();
assert_eq(1, $cnt, 'second connection writes after commit');

echo "PASS test_db_begin_immediate\n";
exit(0);
