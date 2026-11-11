<?php
/**
 * Tests for StoreLnAddresses::chainFromLists() — the helper behind the admin
 * "Auto-Cashout" save when the UI sends two separate operator lists (lightning
 * addresses and CLINK noffers kept in their own sections). Covers the LN-first
 * ordering invariant, per-type validation, blank-skipping, trimming, and
 * cross-list / within-list dedup.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';

$nofferA = ClinkNoffer::encode([
    'pubkey' => str_repeat('ab', 32),
    'relay' => 'wss://relay.a.test',
    'offer' => 'shop',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);
$nofferB = ClinkNoffer::encode([
    'pubkey' => str_repeat('cd', 32),
    'relay' => 'wss://relay.b.test',
    'offer' => 'tips',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);

// ---------- LN addresses first, then noffers (priority order) ----------
$chain = StoreLnAddresses::chainFromLists(
    ['me@strike.me', 'backup@blink.sv'],
    [$nofferA, $nofferB]
);
assert_eq(4, count($chain), 'four destinations');
assert_eq('lnaddress', $chain[0]['type'], 'addresses come first');
assert_eq('me@strike.me', $chain[0]['value'], 'first lnaddress preserved');
assert_eq('lnaddress', $chain[1]['type'], 'second is the fallback lnaddress');
assert_eq('backup@blink.sv', $chain[1]['value'], 'second lnaddress preserved');
assert_eq('noffer', $chain[2]['type'], 'noffers come after addresses');
assert_eq($nofferA, $chain[2]['value'], 'first noffer preserved');
assert_eq('noffer', $chain[3]['type'], 'second noffer');
assert_eq($nofferB, $chain[3]['value'], 'second noffer preserved');

// ---------- only noffers (no addresses) ----------
$chain = StoreLnAddresses::chainFromLists([], [$nofferA]);
assert_eq(1, count($chain), 'noffer-only chain has one entry');
assert_eq('noffer', $chain[0]['type'], 'noffer-only entry typed correctly');

// ---------- empty both lists ----------
assert_eq([], StoreLnAddresses::chainFromLists([], []), 'empty lists yield empty chain');

// ---------- blanks dropped, values trimmed ----------
$chain = StoreLnAddresses::chainFromLists(['  me@strike.me  ', '', '   '], ['', "  $nofferA  "]);
assert_eq(2, count($chain), 'blank entries dropped');
assert_eq('me@strike.me', $chain[0]['value'], 'lnaddress trimmed');
assert_eq($nofferA, $chain[1]['value'], 'noffer trimmed');

// ---------- a noffer pasted into the address list is rejected ----------
$threw = false;
try {
    StoreLnAddresses::chainFromLists([$nofferA], []);
} catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'noffer rejected when declared as a Lightning address');

// ---------- an address pasted into the noffer list is rejected ----------
$threw = false;
try {
    StoreLnAddresses::chainFromLists([], ['me@strike.me']);
} catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'Lightning address rejected when declared as a noffer');

// ---------- duplicate within a single list rejected ----------
$threw = false;
try {
    StoreLnAddresses::chainFromLists(['me@strike.me', 'ME@Strike.me'], []);
} catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'case-insensitive duplicate within the address list rejected');

// ---------- duplicate across the two lists rejected ----------
// (A value can't be both a valid address and a valid noffer in practice, but
// the dedup is by case-folded string, so a repeated noffer across lists trips
// it — guards against the same destination being entered twice.)
$threw = false;
try {
    StoreLnAddresses::chainFromLists([], [$nofferA, $nofferA]);
} catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'repeated noffer rejected');

echo "test_clink_noffer_lists: ok\n";
