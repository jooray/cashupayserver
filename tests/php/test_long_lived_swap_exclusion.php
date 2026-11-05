<?php
/**
 * Long-lived invoices never refresh a submarine swap in place (operator
 * decision 6b): a swap-rail bolt11 is only replaced when a direct destination
 * or a mint can take the invoice over.
 *
 *   1. Swap-only store (xpub + swaps, no mint, no destinations): the invoice
 *      lands on the swap rail; a forced refresh finds no other rail →
 *      'unavailable', the swap bolt11 and its swap_attempts row stay as they
 *      are, nothing is parked.
 *   2. Once the store gains a mint, the refresh moves the invoice onto the
 *      mint rail, parks the swap bolt11 and releases the held swap
 *      (provider cancelInvoice called, row → invoice.expired) — unless
 *      strict no-mint-fallback is on: strict forbids the mint standing in
 *      for the swap rail (as at creation), so the refresh stays
 *      'unavailable' and the swap is kept (test_long_lived_strict_no_mint_refresh.php
 *      covers the strict rule in depth).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/includes/swap/factory.php';
require_once dirname(__DIR__, 2) . '/includes/swap/poller.php';
require_once dirname(__DIR__, 2) . '/includes/swap/provider.php';
require_once dirname(__DIR__, 2) . '/includes/crypto/secp256k1.php';
require_once dirname(__DIR__, 2) . '/includes/crypto/taproot.php';
require_once dirname(__DIR__, 2) . '/includes/crypto/tx_builder.php';

/** Trimmed twin of tests/swap/test_mock_flow.php's provider: valid taproot swap, scripted status. */
final class MockSwapProvider implements SwapProvider {
    public static int $created = 0;
    public static int $cancelled = 0;
    public function getName(): string { return 'mock'; }
    public function isReachable(string $network): bool { return true; }
    public function getReversePairInfo(string $network): SwapPairInfo {
        return new SwapPairInfo(0.5, 200, 150, 1000, 5_000_000, 'mockhash');
    }
    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        self::$created++;
        $refundPriv = hash('sha256', 'mock-refund-key', true);
        $refundPub = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($refundPriv)));
        $claimPub = hex2bin($claimPubkeyHex);
        $preimageHash = hex2bin($preimageHashHex);
        $claimXOnly = substr($claimPub, 1);
        $h160 = hash('ripemd160', hash('sha256', $preimageHash, true), true);
        $claimScript = "\x82\x01\x20\x88\xA9" . chr(20) . $h160 . "\x88" . chr(32) . $claimXOnly . "\xAC";
        $refundScript = chr(32) . substr($refundPub, 1) . "\xAD" . TxBuilder::scriptNumberPush(1_000_000) . "\xB1";
        $root = Taproot::tapBranchHash(
            Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript),
            Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript)
        );
        [$outKey] = Taproot::tweakOutputKey(Taproot::keyAggInternalKey([$refundPub, $claimPub]), $root);
        return new SwapCreateResult(
            swapId: 'mocksw-' . bin2hex(random_bytes(4)),
            invoice: 'lnbcrt' . ($onchainAmountSats + 360) . 'n1mock' . self::$created,
            invoiceAmountSats: $onchainAmountSats + 360,
            onchainAmountSats: $onchainAmountSats,
            lockupAddress: Taproot::encodeP2trAddress($outKey, 'regtest'),
            refundPublicKeyHex: bin2hex($refundPub),
            timeoutBlockHeight: 1_000_000,
            claimLeafScript: $claimScript,
            refundLeafScript: $refundScript,
        );
    }
    public function getSwapStatus(string $network, string $swapId): ?SwapStatus {
        return new SwapStatus('swap.created', null, null, ['status' => 'swap.created']);
    }
    public function broadcastTx(string $network, string $rawTxHex): string { return 'mocktxid'; }
    public function cancelInvoice(string $network, string $swapId): void { self::$cancelled++; }
}
SwapProviderFactory::setRegistry(['mock' => new MockSwapProvider()]);

