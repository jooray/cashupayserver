<?php
/**
 * Self-serve enable resolution (store-only flag, gated on the store being
 * payment-capable; legacy -1 "inherit" rows resolve to off) and max-sats
 * resolution (per-store value over the built-in default — there is no
 * site-wide layer). See includes/selfserve.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/selfserve.php';

// A payment-capable store (has a mint + seed phrase).
$paid = 'store_ss_paid';
make_store($paid, 'https://mint.example');

// A store with no payment method configured (mint_url NULL).
$bare = 'store_ss_bare';
make_store($bare);

// ---- Enable resolution ----

// Default: off (fresh stores carry the legacy -1 column default, which
// resolves to off).
assert_false(SelfServe::isEnabledForStore($paid), 'off by default');

// Turning it on for the store enables it.
SelfServe::setStoreOverride($paid, SelfServe::FORCE_ON);
assert_eq(SelfServe::FORCE_ON, SelfServe::storeOverride($paid), 'flag persisted (on)');
assert_true(SelfServe::isEnabledForStore($paid), 'on when store flag on');

// Turning it off disables it again.
SelfServe::setStoreOverride($paid, SelfServe::FORCE_OFF);
assert_eq(SelfServe::FORCE_OFF, SelfServe::storeOverride($paid), 'flag persisted (off)');
assert_false(SelfServe::isEnabledForStore($paid), 'off when store flag off');

// A legacy -1 row (pre-store-only installs) resolves to off.
Database::query("UPDATE stores SET selfserve_enabled = -1 WHERE id = ?", [$paid]);
assert_false(SelfServe::isEnabledForStore($paid), 'legacy -1 resolves to off');

// ---- Payment-capability gate ----

// A bare store is never enabled, even with the flag on.
SelfServe::setStoreOverride($bare, SelfServe::FORCE_ON);
assert_false(SelfServe::isEnabledForStore($bare), 'bare store never enabled (no payment method)');

// Unknown store id → false, not an error.
assert_false(SelfServe::isEnabledForStore('store_does_not_exist'), 'unknown store false');

// The legacy inherit sentinel and anything else invalid are rejected —
// the setter only accepts the plain on/off values now.
foreach ([SelfServe::INHERIT, 7] as $bad) {
    $threw = false;
    try { SelfServe::setStoreOverride($paid, $bad); } catch (InvalidArgumentException $e) { $threw = true; }
    assert_true($threw, "invalid value {$bad} rejected");
}

// ---- Max-sats resolution ----

// Built-in default when nothing set.
assert_eq(SelfServe::DEFAULT_MAX_SATS, SelfServe::effectiveMaxSats($paid), 'effective max = built-in default');

// Per-store value wins.
SelfServe::setStoreMaxSats($paid, 1000);
assert_eq(1000, SelfServe::storeMaxSats($paid), 'store max persisted');
assert_eq(1000, SelfServe::effectiveMaxSats($paid), 'effective = store max');

// Clearing the store value falls back to the built-in default.
SelfServe::setStoreMaxSats($paid, null);
assert_null(SelfServe::storeMaxSats($paid), 'store max cleared');
assert_eq(SelfServe::DEFAULT_MAX_SATS, SelfServe::effectiveMaxSats($paid), 'effective back to built-in');

// Non-positive maxes are rejected.
$threw = false;
try { SelfServe::setStoreMaxSats($paid, -5); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'negative store max rejected');

echo "test_selfserve_resolution: ok\n";
