<?php
/**
 * Invoice::create's per-destination time budget for NWC/noffer bolt11
 * fetches (the checkout path):
 *
 *   1. The checkout budget defaults to 5s per destination — tighter than the
 *      clients' 10s DEFAULT_TIMEOUT_SEC — and a NWC_TIMEOUT_SEC /
 *      CLINK_NOFFER_TIMEOUT_SEC define in user_config.php still overrides it.
 *   2. The budget is actually plumbed through Invoice::create: with the
 *      override pinned to 2s, a store whose first destination is a silent
 *      NWC wallet reaches its noffer fallback in ~2s (and vice versa),
 *      instead of the old ~10s per dead destination.
 *
 * This file defines both override constants to 2s, so it exercises the
 * override path for real; the 5s default is asserted via reflection against
 * a constant name that is NOT defined in this process.
 */
declare(strict_types=1);

// Pin the checkout budget small before anything loads the clients, the same
// way user_config.php would.
define('NWC_TIMEOUT_SEC', 2);
define('CLINK_NOFFER_TIMEOUT_SEC', 2);

require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use swentel\nostr\Key\Key;

// ---------- 1. budget resolution: default 5s, define() overrides ----------
$m = new ReflectionMethod('Invoice', 'directReceiveTimeoutSec');
$m->setAccessible(true);
assert_eq(5, $m->invoke(null, 'TEST_CONST_THAT_IS_NOT_DEFINED'),
    'checkout budget defaults to 5s per destination');
assert_eq(2, $m->invoke(null, 'NWC_TIMEOUT_SEC'), 'NWC_TIMEOUT_SEC define overrides the default');
assert_eq(2, $m->invoke(null, 'CLINK_NOFFER_TIMEOUT_SEC'),
    'CLINK_NOFFER_TIMEOUT_SEC define overrides the default');

$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();
$merchantSk = $key->generatePrivateKey();
$merchantPk = $key->getPublicKey($merchantSk);

/** Start a mock (nwc wallet | clink relay) subprocess; returns [proc, port]. */
function start_mock(string $script, string $portVar, array $env): array {
    static $seq = 0;
    $base = 27900 + (getmypid() % 800) + (($seq++) * 13);
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

function stop_mock($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- 2a. silent NWC first -> noffer fallback within the budget ----------
[$nwcProc, $nwcPort] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_SILENT' => '1',
]);
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_BOLT11' => 'lnbc10000n1mockinvoice0000000', // 1000 sats
]);
try {
    $store = 'store_budget_nwc_first';
    // Stub mint so the store is "lightning capable"; a direct-receive
    // destination wins before the non-routable mint is ever contacted.
    make_store($store, 'http://127.0.0.1:1');
    $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$nwcPort}&secret={$clientSk}";
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'fallback-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'nwc', 'address' => $uri],
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $t0 = microtime(true);
    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    $elapsed = microtime(true) - $t0;
    assert_eq('noffer', $inv['payment_rail'], 'silent NWC wallet falls through to the noffer');
    assert_true($elapsed >= 1.5, "the NWC budget was actually waited out (elapsed {$elapsed}s)");
    assert_true($elapsed < 5.5,
        "checkout reached the fallback within the 2s budget (elapsed {$elapsed}s, ~10s before)");
} finally {
    stop_mock($nwcProc);
    stop_mock($clinkProc);
}

// ---------- 2b. silent noffer first -> NWC fallback within the budget ----------
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_SILENT' => '1',
]);
[$nwcProc, $nwcPort] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
]);
try {
    $store = 'store_budget_noffer_first';
    make_store($store, 'http://127.0.0.1:1');
    $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$nwcPort}&secret={$clientSk}";
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'silent-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'noffer', 'address' => $noffer],
        ['type' => 'nwc', 'address' => $uri],
    ]);

    $t0 = microtime(true);
    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    $elapsed = microtime(true) - $t0;
    assert_eq('nwc', $inv['payment_rail'], 'silent noffer falls through to the NWC wallet');
    assert_eq('lnbc10000n1mockinvoice0000000', $inv['bolt11'], 'NWC bolt11 for 1000 sats');
    assert_true($elapsed >= 1.5, "the noffer budget was actually waited out (elapsed {$elapsed}s)");
    assert_true($elapsed < 5.5,
        "checkout reached the fallback within the 2s budget (elapsed {$elapsed}s)");
} finally {
    stop_mock($nwcProc);
    stop_mock($clinkProc);
}

echo "test_invoice_direct_receive_timeout: ok\n";