$now = time();
$lifetime = Config::getSharedInvoiceLifetime();
$store = 'store_swap_ll';
make_store($store, null); // no mint: swap is the only Lightning source
Database::update('stores', [
    'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
    'onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'regtest',
    'swaps_enabled' => SwapsConfig::FORCE_ON,
], 'id = ?', [$store]);
SwapsConfig::setStoreProviderOrder($store, ['mock']);
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_ON);

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function swap_status(string $invoiceId): ?string {
    $r = Database::fetchOne("SELECT status FROM swap_attempts WHERE invoice_id = ? ORDER BY id DESC LIMIT 1", [$invoiceId]);
    return $r ? (string)$r['status'] : null;
}

// ---- 1. swap-only store: nothing else can take the invoice over ----
$ll = Invoice::create($store, ['amount' => 50000, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
assert_eq('swap', $ll['payment_rail'], 'long-lived invoice rides the swap rail');
assert_eq(1, (int)$ll['long_lived'], 'long-lived');
assert_eq('swap.created', swap_status($ll['id']), 'swap held');
assert_true(abs((int)$ll['expiration_time'] - ($now + $lifetime)) <= 5, 'shared lifetime');
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll['id']]);
$r = Invoice::refreshLightning($ll['id']);
assert_eq('unavailable', $r['reason'], 'no direct rail / mint: the swap is never refreshed in place');
assert_eq(1, MockSwapProvider::$created, 'no second swap was created');
assert_eq(0, MockSwapProvider::$cancelled, 'held swap not cancelled');
assert_eq($ll['bolt11'], row($ll['id'])['bolt11'], 'swap bolt11 kept');
assert_eq('swap.created', swap_status($ll['id']), 'swap row untouched');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll['id']])['c'], 'nothing parked');

// ---- 2. a mint appears: the refresh moves the invoice onto it ----
$mint = start_mock_mint(['quotes' => [], 'quote_ttl' => 600]);
Database::update('stores', ['mint_url' => $mint['url']], 'id = ?', [$store]);

// 2a. ...but not while strict no-mint-fallback is on: the mint may not stand
// in for the swap rail, so the held swap stays exactly as it was.
Database::update('invoices', ['ln_refresh_lock_at' => null], 'id = ?', [$ll['id']]);
$r = Invoice::refreshLightning($ll['id']);
assert_eq('unavailable', $r['reason'], 'strict mode: the mint does not take the swap invoice over');
assert_eq('swap', row($ll['id'])['payment_rail'], 'strict mode: still on the swap rail');
assert_eq($ll['bolt11'], row($ll['id'])['bolt11'], 'strict mode: swap bolt11 kept');
assert_eq(0, MockSwapProvider::$cancelled, 'strict mode: held swap not cancelled');
assert_eq('swap.created', swap_status($ll['id']), 'strict mode: swap row untouched');

// 2b. strict off: the mint takes the invoice over.
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_OFF);
Database::update('invoices', ['ln_refresh_lock_at' => null], 'id = ?', [$ll['id']]);
$r = Invoice::refreshLightning($ll['id']);
assert_true($r['ok'], 'refresh ok via the mint: ' . json_encode($r['reason'] ?? null));
$a = row($ll['id']);
assert_eq('mint', $a['payment_rail'], 'now on the mint rail');
assert_eq('qauto1', $a['quote_id'], 'fresh mint quote');
assert_eq(50000, (int)$a['amount_sats'], 'mint quote for the invoice amount (the swap fee buffer is gone)');
assert_eq(1, MockSwapProvider::$created, 'still no second swap');
assert_eq(1, MockSwapProvider::$cancelled, 'held swap released at the provider');
assert_eq('invoice.expired', swap_status($ll['id']), 'swap row closed');
$parked = Database::fetchAll("SELECT * FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll['id']]);
assert_eq(1, count($parked), 'swap bolt11 parked');
assert_eq('swap', $parked[0]['payment_rail'], 'parked as swap rail');
assert_eq($ll['bolt11'], $parked[0]['bolt11'], 'parked bolt11 is the swap one');
assert_eq($ll['onchain_address'], $a['onchain_address'], 'on-chain rail untouched by the refresh');

echo "PASS test_long_lived_swap_exclusion\n";
