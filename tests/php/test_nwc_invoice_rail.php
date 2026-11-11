<?php
/**
 * NWC receive-rail settlement tests against the mock wallet: the payment-page
 * poll (pollSingleNwc) settles a paid invoice with the wallet's preimage and
 * fires the settle exactly once; its CAS min-interval gate keeps the 2s
 * checkout tick from hammering the relay; the cron batch poll (pollPendingNwc)
 * settles an invoice nobody is watching; NOT_FOUND and pending lookups leave
 * the invoice open; and expiry closes it out.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use swentel\nostr\Key\Key;

$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();

$store = 'store_nwc_rail';
make_store($store);

/** Start the mock wallet; returns [proc, port]. */
function start_wallet(array $env): array {
    static $seq = 0;
    $base = 28600 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_NWC_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/php/mock_nwc_wallet.php'],
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
    fail('mock NWC wallet failed to start on any port');
}

function insert_nwc_invoice(string $id, string $store, string $uri, string $hash, array $overrides = []): void {
    Database::insert('invoices', array_merge([
        'id' => $id,
        'store_id' => $store,
        'status' => 'New',
        'additional_status' => 'None',
        'amount' => '21',
        'currency' => 'sat',
        'amount_sats' => 21,
        'bolt11' => 'lnbc210n1mockinvoice0000000',
        'payment_rail' => 'nwc',
        'ln_destination' => 'NWC wallet test… via 127.0.0.1',
        'nwc_uri' => $uri,
        'nwc_payment_hash' => $hash,
        'created_at' => time(),
        'expiration_time' => time() + 3600,
    ], $overrides));
}

$hash = hash('sha256', 'inv-under-test');
$preimage = str_repeat('ef', 32);

// ---------- pollSingleNwc settles a paid invoice ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'settled',
    'MOCK_NWC_PREIMAGE' => $preimage,
]);
$uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
try {
    insert_nwc_invoice('inv_nwc_1', $store, $uri, $hash);
    Invoice::pollSingleNwc('inv_nwc_1');
    $inv = Invoice::getById('inv_nwc_1');
    assert_eq('Settled', $inv['status'], 'paid invoice settled by page poll');
    assert_eq('nwc', $inv['settled_rail'], 'settled_rail recorded');
    assert_eq($preimage, $inv['nwc_preimage'], 'preimage stored as proof');
    assert_true((int)$inv['paid_at'] > 0, 'paid_at stamped');

    // Idempotent: a second poll (still settled per wallet) must not error or
    // double-fire.
    Invoice::pollSingleNwc('inv_nwc_1');
    assert_eq('Settled', Invoice::getById('inv_nwc_1')['status'], 'second poll is a no-op');

    // ---------- CAS throttle: a just-polled invoice skips the relay ----------
    insert_nwc_invoice('inv_nwc_2', $store, $uri, $hash, ['last_polled_at' => time()]);
    Invoice::pollSingleNwc('inv_nwc_2');
    assert_eq('New', Invoice::getById('inv_nwc_2')['status'], 'inside min-interval: no relay contact, stays New');
    // Age the claim and poll again — now it settles.
    Database::update('invoices', ['last_polled_at' => time() - 30], 'id = ?', ['inv_nwc_2']);
    Invoice::pollSingleNwc('inv_nwc_2');
    assert_eq('Settled', Invoice::getById('inv_nwc_2')['status'], 'after min-interval: settles');

    // ---------- cron batch poll settles an unwatched invoice ----------
    insert_nwc_invoice('inv_nwc_3', $store, $uri, $hash);
    Invoice::pollPendingNwc(0, 10);
    assert_eq('Settled', Invoice::getById('inv_nwc_3')['status'], 'cron poll settles');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- pending wallet state leaves the invoice open ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'pending',
]);
$uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
try {
    insert_nwc_invoice('inv_nwc_4', $store, $uri, $hash);
    Invoice::pollSingleNwc('inv_nwc_4');
    assert_eq('New', Invoice::getById('inv_nwc_4')['status'], 'pending lookup leaves invoice New');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- NOT_FOUND leaves the invoice open (expiry closes it later) ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'notfound',
]);
$uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
try {
    insert_nwc_invoice('inv_nwc_5', $store, $uri, $hash);
    Invoice::pollSingleNwc('inv_nwc_5');
    assert_eq('New', Invoice::getById('inv_nwc_5')['status'], 'NOT_FOUND leaves invoice New');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- expiry: within the confirm window the wallet is asked first; a
// dead relay cannot confirm "unpaid", so the invoice stays open. Past the
// window it flips to Expired without touching the relay. ----------
insert_nwc_invoice('inv_nwc_6', $store, 'nostr+walletconnect://' . $walletPk
    . '?relay=ws://127.0.0.1:1/&secret=' . $clientSk, $hash,
    ['expiration_time' => time() - 10]);
Invoice::pollSingleNwc('inv_nwc_6');
assert_eq('New', Invoice::getById('inv_nwc_6')['status'], 'just-expired invoice stays New while the relay cannot answer');
Database::update('invoices', ['expiration_time' => time() - Invoice::EXPIRY_CONFIRM_WINDOW - 10], 'id = ?', ['inv_nwc_6']);
Invoice::pollSingleNwc('inv_nwc_6');
assert_eq('Expired', Invoice::getById('inv_nwc_6')['status'], 'expired invoice marked Expired past the confirm window');

echo "test_nwc_invoice_rail: ok\n";
