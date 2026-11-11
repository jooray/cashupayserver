<?php
/**
 * LightningAddress::checkAutoMelt() — LNURL/noffer as the primary, always-on
 * mint-drain rail.
 *
 * Regression coverage for the change that made the LNURL/noffer rail:
 *   1. run for EVERY auto-melt store that has a destination, INCLUDING stores
 *      whose auto_melt_use_swap resolves to the on-chain 'swap' mode (these
 *      used to be skipped up-front via SwapAutoMelt::modeForStore === 'swap');
 *   2. attempt a drain regardless of auto_melt_threshold (that threshold now
 *      governs only the on-chain swap floor, not this rail);
 *   3. still respect the physical dust floor — a balance too small to cover the
 *      Lightning fee is skipped BEFORE any LNURL host is contacted;
 *   4. leave a swap-only store (no LNURL/noffer destination) untouched, so it
 *      still falls through to SwapAutoMelt::checkAndExecute.
 *
 * "Processed" is observed via the per-store result row checkAutoMelt returns.
 * LNURL-pay resolution is redirected to a dead loopback port so every melt
 * attempt fails fast and deterministically (no mint/host/network needed) — a
 * failure row still proves the store passed the pre-melt gates rather than
 * being skipped. Each store uses a distinct mint URL so their offline balances
 * (keyed by mint URL + unit in the wallet store) stay independent.
 */

require __DIR__ . '/harness.php';
fresh_db();

require_once dirname(__DIR__, 2) . '/app/includes/lightning_address.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/auto_melt.php';

use Cashu\Proof;
use Cashu\WalletStorage;

// Redirect LNURL-pay resolution to a port nothing listens on: getInvoice()
// fails with connection-refused immediately, so a melt ATTEMPT surfaces as a
// failure row without any real host or mint.
putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:59321/.well-known/lnurlp/{user}');

/**
 * Seed a store row. Overrides win over the auto-melt-ready defaults so each
 * scenario only states what differs. A distinct mint_url per store keeps
 * offline balances independent.
 */
function seed_store(array $overrides): array {
    $row = array_merge([
        'name'                 => 'test ' . ($overrides['id'] ?? '?'),
        'mint_unit'            => 'sat',
        'seed_phrase'          => 'about about about about about about about about about about about above',
        'created_at'           => Database::timestamp(),
        'auto_melt_enabled'    => 1,
        'auto_melt_threshold'  => 2000,
        'auto_melt_use_swap'   => SwapAutoMelt::INHERIT,
        'onchain_address_mode' => 'xpub',
    ], $overrides);
    Database::insert('stores', $row);
    return $row;
}

/** Give a store a local (offline) spendable balance the wallet reads back. */
function seed_balance(string $mintUrl, int $sats): void {
    $storage = new WalletStorage(Database::getDbPath(), $mintUrl, 'sat');
    $storage->storeProofs([
        new Proof(
            '00ad268c4d1f5826',          // arbitrary keyset id
            $sats,
            bin2hex(random_bytes(16)),   // unique secret
            '02' . str_repeat('00', 32)  // dummy signature point (never checked offline)
        ),
    ]);
}

/** Add one Lightning-address destination to a store's ordered chain. */
function seed_ln_dest(string $storeId, string $address): void {
    StoreLnAddresses::replaceForStore($storeId, [
        ['type' => 'lnaddress', 'address' => $address, 'supports_verify' => null],
    ]);
}

// ---------------------------------------------------------------------------
// Scenario stores.
// ---------------------------------------------------------------------------

// 1. Swap-mode store that ALSO has an LNURL destination, balance far below its
//    (deliberately huge) threshold. Under the old code this was skipped by the
//    modeForStore === 'swap' guard; now it must be drained via LN.
$swap = seed_store([
    'id'                  => 'swap-with-lnurl',
    'mint_url'            => 'https://mint-swap.invalid',
    'auto_melt_use_swap'  => SwapAutoMelt::FORCE_SWAP,
    'auto_melt_threshold' => 1_000_000,
    'onchain_xpub'        => 'tpubDUMMYxpubForSwapModeStore',
    'swaps_enabled'       => SwapsConfig::FORCE_ON,
]);
seed_ln_dest($swap['id'], 'ops@swap-store.example');
seed_balance($swap['mint_url'], 500);

