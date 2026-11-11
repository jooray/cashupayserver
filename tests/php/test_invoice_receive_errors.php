<?php
/**
 * Sanitized direct-receive failure reporting (invoices.receive_errors):
 *
 *   1. Classifier unit checks: NwcException / ClinkException map onto the
 *      fixed payer-facing vocabulary, unknown Throwables get the generic
 *      "could not be reached" phrase, dedupeReceiveErrors collapses repeats.
 *   2. Two silent NWC connections + a live noffer fallback: the invoice
 *      settles on the noffer rail and receive_errors carries a single
 *      deduped {type:nwc, reason:timed out} entry that contains no secret
 *      material (wallet pubkey, client secret, relay URL).
 *   3. A dead LNURL host first + live noffer second: receive_errors records
 *      the lnurl "could not be reached" reason, without the LN address.
 *   4. A first-try direct-receive success leaves receive_errors NULL.
 */
declare(strict_types=1);

// Keep the dead-destination waits short, the same way user_config.php would.
define('NWC_TIMEOUT_SEC', 2);
define('CLINK_NOFFER_TIMEOUT_SEC', 2);

require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use swentel\nostr\Key\Key;

// ---------- 1. classifier + dedupe unit checks ----------
$nwcDescribe = new ReflectionMethod('Invoice', 'describeNwcFailure');
$nwcDescribe->setAccessible(true);
assert_eq(
    'the wallet rejected the request (check the connection\'s permissions)',
    $nwcDescribe->invoke(null, new NwcException('raw wallet text', 'UNAUTHORIZED')),
    'UNAUTHORIZED maps to the permissions phrase'
);
assert_eq(
    'the wallet is rate-limiting requests',
    $nwcDescribe->invoke(null, new NwcException('x', 'RATE_LIMITED')),
    'RATE_LIMITED maps'
);
assert_eq(
    'the wallet reported an error',
    $nwcDescribe->invoke(null, new NwcException('x', 'INTERNAL')),
    'unmapped NIP-47 code falls back to the generic wallet-error phrase'
);
assert_eq(
    'no response from the wallet (timed out)',
    $nwcDescribe->invoke(null, new NwcException('No response from NWC wallet within 2s')),
    'the timeout throw is recognized'
);
assert_eq(
    'the wallet could not be reached',
    $nwcDescribe->invoke(null, new RuntimeException('ws://secret-relay.example refused')),
    'non-NwcException transport errors get the generic phrase (no message leak)'
);

$nofferDescribe = new ReflectionMethod('Invoice', 'describeNofferFailure');
$nofferDescribe->setAccessible(true);
assert_eq('the configured offer has expired',
    $nofferDescribe->invoke(null, new ClinkException('x', 3)), 'NIP-69 code 3 = expired');
assert_eq('no response or a temporary failure from the service',
    $nofferDescribe->invoke(null, new ClinkException('x', 2)), 'NIP-69 code 2 = temp failure');
assert_eq('the service could not be reached',
    $nofferDescribe->invoke(null, new ClinkException('local env error', 0)),
    'code 0 (local/transport) gets the generic phrase');

$dedupe = new ReflectionMethod('Invoice', 'dedupeReceiveErrors');
$dedupe->setAccessible(true);
$deduped = $dedupe->invoke(null, [
    ['type' => 'nwc', 'reason' => 'a'],
    ['type' => 'nwc', 'reason' => 'a'],
    ['type' => 'lnurl', 'reason' => 'a'],
]);
assert_eq(2, count($deduped), 'identical {type,reason} pairs collapse; distinct types survive');

// ---------- shared mock plumbing (mirrors test_invoice_direct_receive_timeout) ----------
$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();
$clientSk2 = $key->generatePrivateKey();
$merchantSk = $key->generatePrivateKey();
$merchantPk = $key->getPublicKey($merchantSk);

