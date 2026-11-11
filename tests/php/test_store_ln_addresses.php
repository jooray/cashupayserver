<?php
/**
 * Unit tests for StoreLnAddresses — the ordered Lightning-address fallback
 * chain that replaced the single stores.auto_melt_address column.
 *
 * Covers: ordering by priority, blank-skipping, case-insensitive duplicate
 * rejection, invalid-address rejection, primaryForStore, and that
 * replaceForStore fully replaces (not appends) the chain.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$store = 'store_lnaddr';
make_store($store);

// ---------- empty by default ----------
assert_eq([], StoreLnAddresses::addressesForStore($store), 'empty chain by default');
assert_null(StoreLnAddresses::primaryForStore($store), 'no primary by default');

// ---------- ordered insert + read back in priority order ----------
StoreLnAddresses::replaceForStore($store, ['a@one.test', 'b@two.test', 'c@three.test']);
assert_eq(
    ['a@one.test', 'b@two.test', 'c@three.test'],
    StoreLnAddresses::addressesForStore($store),
    'addresses returned in insertion (priority) order'
);
assert_eq('a@one.test', StoreLnAddresses::primaryForStore($store), 'primary = position 0');

$list = StoreLnAddresses::listForStore($store);
assert_eq(3, count($list), 'listForStore returns all rows');
assert_eq('a@one.test', $list[0]['address'], 'list[0] is primary');
assert_null($list[0]['supports_verify'], 'supports_verify null when unset');

// ---------- replace fully swaps the chain (no append, new order) ----------
StoreLnAddresses::replaceForStore($store, [
    ['address' => 'x@new.test', 'supports_verify' => 1],
    ['address' => 'y@new.test', 'supports_verify' => 0],
]);
assert_eq(
    ['x@new.test', 'y@new.test'],
    StoreLnAddresses::addressesForStore($store),
    'replaceForStore replaces the whole chain'
);
$list2 = StoreLnAddresses::listForStore($store);
assert_eq(1, $list2[0]['supports_verify'], 'supports_verify=1 persisted');
assert_eq(0, $list2[1]['supports_verify'], 'supports_verify=0 persisted');

// ---------- blank entries skipped ----------
StoreLnAddresses::replaceForStore($store, ['', '  ', 'only@real.test', '']);
assert_eq(['only@real.test'], StoreLnAddresses::addressesForStore($store), 'blanks skipped');

// ---------- case-insensitive duplicate rejection ----------
$threw = false;
try {
    StoreLnAddresses::replaceForStore($store, ['dup@host.test', 'DUP@HOST.TEST']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'duplicate (case-insensitive) addresses rejected');
// Rejected write must not partially apply — chain unchanged from prior step.
assert_eq(['only@real.test'], StoreLnAddresses::addressesForStore($store),
    'failed replace rolled back (transactional)');

// ---------- invalid address rejection ----------
$threw = false;
try {
    StoreLnAddresses::replaceForStore($store, ['good@host.test', 'not-an-address']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'invalid address rejected');

// ---------- empty list clears the chain ----------
StoreLnAddresses::replaceForStore($store, ['something@host.test']);
StoreLnAddresses::replaceForStore($store, []);
assert_eq([], StoreLnAddresses::addressesForStore($store), 'empty list clears chain');

// ---------- isValid spot checks ----------
assert_true(StoreLnAddresses::isValid('me@strike.me'), 'valid LN address');
assert_true(StoreLnAddresses::isValid('me+tag@sub.strike.me'), 'plus tags + subdomain valid');
assert_eq(false, StoreLnAddresses::isValid('nope'), 'no @ → invalid');
assert_eq(false, StoreLnAddresses::isValid('a@b'), 'no TLD dot → invalid');

echo "test_store_ln_addresses: ok\n";
