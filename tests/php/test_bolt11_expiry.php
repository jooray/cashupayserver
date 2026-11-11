<?php
/**
 * Bolt11Expiry reads the creation timestamp + `x` (expiry) tagged field out of
 * a BOLT11 invoice. Vectors are the BOLT #11 spec examples: both were created
 * at 1496314658; the coffee invoice carries x=60, the donation one has no `x`
 * and falls back to the 3600 s default. Garbage yields null.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/bolt11_expiry.php';

$coffee = 'lnbc2500u1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdq5xysxxatsyp3k7enxv4jsxqzpuaztrnwngzn3kdzw5hydlzf03qdgm2hdq27cqv3agm2awhz5se903vruatfhq77w3ls4evs3ch9zw97j25emudupq63nyw24cg27h2rspfj9srp';
$donation = 'lnbc1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdpl2pkx2ctnv5sxxmmwwd5kgetjypeh2ursdae8g6twvus8g6rfwvs8qun0dfjkxaq8rkx3yf5tcsyz3d73gafnh3cax9rn449d9p5uxz9ezhhypd0elx87sjle52x86fux2ypatgddc6k63n7erqz25le42c4u4ecky03ylcqca784w';

$p = Bolt11Expiry::parse($coffee);
assert_not_null($p, 'coffee vector parses');
assert_eq(1496314658, $p['timestamp'], 'coffee timestamp');
assert_eq(60, $p['expiry'], 'coffee x=60');
assert_eq(1496314658 + 60, Bolt11Expiry::expiresAt($coffee), 'coffee expiresAt');
assert_eq(1496314658 + 60, Bolt11Expiry::expiresAt('lightning:' . strtoupper($coffee)), 'URI prefix + uppercase accepted');

$p = Bolt11Expiry::parse($donation);
assert_not_null($p, 'donation vector parses');
assert_eq(1496314658, $p['timestamp'], 'donation timestamp');
assert_eq(3600, $p['expiry'], 'no x field → 3600 default');

assert_null(Bolt11Expiry::expiresAt(''), 'empty → null');
assert_null(Bolt11Expiry::expiresAt('lnbc1stub'), 'mock stub string → null');
assert_null(Bolt11Expiry::expiresAt('bc1qexample'), 'not a bolt11 → null');
assert_null(Bolt11Expiry::expiresAt(substr($coffee, 0, 60)), 'truncated → null');
assert_null(Bolt11Expiry::expiresAt(str_replace('x', 'b', $coffee)), 'invalid charset char (b) → null');

echo "PASS test_bolt11_expiry\n";
