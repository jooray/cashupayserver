<?php
/**
 * Invoice polling must actually repeat.
 *
 * A live 100 sat payment sat unsettled because of a SQLite typing rule: values are
 * ordered by storage class before value, so every INTEGER is less than every TEXT. PDO
 * sent bound parameters as text, and a WHERE clause whose left side was an *expression*
 * ("? - last_polled_at >= ?") got no column affinity to convert them — so `6 >= '5'` was
 * false. Every repeat poll of an invoice was silently skipped, by both the checkout page
 * and the cron batch poller.
 *
 * Run: php tests/poll_scheduling.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-poll-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/invoice.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
$now = time();
Database::query(
    "INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, wallet_account_id, created_at)
     VALUES ('store_1','Shop','https://mint.example','sat','seed words','wa_1',?)",
    [$now]
);

/** An invoice that is due to be polled, with last_polled_at set as given. */
function makeInvoice(string $id, ?int $lastPolled, string $status = 'New'): void {
    $now = time();
    Database::query(
        "INSERT INTO invoices (id, store_id, status, quote_id, mint_url, amount, currency,
                               expiration_time, created_at, last_polled_at)
         VALUES (?, 'store_1', ?, ?, 'https://mint.example', '100', 'SAT', ?, ?, ?)",
        [$id, $status, 'quote-' . $id, $now + 3600, $now, $lastPolled]
    );
}

// --- The comparison itself ----------------------------------------------------
// This is the exact shape the schedulers rely on.
makeInvoice('inv_a', $now - 60);

$due = Database::fetchAll(
    "SELECT id FROM invoices WHERE last_polled_at IS NULL OR last_polled_at <= ?",
    [$now - 30]
);
check(count($due) === 1, 'an invoice polled 60s ago is due again after 30s');

$notDue = Database::fetchAll(
    "SELECT id FROM invoices WHERE last_polled_at IS NULL OR last_polled_at <= ?",
    [$now - 120]
);
check(count($notDue) === 0, 'an invoice polled 60s ago is not due again after 120s');

// The broken form, kept as documentation of what must never come back: an integer
// expression compared against a bound parameter.
$broken = Database::fetchAll(
    "SELECT id FROM invoices WHERE last_polled_at IS NOT NULL AND (? - last_polled_at) >= ?",
    [$now, 30]
);
check(
    count($broken) === 1,
    'expression-vs-parameter comparison works too, now that integers bind as integers'
);

// --- The claim used by the checkout page --------------------------------------
Database::query("UPDATE invoices SET last_polled_at = ? WHERE id = 'inv_a'", [$now]);

$claim = function (int $at) {
    return Database::query(
        "UPDATE invoices SET last_polled_at = ?
         WHERE id = 'inv_a' AND (last_polled_at IS NULL OR last_polled_at <= ?)",
        [$at, $at - Invoice::MIN_POLL_INTERVAL]
    )->rowCount();
};

check($claim($now) === 0, 'a second poll in the same second is coalesced away');
check($claim($now + 2) === 0, 'a poll 2s later is still coalesced away');
check($claim($now + Invoice::MIN_POLL_INTERVAL) === 1, 'a poll after the window goes through');
check($claim($now + Invoice::MIN_POLL_INTERVAL) === 0, 'and immediately coalesces again');

// --- The batch poller must pick up an invoice it has already polled once -------
Database::query("DELETE FROM invoices");
makeInvoice('inv_fresh', null);
makeInvoice('inv_stale', $now - 3600);
makeInvoice('inv_recent', $now - 1);

$selected = array_column(Database::fetchAll(
    "SELECT id FROM invoices
     WHERE status IN ('New', 'Processing') AND quote_id IS NOT NULL
     AND (last_polled_at IS NULL OR last_polled_at <= ?)
     ORDER BY CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END, last_polled_at ASC",
    [$now - 30]
), 'id');

check(in_array('inv_fresh', $selected, true), 'a never-polled invoice is selected');
check(in_array('inv_stale', $selected, true), 'a previously polled invoice is selected again');
check(!in_array('inv_recent', $selected, true), 'a just-polled invoice is skipped');
check($selected[0] === 'inv_fresh', 'never-polled invoices come first');

// --- Integer parameters must survive binding ----------------------------------
Database::query("UPDATE invoices SET last_polled_at = ? WHERE id = 'inv_fresh'", [1788960303]);
$row = Database::fetchOne("SELECT last_polled_at FROM invoices WHERE id = 'inv_fresh'");
check((int)$row['last_polled_at'] === 1788960303, 'integers round-trip through the query helper');
check(
    Database::fetchOne("SELECT typeof(last_polled_at) t FROM invoices WHERE id = 'inv_fresh'")['t'] === 'integer',
    'and are stored as integers, not text'
);

echo "poll_scheduling: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
