<?php
/**
 * Unit tests for SwapAutoMelt.
 *
 * Exercises threshold math, mode resolution, and the quote-history
 * rate-limiter. Does NOT drive an actual swap through to settlement —
 * that's covered by the regtest harness in tests/swap_e2e.py.
 *
 * Uses plain SwapProvider mocks (not BoltzLikeProvider) so the parallel
 * quote fetcher falls through to its sequential path and we avoid wiring
 * curl_multi.
 */

$tmp = sys_get_temp_dir() . '/cashupay-automelt-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('CASHUPAY_DATA_DIR', $tmp);

require_once __DIR__ . '/../../app/includes/database.php';
require_once __DIR__ . '/../../app/includes/config.php';
require_once __DIR__ . '/../../app/includes/swap/factory.php';
require_once __DIR__ . '/../../app/includes/swap/config.php';
require_once __DIR__ . '/../../app/includes/swap/auto_melt.php';

$failures = 0;
$total = 0;
function tassert(bool $cond, string $msg, &$failures): void {
    global $total; $total++;
    if ($cond) echo "PASS {$msg}\n";
    else { echo "FAIL {$msg}\n"; $failures++; }
}

Database::initialize();

// =====================================================================
// 1. Static config readers honour CASHUPAY_AUTO_MELT_SWAP_MIN_SATS /
//    CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT, and fall back to defaults.
// =====================================================================
{
    tassert(SwapAutoMelt::minSats() === 5000,
            'minSats falls back to MIN_SATS_DEFAULT when no define', $failures);
    tassert(abs(SwapAutoMelt::maxFeePct() - 1.0) < 1e-9,
            'maxFeePct falls back to MAX_FEE_PCT_DEFAULT when no define', $failures);
}

// =====================================================================
// 2. Mode resolution (modeForStore).
//
// Truth table (store-only; legacy -1 rows resolve to lightning):
//   mode=-1 (legacy)              → 'lightning'
//   mode=0,  swaps on, xpub       → 'lightning' (explicit lightning)
//   mode=1,  swaps off            → 'lightning' (swap can't run; fall back)
//   mode=1,  swaps on, static     → 'lightning' (static addr incompatible)
//   mode=1,  swaps on, xpub       → 'swap'      (the happy path)
// =====================================================================
{
    $storeOff = [
        'id' => 'so-1', 'auto_melt_use_swap' => SwapAutoMelt::INHERIT,
        'onchain_xpub' => null, 'onchain_address_mode' => 'xpub', 'swaps_enabled' => SwapsConfig::FORCE_OFF,
    ];
    // For SwapsConfig::isEnabledForStore() to make a decision we need a real row.
    Database::insert('stores', [
        'id' => 'so-1', 'name' => 'no-xpub no-swaps',
        'mint_unit' => 'sat', 'created_at' => time(),
        'swaps_enabled' => SwapsConfig::FORCE_OFF,
    ]);
    tassert(SwapAutoMelt::modeForStore($storeOff) === 'lightning',
            'mode: force-off override → lightning', $failures);

    // store with xpub but swaps force-off → still lightning
    $storeForceOff = [
        'id' => 'so-2',
        'auto_melt_use_swap' => SwapAutoMelt::FORCE_SWAP,
        'onchain_xpub' => 'tpubXYZ',
        'onchain_address_mode' => 'xpub',
        'swaps_enabled' => SwapsConfig::FORCE_OFF,
    ];
    Database::insert('stores', [
        'id' => 'so-2', 'name' => 'swap forced + swaps off',
        'mint_unit' => 'sat', 'created_at' => time(),
        'onchain_xpub' => 'tpubXYZ',
        'swaps_enabled' => SwapsConfig::FORCE_OFF,
    ]);
    tassert(SwapAutoMelt::modeForStore($storeForceOff) === 'lightning',
            'mode: force-swap but swaps disabled → lightning', $failures);

    // happy path
    Database::insert('stores', [
        'id' => 'so-3', 'name' => 'swap + swaps on + xpub',
        'mint_unit' => 'sat', 'created_at' => time(),
        'onchain_xpub' => 'tpubXYZ',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ]);
    $storeHappy = [
        'id' => 'so-3',
        'auto_melt_use_swap' => SwapAutoMelt::FORCE_SWAP,
        'onchain_xpub' => 'tpubXYZ',
        'onchain_address_mode' => 'xpub',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ];
    tassert(SwapAutoMelt::modeForStore($storeHappy) === 'swap',
            'mode: happy path (force-swap + swaps on + xpub) → swap', $failures);

    // static-address mode incompatible with sweep
    Database::insert('stores', [
        'id' => 'so-4', 'name' => 'static address',
        'mint_unit' => 'sat', 'created_at' => time(),
        'onchain_xpub' => 'tpubXYZ',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ]);
    $storeStatic = [
        'id' => 'so-4',
        'auto_melt_use_swap' => SwapAutoMelt::FORCE_SWAP,
        'onchain_xpub' => 'tpubXYZ',
        'onchain_address_mode' => 'static',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ];
    tassert(SwapAutoMelt::modeForStore($storeStatic) === 'lightning',
            'mode: force-swap but static-address mode → lightning', $failures);

    // legacy -1 rows resolve to lightning even when a swap could run
    $storeLegacy = [
        'id' => 'so-3',
        'auto_melt_use_swap' => SwapAutoMelt::INHERIT,
        'onchain_xpub' => 'tpubXYZ',
        'onchain_address_mode' => 'xpub',
        'swaps_enabled' => SwapsConfig::FORCE_ON,
    ];
    tassert(SwapAutoMelt::modeForStore($storeLegacy) === 'lightning',
            'mode: legacy -1 resolves to lightning', $failures);
}

