<?php
/**
 * Integration test for the NWC client's NIP-47 round trip, exercised against a
 * local mock wallet service (tests/php/mock_nwc_wallet.php). Covers: nip04
 * baseline and nip44 negotiation off the 13194 info event, make_invoice
 * success + bolt11 amount verification, NIP-47 error propagation, the silent
 * timeout, lookup_invoice paid detection (state=settled and the legacy
 * preimage/settled_at fallback), pending, and NOT_FOUND handling.
 *
 * Uses plain ws:// to 127.0.0.1 so no TLS/cert is needed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/nwc/client.php';

use swentel\nostr\Key\Key;

$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();

/** Start the mock wallet; returns [proc, port]. */
function start_wallet(array $env): array {
    static $seq = 0;
    $base = 26200 + (getmypid() % 2000) + (($seq++) * 13);
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

function stop_wallet($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); }
}

function wallet_uri(string $walletPk, int $port, string $clientSk): string {
    return "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
}

// ---------- make_invoice over the nip04 baseline (no encryption tag) ----------
$dump = tempnam(sys_get_temp_dir(), 'nwc-dump-');
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_DUMP' => $dump,
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $res = NwcClient::makeInvoice($uri, 21, 'test memo', 600, 8);
    assert_eq('lnbc210n1mockinvoice0000000', $res['bolt11'], 'bolt11 for 21 sats returned');
    assert_eq(64, strlen($res['payment_hash']), 'payment_hash captured');
    assert_eq($walletPk, $res['pubkey'], 'wallet pubkey echoed');

    $lines = array_values(array_filter(explode("\n", (string)file_get_contents($dump))));
    $sent = json_decode(end($lines), true);
    assert_eq('nip04', $sent['scheme'], 'no encryption tag advertised -> client used nip04');
    assert_eq('make_invoice', $sent['payload']['method'], 'method sent');
    assert_eq(21000, $sent['payload']['params']['amount'], 'amount sent in msats');
    assert_eq('test memo', $sent['payload']['params']['description'], 'description sent');
    assert_eq(600, $sent['payload']['params']['expiry'], 'expiry sent');
} finally {
    stop_wallet($proc);
    @unlink($dump);
}

// ---------- nip44 negotiation off the info event ----------
$dump = tempnam(sys_get_temp_dir(), 'nwc-dump-');
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_ENCRYPTION' => 'nip44_v2 nip04',
    'MOCK_NWC_DUMP' => $dump,
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $res = NwcClient::makeInvoice($uri, 5, null, null, 8);
    assert_eq('lnbc50n1mockinvoice0000000', $res['bolt11'], 'nip44 round trip returns bolt11');
    $lines = array_values(array_filter(explode("\n", (string)file_get_contents($dump))));
    $sent = json_decode(end($lines), true);
    assert_eq('nip44', $sent['scheme'], 'advertised nip44_v2 -> client used nip44');
} finally {
    stop_wallet($proc);
    @unlink($dump);
}

// ---------- amount-mismatch rejection ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_BOLT11' => 'lnbc990n1mockinvoice0000000', // 99 sats, whatever was asked
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $threw = false;
    try {
        NwcClient::makeInvoice($uri, 21, null, null, 8);
    } catch (NwcException $e) {
        $threw = true;
        assert_true(strpos($e->getMessage(), '99') !== false, 'mismatch message names encoded amount');
    }
    assert_true($threw, 'wrong-amount invoice rejected');
} finally {
    stop_wallet($proc);
}

// ---------- NIP-47 error propagation ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_ERROR_CODE' => 'RESTRICTED',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $threw = false;
    try {
        NwcClient::makeInvoice($uri, 21, null, null, 8);
    } catch (NwcException $e) {
        $threw = true;
        assert_eq('RESTRICTED', $e->nwcCode, 'NIP-47 error code surfaced');
    }
    assert_true($threw, 'error reply raises NwcException');
} finally {
    stop_wallet($proc);
}

// ---------- silent wallet -> timeout ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_SILENT' => '1',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $threw = false;
    $t0 = microtime(true);
    try {
        NwcClient::makeInvoice($uri, 21, null, null, 3);
    } catch (NwcException $e) {
        $threw = true;
        assert_eq('', $e->nwcCode, 'timeout is a transport error (no NIP-47 code)');
    }
    assert_true($threw, 'silent wallet raises NwcException');
    assert_true(microtime(true) - $t0 < 15, 'timeout respected the budget');
} finally {
    stop_wallet($proc);
}

