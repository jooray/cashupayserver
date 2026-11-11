<?php
/**
 * Behavioural test for the fee-too-high gate inside Invoice::trySwapCreate.
 *
 * Confirms that when the prospective swap's total cost exceeds an active
 * threshold the provider's createReverseSwap is NEVER called (the swap is
 * skipped before creation) and trySwapCreate returns null — so Invoice::create
 * falls through to the mint path. When within thresholds (or disabled) the
 * provider is used as before.
 *
 * Drives the private trySwapCreate via reflection so we exercise the gate
 * without standing up a live cashu mint for the fallback half.
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/cashupay-feegate-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('CASHUPAY_DATA_DIR', $tmp);

require_once __DIR__ . '/../../app/includes/database.php';
require_once __DIR__ . '/../../app/includes/config.php';
require_once __DIR__ . '/../../app/includes/invoice.php';
require_once __DIR__ . '/../../app/includes/swap/factory.php';
require_once __DIR__ . '/../../app/includes/swap/config.php';
require_once __DIR__ . '/../../app/includes/swap/provider.php';
require_once __DIR__ . '/../../app/includes/crypto/secp256k1.php';
require_once __DIR__ . '/../../app/includes/crypto/taproot.php';
require_once __DIR__ . '/../../app/includes/crypto/tx_builder.php';

$failures = 0;
$total = 0;
function tassert(bool $cond, string $msg): void {
    global $total, $failures; $total++;
    if ($cond) { echo "PASS {$msg}\n"; }
    else { echo "FAIL {$msg}\n"; $failures++; }
}

/**
 * Minimal mock provider with a fixed fee quote and a valid (self-consistent)
 * lockup tree so verifySwapLockup passes on the success path. Counts how many
 * times createReverseSwap is invoked.
 *
 * Quote: 0.5% + 200 lockup + 150 claim. For a 50,000 sat target the total cost
 * is ceil(50000*0.5/100) + 200 + 150 = 250 + 350 = 600 sats.
 */
class FeeGateMockProvider implements SwapProvider {
    public static int $createdCount = 0;
    public function getName(): string { return 'feegatemock'; }
    public function isReachable(string $network): bool { return true; }

    public function getReversePairInfo(string $network): SwapPairInfo {
        return new SwapPairInfo(0.5, 200, 150, 1000, 5_000_000, 'mockhash');
    }

    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        self::$createdCount++;
        $refundPriv = hash('sha256', 'mock-refund-key', true);
        $refundPub = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($refundPriv)));
        $claimPub = hex2bin($claimPubkeyHex);
        $preimageHash = hex2bin($preimageHashHex);

        $claimXOnly = substr($claimPub, 1);
        $hash160 = hash('ripemd160', hash('sha256', $preimageHash, true), true);
        $claimScript = "\x82\x01\x20\x88\xA9" . chr(20) . $hash160 . "\x88" . chr(32) . $claimXOnly . "\xAC";
        $refundXOnly = substr($refundPub, 1);
        $refundScript = chr(32) . $refundXOnly . "\xAD" . TxBuilder::scriptNumberPush(1_000_000) . "\xB1";

        $claimLeaf  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
        $refundLeaf = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
        $merkleRoot = Taproot::tapBranchHash($claimLeaf, $refundLeaf);
        $internalKey = Taproot::keyAggInternalKey([$refundPub, $claimPub]);
        [$outKey] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
        $lockup = Taproot::encodeP2trAddress($outKey, $network === 'regtest' ? 'regtest' : 'mainnet');

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

    public function getSwapStatus(string $network, string $swapId): ?SwapStatus { return null; }
    public function broadcastTx(string $network, string $rawTxHex): string { return 'mocktxid'; }
    public function cancelInvoice(string $network, string $swapId): void { /* no-op */ }
}

Database::initialize();
SwapProviderFactory::setRegistry(['feegatemock' => new FeeGateMockProvider()]);

$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'Fee Gate Store',
    'mint_url' => null,
    'mint_unit' => 'sat',
    'created_at' => time(),
    'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
    'onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'regtest',
    'swaps_enabled' => SwapsConfig::FORCE_ON,
]);
SwapsConfig::setStoreProviderOrder($storeId, ['feegatemock']);
$store = Config::getStore($storeId);

$gate = new ReflectionMethod(Invoice::class, 'trySwapCreate');
$gate->setAccessible(true);

// Helper: invoke trySwapCreate(storeId, store, target, failures, maxPct, maxSats).
$run = function (int $target, float $maxPct, int $maxSats) use ($gate, $storeId, $store) {
    $failureReasons = [];
    $args = [$storeId, $store, $target, &$failureReasons, $maxPct, $maxSats];
    $res = $gate->invokeArgs(null, $args);
    return [$res, $failureReasons];
};

$TARGET = 50000; // total cost = 600 sats at the mock's quote

// --- Disabled (0/0): swap proceeds as before -------------------------------
FeeGateMockProvider::$createdCount = 0;
[$res] = $run($TARGET, 0.0, 0);
tassert($res !== null, 'thresholds off → swap created');
tassert(FeeGateMockProvider::$createdCount === 1, 'thresholds off → provider createReverseSwap called once');

// --- Sats cap exceeded (600 > 500): fall back, provider not called ---------
FeeGateMockProvider::$createdCount = 0;
[$res, $reasons] = $run($TARGET, 0.0, 500);
tassert($res === null, 'sats cap exceeded → trySwapCreate returns null');
tassert(FeeGateMockProvider::$createdCount === 0, 'sats cap exceeded → createReverseSwap NOT called');
tassert(
    count($reasons) > 0 && str_contains(implode(' ', $reasons), 'mint-fallback threshold'),
    'failure reason names the mint-fallback threshold'
);

// --- Sats cap not exceeded (600 <= 1000): swap proceeds --------------------
FeeGateMockProvider::$createdCount = 0;
[$res] = $run($TARGET, 0.0, 1000);
tassert($res !== null, 'sats cap with headroom → swap created');
tassert(FeeGateMockProvider::$createdCount === 1, 'sats cap with headroom → provider called');

// --- Percent cap exceeded (600 > 1% of 50000 = 500): fall back -------------
FeeGateMockProvider::$createdCount = 0;
[$res] = $run($TARGET, 1.0, 0);
tassert($res === null, 'percent cap exceeded → trySwapCreate returns null');
tassert(FeeGateMockProvider::$createdCount === 0, 'percent cap exceeded → createReverseSwap NOT called');

// --- Percent cap not exceeded (600 <= 2% of 50000 = 1000): swap proceeds ---
FeeGateMockProvider::$createdCount = 0;
[$res] = $run($TARGET, 2.0, 0);
tassert($res !== null, 'percent cap with headroom → swap created');
tassert(FeeGateMockProvider::$createdCount === 1, 'percent cap with headroom → provider called');

echo "\n{$total} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
