<?php
/**
 * Regression: every Lightning batch poller must RE-poll an invoice whose
 * last_polled_at stamp has aged past its min interval.
 *
 * All five (pollPendingQuotes / LnAddress / Nwc / Strike / Noffer) throttled
 * with `(? - last_polled_at) >= ?`. PDO binds execute() params as TEXT and an
 * arithmetic expression has no column affinity to coerce them, so SQLite
 * compared integer against text — ALWAYS false. A stamped invoice was never
 * selected again: cron polled each invoice exactly once (while the stamp was
 * NULL) and the "customer paid after cron's first pass and closed the tab"
 * backup silently never ran for any Lightning rail. The payment page's 2s
 * poll masked it for open tabs, and the existing cron e2e tests pay BEFORE
 * the first cron pass, which is why they stayed green.
 *
 * Each poller stamps last_polled_at BEFORE attempting the rail's settlement
 * check ("so we don't re-poll on failure"), so the stamp itself is the
 * observable: an invoice with an aged stamp must come back with a fresh one
 * after the poller runs (the settlement checks themselves fail fast against
 * dead 127.0.0.1:1 endpoints), and an invoice with a fresh stamp must keep
 * it untouched.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/invoice.php';

// Every remote call a poller makes on selection must fail fast, not hang.
putenv('CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:1/v1');

make_store('s1', 'http://127.0.0.1:1'); // dead mint

$AGED = time() - 3600;
$rails = [
    // id => [poller closure, rail-specific columns satisfying its WHERE]
    'inv_mint' => [
        fn() => Invoice::pollPendingQuotes(30, 10),
        ['payment_rail' => 'mint', 'quote_id' => 'q1', 'mint_url' => 'http://127.0.0.1:1'],
    ],
    'inv_lnaddr' => [
        fn() => Invoice::pollPendingLnAddress(30, 10),
        ['payment_rail' => 'lnaddress', 'lnurl_verify_url' => 'http://127.0.0.1:1/verify/x'],
    ],
    'inv_nwc' => [
        fn() => Invoice::pollPendingNwc(15, 10),
        ['payment_rail' => 'nwc', 'nwc_payment_hash' => str_repeat('ab', 32)],
    ],
    'inv_strike' => [
        fn() => Invoice::pollPendingStrike(15, 10),
        ['payment_rail' => 'strike', 'strike_invoice_id' => 'strk-x',
         'strike_api_key' => 'REPOLLKEY0' . str_repeat('A', 30)],
    ],
    'inv_noffer' => [
        fn() => Invoice::pollPendingNoffer(30, 10),
        ['payment_rail' => 'noffer', 'noffer_request_event_id' => str_repeat('cd', 32)],
    ],
];

foreach ($rails as $id => [$poller, $cols]) {
    Database::insert('invoices', array_merge([
        'id' => $id, 'store_id' => 's1', 'status' => 'New',
        'amount' => '1000', 'currency' => 'sat', 'amount_sats' => 1000,
        'bolt11' => 'lnbcmock' . $id,
        'last_polled_at' => $AGED,
        'created_at' => time(), 'expiration_time' => time() + 3600,
    ], $cols));
}

// ---------- aged stamps come due: every poller re-polls its invoice --------
foreach ($rails as $id => [$poller, $cols]) {
    $poller();
    $row = Database::fetchOne("SELECT last_polled_at FROM invoices WHERE id = ?", [$id]);
    assert_true(
        (int)$row['last_polled_at'] >= time() - 10,
        "{$id}: aged invoice was re-polled (stamp refreshed by {$cols['payment_rail']} poller)"
    );
}

// ---------- fresh stamps still throttle: no poller re-stamps ---------------
$stamps = [];
foreach (array_keys($rails) as $id) {
    $row = Database::fetchOne("SELECT last_polled_at FROM invoices WHERE id = ?", [$id]);
    $stamps[$id] = (int)$row['last_polled_at'];
}
foreach ($rails as $id => [$poller, $cols]) {
    $poller();
    $row = Database::fetchOne("SELECT last_polled_at FROM invoices WHERE id = ?", [$id]);
    assert_eq($stamps[$id], (int)$row['last_polled_at'],
        "{$id}: freshly stamped invoice is throttled");
}

putenv('CASHUPAY_STRIKE_API_BASE');
echo "test_lightning_pollers_repoll: ok\n";
