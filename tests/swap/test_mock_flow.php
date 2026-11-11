<?php
/**
 * End-to-end flow test driven by a mock SwapProvider.
 *
 * Spins up a throwaway SQLite database, configures a store with an xpub,
 * enables swaps with a custom provider injected via SwapProviderFactory's
 * test seam, creates an invoice, and walks the SwapPoller through every
 * lifecycle status. Verifies invoices.payment_rail and the swap_attempts
 * row exist and transition correctly.
 *
 * Does NOT broadcast real on-chain transactions — the mock's lockup tx
 * is a hand-crafted fake that exercises the parser only.
 */

// Isolate from any existing data dir.
$tmp = sys_get_temp_dir() . '/cashupay-swap-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('CASHUPAY_DATA_DIR', $tmp);

require_once __DIR__ . '/../../app/includes/database.php';
require_once __DIR__ . '/../../app/includes/config.php';
require_once __DIR__ . '/../../app/includes/invoice.php';
require_once __DIR__ . '/../../app/includes/swap/factory.php';
require_once __DIR__ . '/../../app/includes/swap/poller.php';
require_once __DIR__ . '/../../app/includes/swap/provider.php';
require_once __DIR__ . '/../../app/includes/crypto/secp256k1.php';
require_once __DIR__ . '/../../app/includes/crypto/taproot.php';
require_once __DIR__ . '/../../app/includes/crypto/tx_builder.php';

$failures = 0;
$total = 0;
function tassert(bool $cond, string $msg, &$failures): void {
    global $total; $total++;
    if ($cond) echo "PASS {$msg}\n";
    else { echo "FAIL {$msg}\n"; $failures++; }
}

// ------------- Mock provider -------------

/**
 * Mock provider that returns a precomputed swap response with internally
 * consistent taproot tree, then transitions through a scripted lifecycle.
 *
 * The lockup output points to a P2TR address derived from the same
 * (claim, refund) keys, so SwapClaimer can compute the same control block
 * and Verify the signature. We don't actually broadcast — broadcastTx
 * returns a fake txid.
 */
class MockSwapProvider implements SwapProvider {
    public static string $name = 'mock';
    /** @var string[] queued statuses returned by getSwapStatus, in order */
    public static array $statusQueue = [];
    public static int $createdCount = 0;
    public static string $broadcastReceived = '';
    public static array $lastResponse = [];

    public function getName(): string { return self::$name; }
    public function isReachable(string $network): bool { return true; }

    public function getReversePairInfo(string $network): SwapPairInfo {
        return new SwapPairInfo(0.5, 200, 150, 1000, 5_000_000, 'mockhash');
    }

    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        self::$createdCount++;

        // Generate a deterministic refund key for the test.
        $refundPriv = hash('sha256', 'mock-refund-key', true);
        $refundPub = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($refundPriv)));
        $claimPub = hex2bin($claimPubkeyHex);
        $preimageHash = hex2bin($preimageHashHex);

        // Build Boltz-style claim leaf (preimage check + claim pubkey checksig)
        $claimXOnly = substr($claimPub, 1);
        $hash160Of_preimageHash = hash('ripemd160', hash('sha256', $preimageHash, true), true);
        $claimScript = "\x82\x01\x20\x88\xA9" . chr(20) . $hash160Of_preimageHash
                     . "\x88" . chr(32) . $claimXOnly . "\xAC";
        $refundXOnly = substr($refundPub, 1);
        $refundScript = chr(32) . $refundXOnly . "\xAD" . TxBuilder::scriptNumberPush(1_000_000) . "\xB1";

        $claimLeaf  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
        $refundLeaf = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
        $merkleRoot = Taproot::tapBranchHash($claimLeaf, $refundLeaf);
        $internalKey = Taproot::keyAggInternalKey([$refundPub, $claimPub]);
        [$outKey, $parity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
        $lockup = Taproot::encodeP2trAddress($outKey, $network === 'regtest' ? 'regtest' : 'mainnet');

        // Stash for the lifecycle: we'll synthesize a lockup tx when status reaches mempool.
        self::$lastResponse = [
            'lockupAddress' => $lockup,
            'outputKey' => $outKey,
            'claimScript' => $claimScript,
            'refundScript' => $refundScript,
            'claimPub' => $claimPub,
            'refundPub' => $refundPub,
            'amount' => $onchainAmountSats + 350, // amount Boltz would lock (target + lockup fee buffer)
            'parity' => $parity,
        ];

        return new SwapCreateResult(
            swapId: 'mocksw-' . bin2hex(random_bytes(4)),
            invoice: 'lnbcrt' . ($onchainAmountSats + 360) . 'n1mock',
            invoiceAmountSats: $onchainAmountSats + 360,
            onchainAmountSats: $onchainAmountSats,
            lockupAddress: $lockup,
            refundPublicKeyHex: bin2hex($refundPub),
            timeoutBlockHeight: 1_000_000,
            claimLeafScript: $claimScript,
            refundLeafScript: $refundScript,
        );
    }

    public function getSwapStatus(string $network, string $swapId): ?SwapStatus {
        if (empty(self::$statusQueue)) return null;
        $next = array_shift(self::$statusQueue);
        $lockupTxHex = null;
        $preimage = null;
        if ($next === 'transaction.mempool' || $next === 'transaction.confirmed') {
            // Build a synthetic lockup tx paying our lockup output.
            $resp = self::$lastResponse;
            $script = TxBuilder::p2trScript($resp['outputKey']);
            // 1-input 1-output v2 tx, prevout is fake.
            $tx = pack('V', 2)
                . "\x01"
                . str_repeat("\x00", 32) . pack('V', 0xFFFFFFFF) . "\x00" . pack('V', 0xFFFFFFFD)
                . "\x01"
                . pack('P', $resp['amount']) . chr(strlen($script)) . $script
                . pack('V', 0);
            $lockupTxHex = bin2hex($tx);
        }
        if ($next === 'invoice.settled') {
            $preimage = str_repeat('aa', 32);
        }
        return new SwapStatus($next, $lockupTxHex, $preimage, ['status' => $next]);
    }

    public function broadcastTx(string $network, string $rawTxHex): string {
        self::$broadcastReceived = $rawTxHex;
        return 'mocktxid' . bin2hex(random_bytes(4));
    }

    public function cancelInvoice(string $network, string $swapId): void { /* no-op */ }
}