// =====================================================================
// 3. Quote-history rate limiter.
//
// historicalQuotesAllFail() should:
//   - return false when <5 rows in the 30-day window (not enough evidence)
//   - return false when at least one historical quote would have satisfied
//     the percent cap for the current balance
//   - return true only when ≥5 historical quotes all fail the percent cap
//     when re-evaluated against the current balance
// =====================================================================
{
    // Reach the private method via reflection. Internal API, but the gate
    // behaviour is load-bearing for the feature so it deserves coverage.
    $ref = new ReflectionClass(SwapAutoMelt::class);
    $m = $ref->getMethod('historicalQuotesAllFail');
    $m->setAccessible(true);

    Database::insert('stores', [
        'id' => 'rl-1', 'name' => 'rate-limit store',
        'mint_unit' => 'sat', 'created_at' => time(),
    ]);

    $insertQuote = function (string $storeId, float $feePercent, int $lockup, int $claim, int $balanceAtFetch, bool $met, int $ageSeconds) {
        Database::insert('swap_quote_history', [
            'store_id' => $storeId,
            'provider' => 'mock',
            'network'  => 'mainnet',
            'fetched_at' => time() - $ageSeconds,
            'fee_percent' => $feePercent,
            'lockup_fee_sats' => $lockup,
            'claim_fee_estimate_sats' => $claim,
            'min_sats' => 0,
            'max_sats' => 1_000_000_000,
            'balance_sats_at_fetch' => $balanceAtFetch,
            'total_cost_sats_at_fetch' => (int)ceil($balanceAtFetch * $feePercent / 100) + $lockup + $claim,
            'met_threshold' => $met ? 1 : 0,
        ]);
    };

    // No history yet → don't skip.
    tassert($m->invoke(null, 'rl-1', 10_000) === false,
            'history: empty → don\'t skip', $failures);

    // 3 high-fee rows (all fail) — still not enough evidence.
    for ($i = 0; $i < 3; $i++) {
        $insertQuote('rl-1', 0.5, 200, 300, 10_000, false, 3600 * ($i + 1));
    }
    tassert($m->invoke(null, 'rl-1', 10_000) === false,
            'history: 3 failing rows → not enough evidence', $failures);

    // 5+ high-fee rows where total > 1% of balance — should skip.
    // total = 50 + 200 + 300 = 550 > 100 (= 1% of 10000) → fails.
    for ($i = 0; $i < 3; $i++) {
        $insertQuote('rl-1', 0.5, 200, 300, 10_000, false, 3600 * ($i + 10));
    }
    tassert($m->invoke(null, 'rl-1', 10_000) === true,
            'history: 6 all-failing rows → skip', $failures);

    // …but if balance grows, the same historical quotes can satisfy the cap.
    // balance = 200_000, percent contribution = 1000, + 500 fixed = 1500
    // 1% of 200_000 = 2000 → satisfied → don't skip.
    tassert($m->invoke(null, 'rl-1', 200_000) === false,
            'history: same quotes satisfy cap at higher balance → don\'t skip', $failures);

    // Rows older than 30 days don't count: nuke the table and insert old ones.
    Database::query("DELETE FROM swap_quote_history WHERE store_id = ?", ['rl-1']);
    for ($i = 0; $i < 6; $i++) {
        $insertQuote('rl-1', 0.5, 200, 300, 10_000, false, 31 * 86400 + 100 + $i);
    }
    tassert($m->invoke(null, 'rl-1', 10_000) === false,
            'history: stale rows (>30d) excluded → don\'t skip', $failures);
}

