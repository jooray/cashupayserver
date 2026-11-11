<?php
/**
 * Invoice::create for Lightning-destination-only stores (no mint, no xpub) —
 * the wizard's "skip on-chain, skip mints, add NWC/noffer" outcome, which the
 * old rails gate rejected with "Store has no payment methods configured"
 * (surfacing as a failed WooCommerce checkout):
 *
 *   1. A store whose ONLY payment rail is an NWC connection creates invoices:
 *      the BOLT11 comes from make_invoice, payment_rail='nwc'.
 *   2. When every destination is dead (wallet offline), creation fails with a
 *      clear error INSTEAD of inserting an unpayable invoice (null bolt11, no
 *      on-chain address), and no invoice row is left behind.
 *   3. A store with no rails at all still gets the configuration error.
 *   4. Owed fees never disable the sole Lightning rail: when the fee-redirect
 *      path can't claim the invoice (fee destination unreachable), the
 *      merchant's destination still serves and the fees simply stay owed —
 *      collected by fee-redirect on a later invoice it CAN claim. (The old
 *      fees-due override, which parked such payments on the mint rail, was
 *      removed outright — direct fee routing and the cron's mint-balance
 *      settle are the only collection paths.)
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

use swentel\nostr\Key\Key;

$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();

/** Start a mock nwc wallet subprocess; returns [proc, port]. */
function start_mock(string $script, string $portVar, array $env): array {
    static $seq = 0;
    $base = 29900 + (getmypid() % 800) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, [$portVar => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/php/' . $script],
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
    fail("mock {$script} failed to start on any port");
}

/** Insert a 'Settled' invoice so DevFee::computeOwed sees it as revenue. */
function paid_invoice(string $storeId, int $sats): void {
    Database::insert('invoices', [
        'id' => 'inv_' . bin2hex(random_bytes(4)),
        'store_id' => $storeId,
        'status' => 'Settled',
        'amount' => (string) $sats,
        'currency' => 'sat',
        'amount_sats' => $sats,
        'created_at' => time(),
        'expiration_time' => time() + 3600,
    ]);
}

function invoice_count(string $storeId): int {
    return (int) Database::fetchOne(
        "SELECT COUNT(*) AS c FROM invoices WHERE store_id = ?", [$storeId]
    )['c'];
}

// ---------- 1: NWC-only store (no mint, no xpub) creates invoices ----------
[$proc, $port] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
]);
try {
    $store = 'store_ln_only_nwc';
    make_store($store); // mint_url NULL => isStoreConfigured() false; no xpub
    $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
    StoreLnAddresses::replaceForStore($store, [['type' => 'nwc', 'address' => $uri]]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('nwc', $inv['payment_rail'], 'NWC-only store rides the nwc rail');
    assert_true(str_starts_with((string)$inv['bolt11'], 'lnbc'), 'wallet bolt11 stored');
    assert_null($inv['mint_url'], 'no mint involved');
    assert_null($inv['onchain_address'], 'no on-chain address involved');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- 2: all destinations dead → clear error, no unpayable row ----------
$store2 = 'store_ln_only_dead';
make_store($store2);
$deadNwc = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:1/&secret={$clientSk}";
StoreLnAddresses::replaceForStore($store2, [['type' => 'nwc', 'address' => $deadNwc]]);

$threw = null;
try {
    Invoice::create($store2, ['amount' => 1000, 'currency' => 'sat']);
} catch (Exception $e) {
    $threw = $e->getMessage();
}
assert_true($threw !== null, 'dead-destination LN-only store must fail invoice creation');
assert_true(
    strpos((string)$threw, 'wallet is online') !== false,
    "error names the likely cause (offline wallet), got: {$threw}"
);
assert_eq(0, invoice_count($store2), 'no unpayable invoice row was inserted');

// ---------- 3: no rails at all → configuration error ----------
$store3 = 'store_no_rails';
make_store($store3);
$threw = null;
try {
    Invoice::create($store3, ['amount' => 1000, 'currency' => 'sat']);
} catch (Exception $e) {
    $threw = $e->getMessage();
}
assert_true(
    strpos((string)$threw, 'no payment methods configured') !== false,
    "rail-less store keeps the configuration error, got: {$threw}"
);

// ---------- 4: owed fees never disable the sole Lightning rail ----------
// Enough settled revenue that the owed dev fee exceeds the next invoice.
// Point the LNURL template at a dead host so the fee-redirect path (which
// would otherwise claim the rail outright, pointing the invoice at the fee
// payee) fails fast to build — the merchant's own destination must then
// serve, with the fees simply staying owed.
Config::set('fee_tracking_start_at', 0);
putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:1/.well-known/lnurlp/{user}');
[$proc, $port] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
]);
try {
    $store4 = 'store_ln_only_fees_due';
    make_store($store4);
    $uri4 = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
    StoreLnAddresses::replaceForStore($store4, [['type' => 'nwc', 'address' => $uri4]]);
    paid_invoice($store4, 1_000_000);

    $owed = DevFee::computeOwed($store4);
    $feesDue = (int)$owed['dev_owed'] + (int)$owed['hosting_owed'];
    assert_true($feesDue > 1000, "owed fees ({$feesDue}) must exceed the upcoming invoice");

    $inv4 = Invoice::create($store4, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('nwc', $inv4['payment_rail'], 'the sole rail still serves with fees owed');
    assert_null($inv4['lnurl_override_reason'], 'nothing writes the retired override column');
    assert_true(str_starts_with((string)$inv4['bolt11'], 'lnbc'), 'wallet bolt11 stored');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
    putenv('CASHU_LNURL_URL_TEMPLATE');
}

echo "test_invoice_ln_only_store: ok\n";
