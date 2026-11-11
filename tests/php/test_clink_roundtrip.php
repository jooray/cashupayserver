<?php
/**
 * Integration test for the CLINK client's relay round trip, exercised against a
 * local mock relay (tests/php/mock_clink_relay.php) that also plays the merchant
 * service. Covers: subscribe-before-publish invoice fetch, NIP-69 error-code
 * propagation, and the cron-style receipt poll (fetchReceipt).
 *
 * Uses plain ws:// to 127.0.0.1 so no TLS/cert is needed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/client.php';

use swentel\nostr\Key\Key;

$key = new Key();
$mSk = $key->generatePrivateKey();
$mPk = $key->getPublicKey($mSk);

/** Start the mock relay; returns [proc, pipes, port]. */
function start_relay(array $env): array {
    // Distinct port window per call so a just-terminated relay's socket
    // lingering in TIME_WAIT can't be reconnected by the next start.
    static $seq = 0;
    $base = 24000 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_CLINK_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/php/mock_clink_relay.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        // wait for the port to accept connections
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $pipes, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail('mock relay failed to start on any port');
}

function stop_relay($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- success: fetch a bolt11 ----------
$bolt11 = 'lnbc100n1mockinvoice0000000';
[$proc, $pipes, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => $bolt11,
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relay' => "ws://127.0.0.1:$port",
        'offer' => 'test-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    $res = ClinkClient::requestInvoice($noffer, 100, 'coffee', 8);
    assert_eq($bolt11, $res['bolt11'], 'requestInvoice returns merchant bolt11');
    assert_eq(64, strlen($res['ephemeral_sk']), 'ephemeral_sk captured');
    assert_eq(64, strlen($res['request_event_id']), 'request_event_id captured');
    assert_eq($mPk, $res['receiver_pubkey'], 'receiver pubkey echoed');
} finally {
    stop_relay($proc);
}

// ---------- error code propagation (NIP-69 code 5: invalid amount) ----------
[$proc, $pipes, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_ERROR_CODE' => '5',
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk, 'relay' => "ws://127.0.0.1:$port", 'offer' => 'o',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    $threw = false;
    try {
        ClinkClient::requestInvoice($noffer, 1, null, 8);
    } catch (ClinkException $e) {
        $threw = true;
        assert_eq(5, $e->clinkCode, 'NIP-69 error code surfaced');
    }
    assert_true($threw, 'error reply raises ClinkException');
} finally {
    stop_relay($proc);
}

// ---------- cron receipt poll: merchant emits a receipt, fetchReceipt sees paid ----------
[$proc, $pipes, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => $bolt11,
    'MOCK_CLINK_SEND_RECEIPT' => '1',
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk, 'relay' => "ws://127.0.0.1:$port", 'offer' => 'o',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    // Request the invoice; the mock also fires a {res:'ok'} receipt right after.
    $res = ClinkClient::requestInvoice($noffer, 100, null, 8);
    $ctx = [
        'relay' => $res['relay'],
        'receiver_pubkey' => $res['receiver_pubkey'],
        'ephemeral_sk' => $res['ephemeral_sk'],
        'ephemeral_pubkey' => $res['ephemeral_pubkey'],
        'request_event_id' => $res['request_event_id'],
        'created_at' => $res['created_at'],
    ];
    // The mock relay relays the receipt to any matching subscription, so the
    // cron-style re-subscribe should observe it.
    $verdict = ClinkClient::fetchReceipt($ctx, 5);
    assert_true(!empty($verdict['paid']), 'fetchReceipt observes the merchant receipt');
} finally {
    stop_relay($proc);
}

echo "test_clink_roundtrip: ok\n";
