<?php
/**
 * Database::retryOnBusy / query() / beginImmediate(): a lock wait that runs
 * out is retried before the caller sees SQLITE_BUSY — but only where a
 * repeat is safe (autocommit statements, the lock grab at the start of a
 * transaction), never inside an open transaction.
 *
 * The lock holder is a child PHP process (a second connection in THIS
 * process would be arbitrated by SQLite's in-process lock table and never
 * contend). It takes BEGIN IMMEDIATE, sleeps, commits. We shrink our own
 * busy_timeout so the first attempt gives up before the child releases and
 * the retry is what carries the write through.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dir = fresh_db();
$dbPath = Database::getDbPath();

/** Spawn a child that holds the write lock for $holdMs; returns the handle. */
function hold_lock_in_child(string $dbPath, int $holdMs): array {
    $code = sprintf(
        '$p = new PDO("sqlite:%s"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
        . '$p->exec("PRAGMA busy_timeout = 5000"); $p->exec("BEGIN IMMEDIATE");'
        . '$p->exec("UPDATE config SET updated_at = updated_at WHERE key = \'setup_complete\'");'
        . 'echo "LOCKED\n"; fflush(STDOUT); usleep(%d); $p->exec("COMMIT"); echo "RELEASED\n";',
        addslashes($dbPath), $holdMs * 1000
    );
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        fail('could not spawn lock-holder child');
    }
    // Wait until the child reports it holds the lock.
    $line = fgets($pipes[1]);
    assert_eq("LOCKED\n", $line, 'child took the write lock');
    return ['proc' => $proc, 'pipes' => $pipes];
}

function finish_child(array $child): void {
    stream_get_contents($child['pipes'][1]);
    stream_get_contents($child['pipes'][2]);
    proc_close($child['proc']);
}

// --- isBusyError classification -------------------------------------------
$busy = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
$busy->errorInfo = ['HY000', 5, 'database is locked'];
assert_true(Database::isBusyError($busy), 'SQLITE_BUSY (5) classified as busy');
$locked = new PDOException('SQLSTATE[HY000]: General error: 6 database table is locked');
$locked->errorInfo = ['HY000', 6, 'database table is locked'];
assert_true(Database::isBusyError($locked), 'SQLITE_LOCKED (6) classified as busy');
$msgOnly = new PDOException('SQLSTATE[HY000]: General error: database is locked');
assert_true(Database::isBusyError($msgOnly), 'message-only lock error classified as busy');
$constraint = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed');
$constraint->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];
assert_false(Database::isBusyError($constraint), 'constraint violation is not busy');
assert_false(Database::isBusyError(new RuntimeException('database is locked')), 'non-PDO exception is not busy');

// --- retryOnBusy: a non-busy error is never retried -----------------------
$calls = 0;
try {
    Database::retryOnBusy(function () use (&$calls) { $calls++; throw new RuntimeException('boom'); });
    fail('non-busy exception should propagate');
} catch (RuntimeException $e) {
    assert_eq(1, $calls, 'non-busy error: exactly one attempt');
}

// --- retryOnBusy: busy errors are retried up to the limit then rethrown ---
$calls = 0;
$before = Database::busyRetryCount();
try {
    Database::retryOnBusy(function () use (&$calls, $busy) { $calls++; throw $busy; });
    fail('exhausted busy should propagate');
} catch (PDOException $e) {
    assert_eq(Database::BUSY_RETRY_ATTEMPTS, $calls, 'busy error: attempted BUSY_RETRY_ATTEMPTS times');
    assert_eq($before + Database::BUSY_RETRY_ATTEMPTS - 1, Database::busyRetryCount(), 'retry counter advanced per retry');
}

// --- retryOnBusy: success on a later attempt returns the value ------------
$calls = 0;
$v = Database::retryOnBusy(function () use (&$calls, $busy) {
    $calls++;
    if ($calls < 2) { throw $busy; }
    return 'done';
});
assert_eq('done', $v, 'value returned once an attempt succeeds');
assert_eq(2, $calls, 'succeeded on the second attempt');

// --- real contention: autocommit statement rides through a retry ---------
// Child holds the lock ~900 ms; our busy_timeout is 300 ms, so attempt 1
// (and probably 2) time out and a later attempt lands after the release.
Database::getInstance()->exec('PRAGMA busy_timeout = 300');
$child = hold_lock_in_child($dbPath, 900);
$before = Database::busyRetryCount();
$t0 = microtime(true);
Database::query(
    "INSERT INTO config (key, value, created_at, updated_at) VALUES ('busy_probe', '1', 0, 0)"
);
$elapsed = microtime(true) - $t0;
finish_child($child);
assert_true(Database::busyRetryCount() > $before, 'autocommit write needed at least one retry');
assert_true($elapsed >= 0.3, 'first attempt genuinely waited out busy_timeout');
assert_eq(
    '1',
    (string) Database::fetchOne("SELECT value FROM config WHERE key = 'busy_probe'")['value'],
    'the retried write landed exactly once'
);

// --- real contention: beginImmediate's lock grab is retried ---------------
$child = hold_lock_in_child($dbPath, 900);
$before = Database::busyRetryCount();
Database::beginImmediate();
assert_true(Database::inTransaction(), 'beginImmediate succeeded after the child released');
Database::update('config', ['value' => '2'], 'key = ?', ['busy_probe']);
Database::commit();
finish_child($child);
assert_true(Database::busyRetryCount() > $before, 'beginImmediate needed at least one retry');
assert_eq(
    '2',
    (string) Database::fetchOne("SELECT value FROM config WHERE key = 'busy_probe'")['value'],
    'write inside the retried transaction committed'
);

// --- inside an open transaction: NO retry, the error reaches the caller ---
// A deferred transaction that already read must not have its first write
// silently re-run: the caller's rollback is the only correct outcome.
$child = hold_lock_in_child($dbPath, 1500);
$before = Database::busyRetryCount();
Database::beginTransaction();
Database::fetchOne("SELECT value FROM config WHERE key = 'busy_probe'");
$t0 = microtime(true);
$threw = false;
try {
    Database::update('config', ['value' => '3'], 'key = ?', ['busy_probe']);
} catch (PDOException $e) {
    $threw = Database::isBusyError($e);
}
$elapsed = microtime(true) - $t0;
Database::rollback();
finish_child($child);
assert_true($threw, 'write inside an open transaction surfaced the busy error');
assert_eq($before, Database::busyRetryCount(), 'no retry was attempted inside the transaction');
assert_true($elapsed < 1.2, 'in-transaction failure was not stretched by retries');
assert_eq(
    '2',
    (string) Database::fetchOne("SELECT value FROM config WHERE key = 'busy_probe'")['value'],
    'rolled-back write did not land'
);

// --- exhausted retries still surface the error --------------------------
Database::getInstance()->exec('PRAGMA busy_timeout = 100');
$child = hold_lock_in_child($dbPath, 2500);
$before = Database::busyRetryCount();
$threw = false;
try {
    Database::query("UPDATE config SET value = '4' WHERE key = 'busy_probe'");
} catch (PDOException $e) {
    $threw = Database::isBusyError($e);
}
finish_child($child);
assert_true($threw, 'a lock held longer than every attempt still throws busy');
assert_eq($before + Database::BUSY_RETRY_ATTEMPTS - 1, Database::busyRetryCount(), 'all retries were spent first');
Database::getInstance()->exec('PRAGMA busy_timeout = 5000');

echo "PASS test_db_busy_retry\n";
exit(0);
