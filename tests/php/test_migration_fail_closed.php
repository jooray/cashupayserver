<?php
/**
 * A failed migration must not look like a fresh install (MF-1).
 *
 * Database::getInstance() used to cache the PDO handle BEFORE the migration
 * gate ran, so a failing ALTER TABLE (locked / read-only / full disk on the
 * first request after an upgrade) left a half-migrated handle in the
 * singleton: the first isInitialized() saw the throw and said false, the
 * second reused the cached handle and said true, and setup.php opened the
 * first-run wizard on a live install.
 *
 * Now: getInstance() throws and caches nothing, remembers the error;
 * isInitialized() falls back to a direct file probe (an existing, unreadable
 * database counts as existing); Config::isSetupComplete() rethrows instead
 * of answering "not configured". Self-heal still works once the cause is
 * fixed. Requires a non-root user (chmod does not bind root).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "skip: running as root, read-only simulation impossible\n";
    exit(0);
}

Auth::setAdminPassword('migration-test-pw');
assert_true(Config::isSetupComplete(), 'precondition: configured install');

function reset_db_singleton(): void {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    $cacheRef = new ReflectionProperty('Config', 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(null, []);
}
function instance_is_null(): bool {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    return $ref->getValue() === null;
}
function has_col(string $table, string $col): bool {
    $r = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM pragma_table_info('{$table}') WHERE name = ?",
        [$col]
    );
    return (int)($r['n'] ?? 0) > 0;
}

// Simulate "code upgraded, migration pending": drop the marker column so the
// next connection must run migrations. Fold the WAL back into the main file
// so the read-only simulation has a single file to lock down.
$pdo = Database::getInstance();
$pdo->exec("ALTER TABLE stores DROP COLUMN wallet_tabs");
$pdo->exec("PRAGMA wal_checkpoint(TRUNCATE)");
$pdo->exec("PRAGMA journal_mode = DELETE");
unset($pdo);
reset_db_singleton();

$dbPath = Database::getDbPath();
chmod($dbPath, 0444);
chmod($dir, 0555);
try {
    // getInstance() throws and caches nothing.
    $threw = null;
    try {
        Database::getInstance();
    } catch (\Throwable $e) {
        $threw = $e;
    }
    assert_not_null($threw, 'getInstance throws when the migration cannot run');
    assert_true(instance_is_null(), 'the half-migrated handle is NOT cached');
    assert_not_null(Database::getMigrationError(), 'the failure is remembered');

    // A second call in the same request behaves the same (no cached handle).
    $threw = null;
    try {
        Database::getInstance();
    } catch (\Throwable $e) {
        $threw = $e;
    }
    assert_not_null($threw, 'second getInstance throws too');

    // isInitialized() fails closed: the database exists.
    assert_true(Database::isInitialized(), 'isInitialized is TRUE on a wedged existing install');
    assert_true(Database::hasExistingInstallation(), 'direct probe sees the config table');

    // Config reads rethrow rather than pretending the install is unconfigured.
    $threw = false;
    try {
        Config::isSetupComplete();
    } catch (\Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'Config::isSetupComplete throws instead of returning false');
} finally {
    chmod($dir, 0750);
    chmod($dbPath, 0640);
}

// Self-heal: once writable again, the next connection runs the migration.
reset_db_singleton();
$pdo = Database::getInstance();
assert_null(Database::getMigrationError(), 'error cleared after a successful gate');
assert_true(has_col('stores', 'wallet_tabs'), 'marker column re-added by the migration');
assert_true(Config::isSetupComplete(), 'install reads as configured again');

// hasExistingInstallation(): false only when there is no database file at all.
$emptyDir = sys_get_temp_dir() . '/cashupay_test_empty_' . bin2hex(random_bytes(4));
mkdir($emptyDir, 0750, true);
$ref = new ReflectionProperty('Database', 'dbPath');
$ref->setAccessible(true);
$savedPath = $ref->getValue();
$ref->setValue(null, $emptyDir . '/cashupay.sqlite');
assert_false(Database::hasExistingInstallation(), 'no file -> no installation');
file_put_contents($emptyDir . '/cashupay.sqlite', 'not a database');
assert_true(Database::hasExistingInstallation(), 'unreadable-but-present -> treated as existing (fail closed)');
$ref->setValue(null, $savedPath);
@unlink($emptyDir . '/cashupay.sqlite');
@rmdir($emptyDir);

echo "ok\n";
