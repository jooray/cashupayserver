<?php
/**
 * Tests for the auto-select-cheapest swap provider ranking.
 *
 * The ranking algorithm lives in SwapProviderFactory::rankedForStore() and
 * is driven by SwapQuoteFetcher + SwapsConfig. We use plain SwapProvider
 * implementations (not BoltzLikeProvider) so the fetcher falls through to
 * its sequential path and we can avoid wiring up curl_multi.
 *
 * Additionally exercises Invoice::create() once to confirm that the audit
 * trail lands in swap_attempts.quotes_compared_json.
 */

$tmp = sys_get_temp_dir() . '/cashupay-autoselect-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('CASHUPAY_DATA_DIR', $tmp);

require_once __DIR__ . '/../../app/includes/database.php';
require_once __DIR__ . '/../../app/includes/config.php';
require_once __DIR__ . '/../../app/includes/invoice.php';
require_once __DIR__ . '/../../app/includes/swap/factory.php';
require_once __DIR__ . '/../../app/includes/swap/config.php';
require_once __DIR__ . '/../../app/includes/swap/quote_fetcher.php';
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

/**
 * Mock provider that returns a configurable SwapPairInfo. Used to test
 * the ranking algorithm without any HTTP traffic.
 */
class QuoteMockProvider implements SwapProvider {
    public int $createCalls = 0;
    public int $quoteCalls = 0;
    public bool $createThrows = false;
    public bool $quoteThrows = false;
    public function __construct(
        private string $name,
        protected SwapPairInfo $pairInfo,
    ) {}
    public function getName(): string { return $this->name; }
    public function isReachable(string $network): bool { return true; }
    public function getReversePairInfo(string $network): SwapPairInfo {
        $this->quoteCalls++;
        if ($this->quoteThrows) throw new RuntimeException("{$this->name}: quote unavailable");
        return $this->pairInfo;
    }
    public function createReverseSwap(string $network, int $onchainAmountSats,
                                       string $claimPublicKeyHex, string $preimageHashHex): SwapCreateResult {
        $this->createCalls++;
        if ($this->createThrows) throw new RuntimeException("{$this->name}: create unavailable");
        throw new RuntimeException("not implemented in ranking tests");
    }
    public function getSwapStatus(string $network, string $swapId): ?SwapStatus { return null; }
    public function broadcastTx(string $network, string $rawTxHex): string { return ''; }
    public function cancelInvoice(string $network, string $swapId): void {}
}

// SwapPairInfo args: feePercent, lockupFeeSats, claimFeeEstimateSats, minSats, maxSats, pairHash.

Database::initialize();

// All swap prefs are per-store now. The pure ranking tests below share this
// settings-only store; the Invoice::create() tests re-point the prefs at the
// store they create.
const AUTOSEL_STORE = 'autosel-settings-store';
Database::insert('stores', [
    'id' => AUTOSEL_STORE, 'name' => 'AutoSelect Settings',
    'mint_unit' => 'sat', 'created_at' => time(),
]);

// ------------- Ranking: cheapest is >10% cheaper, wins -------------

{
    // For target=10000 sats:
    //   leader (priority 0): 0.5% + 200 + 150 = 50 + 350 = 400 sats total
    //   cheap  (priority 1): 0.1% + 100 + 100 = 10 + 200 = 210 sats total
    //   210 < 400 * 0.9 = 360 → cheap wins.
    $leader = new QuoteMockProvider('leader', new SwapPairInfo(0.5, 200, 150, 1000, 5_000_000, 'h1'));
    $cheap  = new QuoteMockProvider('cheap',  new SwapPairInfo(0.1, 100, 100, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['leader' => $leader, 'cheap' => $cheap]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['leader', 'cheap']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);
    SwapsConfig::setStoreAutoSelectThresholdPct(AUTOSEL_STORE, 10);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['cheap', 'leader'], 'cheapest >10% cheaper is promoted to first', $failures);
    tassert($ranked[0]['quote'] !== null, 'cheapest carries cached quote into ranking', $failures);

    $audit = SwapQuoteFetcher::lastAuditTrail();
    tassert($audit !== null && $audit['chosen'] === 'cheap', 'audit chose cheapest', $failures);
    tassert($audit['reason'] === 'cheapest_below_threshold', 'audit reason = cheapest_below_threshold', $failures);
    tassert($audit['threshold_pct'] === 10, 'audit records threshold', $failures);
}

