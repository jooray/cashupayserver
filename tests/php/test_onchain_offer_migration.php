<?php
/**
 * Regression: the on-chain offer setting ships a schema migration that adds
 * stores.onchain_offer_enabled. runMigrations() only fires for an already-
 * current install when the marker column in Database::getInstance() is absent,
 * so the marker MUST advance to the newest migration's artifact. When it didn't,
 * existing installs skipped the migration and every Invoice::create() threw
 * "no such column: onchain_offer_enabled" (surfaced as the generic self-serve
 * "Could not create the invoice right now." error).
 *
 * This test simulates a pre-merge install (newest column dropped, old marker
 * still present), drops the connection singleton, and asserts the next
 * connection self-heals — re-adding the column so OnchainConfig works.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/onchain/config.php';

/** Force the next Database call to reconnect and re-run the migration gate. */
function reset_db_singleton(): void {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    $cacheRef = new ReflectionProperty('Config', 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(null, []);
}

function has_col(string $table, string $col): bool {
    $r = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM pragma_table_info('{$table}') WHERE name = ?",
        [$col]
    );
    return (int)($r['n'] ?? 0) > 0;
}

function has_table(string $table): bool {
    $r = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = ?",
        [$table]
    );
    return (int)($r['n'] ?? 0) > 0;
}

// A fresh install has the newest marker artifact (the
// invoices.onchain_last_polled_at column) AND the older migration artifacts.
assert_true(has_col('invoices', 'onchain_last_polled_at'), 'fresh install has the current marker column');
assert_true(has_col('invoices', 'strike_receive_request_id'), 'fresh install has the strike receive-request column');
assert_true(has_col('stores', 'onchain_strike_enabled'), 'fresh install has the strike on-chain flag');
assert_true(has_col('melts', 'strike_invoice_id'), 'fresh install has the previous marker column');
assert_true(has_col('invoices', 'strike_invoice_id'), 'fresh install has the strike receive columns');
assert_true(has_col('invoices', 'strike_api_key'), 'fresh install has the strike key column');
assert_true(has_table('admin_event_log'), 'fresh install has the previous marker table');
assert_true(has_col('stores', 'onchain_offer_enabled'), 'fresh install has onchain_offer_enabled');
assert_true(has_col('invoices', 'receive_errors'), 'fresh install has older marker columns');
assert_true(has_col('melts', 'melt_quote_id'), 'fresh install has older migration artifacts');

// Simulate a pre-merge install: the newest artifacts are missing, but older
// ones are still present — exactly the state of a running instance when it
// pulls code that adds a new migration.
Database::getInstance()->exec("ALTER TABLE invoices DROP COLUMN onchain_last_polled_at");
Database::getInstance()->exec("ALTER TABLE invoices DROP COLUMN strike_receive_request_id");
Database::getInstance()->exec("ALTER TABLE stores DROP COLUMN onchain_strike_enabled");
Database::getInstance()->exec("ALTER TABLE melts DROP COLUMN strike_invoice_id");
Database::getInstance()->exec("ALTER TABLE invoices DROP COLUMN strike_invoice_id");
Database::getInstance()->exec("ALTER TABLE invoices DROP COLUMN strike_api_key");
Database::getInstance()->exec("ALTER TABLE stores DROP COLUMN onchain_offer_enabled");
assert_false(has_col('invoices', 'onchain_last_polled_at'), 'pre-merge: marker column dropped');
assert_false(has_col('stores', 'onchain_offer_enabled'), 'pre-merge: column dropped');
assert_true(has_table('admin_event_log'), 'pre-merge: old marker still present');
assert_true(has_col('melts', 'melt_quote_id'), 'pre-merge: older artifacts still present');

// First DB access after the "deploy" must re-run migrations and re-add them.
reset_db_singleton();
Database::getInstance();
assert_true(has_col('invoices', 'onchain_last_polled_at'), 'getInstance() self-heals the marker column');
assert_true(has_col('invoices', 'strike_receive_request_id'), 'getInstance() self-heals the strike receive-request column');
assert_true(has_col('stores', 'onchain_strike_enabled'), 'getInstance() self-heals the strike on-chain flag');
assert_true(has_col('melts', 'strike_invoice_id'), 'getInstance() self-heals the previous marker column');
assert_true(has_col('invoices', 'strike_invoice_id'), 'getInstance() self-heals the strike receive column');
assert_true(has_col('invoices', 'strike_api_key'), 'getInstance() self-heals the strike key column');
assert_true(has_col('stores', 'onchain_offer_enabled'), 'getInstance() self-heals the missing column');

// And the resolver that Invoice::create() calls now works instead of throwing.
Database::insert('stores', [
    'id' => 'store_offer_mig',
    'name' => 'Offer Mig',
    'mint_unit' => 'sat',
    'created_at' => Database::timestamp(),
]);
assert_true(OnchainConfig::isEnabledForStore('store_offer_mig'), 'OnchainConfig works after heal (default on)');

// Idempotent: a second reconnect makes no further changes and no errors.
reset_db_singleton();
Database::getInstance();
assert_true(has_col('stores', 'onchain_offer_enabled'), 're-run leaves the column in place');
assert_true(has_col('invoices', 'onchain_last_polled_at'), 're-run leaves the marker column in place');

echo "test_onchain_offer_migration: ok\n";
