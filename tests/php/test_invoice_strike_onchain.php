<?php
/**
 * The Strike on-chain receive option through Invoice::create and settlement:
 *
 *   1. A static-mode store with a Strike key + onchain_strike_enabled: the
 *      invoice's on-chain address comes from the Strike receive request (NOT
 *      the static address), with no amount tweak, strike_receive_request_id
 *      persisted, and the receive-request body sat-exact. formatForApi
 *      exposes the receive-request id, the BTC-OnChain method carries the
 *      Strike address, and the key never leaks.
 *   2. Settlement via the CHAIN WATCHER: the Strike address settles on
 *      unique-address attribution even though the STORE is in static mode —
 *      a payment whose amount differs from the invoice total still counts
 *      (no static amount-matching, no manual-confirmation flag).
 *   3. Fallback: with POST /receive-requests failing, the invoice falls back
 *      to the store's static address (tweak allocated, no receive-request
 *      id) and — because the on-chain rail still works — no payer-facing
 *      receive_errors entry is added for the Strike on-chain failure.
 *   4. No local fallback: a store whose ONLY on-chain source is Strike gets
 *      an invoice without an on-chain address when Strike fails, and the
 *      failure IS surfaced in receive_errors.
 *   5. Gates: a non-mainnet on-chain network skips Strike entirely; the
 *      per-store "offer on-chain" off switch beats the Strike option.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/includes/onchain/provider.php';

$KEY = 'ONCHAININV0' . str_repeat('D', 29);

[$pid, $port, $dir] = start_strike_mock($KEY);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

// Scripted chain provider so no poll or tip read ever touches the network.
$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public int $tip = 800000;
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs; }
    public function currentTipHeight(): int { return $this->tip; }
};
OnchainProviderFactory::$testProvider = $fake;

// The static fallback address (valid mainnet, distinct from the mock's pool).
$STATIC_ADDR = '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2';

try {
    $store = 'store_strike_onchain';
    make_store($store, 'http://127.0.0.1:1'); // dead mint — must never be needed
    Database::update('stores', [
        'onchain_address_mode' => 'static',
        'onchain_static_address' => $STATIC_ADDR,
        'onchain_static_tweak_range' => 1000,
        'onchain_network' => 'mainnet',
        'onchain_min_confs' => 1,
        'onchain_strike_enabled' => 1,
    ], 'id = ?', [$store]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'strike', 'address' => $KEY],
    ]);

    // ---------- 1. Strike mints the on-chain address ----------
    $inv = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv['id']]);
    assert_not_null($row['strike_receive_request_id'], 'receive request id persisted');
    assert_neq($STATIC_ADDR, $row['onchain_address'], 'address is NOT the static fallback');
    assert_true(str_starts_with((string)$row['onchain_address'], 'bc1'),
        'address came from the Strike mock');
    assert_eq(5000, (int)$row['onchain_amount_sat'], 'expected total is the plain invoice amount');
    assert_null($row['onchain_amount_tweak_sats'], 'no static-mode tweak on a Strike address');
    assert_eq($fake->tip, (int)$row['onchain_created_tip_height'],
        'allocation tip recorded for historical-UTXO filtering');

    $captured = strike_mock_receive_requests($dir);
    $req = $captured[$row['strike_receive_request_id']] ?? null;
    assert_not_null($req, 'mock captured the receive request');
    assert_eq('0.00005000', $req['body']['onchain']['amount']['amount'],
        'receive request is sat-exact BTC');
    assert_eq($row['onchain_address'], $req['address'], 'persisted address matches what Strike returned');

    $api = Invoice::formatForApi($row);
    $apiJson = json_encode($api, JSON_UNESCAPED_UNICODE);
    assert_false(strpos($apiJson, $KEY) !== false, 'formatForApi never contains the key');
    assert_eq($row['strike_receive_request_id'], $api['strikeReceiveRequestId'] ?? null,
        'formatForApi exposes the receive request id');
    assert_eq($row['onchain_address'],
        $api['checkout']['paymentMethods']['BTC-OnChain']['destination'] ?? null,
        'BTC-OnChain method carries the Strike address');

    // ---------- 2. chain-watched settlement, unique-address attribution ----
    // Pay a DIFFERENT amount than the expected total (5000 + 250 extra in one
    // UTXO). Static-mode attribution would demand an exact amount match; the
    // Strike address is invoice-unique so any UTXO on it must count.
    $fake->obs = [
        new OnchainTxObservation('feed', 0, 5250, 1, 800001),
    ];
    $r = OnchainPayments::pollInvoice((string)$inv['id']);
    assert_eq('Settled', $r['status'], 'overpaying UTXO settles without amount matching');
    $row = Database::fetchOne(
        "SELECT status, settled_rail, onchain_needs_manual_confirmation FROM invoices WHERE id = ?",
        [$inv['id']]
    );
    assert_eq('Settled', $row['status'], 'invoice settled via the chain watcher');
    assert_eq('onchain', $row['settled_rail'], 'settled_rail records onchain');
    assert_eq(0, (int)$row['onchain_needs_manual_confirmation'],
        'no manual-confirmation flag on a Strike address');
    $fake->obs = [];

    // ---------- 3. Strike fails -> static fallback, no payer-facing error --
    file_put_contents($dir . '/fail_receive_request', '500');
    $inv2 = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row2 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv2['id']]);
    assert_eq($STATIC_ADDR, $row2['onchain_address'], 'fallback to the static address');
    assert_null($row2['strike_receive_request_id'], 'no receive request id on the fallback');
    assert_not_null($row2['onchain_amount_tweak_sats'], 'static-mode tweak allocated on the fallback');
    $errors2 = json_decode((string)$row2['receive_errors'], true) ?: [];
    $strikeErrors2 = array_filter($errors2, fn($e) => ($e['type'] ?? '') === 'strike');
    assert_eq(0, count($strikeErrors2),
        'working fallback keeps the Strike on-chain failure out of receive_errors');
    // The operator still sees it in the admin event log.
    $lr = Database::fetchOne(
        "SELECT * FROM admin_event_log WHERE category = 'strike' AND context = 'onchain' AND store_id = ? ORDER BY id DESC LIMIT 1",
        [$store]
    );
    assert_not_null($lr, 'strike on-chain failure logged for the admin');
    assert_false(strpos($lr['label'] . ' ' . $lr['message'], $KEY) !== false,
        'admin log never contains the key');
    // The fallback invoice settles with static-mode EXACT amount matching.
    $expected2 = (int)$row2['onchain_amount_sat'];
    $fake->obs = [
        new OnchainTxObservation('cafe', 0, $expected2, 1, 800002),
    ];
    $r2 = OnchainPayments::pollInvoice((string)$inv2['id']);
    assert_eq('Settled', $r2['status'], 'fallback invoice settles via static attribution');
    $fake->obs = [];
    unlink($dir . '/fail_receive_request');

    // ---------- 4. no local fallback -> failure surfaces to the payer ------
    $store2 = 'store_strike_only';
    make_store($store2, 'http://127.0.0.1:1');
    Database::update('stores', [
        'onchain_strike_enabled' => 1,
    ], 'id = ?', [$store2]);
    StoreLnAddresses::replaceForStore($store2, [
        ['type' => 'strike', 'address' => $KEY],
    ]);
    // Sanity: with Strike up, the strike-only store DOES get an on-chain rail.
    $inv3 = Invoice::create($store2, ['amount' => 700, 'currency' => 'sat']);
    $row3 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv3['id']]);
    assert_not_null($row3['onchain_address'], 'strike-only store offers on-chain when Strike is up');
    assert_not_null($row3['strike_receive_request_id'], 'via a receive request');

    file_put_contents($dir . '/fail_receive_request', '500');
    $inv4 = Invoice::create($store2, ['amount' => 700, 'currency' => 'sat']);
    $row4 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv4['id']]);
    assert_null($row4['onchain_address'], 'no on-chain rail when Strike fails with no fallback');
    assert_not_null($row4['bolt11'], 'the Strike Lightning rail still serves the invoice');
    $errors4 = json_decode((string)$row4['receive_errors'], true) ?: [];
    $strikeErrors4 = array_values(array_filter($errors4, fn($e) => ($e['type'] ?? '') === 'strike'));
    assert_true(count($strikeErrors4) >= 1, 'the lost on-chain rail is surfaced in receive_errors');
    assert_eq('the Strike API reported a server error', $strikeErrors4[0]['reason'], 'sanitized phrase');
    assert_false(strpos((string)$row4['receive_errors'], $KEY) !== false,
        'receive_errors never contains the key');
    unlink($dir . '/fail_receive_request');

    // ---------- 5a. non-mainnet network skips Strike ----------
    $before = count(strike_mock_receive_requests($dir));
    Database::update('stores', ['onchain_network' => 'testnet'], 'id = ?', [$store]);
    $inv5 = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row5 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv5['id']]);
    assert_eq($STATIC_ADDR, $row5['onchain_address'], 'testnet store uses its own address');
    assert_null($row5['strike_receive_request_id'], 'no receive request on a testnet store');
    assert_eq($before, count(strike_mock_receive_requests($dir)),
        'Strike was never asked on a non-mainnet store');
    Database::update('stores', ['onchain_network' => 'mainnet'], 'id = ?', [$store]);

    // ---------- 5b. per-store "offer on-chain" off beats the option --------
    OnchainConfig::setStoreOverride($store, OnchainConfig::FORCE_OFF);
    $inv6 = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row6 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv6['id']]);
    assert_null($row6['onchain_address'], 'offer-off store gets no on-chain rail');
    assert_null($row6['strike_receive_request_id'], 'and no receive request');
    OnchainConfig::setStoreOverride($store, OnchainConfig::FORCE_ON);
} finally {
    OnchainProviderFactory::$testProvider = null;
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
}

echo "test_invoice_strike_onchain: ok\n";
