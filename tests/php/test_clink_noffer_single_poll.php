<?php
/**
 * noffer receive-rail recovery tests: the payment-page poll (pollSingleNoffer)
 * re-subscribes to the offer's relay server-side and settles a paid invoice
 * even when the browser's own subscription missed the ephemeral kind-21001
 * receipt. Covers both relay behaviours:
 *   - retention-friendly relay (receipt replayed before EOSE), and
 *   - spec-compliant relay (nothing retained; receipt arrives live AFTER
 *     EOSE, caught only because fetchReceipt keeps a live-listen window).
 * Also: the CAS min-interval gate, expiry, the pollSingleQuote dispatch, and
 * that the pre-existing hang-up-at-EOSE behaviour (cron batch mode) misses the
 * live receipt — documenting why the live window exists.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/clink/client.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use swentel\nostr\Key\Key;

$key = new Key();
$mSk = $key->generatePrivateKey();           // merchant (receiver) identity
$mPk = $key->getPublicKey($mSk);

$store = 'store_noffer_poll';
make_store($store);

/** Start the mock relay/merchant; returns [proc, port]. */
function start_relay(array $env): array {
    static $seq = 0;
    $base = 27300 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_CLINK_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/mock_clink_relay.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail('mock relay failed to start on any port');
}

function insert_noffer_invoice(string $id, string $store, string $relay, string $mPk, array $overrides = []): array {
    $key = new Key();
    $eSk = $key->generatePrivateKey();       // payer ephemeral identity (ours)
    $ePk = $key->getPublicKey($eSk);
    $reqId = bin2hex(random_bytes(32));
    Database::insert('invoices', array_merge([
        'id' => $id,
        'store_id' => $store,
        'status' => 'New',
        'additional_status' => 'None',
        'amount' => '21',
        'currency' => 'sat',
        'amount_sats' => 21,
        'bolt11' => 'lnbc210n1mockinvoice0000000',
        'payment_rail' => 'noffer',
        'ln_destination' => 'noffer test',
        'noffer_relay' => $relay,
        'noffer_receiver_pubkey' => $mPk,
        'noffer_ephemeral_sk' => $eSk,
        'noffer_ephemeral_pubkey' => $ePk,
        'noffer_request_event_id' => $reqId,
        'noffer_created_at' => time() - 10,
        'created_at' => time(),
        'expiration_time' => time() + 3600,
    ], $overrides));
    return ['ephemeral_sk' => $eSk, 'ephemeral_pubkey' => $ePk, 'request_event_id' => $reqId];
}

// ---------- retention-friendly relay: receipt replayed before EOSE ----------
[$proc, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_SEND_RECEIPT' => '1',
]);
$relay = "ws://127.0.0.1:$port";
try {
    insert_noffer_invoice('inv_nof_1', $store, $relay, $mPk);
    Invoice::pollSingleNoffer('inv_nof_1');
    $inv = Invoice::getById('inv_nof_1');
    assert_eq('Settled', $inv['status'], 'retained receipt settles via page poll');
    assert_eq('noffer', $inv['settled_rail'], 'settled_rail recorded');
    assert_true((int)$inv['paid_at'] > 0, 'paid_at stamped');

    // Idempotent: a second poll must not error or double-fire.
    Invoice::pollSingleNoffer('inv_nof_1');
    assert_eq('Settled', Invoice::getById('inv_nof_1')['status'], 'second poll is a no-op');

    // ---------- CAS throttle: a just-polled invoice skips the relay ----------
    insert_noffer_invoice('inv_nof_2', $store, $relay, $mPk, ['last_polled_at' => time()]);
    Invoice::pollSingleNoffer('inv_nof_2');
    assert_eq('New', Invoice::getById('inv_nof_2')['status'], 'inside min-interval: no relay contact, stays New');
    Database::update('invoices', ['last_polled_at' => time() - 30], 'id = ?', ['inv_nof_2']);
    Invoice::pollSingleNoffer('inv_nof_2');
    assert_eq('Settled', Invoice::getById('inv_nof_2')['status'], 'after min-interval: settles');

    // ---------- pollSingleQuote dispatches the noffer rail ----------
    insert_noffer_invoice('inv_nof_3', $store, $relay, $mPk);
    Invoice::pollSingleQuote('inv_nof_3');
    assert_eq('Settled', Invoice::getById('inv_nof_3')['status'],
        'checkout tick (pollSingleQuote) settles a noffer invoice');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- spec-compliant relay: receipt only arrives live, after EOSE ----------
[$proc, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_SEND_RECEIPT' => '1',
    'MOCK_CLINK_RECEIPT_AFTER_EOSE' => '1',
]);
$relay = "ws://127.0.0.1:$port";
try {
    // The cron-style hang-up-at-EOSE read misses the live receipt…
    $ids = insert_noffer_invoice('inv_nof_4', $store, $relay, $mPk);
    $ctx = Invoice::nofferCtxFromInvoice(Invoice::getById('inv_nof_4'));
    assert_not_null($ctx, 'ctx rebuilt from the invoice row');
    $res = ClinkClient::fetchReceipt($ctx, 3, false);
    assert_false(!empty($res['paid']), 'hang-up-at-EOSE read misses a live-only receipt');

    // …while the page poll's live-listen window catches it.
    Invoice::pollSingleNoffer('inv_nof_4');
    assert_eq('Settled', Invoice::getById('inv_nof_4')['status'],
        'live-listen window catches the after-EOSE receipt');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- expired invoice flips to Expired without touching the relay ----------
insert_noffer_invoice('inv_nof_5', $store, 'ws://127.0.0.1:1', $mPk,
    ['expiration_time' => time() - 10]);
Invoice::pollSingleNoffer('inv_nof_5');
assert_eq('Expired', Invoice::getById('inv_nof_5')['status'], 'expired invoice marked Expired');

// ---------- unreachable relay leaves the invoice open (no throw) ----------
insert_noffer_invoice('inv_nof_6', $store, 'ws://127.0.0.1:1', $mPk);
Invoice::pollSingleNoffer('inv_nof_6');
assert_eq('New', Invoice::getById('inv_nof_6')['status'], 'unreachable relay leaves invoice New');

echo "test_clink_noffer_single_poll: ok\n";
