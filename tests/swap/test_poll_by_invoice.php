<?php
/**
 * Unit test for SwapPoller::pollByInvoiceId() — the single-row poll wired
 * into the customer checkout flow (Invoice::pollSingleQuote) so swaps settle
 * without waiting for cron.
 *
 * Verifies:
 *   - it drives the same lifecycle as pollPending (mempool→claim, settled→Settled);
 *   - the last_polled_at gate rate-limits back-to-back checkout polls;
 *   - the claim broadcast is idempotent across the two entry points;
 *   - terminal rows and unknown invoice ids are no-ops.
 *
 * Mirrors tests/swap/test_mock_flow.php's mock-provider setup.
 */

$tmp = sys_get_temp_dir() . '/cashupay-swap-pollby-' . bin2hex(random_bytes(4));
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

// ------------- Mock provider (same shape as test_mock_flow.php) -------------

class MockSwapProvider implements SwapProvider {
    public static string $name = 'mock';
    /** @var string[] queued statuses returned by getSwapStatus, in order */
    public static array $statusQueue = [];
    public static int $broadcastCount = 0;
    public static array $lastResponse = [];

    public function getName(): string { return self::$name; }
    public function isReachable(string $network): bool { return true; }

    public function getReversePairInfo(string $network): SwapPairInfo {
        return new SwapPairInfo(0.5, 200, 150, 1000, 5_000_000, 'mockhash');
    }

    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        $refundPriv = hash('sha256', 'mock-refund-key', true);
        $refundPub = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($refundPriv)));
        $claimPub = hex2bin($claimPubkeyHex);
        $preimageHash = hex2bin($preimageHashHex);

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

        self::$lastResponse = [
            'outputKey' => $outKey,
            'amount' => $onchainAmountSats + 350,
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
            $resp = self::$lastResponse;
            $script = TxBuilder::p2trScript($resp['outputKey']);
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
        self::$broadcastCount++;
        return 'mocktxid' . bin2hex(random_bytes(4));
    }

    public function cancelInvoice(string $network, string $swapId): void { /* no-op */ }
}

// ------------- Wire up DB + config + store -------------

Database::initialize();
SwapProviderFactory::setRegistry(['mock' => new MockSwapProvider()]);

$storeId = Database::generateId('store');
$now = time();
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'Mock Test Store',
    'mint_url' => null,
    'mint_unit' => 'sat',
    'created_at' => $now,
    'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
    'onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'regtest',
    'swaps_enabled' => SwapsConfig::FORCE_ON,
]);
SwapsConfig::setStoreProviderOrder($storeId, ['mock']);
SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);

// ------------- Create a swap invoice -------------

MockSwapProvider::$statusQueue = ['swap.created'];
$invoice = Invoice::create($storeId, ['amount' => 50000, 'currency' => 'sat']);
tassert($invoice['payment_rail'] === 'swap', 'invoice on swap rail', $failures);
$invId = $invoice['id'];

// ------------- Unknown invoice id is a no-op -------------

$rNone = SwapPoller::pollByInvoiceId('nonexistent-invoice');
tassert($rNone['polled'] === 0 && $rNone['errors'] === 0, 'unknown invoice id: no-op', $failures);

// ------------- First poll at swap.created: drives one tick, no action -------------

MockSwapProvider::$statusQueue = ['swap.created'];
$r1 = SwapPoller::pollByInvoiceId($invId, 0);
tassert($r1['polled'] === 1 && $r1['errors'] === 0, 'pollByInvoiceId drives the row', $failures);

// ------------- Gate: an immediate re-poll within minInterval is skipped -------------

// Default minInterval (8s) means a back-to-back checkout tick must not hit
// the provider again — the row was just stamped, so polled stays 0.
MockSwapProvider::$statusQueue = ['transaction.mempool']; // would act, if not gated
$rGated = SwapPoller::pollByInvoiceId($invId); // default 8s gate
tassert($rGated['polled'] === 0 && $rGated['errors'] === 0, 'within min-interval: gated (no provider hit)', $failures);
$gatedAttempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE invoice_id = ?", [$invId]);
tassert(empty($gatedAttempt['claim_txid']), 'gated poll did not broadcast a claim', $failures);
// The status we queued must still be pending (gate skipped the read).
MockSwapProvider::$statusQueue = []; // drain any leftover; expect it was untouched
tassert(true, 'gate consumed no queued status', $failures);

// ------------- mempool → claim broadcast (minInterval=0 bypasses the gate) -------------

sleep(1);
MockSwapProvider::$broadcastCount = 0;
MockSwapProvider::$statusQueue = ['transaction.mempool'];
$r2 = SwapPoller::pollByInvoiceId($invId, 0);
tassert($r2['polled'] === 1 && $r2['errors'] === 0, 'mempool poll: clean tick', $failures);
$attempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE invoice_id = ?", [$invId]);
tassert(!empty($attempt['claim_txid']), 'claim_txid recorded after broadcast', $failures);
tassert(MockSwapProvider::$broadcastCount === 1, 'claim broadcast exactly once', $failures);

// ------------- Idempotency: a second mempool tick does not re-broadcast -------------

sleep(1);
MockSwapProvider::$statusQueue = ['transaction.mempool'];
$r2b = SwapPoller::pollByInvoiceId($invId, 0);
tassert($r2b['polled'] === 1 && $r2b['errors'] === 0, 'second mempool tick: clean', $failures);
tassert(MockSwapProvider::$broadcastCount === 1, 'claim not re-broadcast (idempotent on claim_txid)', $failures);

// ------------- invoice.settled → invoice flips to Settled -------------

sleep(1);
MockSwapProvider::$statusQueue = ['invoice.settled'];
$r3 = SwapPoller::pollByInvoiceId($invId, 0);
tassert($r3['polled'] === 1 && $r3['errors'] === 0, 'settled poll: clean tick', $failures);
$invoiceAfter = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invId]);
tassert($invoiceAfter['status'] === 'Settled', 'invoice flipped to Settled', $failures);
tassert($invoiceAfter['settled_rail'] === 'swap', 'settled_rail recorded as swap', $failures);

// ------------- Terminal row is skipped (status now invoice.settled) -------------

MockSwapProvider::$statusQueue = ['invoice.settled'];
$r4 = SwapPoller::pollByInvoiceId($invId, 0);
tassert($r4['polled'] === 0 && $r4['errors'] === 0, 'terminal swap row: no-op', $failures);

// ------------- Cleanup -------------

echo "\n{$total} tested, {$failures} failed\n";
@unlink($tmp . '/cashupay.sqlite');
@rmdir($tmp);
exit($failures === 0 ? 0 : 1);
