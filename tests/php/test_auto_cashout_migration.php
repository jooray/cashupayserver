<?php
/**
 * The "auto-withdraw" → "auto-cashout" rename ships a data-only migration:
 *   - the notifications toggle config key is renamed (value carried forward),
 *   - in-flight notification_queue / notification_log rows are re-labeled from
 *     the AutoWithdraw* event names to AutoCashout*.
 *
 * runMigrations() only fires for an already-current install when the gate in
 * Database::getInstance() detects the legacy config key, so this test seeds a
 * legacy DB, drops the connection singleton, and asserts the next connection
 * performs the migration (and that a second run is a no-op).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

/** Force the next Database call to reconnect and re-run the migration gate. */
function reset_db_singleton(): void {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    // Config caches values per-process; clear it so reads hit the fresh DB.
    $cacheRef = new ReflectionProperty('Config', 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(null, []);
}

$now = Database::timestamp();

// --- Seed a legacy install: old config key + old-named notification rows. ---
Database::insert('config', [
    'key' => 'notifications_auto_withdraw_enabled',
    'value' => json_encode(true),
    'created_at' => $now,
    'updated_at' => $now,
]);

// A pending success email and a failure email, both under the old event names.
Database::insert('notification_queue', [
    'store_id' => 'store_mig',
    'event_type' => 'AutoWithdrawSuccess',
    'to_email' => 'ops@example.com',
    'subject' => 'old success',
    'body' => 'body',
    'dedupe_key' => null,
    'created_at' => $now,
]);
Database::insert('notification_queue', [
    'store_id' => 'store_mig',
    'event_type' => 'AutoWithdrawFailure',
    'to_email' => 'ops@example.com',
    'subject' => 'old failure',
    'body' => 'body',
    'dedupe_key' => 'dk1',
    'created_at' => $now,
]);
// An unrelated event must be left untouched.
Database::insert('notification_queue', [
    'store_id' => 'store_mig',
    'event_type' => 'InvoicePaid',
    'to_email' => 'ops@example.com',
    'subject' => 'paid',
    'body' => 'body',
    'dedupe_key' => null,
    'created_at' => $now,
]);
// A dedupe-log row under the old failure event name.
Database::insert('notification_log', [
    'store_id' => 'store_mig',
    'event_type' => 'AutoWithdrawFailure',
    'dedupe_key' => 'dk1',
    'sent_at' => $now,
]);

// --- Trigger the migration by reconnecting. ---
reset_db_singleton();
Database::getInstance();

// --- Config key carried forward, legacy key removed. ---
$legacy = Database::fetchOne(
    "SELECT value FROM config WHERE key = ?",
    ['notifications_auto_withdraw_enabled']
);
assert_null($legacy, 'legacy config key removed after migration');

$migrated = Database::fetchOne(
    "SELECT value FROM config WHERE key = ?",
    ['notifications_auto_cashout_enabled']
);
assert_not_null($migrated, 'new config key created by migration');
assert_eq(true, json_decode($migrated['value'], true), 'toggle value carried forward');

// --- Notification rows re-labeled, unrelated rows untouched. ---
function event_count(string $event): int {
    $row = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM notification_queue WHERE event_type = ?",
        [$event]
    );
    return (int)$row['n'];
}
assert_eq(0, event_count('AutoWithdrawSuccess'), 'no legacy success rows remain');
assert_eq(0, event_count('AutoWithdrawFailure'), 'no legacy failure rows remain');
assert_eq(1, event_count('AutoCashoutSuccess'), 'success row re-labeled');
assert_eq(1, event_count('AutoCashoutFailure'), 'failure row re-labeled');
assert_eq(1, event_count('InvoicePaid'), 'unrelated event untouched');

$logRow = Database::fetchOne(
    "SELECT COUNT(*) AS n FROM notification_log WHERE event_type = ?",
    ['AutoCashoutFailure']
);
assert_eq(1, (int)$logRow['n'], 'dedupe-log row re-labeled (48h window preserved)');

// --- Idempotent: a second connection makes no further changes and no errors. ---
reset_db_singleton();
Database::getInstance();
assert_eq(1, event_count('AutoCashoutSuccess'), 're-run leaves success row as-is');
assert_eq(1, event_count('AutoCashoutFailure'), 're-run leaves failure row as-is');
$legacyAgain = Database::fetchOne(
    "SELECT value FROM config WHERE key = ?",
    ['notifications_auto_withdraw_enabled']
);
assert_null($legacyAgain, 're-run does not resurrect legacy key');

echo "test_auto_cashout_migration: ok\n";