// ------------- Wire up DB + config + store -------------

Database::initialize();

// Swaps are store-only now; the store row below carries the flag and the
// provider prefs are written right after it exists.
SwapProviderFactory::setRegistry(['mock' => new MockSwapProvider()]);

// Create a store with xpub configured (regtest tpub for sanity).
$storeId = Database::generateId('store');
$now = time();
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'Mock Test Store',
    'mint_url' => null, // no mint — swap-only
    'mint_unit' => 'sat',
    'created_at' => $now,
    'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
    'onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'regtest',
    'swaps_enabled' => SwapsConfig::FORCE_ON,
]);
SwapsConfig::setStoreProviderOrder($storeId, ['mock']);
SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);

// ------------- Create an invoice (target 50,000 sats) -------------

MockSwapProvider::$statusQueue = ['swap.created'];
$invoice = Invoice::create($storeId, ['amount' => 50000, 'currency' => 'sat']);
tassert($invoice['payment_rail'] === 'swap', 'invoice on swap rail', $failures);
tassert(!empty($invoice['bolt11']), 'invoice has bolt11 from provider', $failures);
// The swap rail no longer disables the normal on-chain route: because this
// store has an xpub, the same invoice also carries a parallel pay-to-address.
// The swap supplies Lightning; the on-chain address settles independently.
tassert(!empty($invoice['onchain_address']), 'swap invoice also carries an on-chain address', $failures);
tassert((int)$invoice['onchain_amount_sat'] === 50000, 'on-chain rail targets the invoice amount', $failures);
tassert(empty($invoice['mint_url']), 'no mint_url on swap invoice', $failures);
// Longer-of-two expiry: the on-chain rail keeps the full default window and is
// not cut short by the (shorter-lived) Lightning side.
tassert((int)$invoice['expiration_time'] >= time() + Config::getInvoiceExpiration() - 60,
    'swap+onchain invoice keeps the full on-chain expiry window', $failures);
// The on-chain pay-to-address is a distinct allocation from the swap's claim
// address (both derived from the xpub), so the two rails never collide.
$swapClaimAddr = Database::fetchOne("SELECT merchant_address FROM swap_attempts WHERE invoice_id = ?", [$invoice['id']]);
tassert($swapClaimAddr && $swapClaimAddr['merchant_address'] !== $invoice['onchain_address'],
    'on-chain rail address differs from the swap claim address', $failures);

$attempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE invoice_id = ?", [$invoice['id']]);
tassert($attempt !== null, 'swap_attempts row created', $failures);
tassert($attempt['provider'] === 'mock', 'attempt provider name persisted', $failures);
tassert($attempt['status'] === 'swap.created', 'attempt starts at swap.created', $failures);
tassert($attempt['target_onchain_amount_sats'] === 50000, 'target on-chain amount persisted', $failures);
tassert($attempt['invoice_amount_sats'] > 50000, 'invoice amount includes fees', $failures);

// ------------- Poller: stay at swap.created (no action) -------------

MockSwapProvider::$statusQueue = ['swap.created'];
$r1 = SwapPoller::pollPending(0);
tassert($r1['polled'] === 1 && $r1['errors'] === 0, 'first poll: no error', $failures);

// ------------- Poller: transaction.mempool → triggers claim build+broadcast -------------

MockSwapProvider::$statusQueue = ['transaction.mempool'];
// Sleep so the second poll's `now` is strictly greater than the first
// poll's stamp; with minInterval=0 the row is eligible immediately, but
// some SQL dialects refuse the comparison when the diff is exactly 0.
sleep(1);
$r2 = SwapPoller::pollPending(0);
tassert($r2['polled'] === 1 && $r2['errors'] === 0, 'mempool poll: no error', $failures);

$attempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE id = ?", [$attempt['id']]);
tassert(!empty($attempt['claim_txid']), 'claim_txid recorded after broadcast', $failures);
tassert(!empty($attempt['lockup_txid']), 'lockup_txid recorded', $failures);
tassert(MockSwapProvider::$broadcastReceived !== '', 'broadcast called with raw tx', $failures);

// ------------- Poller: invoice.settled → invoice flips to Settled -------------

MockSwapProvider::$statusQueue = ['invoice.settled'];
sleep(1);
$r3 = SwapPoller::pollPending(0);
tassert($r3['polled'] === 1 && $r3['errors'] === 0, 'settled poll: no error', $failures);

$invoiceAfter = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoice['id']]);
tassert($invoiceAfter['status'] === 'Settled', 'invoice settled', $failures);

// ------------- On-chain OFFERING toggle (Lightning-only checkout) -------------
// A merchant can keep the xpub (swaps still need it to settle on-chain) but
// turn OFF the customer-facing on-chain rail for a Lightning-only checkout. The
// swap is still created and still allocates its xpub claim address; the invoice
// just carries no pay-to-address.
tassert(OnchainConfig::isEnabledForStore($storeId) === true, 'offering default: enabled', $failures);
OnchainConfig::setStoreOverride($storeId, OnchainConfig::FORCE_OFF);
tassert(OnchainConfig::isEnabledForStore($storeId) === false, 'offering-off: resolver reports disabled', $failures);
$lnOnly = Invoice::create($storeId, ['amount' => 50000, 'currency' => 'sat']);
tassert($lnOnly['payment_rail'] === 'swap', 'offering-off: invoice still on swap rail', $failures);
tassert(!empty($lnOnly['bolt11']), 'offering-off: swap bolt11 still present', $failures);
tassert(empty($lnOnly['onchain_address']), 'offering-off: no customer-facing on-chain address', $failures);
$lnOnlySwap = Database::fetchOne("SELECT merchant_address FROM swap_attempts WHERE invoice_id = ?", [$lnOnly['id']]);
tassert($lnOnlySwap && !empty($lnOnlySwap['merchant_address']),
    'offering-off: swap still allocates its xpub claim address (settles on-chain internally)', $failures);
OnchainConfig::setStoreOverride($storeId, OnchainConfig::FORCE_ON);
tassert(OnchainConfig::isEnabledForStore($storeId) === true, 'offering back on', $failures);
// A legacy -1 row (pre-store-only installs) resolves to on.
Database::query("UPDATE stores SET onchain_offer_enabled = -1 WHERE id = ?", [$storeId]);
tassert(OnchainConfig::isEnabledForStore($storeId) === true, 'legacy -1 offer row resolves to on', $failures);

// ------------- Lockup mismatch defense -------------

// New invoice with a provider that returns a wrong lockup_address
final class BadLockupProvider extends MockSwapProvider {
    public function getName(): string { return 'badmock'; }
    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        $res = parent::createReverseSwap($network, $onchainAmountSats, $claimPubkeyHex, $preimageHashHex);
        return new SwapCreateResult(
            swapId: $res->swapId,
            invoice: $res->invoice,
            invoiceAmountSats: $res->invoiceAmountSats,
            onchainAmountSats: $res->onchainAmountSats,
            lockupAddress: 'bcrt1p' . str_repeat('q', 58),  // garbage
            refundPublicKeyHex: $res->refundPublicKeyHex,
            timeoutBlockHeight: $res->timeoutBlockHeight,
            claimLeafScript: $res->claimLeafScript,
            refundLeafScript: $res->refundLeafScript,
        );
    }
}

SwapProviderFactory::setRegistry(['badmock' => new BadLockupProvider()]);
SwapsConfig::setStoreProviderOrder($storeId, ['badmock']);
SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);

// The mismatched swap must be rejected — but a Lightning-path failure never
// aborts creation while another rail can serve: with the store's xpub the
// invoice degrades to on-chain-only (strict mode only forbids the MINT
// fallback), and no swap_attempts row is persisted for the bad swap.
$badInvoice = Invoice::create($storeId, ['amount' => 50000, 'currency' => 'sat']);
tassert($badInvoice['payment_rail'] === 'onchain',
    'lockup mismatch rejected (invoice degrades to the on-chain rail, not the bad swap)', $failures);
tassert(empty($badInvoice['bolt11']), 'no bolt11 from the rejected swap', $failures);
tassert(!empty($badInvoice['onchain_address']), 'on-chain address still allocated', $failures);
$badSwapRows = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM swap_attempts WHERE invoice_id = ?", [$badInvoice['id']]
);
tassert((int)$badSwapRows['c'] === 0, 'no swap_attempts row for the rejected swap', $failures);

// ------------- Cleanup -------------

echo "\n{$total} tested, {$failures} failed\n";
@unlink($tmp . '/cashupay.sqlite');
@rmdir($tmp);
exit($failures === 0 ? 0 : 1);
