<?php
/**
 * Config::set() must be an atomic upsert, not a SELECT-then-INSERT/UPDATE. The
 * old form could throw a PRIMARY KEY violation when two requests set the same
 * brand-new key at the same instant (both miss the SELECT, both INSERT). It
 * must also preserve created_at while refreshing value/updated_at.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// New key: stored and readable.
Config::set('race_key', 'first');
assert_eq('first', Config::get('race_key'), 'new key set');

// Exactly one row.
$rows = Database::fetchAll("SELECT * FROM config WHERE key = 'race_key'");
assert_eq(1, count($rows), 'one row after first set');
$createdAt = (int)$rows[0]['created_at'];

// Simulate the concurrent-create losing path: a second connection inserts the
// SAME new key out from under us, then our Config::set runs. The old code would
// have already decided "INSERT" and thrown on the PK; the upsert must absorb it
// and end up as an UPDATE.
$other = new PDO('sqlite:' . Database::getDbPath());
$other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$other->exec('PRAGMA busy_timeout = 2000');
$other->exec("INSERT INTO config (key, value, created_at, updated_at) VALUES ('concurrent_key', '\"theirs\"', 100, 100)");

$threw = false;
try {
    Config::set('concurrent_key', 'ours');
} catch (Throwable $e) {
    $threw = true;
}
assert_false($threw, 'Config::set does not throw when the key was created concurrently');
// Config::set stores string values verbatim (only non-strings are json_encoded).
$row = Database::fetchOne("SELECT * FROM config WHERE key = 'concurrent_key'");
assert_eq('ours', $row['value'], 'upsert overwrote the concurrently-created value');
assert_eq(100, (int)$row['created_at'], 'upsert preserved the original created_at');

// Plain update path preserves created_at too.
Config::set('race_key', 'second');
$row = Database::fetchOne("SELECT * FROM config WHERE key = 'race_key'");
assert_eq('second', $row['value'], 'value updated on second set');
assert_eq($createdAt, (int)$row['created_at'], 'created_at preserved across update');

echo "PASS test_config_set_upsert\n";
exit(0);