// =====================================================================
// 4. cleanupQuoteHistory deletes rows older than 30 days.
// =====================================================================
{
    Database::query("DELETE FROM swap_quote_history");
    Database::insert('stores', [
        'id' => 'cl-1', 'name' => 'cleanup store',
        'mint_unit' => 'sat', 'created_at' => time(),
    ]);
    // 3 fresh, 4 stale rows.
    for ($i = 0; $i < 3; $i++) {
        Database::insert('swap_quote_history', [
            'store_id' => 'cl-1', 'provider' => 'mock', 'network' => 'mainnet',
            'fetched_at' => time() - 3600, 'fee_percent' => 0.5,
            'lockup_fee_sats' => 100, 'claim_fee_estimate_sats' => 100,
            'min_sats' => 0, 'max_sats' => 1, 'balance_sats_at_fetch' => 1,
            'total_cost_sats_at_fetch' => 200, 'met_threshold' => 0,
        ]);
    }
    for ($i = 0; $i < 4; $i++) {
        Database::insert('swap_quote_history', [
            'store_id' => 'cl-1', 'provider' => 'mock', 'network' => 'mainnet',
            'fetched_at' => time() - (31 * 86400) - $i, 'fee_percent' => 0.5,
            'lockup_fee_sats' => 100, 'claim_fee_estimate_sats' => 100,
            'min_sats' => 0, 'max_sats' => 1, 'balance_sats_at_fetch' => 1,
            'total_cost_sats_at_fetch' => 200, 'met_threshold' => 0,
        ]);
    }
    $deleted = SwapAutoMelt::cleanupQuoteHistory();
    tassert($deleted === 4, 'cleanupQuoteHistory deletes only rows >30d', $failures);
    $remaining = Database::fetchOne("SELECT COUNT(*) AS c FROM swap_quote_history WHERE store_id = ?", ['cl-1']);
    tassert((int)$remaining['c'] === 3, 'cleanupQuoteHistory leaves fresh rows intact', $failures);
}

// =====================================================================
// 5. solveTargetFromBalance: back-solve target sats from cashu balance +
// quote fees so the resulting swap invoice + mint melt buffer fits.
//
// Invariant: target + ceil(target * pct / 100) + lockupFee + meltBuffer
//            ≤ balance
// =====================================================================
{
    // Balance 80,000 sat, pct 0.5%, lockup 200, melt buffer 100:
    // maxInvoice = 80000 - 100 - 200 = 79700
    // continuous target = floor(79700 / 1.005) ≈ 79303
    // at target=79303: percentFee = ceil(79303 * 0.005) = 397
    //                  invoice = 79303 + 397 = 79700 → invariant: 79700 ≤ 79700 ✓
    $t = SwapAutoMelt::solveTargetFromBalance(80_000, 0.5, 200, 100);
    $pct = (int)ceil($t * 0.5 / 100.0);
    tassert($t > 0 && ($t + $pct + 200 + 100) <= 80_000,
            "solveTarget: 80k/0.5%/lockup200/buffer100 produces a safe target ({$t})",
            $failures);

    // Balance only just covers fees: 1000 + 200 lockup + 50 buffer = 1250
    // expected: tiny positive target, invariant holds.
    $t2 = SwapAutoMelt::solveTargetFromBalance(2000, 1.0, 200, 50);
    $pct2 = (int)ceil($t2 * 1.0 / 100.0);
    tassert($t2 > 0 && ($t2 + $pct2 + 200 + 50) <= 2000,
            "solveTarget: tight 2k balance still yields a safe target ({$t2})",
            $failures);

    // Balance is entirely consumed by fixed fees: should return 0.
    $t3 = SwapAutoMelt::solveTargetFromBalance(250, 0.5, 200, 60);
    tassert($t3 === 0, "solveTarget: balance ≤ fixed fees returns 0", $failures);

    // Zero balance: 0.
    $t4 = SwapAutoMelt::solveTargetFromBalance(0, 0.5, 200, 100);
    tassert($t4 === 0, "solveTarget: zero balance returns 0", $failures);
}

echo "\n--- $total assertions, $failures failed ---\n";
exit($failures > 0 ? 1 : 0);
