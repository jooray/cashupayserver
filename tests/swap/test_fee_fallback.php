<?php
/**
 * Fee-too-high → mint fallback: threshold comparison + three-layer resolution.
 *
 * Covers SwapsConfig::swapFeeExceedsThreshold() (OR semantics, strict
 * greater-than, 0 disables a check) and the config-file → store precedence
 * in effectiveFeeFallbackForStore (there is no site layer any more).
 *
 * The config-file layer is exercised by defining the constants below before
 * the config module loads (each test runs in its own PHP subprocess, so this
 * does not leak into other cases).
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/cashupay-feefallback-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('CASHUPAY_DATA_DIR', $tmp);

// Config-file layer (lowest precedence). Chosen so the assertions below can
// tell each layer apart.
define('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT', 5.0);
define('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS', 2000);

require_once __DIR__ . '/../../app/includes/database.php';
Database::ensureExists();
Database::initialize();
require_once __DIR__ . '/../../app/includes/config.php';
require_once __DIR__ . '/../../app/includes/swap/config.php';

$failures = 0;
$total = 0;
function tassert(bool $cond, string $msg): void {
    global $total, $failures; $total++;
    if ($cond) { echo "PASS {$msg}\n"; }
    else { echo "FAIL {$msg}\n"; $failures++; }
}

// ---- swapFeeExceedsThreshold: comparison semantics -------------------------

// Both thresholds off → never trips, even on a huge fee.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(999999, 1000, 0.0, 0) === false,
    'both thresholds 0 → no fallback'
);

// Sats cap: strict greater-than (equal does NOT trip).
tassert(
    SwapsConfig::swapFeeExceedsThreshold(1000, 100000, 0.0, 1000) === false,
    'fee == sats cap does not trip'
);
tassert(
    SwapsConfig::swapFeeExceedsThreshold(1001, 100000, 0.0, 1000) === true,
    'fee > sats cap trips'
);

// Percent cap: 10% of 1000 = 100. Equal does not trip; over does.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(100, 1000, 10.0, 0) === false,
    'fee == pct cap does not trip'
);
tassert(
    SwapsConfig::swapFeeExceedsThreshold(101, 1000, 10.0, 0) === true,
    'fee > pct cap trips'
);

// OR semantics: only the percent cap is exceeded (200 > 50 sat-equivalent? no
// sats cap here), sats cap off.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(200, 1000, 10.0, 0) === true,
    'percent-only breach trips (OR)'
);
// OR semantics: only the sats cap is exceeded; percent within bounds.
//   fee 1500 of 100000 target = 1.5% (< 5% pct cap) but > 1000 sat cap.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(1500, 100000, 5.0, 1000) === true,
    'sats-only breach trips (OR)'
);
// Neither breached.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(900, 100000, 5.0, 1000) === false,
    'neither cap breached → no fallback'
);

// The motivating example: 1000 sat payment, ~2000 sat swap cost.
//   2000 > 1000*5% (=50) → trips on percent; also > 1000 sat cap.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(2000, 1000, 5.0, 1000) === true,
    '1000 sat payment with 2000 sat fee falls back'
);

// Target 0 must not divide-by-zero / falsely trip the percent rule.
tassert(
    SwapsConfig::swapFeeExceedsThreshold(10, 0, 5.0, 0) === false,
    'zero target with pct cap does not trip'
);

// ---- Config-file constants are the base layer ------------------------------

tassert(SwapsConfig::configFileFeeFallbackMaxPct() === 5.0, 'config-file pct read');
tassert(SwapsConfig::configFileFeeFallbackMaxSats() === 2000, 'config-file sats read');

// ---- Store layer overrides the config-file constant, NULL inherits ---------

$storeId = 'store_feefallback_test';
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'Fee Fallback Store',
    'mint_url' => 'https://mint.example',
    'mint_unit' => 'sat',
    'seed_phrase' => 'test seed',
    'created_at' => time(),
]);

// No per-store value yet → inherits the config-file constants.
$eff = SwapsConfig::effectiveFeeFallbackForStore($storeId);
tassert($eff['pct'] === 5.0 && $eff['sats'] === 2000, 'store with no value inherits config-file');

// Set both per-store values → they win.
SwapsConfig::setStoreFeeFallback($storeId, 3.5, 1200);
$eff = SwapsConfig::effectiveFeeFallbackForStore($storeId);
tassert($eff['pct'] === 3.5 && $eff['sats'] === 1200, 'store value wins over config-file');

// Set only the percent (sats null) → pct from store, sats from config-file.
SwapsConfig::setStoreFeeFallback($storeId, 2.0, null);
$eff = SwapsConfig::effectiveFeeFallbackForStore($storeId);
tassert($eff['pct'] === 2.0 && $eff['sats'] === 2000, 'mixed: store pct + inherited config-file sats');

// A per-store explicit 0 disables that check for the store (distinct from null).
SwapsConfig::setStoreFeeFallback($storeId, 0.0, 0);
$eff = SwapsConfig::effectiveFeeFallbackForStore($storeId);
tassert($eff['pct'] === 0.0 && $eff['sats'] === 0, 'store explicit 0 disables (not inherit)');

// Clearing both → back to inheriting the config-file constants.
SwapsConfig::setStoreFeeFallback($storeId, null, null);
$eff = SwapsConfig::effectiveFeeFallbackForStore($storeId);
tassert($eff['pct'] === 5.0 && $eff['sats'] === 2000, 'clearing store values re-inherits config-file');

echo "\n{$total} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
