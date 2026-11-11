<?php
/**
 * Per-store strict-no-mint-fallback resolution.
 *
 * The onboarding wizard sets stores.strict_no_mint_fallback to FORCE_ON when
 * the operator declines Cashu mints, so a mint-free store errors instead of
 * silently acquiring a mint rail. The flag is store-only: legacy -1 "inherit"
 * rows (from when a site-wide default existed) resolve to off.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';

make_store('store_legacy');
make_store('store_off');
make_store('store_on');

// --- Fresh stores default to off (legacy -1 column default → off). --------
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_legacy'),
    'a fresh store is not strict'
);

// A legacy -1 row resolves to off.
Database::query("UPDATE stores SET strict_no_mint_fallback = -1 WHERE id = ?", ['store_legacy']);
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_legacy'),
    'legacy -1 resolves to off'
);

// --- Explicit per-store values. -------------------------------------------
SwapsConfig::setStoreStrictOverride('store_off', SwapsConfig::FORCE_OFF);
SwapsConfig::setStoreStrictOverride('store_on', SwapsConfig::FORCE_ON);

assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_on'),
    'FORCE_ON is strict'
);
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_off'),
    'FORCE_OFF is not strict'
);

// --- Unknown store ids resolve to off rather than throwing. ---------------
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_does_not_exist'),
    'a missing store resolves to off'
);

// --- The value is validated on write (the legacy inherit sentinel too). ---
foreach ([SwapsConfig::INHERIT, 7] as $bad) {
    $threw = false;
    try {
        SwapsConfig::setStoreStrictOverride('store_on', $bad);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, "out-of-range value {$bad} must be rejected");
}
assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_on'),
    'the rejected writes must not have changed the stored value'
);

// --- The column is outside Config::updateStore's allowlist. ---------------
// Keeping it out is deliberate (same as swaps_enabled); this pins that so a
// future allowlist edit has to be a conscious decision.
Config::updateStore('store_off', ['strict_no_mint_fallback' => 1]);
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_off'),
    'updateStore must not be able to write the strict flag'
);

echo "ok\n";