// ------------- Ranking: exactly 10% cheaper, leader wins (strict >) -------------

{
    // For target=10000:
    //   leader: 1% + 0 + 0 = 100
    //   exact:  0.9% + 0 + 0 = 90
    //   90 * 100 = 9000, leader 100 * (100-10) = 9000. NOT strictly less → leader wins.
    $leader = new QuoteMockProvider('leader', new SwapPairInfo(1.0, 0, 0, 1000, 5_000_000, 'h1'));
    $exact  = new QuoteMockProvider('exact',  new SwapPairInfo(0.9, 0, 0, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['leader' => $leader, 'exact' => $exact]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['leader', 'exact']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);
    SwapsConfig::setStoreAutoSelectThresholdPct(AUTOSEL_STORE, 10);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['leader', 'exact'], 'exactly 10% cheaper does NOT promote (strict >)', $failures);
    $audit = SwapQuoteFetcher::lastAuditTrail();
    tassert($audit['reason'] === 'priority_leader', 'audit reason = priority_leader at exact threshold', $failures);
}

// ------------- Ranking: only 5% cheaper, leader wins -------------

{
    $leader = new QuoteMockProvider('leader', new SwapPairInfo(1.0, 0, 0, 1000, 5_000_000, 'h1'));
    $slight = new QuoteMockProvider('slight', new SwapPairInfo(0.95, 0, 0, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['leader' => $leader, 'slight' => $slight]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['leader', 'slight']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);
    SwapsConfig::setStoreAutoSelectThresholdPct(AUTOSEL_STORE, 10);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['leader', 'slight'], 'only 5% cheaper leaves priority order unchanged', $failures);
}

// ------------- Ranking: feature disabled → identical to orderedForStore -------------

{
    $leader = new QuoteMockProvider('leader', new SwapPairInfo(1.0, 0, 0, 1000, 5_000_000, 'h1'));
    $cheap  = new QuoteMockProvider('cheap',  new SwapPairInfo(0.1, 0, 0, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['leader' => $leader, 'cheap' => $cheap]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['leader', 'cheap']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, false);
    SwapsConfig::setStoreAutoSelectThresholdPct(AUTOSEL_STORE, 10);

    $leader->quoteCalls = 0;
    $cheap->quoteCalls = 0;
    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['leader', 'cheap'], 'feature off: priority order preserved', $failures);
    tassert($ranked[0]['quote'] === null && $ranked[1]['quote'] === null,
            'feature off: no quotes prefetched', $failures);
    tassert($leader->quoteCalls === 0 && $cheap->quoteCalls === 0,
            'feature off: no getReversePairInfo calls', $failures);
    $audit = SwapQuoteFetcher::lastAuditTrail();
    tassert($audit['reason'] === 'auto_select_disabled', 'audit records disabled reason', $failures);
}

// ------------- Ranking: leader quote unavailable, single candidate selected -------------

