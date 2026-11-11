<?php
/**
 * Invoice::create on the NWC rail, against the mock wallet service:
 *   1. A store whose only destination is an NWC connection gets its BOLT11
 *      from make_invoice — payment_rail='nwc', payment hash + connection URI
 *      persisted for the pollers, ln_destination stored masked.
 *   2. The request the wallet actually receives carries the amount in msats,
 *      the store-name memo, and an expiry.
 *   3. A dead NWC connection falls through to the next destination in the
 *      chain (here a noffer) instead of failing the invoice.
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

/** Start a mock (nwc wallet | clink relay) subprocess; returns [proc, port]. */
function start_mock(string $script, string $portVar, array $env): array {
    static $seq = 0;
    $base = 29100 + (getmypid() % 800) + (($seq++) * 13);
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

// ---------- 1 + 2: NWC-only store rides the nwc rail ----------
$dump = sys_get_temp_dir() . '/nwc_create_dump_' . bin2hex(random_bytes(4)) . '.jsonl';
[$proc, $port] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_DUMP' => $dump,
]);
try {
    $store = 'store_nwc_create';
    // Stub mint so the store is "lightning capable"; the NWC rail is tried
    // first and wins, so the non-routable mint is never contacted.
    make_store($store, 'http://127.0.0.1:1');
    Database::update('stores', ['name' => 'Acme Coffee'], 'id = ?', [$store]);
    $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
    StoreLnAddresses::replaceForStore($store, [['type' => 'nwc', 'address' => $uri]]);

    $inv = Invoice::create($store, [
        'amount' => 1000,
        'currency' => 'sat',
        'metadata' => ['itemDesc' => '2x Latte', 'orderId' => '555'],
    ]);
    assert_eq('nwc', $inv['payment_rail'], 'invoice rides the nwc rail');
    assert_eq('lnbc10000n1mockinvoice0000000', $inv['bolt11'], 'wallet bolt11 stored (1000 sats)');
    assert_eq(hash('sha256', $inv['bolt11']), $inv['nwc_payment_hash'], 'payment hash persisted for the pollers');
    assert_eq($uri, $inv['nwc_uri'], 'connection URI persisted server-side for lookups');
    assert_true(strpos((string)$inv['ln_destination'], $clientSk) === false, 'ln_destination is masked');
    assert_true(strpos((string)$inv['ln_destination'], 'NWC wallet') === 0, 'ln_destination is the display label');

    // What the wallet actually received.
    $lines = array_values(array_filter(explode("\n", (string)file_get_contents($dump))));
    $sent = json_decode(end($lines), true)['payload'] ?? [];
    assert_eq('make_invoice', $sent['method'] ?? null, 'make_invoice sent');
    assert_eq(1000000, $sent['params']['amount'] ?? null, 'amount sent in msats');
    assert_eq('Acme Coffee - Order 555 - 2x Latte', $sent['params']['description'] ?? null,
        'store-name + order-reference memo sent');
    assert_true(($sent['params']['expiry'] ?? 0) > 0, 'expiry sent');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
    @unlink($dump);
}

// ---------- 3: dead NWC connection falls through to the noffer ----------
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';
$mSk = $key->generatePrivateKey();
$mPk = $key->getPublicKey($mSk);
[$proc, $port] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => 'lnbc100n1mockinvoice0000000',
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relay' => "ws://127.0.0.1:{$port}",
        'offer' => 'shop',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    $store = 'store_nwc_fallback';
    make_store($store, 'http://127.0.0.1:1');
    $deadNwc = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:1/&secret={$clientSk}";
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'nwc', 'address' => $deadNwc],
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('noffer', $inv['payment_rail'], 'dead NWC falls through to the noffer');
    assert_eq('lnbc100n1mockinvoice0000000', $inv['bolt11'], 'noffer bolt11 stored');
    assert_null($inv['nwc_uri'], 'no nwc context persisted on the fallback rail');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

echo "test_nwc_invoice_create: ok\n";
