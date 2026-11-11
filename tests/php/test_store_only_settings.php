<?php
/**
 * Store-only settings resolution after the site-wide layer was removed:
 * submarine swaps (enable + provider prefs), the on-chain customer offer,
 * and the auto-cashout mode. Legacy -1 "inherit" rows (from installs that
 * predate the removal) must resolve to each setting's built-in default:
 * swaps off, on-chain offer ON, cashout mode lightning. Stale site config
 * keys left in the config table must be ignored entirely.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/auto_melt.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/factory.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/config.php';

$xpub = 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B';

make_store('store_x');
Database::query("UPDATE stores SET onchain_xpub = ? WHERE id = ?", [$xpub, 'store_x']);
make_store('store_noxpub');

// Stale site keys from an old install must have no effect on anything below.
Config::set('swaps_enabled', true);
Config::set('onchain_payments_enabled', false);
Config::set('auto_melt_use_swap_default', true);
Config::set('swaps_auto_select_cheapest', false);
Config::set('swaps_auto_select_threshold_pct', 55);
Config::set('swaps_provider_order', ['boltz']);
Config::set('swaps_minimum_target_sats', 99999);

// ---- Swaps enable: store flag AND xpub; legacy -1 → off --------------------

assert_false(SwapsConfig::isEnabledForStore('store_x'), 'fresh store (legacy -1) is off despite stale site key');
SwapsConfig::setStoreOverride('store_x', SwapsConfig::FORCE_ON);
assert_true(SwapsConfig::isEnabledForStore('store_x'), 'flag on + xpub → enabled');
SwapsConfig::setStoreOverride('store_x', SwapsConfig::FORCE_OFF);
assert_false(SwapsConfig::isEnabledForStore('store_x'), 'flag off → disabled');

SwapsConfig::setStoreOverride('store_noxpub', SwapsConfig::FORCE_ON);
assert_false(SwapsConfig::isEnabledForStore('store_noxpub'), 'no xpub → never enabled');

$threw = false;
try { SwapsConfig::setStoreOverride('store_x', SwapsConfig::INHERIT); }
catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'legacy inherit sentinel rejected on write');

// ---- Provider prefs: per-store with built-in defaults ----------------------

assert_eq(SwapsConfig::DEFAULT_PROVIDER_ORDER, SwapsConfig::providerOrderForStore('store_x'),
    'NULL column → default provider order');
assert_eq(SwapsConfig::DEFAULT_PROVIDER_ORDER, SwapsConfig::providerOrderForStore(null),
    'null storeId → default provider order');
SwapsConfig::setStoreProviderOrder('store_x', ['Boltz', 'zeus']);
assert_eq(['boltz', 'zeus'], SwapsConfig::providerOrderForStore('store_x'),
    'store order persisted, lowercased');
SwapsConfig::setStoreProviderOrder('store_x', []);
assert_eq(SwapsConfig::DEFAULT_PROVIDER_ORDER, SwapsConfig::providerOrderForStore('store_x'),
    'empty list resets to default');

assert_true(SwapsConfig::autoSelectCheapestForStore('store_x'), 'auto-select defaults on (stale site key ignored)');
SwapsConfig::setStoreAutoSelectCheapest('store_x', false);
assert_false(SwapsConfig::autoSelectCheapestForStore('store_x'), 'auto-select store off persisted');

assert_eq(SwapsConfig::DEFAULT_AUTO_SELECT_THRESHOLD_PCT,
    SwapsConfig::autoSelectThresholdPctForStore('store_x'), 'threshold defaults (stale site key ignored)');
SwapsConfig::setStoreAutoSelectThresholdPct('store_x', 25);
assert_eq(25, SwapsConfig::autoSelectThresholdPctForStore('store_x'), 'threshold persisted');
SwapsConfig::setStoreAutoSelectThresholdPct('store_x', 500);
assert_eq(90, SwapsConfig::autoSelectThresholdPctForStore('store_x'), 'threshold clamped to 90');

assert_null(SwapsConfig::minimumTargetSatsForStore('store_x'), 'min target defaults null (stale site key ignored)');
SwapsConfig::setStoreMinimumTargetSats('store_x', 12345);
assert_eq(12345, SwapsConfig::minimumTargetSatsForStore('store_x'), 'min target persisted');
SwapsConfig::setStoreMinimumTargetSats('store_x', null);
assert_null(SwapsConfig::minimumTargetSatsForStore('store_x'), 'min target cleared');

// The factory consumes the per-store order.
SwapsConfig::setStoreProviderOrder('store_x', ['boltz']);
$ordered = SwapProviderFactory::orderedForStore('store_x');
assert_eq(1, count($ordered), 'factory honours store order');
assert_eq('boltz', $ordered[0]->getName(), 'factory picked the store-configured provider');
assert_eq(2, count(SwapProviderFactory::orderedForStore(null)), 'null storeId → default order in factory');

// ---- On-chain offer: default ON, legacy -1 → on ----------------------------

assert_true(OnchainConfig::isEnabledForStore('store_x'), 'offer defaults on despite stale site key off');
OnchainConfig::setStoreOverride('store_x', OnchainConfig::FORCE_OFF);
assert_false(OnchainConfig::isEnabledForStore('store_x'), 'offer off persisted');
Database::query("UPDATE stores SET onchain_offer_enabled = -1 WHERE id = ?", ['store_x']);
assert_true(OnchainConfig::isEnabledForStore('store_x'), 'legacy -1 offer row resolves to on');
$threw = false;
try { OnchainConfig::setStoreOverride('store_x', OnchainConfig::INHERIT); }
catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'legacy inherit sentinel rejected on offer write');

// ---- Auto-cashout mode: legacy -1 → lightning ------------------------------

SwapsConfig::setStoreOverride('store_x', SwapsConfig::FORCE_ON);
$storeRow = Config::getStore('store_x');
$storeRow['auto_melt_use_swap'] = SwapAutoMelt::INHERIT;
$storeRow['onchain_address_mode'] = 'xpub';
assert_eq('lightning', SwapAutoMelt::modeForStore($storeRow),
    'legacy -1 mode resolves to lightning despite stale site default');
$storeRow['auto_melt_use_swap'] = SwapAutoMelt::FORCE_SWAP;
assert_eq('swap', SwapAutoMelt::modeForStore($storeRow), 'explicit swap mode still works');
$threw = false;
try { SwapAutoMelt::setStoreOverride('store_x', SwapAutoMelt::INHERIT); }
catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'legacy inherit sentinel rejected on mode write');

echo "test_store_only_settings: ok\n";
