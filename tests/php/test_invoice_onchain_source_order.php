<?php
/**
 * The configurable on-chain address source order through Invoice::create:
 *
 *   1. Baseline: a static-mode store with onchain_strike_enabled and NO
 *      explicit order mints the address in Strike (default strike-first).
 *   2. onchain_source_order = local,strike flips it: the SAME store's next
 *      invoice uses the static address (tweak allocated) and Strike is never
 *      asked for a receive request.
 *   3. Local-first still falls back to Strike when the local source can't
 *      produce an address: with a 1-slot tweak range, the first invoice takes
 *      the only local slot and the second lands on a Strike-minted address
 *      instead of failing.
 *   4. Local-first with the local source exhausted AND Strike failing throws
 *      the operator-actionable "payment slots" error (the historical message).
 *   5. Restoring strike-first puts the next invoice back on Strike.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';
require_once dirname(__DIR__, 2) . '/app/includes/rail_order.php';

$KEY = 'OCORDERKEY0' . str_repeat('C', 29);

[$pid, $port, $dir] = start_strike_mock($KEY);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

// Scripted chain provider so no tip read touches the network.
$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public int $tip = 800000;
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs; }
    public function currentTipHeight(): int { return $this->tip; }
};
OnchainProviderFactory::$testProvider = $fake;

$STATIC_ADDR = '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2';

try {
    $store = 'store_oc_order';
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

    // ---------- 1. default: Strike mints the address ----------
    $inv = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv['id']]);
    assert_not_null($row['strike_receive_request_id'], 'default order asks Strike first');
    assert_neq($STATIC_ADDR, $row['onchain_address'], 'not the static address');

    // ---------- 2. local-first uses the static address, skips Strike ------
    RailOrder::setOnchainOrder($store, 'local,strike');
    $reqsBefore = count(strike_mock_receive_requests($dir));
    $inv2 = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row2 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv2['id']]);
    assert_eq($STATIC_ADDR, $row2['onchain_address'], 'local-first uses the static address');
    assert_null($row2['strike_receive_request_id'], 'no receive request on the local source');
    assert_not_null($row2['onchain_amount_tweak_sats'], 'static-mode tweak allocated');
    assert_eq($reqsBefore, count(strike_mock_receive_requests($dir)),
        'Strike was never asked when local came first and worked');
    assert_null($row2['receive_errors'], 'no payer-facing errors on a working local source');

    // ---------- 3. local exhausted → Strike rescues the rail --------------
    $store2 = 'store_oc_order_slots';
    make_store($store2, 'http://127.0.0.1:1');
    Database::update('stores', [
        'onchain_address_mode' => 'static',
        'onchain_static_address' => $STATIC_ADDR,
        'onchain_static_tweak_range' => 1, // exactly one open-invoice slot
        'onchain_network' => 'mainnet',
        'onchain_min_confs' => 1,
        'onchain_strike_enabled' => 1,
        'onchain_source_order' => 'local,strike',
    ], 'id = ?', [$store2]);
    StoreLnAddresses::replaceForStore($store2, [
        ['type' => 'strike', 'address' => $KEY],
    ]);
    $invA = Invoice::create($store2, ['amount' => 900, 'currency' => 'sat']);
    $rowA = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invA['id']]);
    assert_eq($STATIC_ADDR, $rowA['onchain_address'], 'first invoice takes the only local slot');
    $invB = Invoice::create($store2, ['amount' => 900, 'currency' => 'sat']);
    $rowB = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invB['id']]);
    assert_not_null($rowB['onchain_address'], 'second invoice still offers on-chain');
    assert_neq($STATIC_ADDR, $rowB['onchain_address'], 'exhausted local source falls back to Strike');
    assert_not_null($rowB['strike_receive_request_id'], 'the fallback is a Strike receive request');

    // ---------- 4. local exhausted AND Strike down → actionable error -----
    file_put_contents($dir . '/fail_receive_request', '500');
    $threw = null;
    try {
        Invoice::create($store2, ['amount' => 900, 'currency' => 'sat']);
    } catch (RuntimeException $e) {
        $threw = $e->getMessage();
    }
    assert_eq('All on-chain payment slots are temporarily reserved. Please try again in a few minutes.',
        $threw, 'both sources failing surfaces the historical slots error');
    unlink($dir . '/fail_receive_request');

    // ---------- 5. restoring strike-first flips back ----------------------
    RailOrder::setOnchainOrder($store, 'strike,local');
    $inv5 = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
    $row5 = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv5['id']]);
    assert_not_null($row5['strike_receive_request_id'], 'restored order asks Strike first again');
} finally {
    OnchainProviderFactory::$testProvider = null;
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
}

echo "test_invoice_onchain_source_order: ok\n";
