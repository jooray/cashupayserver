<?php
/**
 * Wall-clock budget enforcement for the NWC and CLINK noffer clients — the
 * "how long until checkout skips a dead wallet" guarantees:
 *
 *   1. A multi-relay NWC URI shares ONE budget: every relay (and its connect
 *      time) draws from the same deadline, so N relays cost ~timeout total,
 *      not N × timeout.
 *   2. A relay host that accepts TCP but never completes the websocket
 *      handshake (black hole) spends the budget too — connect time counts.
 *   3. NWC failover to a later relay still works when an earlier one refuses
 *      the connection outright.
 *   4. A noffer now tries EVERY relay in the offer under the same shared
 *      budget: a dead first relay falls through to the next, a definitive
 *      service reply from any relay ends the walk, and the winning relay is
 *      what requestInvoice reports (the receipt poll listens there).
 *
 * Timing assertions use generous margins but stay below what the old
 * per-relay/post-connect deadlines would have cost, so a regression to the
 * unenforced behaviour fails the test rather than just running slower.
 *
 * Uses plain ws:// to 127.0.0.1 so no TLS/cert is needed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/nwc/client.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/client.php';

use swentel\nostr\Key\Key;

$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();

/** Start a mock (nwc wallet | clink relay) subprocess; returns [proc, port]. */
function start_mock(string $script, string $portVar, array $env): array {
    static $seq = 0;
    $base = 27400 + (getmypid() % 800) + (($seq++) * 13);
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

/**
 * A TCP listener that accepts connections (via the kernel backlog) but never
 * speaks — the websocket handshake blocks until the client's own timeout.
 * Returns [server-resource (keep alive), port].
 */
function start_blackhole(): array {
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($srv === false) {
        fail("blackhole listener failed: {$errstr}");
    }
    $name = stream_socket_get_name($srv, false);
    $port = (int)substr($name, strrpos($name, ':') + 1);
    return [$srv, $port];
}

// ---------- 1. NWC: three silent relays share one budget ----------
// Old behaviour: each relay got its own fresh deadline, started only after
// connect -> ~3 × budget. New: ~1 × budget for the whole URI.
$mocks = [];
$relayParams = [];
for ($i = 0; $i < 3; $i++) {
    [$proc, $port] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
        'MOCK_NWC_WALLET_SK' => $walletSk,
        'MOCK_NWC_SILENT' => '1',
    ]);
    $mocks[] = $proc;
    $relayParams[] = "relay=ws://127.0.0.1:{$port}";
}
try {
    $uri = "nostr+walletconnect://{$walletPk}?" . implode('&', $relayParams) . "&secret={$clientSk}";
    $t0 = microtime(true);
    $threw = false;
    try {
        NwcClient::makeInvoice($uri, 21, null, null, 2);
    } catch (NwcException $e) {
        $threw = true;
        assert_eq('', $e->nwcCode, 'silent relays surface as a transport failure');
    }
    $elapsed = microtime(true) - $t0;
    assert_true($threw, 'all-silent multi-relay URI raises NwcException');
    assert_true($elapsed >= 1.5, "budget actually waited out (elapsed {$elapsed}s)");
    assert_true($elapsed < 4.4, "3 relays share the 2s budget (elapsed {$elapsed}s, old ~6s)");
} finally {
    foreach ($mocks as $p) { stop_mock($p); }
}

// ---------- 2. NWC: connect time draws from the budget ----------
// relay[0] black-holes the handshake; relay[1] is reachable but silent. Old
// behaviour: 2s connect timeout on relay[0], then a FRESH post-connect 2s
// deadline on relay[1] -> ~4s+. New: one 2s budget for both.
[$blackhole, $bhPort] = start_blackhole();
[$proc, $port] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_SILENT' => '1',
]);
try {
    $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$bhPort}"
        . "&relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
    $t0 = microtime(true);
    $threw = false;
    try {
        NwcClient::makeInvoice($uri, 21, null, null, 2);
    } catch (NwcException $e) {
        $threw = true;
    }
    $elapsed = microtime(true) - $t0;
    assert_true($threw, 'black-holed first relay still ends in NwcException');
    assert_true($elapsed < 3.5, "handshake block spent the shared budget (elapsed {$elapsed}s, old ~4s+)");
} finally {
    stop_mock($proc);
    fclose($blackhole);
}

// ---------- 3. NWC: refused first relay fails over and succeeds ----------
[$proc, $port] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
]);
try {
    $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:1"
        . "&relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
    $res = NwcClient::makeInvoice($uri, 21, null, null, 8);
    assert_eq('lnbc210n1mockinvoice0000000', $res['bolt11'], 'failover relay still yields the bolt11');
    assert_eq("ws://127.0.0.1:{$port}", $res['relay'], 'the answering relay is reported');
} finally {
    stop_mock($proc);
}

// ---------- 4a. noffer: silent relays share one budget ----------
$mSk = $key->generatePrivateKey();
$mPk = $key->getPublicKey($mSk);
[$procA, $portA] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_SILENT' => '1',
]);
[$procB, $portB] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_SILENT' => '1',
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relays' => ["ws://127.0.0.1:{$portA}", "ws://127.0.0.1:{$portB}"],
        'offer' => 'budget-test',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    $t0 = microtime(true);
    $threw = false;
    try {
        ClinkClient::requestInvoice($noffer, 100, null, 2);
    } catch (ClinkException $e) {
        $threw = true;
        assert_eq(2, $e->clinkCode, 'timeout surfaces as NIP-69 temporary failure');
    }
    $elapsed = microtime(true) - $t0;
    assert_true($threw, 'all-silent noffer relays raise ClinkException');
    assert_true($elapsed >= 1.5, "budget actually waited out (elapsed {$elapsed}s)");
    assert_true($elapsed < 3.5, "2 relays share the 2s budget (elapsed {$elapsed}s)");
} finally {
    stop_mock($procA);
    stop_mock($procB);
}

// ---------- 4b. noffer: refused first relay fails over to the second ----------
$bolt11 = 'lnbc100n1mockinvoice0000000';
[$proc, $port] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => $bolt11,
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relays' => ['ws://127.0.0.1:1', "ws://127.0.0.1:{$port}"],
        'offer' => 'failover-test',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    $res = ClinkClient::requestInvoice($noffer, 100, null, 8);
    assert_eq($bolt11, $res['bolt11'], 'second relay yields the bolt11');
    assert_eq("ws://127.0.0.1:{$port}", $res['relay'],
        'the answering relay is reported (receipt poll must listen there)');
} finally {
    stop_mock($proc);
}

// ---------- 4c. noffer: a definitive service error ends the relay walk ----------
// The error reply IS the merchant's answer; a later relay must not be asked
// for a second opinion (it would double-request the invoice).
[$procErr, $portErr] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_ERROR_CODE' => '5',
]);
[$procOk, $portOk] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => $bolt11,
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relays' => ["ws://127.0.0.1:{$portErr}", "ws://127.0.0.1:{$portOk}"],
        'offer' => 'definitive-error',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    $threw = false;
    try {
        ClinkClient::requestInvoice($noffer, 1, null, 8);
    } catch (ClinkException $e) {
        $threw = true;
        assert_eq(5, $e->clinkCode, 'service error propagated, not papered over by relay 2');
    }
    assert_true($threw, 'definitive error reply stops the walk despite a healthy second relay');
} finally {
    stop_mock($procErr);
    stop_mock($procOk);
}

echo "test_destination_timeout_budget: ok\n";