// 2. Lightning-mode store whose balance is well below its threshold. Proves the
//    threshold no longer gates this rail.
$ln = seed_store([
    'id'                  => 'ln-below-threshold',
    'mint_url'            => 'https://mint-ln.invalid',
    'auto_melt_use_swap'  => SwapAutoMelt::FORCE_LIGHTNING,
    'auto_melt_threshold' => 1_000_000,
]);
seed_ln_dest($ln['id'], 'ops@ln-store.example');
seed_balance($ln['mint_url'], 500);

// 3. Dust store: balance is a single sat — smaller than the unavoidable fee
//    buffer — so the melt-amount guard drops it before any host is contacted.
$dust = seed_store([
    'id'                  => 'dust-store',
    'mint_url'            => 'https://mint-dust.invalid',
    'auto_melt_use_swap'  => SwapAutoMelt::FORCE_LIGHTNING,
    'auto_melt_threshold' => 1,
]);
seed_ln_dest($dust['id'], 'ops@dust-store.example');
seed_balance($dust['mint_url'], 1);

// 4. Swap-only store with NO LNURL/noffer destination. The LN rail must leave
//    it entirely alone so the on-chain rail can handle it.
$swapOnly = seed_store([
    'id'                  => 'swap-only-no-dest',
    'mint_url'            => 'https://mint-swaponly.invalid',
    'auto_melt_use_swap'  => SwapAutoMelt::FORCE_SWAP,
    'auto_melt_threshold' => 1_000_000,
    'onchain_xpub'        => 'tpubDUMMYxpubForSwapOnlyStore',
    'swaps_enabled'       => SwapsConfig::FORCE_ON,
]);
seed_balance($swapOnly['mint_url'], 500);

// ---------------------------------------------------------------------------
// Preconditions: modes resolve as intended (so the assertions below are really
// exercising "swap-mode is not skipped", not an accidental lightning mode).
// ---------------------------------------------------------------------------
$swapStoreRow = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$swap['id']]);
assert_eq('swap', SwapAutoMelt::modeForStore($swapStoreRow),
    'precondition: swap-with-lnurl resolves to swap mode');

$swapOnlyRow = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$swapOnly['id']]);
assert_eq('swap', SwapAutoMelt::modeForStore($swapOnlyRow),
    'precondition: swap-only-no-dest resolves to swap mode');

// ---------------------------------------------------------------------------
// Run the rail and index results by store.
// ---------------------------------------------------------------------------
$results = LightningAddress::checkAutoMelt();
assert_not_null($results, 'checkAutoMelt returned rows (at least one store drained)');

$byStore = [];
foreach ($results as $r) {
    $byStore[$r['store_id']] = $r;
}

// 1. Swap-mode store WITH a destination was processed despite swap mode and a
//    balance far below threshold; the melt attempt fails (dead host) but the
//    presence of the failure row proves it was not skipped.
assert_true(isset($byStore[$swap['id']]),
    'swap-mode store with an LNURL destination is processed by the LN rail');
assert_false($byStore[$swap['id']]['success'] ?? true,
    'swap-mode store melt attempt failed against the dead host (i.e. it attempted)');

// 2. Threshold is not consulted: a below-threshold lightning store is drained.
assert_true(isset($byStore[$ln['id']]),
    'below-threshold lightning store is processed (threshold no longer gates)');
assert_false($byStore[$ln['id']]['success'] ?? true,
    'below-threshold store attempted the melt');

// 3. Dust floor still applies: no attempt, no row.
assert_false(isset($byStore[$dust['id']]),
    'dust-balance store is skipped by the physical fee floor (no attempt)');

// 4. Swap-only store with no destination is untouched by the LN rail.
assert_false(isset($byStore[$swapOnly['id']]),
    'swap-only store with no LNURL/noffer destination is left for the swap rail');

echo "PASS test_auto_melt_ln_primary\n";