{
    $leader = new QuoteMockProvider('leader', new SwapPairInfo(1.0, 0, 0, 1000, 5_000_000, 'h1'));
    $leader->quoteThrows = true;
    $alt    = new QuoteMockProvider('alt',    new SwapPairInfo(0.5, 0, 0, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['leader' => $leader, 'alt' => $alt]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['leader', 'alt']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);
    SwapsConfig::setStoreAutoSelectThresholdPct(AUTOSEL_STORE, 10);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    // alt is the only candidate. Leader gets appended at the end (unreachable).
    tassert($order === ['alt', 'leader'], 'unreachable leader falls to end of list', $failures);
    tassert($ranked[0]['quote'] !== null, 'alt carries its cached quote', $failures);
    tassert($ranked[1]['quote'] === null, 'unreachable leader has null cached quote', $failures);
    $audit = SwapQuoteFetcher::lastAuditTrail();
    tassert($audit['chosen'] === 'alt', 'chosen = surviving candidate when leader down', $failures);
}

// ------------- Ranking: all quotes fail → full priority list returned, no new failure mode -------------

{
    $a = new QuoteMockProvider('a', new SwapPairInfo(0.5, 0, 0, 1000, 5_000_000, 'h1'));
    $b = new QuoteMockProvider('b', new SwapPairInfo(0.5, 0, 0, 1000, 5_000_000, 'h2'));
    $a->quoteThrows = true;
    $b->quoteThrows = true;
    SwapProviderFactory::setRegistry(['a' => $a, 'b' => $b]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['a', 'b']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['a', 'b'], 'all-quotes-failed returns priority order intact', $failures);
    tassert($ranked[0]['quote'] === null && $ranked[1]['quote'] === null,
            'no cached quotes when all fail (sequential fallback will retry)', $failures);
    $audit = SwapQuoteFetcher::lastAuditTrail();
    tassert($audit['reason'] === 'all_quotes_failed', 'audit records all_quotes_failed reason', $failures);
}

// ------------- Ranking: out-of-range candidate excluded but carried with cached quote -------------

{
    $smallrange = new QuoteMockProvider('smallrange',
        new SwapPairInfo(0.1, 0, 0, 1, 5_000, 'h1'));   // max=5000, target=10000 → out of range
    $normal     = new QuoteMockProvider('normal',
        new SwapPairInfo(1.0, 0, 0, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['smallrange' => $smallrange, 'normal' => $normal]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['smallrange', 'normal']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 10000);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['normal', 'smallrange'], 'out-of-range provider falls to end', $failures);
    tassert($ranked[1]['quote'] !== null,
            'out-of-range still carries cached quote (no re-fetch needed for range check)', $failures);
}

// ------------- Ranking: 4-provider recursive rule example from the plan -------------

{
    // Same fee%, varying lockup fee so total = lockup. Target=0 makes percent contribution 0.
    // P1: lockup=100, P2: lockup=95, P3: lockup=89, P4: lockup=84. Threshold 10.
    // Plan's expected order: P4, P3, P1, P2.
    $p1 = new QuoteMockProvider('p1', new SwapPairInfo(0, 100, 0, 0, 1_000_000_000, 'h1'));
    $p2 = new QuoteMockProvider('p2', new SwapPairInfo(0, 95,  0, 0, 1_000_000_000, 'h2'));
    $p3 = new QuoteMockProvider('p3', new SwapPairInfo(0, 89,  0, 0, 1_000_000_000, 'h3'));
    $p4 = new QuoteMockProvider('p4', new SwapPairInfo(0, 84,  0, 0, 1_000_000_000, 'h4'));
    SwapProviderFactory::setRegistry(['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4]);
    SwapsConfig::setStoreProviderOrder(AUTOSEL_STORE, ['p1', 'p2', 'p3', 'p4']);
    SwapsConfig::setStoreAutoSelectCheapest(AUTOSEL_STORE, true);
    SwapsConfig::setStoreAutoSelectThresholdPct(AUTOSEL_STORE, 10);

    $ranked = SwapProviderFactory::rankedForStore(AUTOSEL_STORE, 'mainnet', 0);
    $order = array_map(fn($r) => $r['provider']->getName(), $ranked);
    tassert($order === ['p4', 'p3', 'p1', 'p2'],
            '4-provider recursive rule matches plan example (P4, P3, P1, P2)', $failures);
}

// ------------- Total cost calculation -------------

{
    // 1% of 10000 = 100, + 200 lockup + 50 claim = 350
    $info = new SwapPairInfo(1.0, 200, 50, 1, 1_000_000, null);
    tassert(SwapQuoteFetcher::totalCostSats($info, 10_000) === 350,
            'totalCostSats: 1% + lockup + claim arithmetic', $failures);
    // Fractional percent rounds up
    $info2 = new SwapPairInfo(0.5, 0, 0, 1, 1_000_000, null);
    tassert(SwapQuoteFetcher::totalCostSats($info2, 9_999) === 50,
            'totalCostSats: fractional percent rounded up', $failures);
}

// ------------- audit JSON persisted in swap_attempts -------------
// We exercise the full Invoice::create() path here. That needs a swap that
// passes lockup verification, so we reuse the BoltzLike-tree mock from
// test_mock_flow.php (inlined here in compact form).

class LockupTreeMockProvider extends QuoteMockProvider {
    public static array $lastResponse = [];
    public function createReverseSwap(string $network, int $onchainAmountSats,
                                       string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        $refundPriv = hash('sha256', 'autoselect-test-refund:' . $this->getName(), true);
        $refundPub = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($refundPriv)));
        $claimPub = hex2bin($claimPubkeyHex);
        $preimageHash = hex2bin($preimageHashHex);
        $claimXOnly = substr($claimPub, 1);
        $hash160 = hash('ripemd160', hash('sha256', $preimageHash, true), true);
        $claimScript = "\x82\x01\x20\x88\xA9" . chr(20) . $hash160
                     . "\x88" . chr(32) . $claimXOnly . "\xAC";
        $refundXOnly = substr($refundPub, 1);
        $refundScript = chr(32) . $refundXOnly . "\xAD" . TxBuilder::scriptNumberPush(1_000_000) . "\xB1";
        $claimLeaf  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
        $refundLeaf = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
        $merkleRoot = Taproot::tapBranchHash($claimLeaf, $refundLeaf);
        $internalKey = Taproot::keyAggInternalKey([$refundPub, $claimPub]);
        [$outKey, $_p] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
        $lockup = Taproot::encodeP2trAddress($outKey, $network === 'regtest' ? 'regtest' : 'mainnet');
        self::$lastResponse = ['lockup' => $lockup];
        // Issue an invoice CONSISTENT with this provider's own quote
        // (lockup fee + percent fee), so a well-behaved provider passes
        // Invoice::trySwapCreate's overcharge bound. A provider that returned
        // more than its quoted economics is exactly what that bound rejects.
        $consistentInvoice = $onchainAmountSats
            + $this->pairInfo->lockupFeeSats
            + (int)ceil($onchainAmountSats * $this->pairInfo->feePercent / 100.0);
        return new SwapCreateResult(
            swapId: 'sw-' . $this->getName() . '-' . bin2hex(random_bytes(3)),
            invoice: 'lnbcrt' . $consistentInvoice . 'n1mockauto',
            invoiceAmountSats: $consistentInvoice,
            onchainAmountSats: $onchainAmountSats,
            lockupAddress: $lockup,
            refundPublicKeyHex: bin2hex($refundPub),
            timeoutBlockHeight: 1_000_000,
            claimLeafScript: $claimScript,
            refundLeafScript: $refundScript,
        );
    }
}

{
    $cheap = new LockupTreeMockProvider('cheaplt', new SwapPairInfo(0.1, 50, 50, 1000, 5_000_000, 'h1'));
    $leader = new LockupTreeMockProvider('leadlt', new SwapPairInfo(1.0, 500, 500, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['leadlt' => $leader, 'cheaplt' => $cheap]);

    $storeId = Database::generateId('store');
    $now = time();
    Database::insert('stores', [
        'id' => $storeId,
        'name' => 'AutoSelect Store',
        'mint_url' => null,
        'mint_unit' => 'sat',
        'created_at' => $now,
        'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
        'onchain_address_type' => 'P2WPKH',
        'onchain_network' => 'regtest',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ]);
    SwapsConfig::setStoreProviderOrder($storeId, ['leadlt', 'cheaplt']);
    SwapsConfig::setStoreAutoSelectCheapest($storeId, true);
    SwapsConfig::setStoreAutoSelectThresholdPct($storeId, 10);
    SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);

    $invoice = Invoice::create($storeId, ['amount' => 50_000, 'currency' => 'sat']);
    tassert($invoice['payment_rail'] === 'swap', 'invoice ended up on swap rail', $failures);
    $attempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE invoice_id = ?", [$invoice['id']]);
    tassert($attempt !== null, 'swap_attempts row exists', $failures);
    tassert($attempt['provider'] === 'cheaplt', 'cheaper provider was chosen', $failures);
    tassert(!empty($attempt['quotes_compared_json']), 'quotes_compared_json populated', $failures);
    $decoded = json_decode($attempt['quotes_compared_json'], true);
    tassert(is_array($decoded) && ($decoded['chosen'] ?? null) === 'cheaplt',
            'audit JSON chosen field matches db.provider', $failures);
    tassert(($decoded['threshold_pct'] ?? null) === 10, 'audit JSON threshold_pct = 10', $failures);
    tassert(($decoded['reason'] ?? null) === 'cheapest_below_threshold',
            'audit JSON reason set', $failures);
    tassert(is_array($decoded['providers'] ?? null) && count($decoded['providers']) === 2,
            'audit JSON has both providers', $failures);
}

// ------------- audit JSON null when feature off -------------

{
    $only = new LockupTreeMockProvider('only', new SwapPairInfo(1.0, 100, 100, 1000, 5_000_000, 'h1'));
    SwapProviderFactory::setRegistry(['only' => $only]);

    $storeId = Database::generateId('store');
    Database::insert('stores', [
        'id' => $storeId,
        'name' => 'AutoSelect Off Store',
        'mint_url' => null,
        'mint_unit' => 'sat',
        'created_at' => time(),
        'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
        'onchain_address_type' => 'P2WPKH',
        'onchain_network' => 'regtest',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ]);
    SwapsConfig::setStoreAutoSelectCheapest($storeId, false);
    SwapsConfig::setStoreProviderOrder($storeId, ['only']);
    SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);

    $invoice = Invoice::create($storeId, ['amount' => 50_000, 'currency' => 'sat']);
    $attempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE invoice_id = ?", [$invoice['id']]);
    // With feature off, rankedForStore still records an audit ('auto_select_disabled')
    // and threads it through — that's the right behaviour so operators can see why a
    // particular row didn't compare. We just check the shape is sane.
    $decoded = $attempt['quotes_compared_json'] ? json_decode($attempt['quotes_compared_json'], true) : null;
    tassert($decoded === null || ($decoded['reason'] ?? null) === 'auto_select_disabled',
            'feature off: audit either null or reason=auto_select_disabled', $failures);
}

