<?php
/**
 * Config::storeHasPaymentRail — the "can this store take payments at all?"
 * check behind the pairing chooser (api-keys/authorize.php) and the admin
 * store selector's "(not configured)" label.
 *
 * isStoreConfigured only reflects the mint rail, but the wizard's "run
 * without mints" answer leaves mint_url/seed_phrase NULL on stores that are
 * fully operational over Lightning or on-chain. Filtering the pairing
 * chooser on the mint columns told exactly those merchants "No stores
 * found" and blocked the WordPress plugin from pairing. Pinned here: every
 * rail shape Invoice::create's gate accepts counts as a rail, and only a
 * store with none of them fails the check.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

function bare_store(string $id, array $extra = []): void {
    Database::insert('stores', array_merge([
        'id' => $id,
        'name' => 'test ' . $id,
        'created_at' => Database::timestamp(),
    ], $extra));
}

function ln_row(string $storeId, string $address, string $type): void {
    Database::insert('store_ln_addresses', [
        'store_id' => $storeId,
        'position' => 0,
        'address' => $address,
        'type' => $type,
    ]);
}

// Mint rail: make_store sets mint_url + seed_phrase.
make_store('s_mint', 'https://mint.example.test');
assert_true(Config::storeHasPaymentRail('s_mint'), 'mint-configured store has a rail');
assert_true(Config::isStoreConfigured('s_mint'), 'and is mint-configured');

// A leftover seed phrase alone (mint later removed) is not a rail.
bare_store('s_seed_only', ['seed_phrase' => 'about about about about about about about about about about about above']);
assert_false(Config::storeHasPaymentRail('s_seed_only'), 'seed without mint_url is not a rail');

// Lightning-only: the wizard's "skip on-chain, skip mints, add an LNURL
// address" outcome. No mint, no seed — still a payment rail.
bare_store('s_ln');
ln_row('s_ln', 'tips@ln.example.test', StoreLnAddresses::TYPE_LNADDRESS);
assert_true(Config::storeHasPaymentRail('s_ln'), 'Lightning-address-only store has a rail');
assert_false(Config::isStoreConfigured('s_ln'), 'but is not mint-configured');

// NWC-only counts the same way (any store_ln_addresses row is a rail).
bare_store('s_nwc');
ln_row('s_nwc', 'nostr+walletconnect://abc?relay=wss%3A%2F%2Fr.example&secret=00', StoreLnAddresses::TYPE_NWC);
assert_true(Config::storeHasPaymentRail('s_nwc'), 'NWC-only store has a rail');

// On-chain xpub in the default (xpub) address mode.
bare_store('s_xpub', ['onchain_xpub' => 'zpub6nTestTestTestTestTestTestTestTest']);
assert_true(Config::storeHasPaymentRail('s_xpub'), 'xpub-only store has a rail');

// Static address mode: the static address is the rail...
bare_store('s_static', [
    'onchain_address_mode' => 'static',
    'onchain_static_address' => 'bc1qtesttesttesttesttesttesttest',
]);
assert_true(Config::storeHasPaymentRail('s_static'), 'static-address store has a rail');

// ...and an xpub left over from a mode switch does NOT count while the
// active mode is static with no static address (mirrors SetupFlow::onchainState).
bare_store('s_static_empty', [
    'onchain_address_mode' => 'static',
    'onchain_xpub' => 'zpub6nTestTestTestTestTestTestTestTest',
]);
assert_false(Config::storeHasPaymentRail('s_static_empty'), 'static mode without a static address is not a rail');

// Zero rails: nothing configured at all.
bare_store('s_none');
assert_false(Config::storeHasPaymentRail('s_none'), 'store with no rails fails the check');

// Unknown store id.
assert_false(Config::storeHasPaymentRail('s_missing'), 'missing store has no rail');

echo "OK\n";
