<?php
/**
 * SetupFlow::resolveAutoCashout — which rail sweeps the mint balance.
 *
 * The onboarding wizard asks about Lightning destinations before it asks about
 * swaps and mints, so the Lightning screen cannot decide this on its own: a
 * store with no Lightning address but with a mint, swaps and an xpub should
 * still sweep, via a submarine swap to the on-chain wallet. Deciding it too
 * early is what would leave those stores with auto-cashout silently off,
 * contradicting what the mints screen promises the operator.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/setup_flow.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/auto_melt.php';

const XPUB = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic';

/** @return array{enabled:int, useSwap:int} */
function melt_state(string $storeId): array {
    $row = Database::fetchOne(
        "SELECT auto_melt_enabled, auto_melt_use_swap FROM stores WHERE id = ?",
        [$storeId]
    );
    return ['enabled' => (int)$row['auto_melt_enabled'], 'useSwap' => (int)$row['auto_melt_use_swap']];
}

function give_xpub(string $storeId): void {
    Database::update('stores', [
        'onchain_address_mode' => 'xpub',
        'onchain_xpub' => XPUB,
    ], 'id = ?', [$storeId]);
}

// --- 1. A Lightning destination always wins ------------------------------
// Cheapest and fastest rail, so it beats swaps even when both are possible.

make_store('store_ln', 'https://mint.example');
give_xpub('store_ln');
SwapsConfig::setStoreOverride('store_ln', SwapsConfig::FORCE_ON);
StoreLnAddresses::replaceForStore('store_ln', ['merchant@strike.me']);

assert_eq('lightning', SetupFlow::resolveAutoCashout('store_ln'), 'a Lightning address wins over swaps');
assert_eq(
    ['enabled' => 1, 'useSwap' => SwapAutoMelt::FORCE_LIGHTNING],
    melt_state('store_ln'),
    'Lightning mode is forced, not left to inherit'
);

// A CLINK noffer counts as a destination just like an LNURL address does.
make_store('store_noffer', 'https://mint.example');
StoreLnAddresses::replaceForStore('store_noffer', [[
    'address' => 'noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw'
        . 'dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv'
        . 'a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z',
    'type' => StoreLnAddresses::TYPE_NOFFER,
]]);
assert_eq('lightning', SetupFlow::resolveAutoCashout('store_noffer'), 'a noffer is a payout destination too');

// --- 2. No Lightning, but mint + swaps + xpub → submarine swap ------------

make_store('store_swap', 'https://mint.example');
give_xpub('store_swap');
SwapsConfig::setStoreOverride('store_swap', SwapsConfig::FORCE_ON);

assert_eq('swap', SetupFlow::resolveAutoCashout('store_swap'), 'mint + swaps + xpub sweeps on-chain');
assert_eq(
    ['enabled' => 1, 'useSwap' => SwapAutoMelt::FORCE_SWAP],
    melt_state('store_swap'),
    'swap mode is forced on'
);
// The rail the cron actually resolves must agree with what we persisted —
// SwapAutoMelt re-derives it from the same columns and would silently fall
// back to 'lightning' if any precondition were missing.
assert_eq(
    'swap',
    SwapAutoMelt::modeForStore(Config::getStore('store_swap')),
    'the persisted state must resolve to a real swap sweep at cron time'
);

// --- 3. Each missing precondition falls back to off ----------------------

// Swaps off for this store.
make_store('store_no_swaps', 'https://mint.example');
give_xpub('store_no_swaps');
SwapsConfig::setStoreOverride('store_no_swaps', SwapsConfig::FORCE_OFF);
assert_eq('off', SetupFlow::resolveAutoCashout('store_no_swaps'), 'no swaps means nowhere to sweep');
assert_eq(0, melt_state('store_no_swaps')['enabled'], 'auto-cashout is disabled');

// Swaps on, but no on-chain destination at all.
make_store('store_no_xpub', 'https://mint.example');
SwapsConfig::setStoreOverride('store_no_xpub', SwapsConfig::FORCE_ON);
assert_eq('off', SetupFlow::resolveAutoCashout('store_no_xpub'), 'swaps without an xpub cannot sweep');

// Swaps on and an on-chain destination, but it's a reused static address —
// swap claims need a fresh address per swap, so this is not swap-capable.
make_store('store_static', 'https://mint.example');
Database::update('stores', [
    'onchain_address_mode' => 'static',
    'onchain_static_address' => 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
], 'id = ?', ['store_static']);
SwapsConfig::setStoreOverride('store_static', SwapsConfig::FORCE_ON);
assert_eq('off', SetupFlow::resolveAutoCashout('store_static'), 'a static address cannot back swap sweeps');

// No mint at all: there is no mint balance to sweep in the first place.
make_store('store_no_mint');
Database::update('stores', ['mint_url' => null], 'id = ?', ['store_no_mint']);
give_xpub('store_no_mint');
SwapsConfig::setStoreOverride('store_no_mint', SwapsConfig::FORCE_ON);
assert_eq('off', SetupFlow::resolveAutoCashout('store_no_mint'), 'no mint means no balance to sweep');

// A mint URL with no seed is not a usable wallet either.
make_store('store_no_seed', 'https://mint.example');
Database::update('stores', ['seed_phrase' => null], 'id = ?', ['store_no_seed']);
give_xpub('store_no_seed');
SwapsConfig::setStoreOverride('store_no_seed', SwapsConfig::FORCE_ON);
assert_eq('off', SetupFlow::resolveAutoCashout('store_no_seed'), 'a mint without a seed is not configured');

// --- 4. Re-running is idempotent and reflects the current state ----------
// The wizard calls this once, but going Back and re-answering must not leave
// a stale rail behind.

assert_eq('off', SetupFlow::resolveAutoCashout('store_no_swaps'), 'repeat call is stable');
SwapsConfig::setStoreOverride('store_no_swaps', SwapsConfig::FORCE_ON);
assert_eq(
    'swap',
    SetupFlow::resolveAutoCashout('store_no_swaps'),
    'turning swaps on later flips the rail on the next resolve'
);
StoreLnAddresses::replaceForStore('store_no_swaps', ['later@strike.me']);
assert_eq(
    'lightning',
    SetupFlow::resolveAutoCashout('store_no_swaps'),
    'adding a Lightning destination later takes precedence again'
);

// --- 5. Unknown store ids are inert --------------------------------------

assert_eq('off', SetupFlow::resolveAutoCashout('store_missing'), 'an unknown store resolves to off');

echo "ok\n";