// ------------- Overcharge bound: provider that issues an invoice far above
// its own quote is rejected, and the flow falls through to a well-behaved one.

class OverchargeMockProvider extends LockupTreeMockProvider {
    public function createReverseSwap(string $network, int $onchainAmountSats,
                                       string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        $base = parent::createReverseSwap($network, $onchainAmountSats, $claimPubkeyHex, $preimageHashHex);
        // Re-issue with an invoice 5000 sat over the quoted economics — a
        // provider overcharging the payer. Everything else (valid lockup tree)
        // is kept so verifySwapLockup passes and only the amount bound fires.
        return new SwapCreateResult(
            swapId: $base->swapId,
            invoice: 'lnbcrt' . ($onchainAmountSats + 5000) . 'n1mockover',
            invoiceAmountSats: $onchainAmountSats + 5000,
            onchainAmountSats: $base->onchainAmountSats,
            lockupAddress: $base->lockupAddress,
            refundPublicKeyHex: $base->refundPublicKeyHex,
            timeoutBlockHeight: $base->timeoutBlockHeight,
            claimLeafScript: $base->claimLeafScript,
            refundLeafScript: $base->refundLeafScript,
        );
    }
}

{
    // greedy is first in priority but overcharges by 5000 sat; good is a
    // well-behaved fallback. Auto-select off so we walk strict priority order.
    $greedy = new OverchargeMockProvider('greedy', new SwapPairInfo(0.1, 50, 50, 1000, 5_000_000, 'h1'));
    $good   = new LockupTreeMockProvider('good',   new SwapPairInfo(0.1, 50, 50, 1000, 5_000_000, 'h2'));
    SwapProviderFactory::setRegistry(['greedy' => $greedy, 'good' => $good]);

    $storeId = Database::generateId('store');
    Database::insert('stores', [
        'id' => $storeId,
        'name' => 'Overcharge Store',
        'mint_url' => null,
        'mint_unit' => 'sat',
        'created_at' => time(),
        'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
        'onchain_address_type' => 'P2WPKH',
        'onchain_network' => 'regtest',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ]);
    SwapsConfig::setStoreProviderOrder($storeId, ['greedy', 'good']);
    SwapsConfig::setStoreAutoSelectCheapest($storeId, false);
    SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);

    $invoice = Invoice::create($storeId, ['amount' => 50_000, 'currency' => 'sat']);
    $attempt = Database::fetchOne("SELECT * FROM swap_attempts WHERE invoice_id = ?", [$invoice['id']]);
    // 'good' is second in priority, so it can only be chosen if 'greedy' was
    // tried first and rejected by the overcharge bound (see the error_log).
    tassert($attempt !== null && $attempt['provider'] === 'good',
            'overcharging provider rejected; well-behaved provider chosen', $failures);
}

// ------------- Cleanup -------------

echo "\n{$total} tested, {$failures} failed\n";
@unlink($tmp . '/cashupay.sqlite');
@rmdir($tmp);
exit($failures === 0 ? 0 : 1);