// ---------- impostor reply (bad Schnorr signature) is ignored ----------
// The mock encrypts correctly (it holds the real wallet key) but mangles the
// signature; the client's reply verification must drop the event and time
// out rather than accept the bolt11.
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_BAD_SIG' => '1',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $threw = false;
    try {
        NwcClient::makeInvoice($uri, 21, null, null, 3);
    } catch (NwcException $e) {
        $threw = true;
        assert_eq('', $e->nwcCode, 'unsigned reply is treated as no reply, not a wallet error');
    }
    assert_true($threw, 'reply with an invalid signature is not accepted');
} finally {
    stop_wallet($proc);
}

// ---------- lookup_invoice: settled ----------
$hash = hash('sha256', 'some-invoice');
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'settled',
    'MOCK_NWC_PREIMAGE' => str_repeat('cd', 32),
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $res = NwcClient::lookupInvoice($uri, $hash, 8);
    assert_true($res['found'], 'settled lookup found');
    assert_true($res['paid'], 'state=settled detected as paid');
    assert_eq(str_repeat('cd', 32), $res['preimage'], 'preimage captured');
} finally {
    stop_wallet($proc);
}

// ---------- lookup_invoice: legacy wallet without `state` ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'legacy_settled',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $res = NwcClient::lookupInvoice($uri, $hash, 8);
    assert_true($res['paid'], 'preimage/settled_at without state detected as paid');
} finally {
    stop_wallet($proc);
}

// ---------- lookup_invoice: pending ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'pending',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $res = NwcClient::lookupInvoice($uri, $hash, 8);
    assert_true($res['found'], 'pending lookup found');
    assert_true(!$res['paid'], 'pending not paid');
} finally {
    stop_wallet($proc);
}

// ---------- lookup_invoice: NOT_FOUND is not an exception ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'notfound',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $res = NwcClient::lookupInvoice($uri, $hash, 8);
    assert_true(!$res['found'], 'NOT_FOUND -> found=false');
    assert_true(!$res['paid'], 'NOT_FOUND -> not paid');
} finally {
    stop_wallet($proc);
}

// ---------- probeConnection: happy path + spend-permission warning ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'pending',
    'MOCK_NWC_METHODS' => 'pay_invoice make_invoice lookup_invoice',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $probe = NwcClient::probeConnection($uri, 8);
    assert_true($probe['ok'], 'probe passes (make + lookup work)');
    assert_true($probe['warning'] !== null, 'spend-capable connection warned about');
    assert_true(strpos($probe['warning'], 'pay_invoice') !== false, 'warning names the spend method');
} finally {
    stop_wallet($proc);
}

// ---------- probeConnection: receive-only connection has no warning ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'pending',
    'MOCK_NWC_METHODS' => 'make_invoice lookup_invoice',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $probe = NwcClient::probeConnection($uri, 8);
    assert_true($probe['ok'], 'receive-only probe passes');
    assert_null($probe['warning'], 'no warning for receive-only connection');
} finally {
    stop_wallet($proc);
}

// ---------- probeConnection: get_info denied is fine ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'pending',
    // MOCK_NWC_METHODS unset -> get_info answers RESTRICTED
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $probe = NwcClient::probeConnection($uri, 8);
    assert_true($probe['ok'], 'probe passes when get_info is denied');
    assert_null($probe['warning'], 'denied get_info produces no warning');
} finally {
    stop_wallet($proc);
}

// ---------- probeConnection: broken wallet blocks ----------
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_ERROR_CODE' => 'INTERNAL',
]);
try {
    $uri = wallet_uri($walletPk, $port, $clientSk);
    $probe = NwcClient::probeConnection($uri, 8);
    assert_true(!$probe['ok'], 'failing wallet -> probe not ok');
    assert_true($probe['error'] !== null, 'probe reports the reason');
} finally {
    stop_wallet($proc);
}

// ---------- unreachable relay ----------
$deadUri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:1/&secret={$clientSk}";
$threw = false;
try {
    NwcClient::makeInvoice($deadUri, 21, null, null, 3);
} catch (NwcException $e) {
    $threw = true;
}
assert_true($threw, 'unreachable relay raises NwcException');

echo "test_nwc_roundtrip: ok\n";
