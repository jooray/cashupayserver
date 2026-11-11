<?php
/**
 * A Lightning-path failure must never abort Invoice::create while another
 * rail can serve the invoice (the self-serve page turns any such abort into
 * an opaque "Could not create the invoice right now."):
 *
 *   1. Strict no-mint-fallback swap mode: when every destination fails AND no
 *      swap provider accepts the amount, a store that offers on-chain still
 *      gets an on-chain-only invoice. Strict semantics are preserved — the
 *      mint is never queried — and the failures are recorded in
 *      receive_errors (sanitized fixed phrases only).
 *   2. Strict mode with the customer-facing on-chain rail switched OFF still
 *      fails creation (nothing payable would remain) and leaves no row.
 *   3. Strict mode with no on-chain at all keeps the original swap error.
 *   4. A mint-unit rate-conversion failure (fiat-unit mint, no usable rate)
 *      degrades to the on-chain rail instead of aborting; without on-chain
 *      it still aborts.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/factory.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/provider.php';

$xpub = 'xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLjKd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1';

// Keep on-chain allocation off the network (best-effort tip read).
OnchainProviderFactory::$testProvider = new class implements BlockchainProvider {
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return []; }
    public function currentTipHeight(): int { return 800000; }
};

// Swap provider that is reachable but rejects every realistic amount —
// deterministic stand-in for "amount outside provider range", no network.
$rejectingProvider = new class implements SwapProvider {
    public function getName(): string { return 'boltz'; }
    public function isReachable(string $network): bool { return true; }
    public function getReversePairInfo(string $network): SwapPairInfo {
        return new SwapPairInfo(0.5, 100, 100, 1, 10); // max 10 sats
    }
    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPublicKeyHex, string $preimageHashHex): SwapCreateResult {
        throw new RuntimeException('unreachable in this test');
    }
    public function getSwapStatus(string $network, string $swapId): ?SwapStatus { return null; }
    public function broadcastTx(string $network, string $rawTxHex): string {
        throw new RuntimeException('unreachable in this test');
    }
    public function cancelInvoice(string $network, string $swapId): void {}
};
SwapProviderFactory::setRegistry(['boltz' => $rejectingProvider]);

putenv('NWC_TIMEOUT_SEC=1');

// Dead NWC destination: connection refused instantly (nothing listens on :1).
$deadNwc = 'nostr+walletconnect://' . str_repeat('ab', 32)
    . '?relay=ws://127.0.0.1:1/&secret=' . str_repeat('cd', 32);

function set_xpub(string $store, string $xpub): void {
    Database::query(
        "UPDATE stores SET onchain_address_mode='xpub', onchain_xpub=?, onchain_network='mainnet', onchain_address_type='P2WPKH' WHERE id=?",
        [$xpub, $store]
    );
}

function invoice_row(string $storeId): ?array {
    $row = Database::fetchOne("SELECT * FROM invoices WHERE store_id = ?", [$storeId]);
    return $row ?: null;
}

// ---------- 1: strict swap + dead destination + mint + xpub → on-chain ----------
$s1 = 'store_strict_onchain';
make_store($s1, 'http://127.0.0.1:9'); // mint configured but must never be queried
set_xpub($s1, $xpub);
Database::query("UPDATE stores SET swaps_enabled=1, strict_no_mint_fallback=1 WHERE id=?", [$s1]);
StoreLnAddresses::replaceForStore($s1, [['type' => 'nwc', 'address' => $deadNwc]]);

$inv = Invoice::create($s1, ['amount' => 100000, 'currency' => 'sat']);
assert_eq('onchain', $inv['payment_rail'], 'strict swap failure degrades to the on-chain rail');
assert_not_null($inv['onchain_address'], 'on-chain address allocated');
assert_null($inv['bolt11'], 'no bolt11 on the on-chain-only invoice');
assert_null($inv['mint_url'], 'no mint involved');
$errors = json_decode((string)$inv['receive_errors'], true);
$types = array_column($errors, 'type');
assert_true(in_array('swap', $types, true), 'swap failure recorded in receive_errors');
assert_true(in_array('nwc', $types, true), 'destination failure recorded in receive_errors');
foreach ($errors as $re) {
    assert_true(strpos((string)$re['reason'], '127.0.0.1') === false, 'no endpoint detail leaks into receive_errors');
}

// Strict semantics kept: the mint was never asked for a quote.
$mintTouched = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM mint_reliability WHERE mint_url = ?", ['http://127.0.0.1:9']
);
assert_eq(0, (int)$mintTouched['c'], 'strict mode still never queries the mint');
$swapRows = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM swap_attempts WHERE store_id = ?", [$s1]
);
assert_eq(0, (int)$swapRows['c'], 'no swap attempt row persisted for the failed swap');

// ---------- 2: strict + customer on-chain rail OFF → still fails, no row ----------
$s2 = 'store_strict_onchain_off';
make_store($s2); // no mint
set_xpub($s2, $xpub);
Database::query("UPDATE stores SET swaps_enabled=1, strict_no_mint_fallback=1 WHERE id=?", [$s2]);
OnchainConfig::setStoreOverride($s2, 0);
StoreLnAddresses::replaceForStore($s2, [['type' => 'nwc', 'address' => $deadNwc]]);

$threw = null;
try {
    Invoice::create($s2, ['amount' => 100000, 'currency' => 'sat']);
} catch (Exception $e) {
    $threw = $e->getMessage();
}
assert_true($threw !== null, 'on-chain rail off for customers: creation still fails');
assert_true(
    strpos((string)$threw, 'Submarine swap could not be created') !== false,
    "strict swap error kept when nothing else can serve, got: {$threw}"
);
assert_null(invoice_row($s2), 'no unpayable invoice row was inserted');

// ---------- 3: strict + no on-chain at all → original error kept ----------
$s3 = 'store_strict_no_onchain';
make_store($s3);
// swaps need an xpub to be enabled, so this store's swap block never runs;
// enable strict anyway to prove the flag alone changes nothing without swaps.
Database::query("UPDATE stores SET swaps_enabled=1, strict_no_mint_fallback=1 WHERE id=?", [$s3]);
StoreLnAddresses::replaceForStore($s3, [['type' => 'nwc', 'address' => $deadNwc]]);
$threw = null;
try {
    Invoice::create($s3, ['amount' => 100000, 'currency' => 'sat']);
} catch (Exception $e) {
    $threw = $e->getMessage();
}
assert_true($threw !== null, 'LN-only store with dead destination still fails');
assert_null(invoice_row($s3), 'no unpayable invoice row was inserted');

// ---------- 4: mint-unit rate failure degrades to on-chain ----------
// A zero cached rate makes the sat→usd conversion throw deterministically
// (divisor guard) without any network. Seed the fresh-cache slot that
// getBtcPrice() reads first (same seam as test_rates_guard.php).
Config::set('rate_usd', [
    'rate' => 0,
    'timestamp' => Database::timestamp(),
    'provider' => 'test',
]);

$s4 = 'store_usd_mint_rate_down';
make_store($s4, 'http://127.0.0.1:9', 'usd');
set_xpub($s4, $xpub);
$inv4 = Invoice::create($s4, ['amount' => 100000, 'currency' => 'sat']);
assert_eq('onchain', $inv4['payment_rail'], 'rate failure on a fiat-unit mint degrades to on-chain');
assert_not_null($inv4['onchain_address'], 'on-chain address allocated despite rate failure');

$s5 = 'store_usd_mint_rate_down_no_onchain';
make_store($s5, 'http://127.0.0.1:9', 'usd');
$threw = null;
try {
    Invoice::create($s5, ['amount' => 100000, 'currency' => 'sat']);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
assert_true($threw !== null, 'rate failure still aborts when no other rail exists');
assert_null(invoice_row($s5), 'no unpayable invoice row was inserted');

SwapProviderFactory::setRegistry(null);
OnchainProviderFactory::$testProvider = null;

echo "OK test_invoice_strict_swap_onchain_fallback\n";