/** Start a mock (nwc wallet | clink relay) subprocess; returns [proc, port]. */
function start_mock(string $script, string $portVar, array $env): array {
    static $seq = 0;
    $base = 28900 + (getmypid() % 800) + (($seq++) * 13);
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

// ---------- 2. two silent NWC connections -> noffer fallback ----------
[$nwcProc, $nwcPort] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_SILENT' => '1',
]);
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_BOLT11' => 'lnbc10000n1mockinvoice0000000', // 1000 sats
]);
try {
    $store = 'store_recv_err_nwc';
    make_store($store, 'http://127.0.0.1:1');
    $uri1 = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$nwcPort}&secret={$clientSk}";
    $uri2 = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$nwcPort}&secret={$clientSk2}";
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'fallback-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'nwc', 'address' => $uri1],
        ['type' => 'nwc', 'address' => $uri2],
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('noffer', $inv['payment_rail'], 'silent NWC wallets fall through to the noffer');

    $row = Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv['id']]);
    assert_not_null($row['receive_errors'], 'receive_errors persisted');
    $errors = json_decode($row['receive_errors'], true);
    assert_eq(1, count($errors), 'the two identical NWC timeouts dedupe to one entry');
    assert_eq('nwc', $errors[0]['type'], 'entry names the wallet type');
    assert_eq('no response from the wallet (timed out)', $errors[0]['reason'],
        'entry carries the sanitized timeout phrase');

    // Secret hygiene: nothing from the connection URIs may reach the column.
    foreach ([$clientSk, $clientSk2, $walletPk, (string)$nwcPort, 'nostr+walletconnect'] as $needle) {
        assert_false(strpos($row['receive_errors'], $needle) !== false,
            "receive_errors must not contain '{$needle}'");
    }

    // Both failures also land in the admin event log (checkout context, one
    // row per destination — no payer-side dedupe there), carrying the masked
    // NWC label but never the connection secret or full URI.
    $logRows = Database::fetchAll(
        "SELECT * FROM admin_event_log WHERE category = 'nwc' AND context = 'checkout' AND store_id = ?",
        [$store]
    );
    assert_eq(2, count($logRows), 'one admin log row per failed NWC destination');
    foreach ($logRows as $lr) {
        assert_true(strpos((string)$lr['label'], 'NWC wallet') === 0, 'label is the masked display label');
        foreach ([$clientSk, $clientSk2, 'nostr+walletconnect'] as $needle) {
            assert_false(strpos($lr['label'] . ' ' . $lr['message'], $needle) !== false,
                "admin log must not contain '{$needle}'");
        }
    }
} finally {
    stop_mock($nwcProc);
    stop_mock($clinkProc);
}

// ---------- 3. dead LNURL host first -> noffer second ----------
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_BOLT11' => 'lnbc10000n1mockinvoice0000000',
]);
putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:1/.well-known/lnurlp/{user}');
try {
    $store = 'store_recv_err_lnurl';
    make_store($store, 'http://127.0.0.1:1');
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'fallback-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'lnaddress', 'address' => 'merchant@dead-host.test'],
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('noffer', $inv['payment_rail'], 'dead LNURL host falls through to the noffer');

    $row = Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv['id']]);
    $errors = json_decode((string)$row['receive_errors'], true);
    assert_eq(1, count($errors), 'one lnurl failure recorded');
    assert_eq('lnurl', $errors[0]['type'], 'entry names the lnurl type');
    assert_eq('the Lightning address service could not be reached', $errors[0]['reason'],
        'unreachable host maps to the fixed phrase');
    assert_false(strpos($row['receive_errors'], 'dead-host.test') !== false,
        'the LN address never reaches receive_errors');

    // The admin log DOES name the failing address — it's admin-only.
    $lr = Database::fetchOne(
        "SELECT * FROM admin_event_log WHERE category = 'lnurl' AND store_id = ? ORDER BY id DESC LIMIT 1",
        [$store]
    );
    assert_eq('merchant@dead-host.test', $lr['label'], 'admin log row names the LN address');
} finally {
    putenv('CASHU_LNURL_URL_TEMPLATE');
    stop_mock($clinkProc);
}

// ---------- 4. first-try success leaves the column NULL ----------
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_BOLT11' => 'lnbc10000n1mockinvoice0000000',
]);
try {
    $store = 'store_recv_err_none';
    make_store($store, 'http://127.0.0.1:1');
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'primary-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('noffer', $inv['payment_rail'], 'primary noffer wins');
    $row = Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv['id']]);
    assert_null($row['receive_errors'], 'no failures -> receive_errors stays NULL');
} finally {
    stop_mock($clinkProc);
}

echo "test_invoice_receive_errors: ok\n";
