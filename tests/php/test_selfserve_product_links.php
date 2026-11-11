<?php
/**
 * Self-serve product links: parsing/resolution of the untrusted ?products=
 * link parameter and the optional tip input (SelfServe::parseProductsParam /
 * SelfServe::buildTipLine). Hermetic — sat-store tips and percent tips need no
 * exchange rates; the full page flow (pricing + Cart::checkout) is covered by
 * tests/e2e/test_selfserve_product_links.py.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/selfserve.php';
require_once dirname(__DIR__, 2) . '/app/includes/products.php';

make_store('s1');
make_store('s2');

$coffee = Product::create('s1', ['title' => 'Coffee', 'price' => '1000']);
$tea    = Product::create('s1', ['title' => 'Tea', 'price' => '500']);
$off    = Product::create('s1', ['title' => 'Retired', 'price' => '250']);
Product::update($off['id'], 's1', ['enabled' => 0]);
$other  = Product::create('s2', ['title' => 'Other store', 'price' => '100']);

/** Assert that $fn() throws SelfServeValidationException. */
function assert_rejects(callable $fn, string $msg): void {
    $threw = false;
    try { $fn(); } catch (SelfServeValidationException $e) { $threw = true; }
    assert_true($threw, $msg);
}

// ---------------- parseProductsParam: happy paths ----------------

$r = SelfServe::parseProductsParam('s1', $coffee['id']);
assert_eq(1, count($r['items']), 'single product resolves');
assert_eq(1, $r['items'][0]['quantity'], 'quantity defaults to 1');
assert_eq('Coffee', $r['items'][0]['product']['title'], 'product row loaded');
assert_eq(0, $r['dropped'], 'nothing dropped');

$r = SelfServe::parseProductsParam('s1', $coffee['id'] . ':3,' . $tea['id'] . ':2');
assert_eq(2, count($r['items']), 'two products resolve');
assert_eq(3, $r['items'][0]['quantity'], 'explicit quantity respected');
assert_eq(2, $r['items'][1]['quantity'], 'second quantity respected');

// Duplicate ids merge their quantities (capped at 999).
$r = SelfServe::parseProductsParam('s1', $coffee['id'] . ':2,' . $coffee['id'] . ':5');
assert_eq(1, count($r['items']), 'duplicates merge into one line');
assert_eq(7, $r['items'][0]['quantity'], 'duplicate quantities summed');

$r = SelfServe::parseProductsParam('s1', $coffee['id'] . ':999,' . $coffee['id'] . ':999');
assert_eq(999, $r['items'][0]['quantity'], 'merged quantity capped at 999');

// Whitespace around tokens tolerated (manual link editing).
$r = SelfServe::parseProductsParam('s1', ' ' . $coffee['id'] . ' , ' . $tea['id'] . ':2 ');
assert_eq(2, count($r['items']), 'whitespace around tokens tolerated');

// ---------------- parseProductsParam: drops (not errors) ----------------

// Disabled products are dropped, remaining items survive.
$r = SelfServe::parseProductsParam('s1', $off['id'] . ',' . $coffee['id']);
assert_eq(1, count($r['items']), 'disabled product dropped');
assert_eq('Coffee', $r['items'][0]['product']['title'], 'enabled product kept');
assert_eq(1, $r['dropped'], 'drop counted');

// A deleted product is dropped the same way.
$gone = Product::create('s1', ['title' => 'Gone', 'price' => '10']);
Product::delete($gone['id'], 's1');
$r = SelfServe::parseProductsParam('s1', $gone['id'] . ',' . $tea['id']);
assert_eq(1, count($r['items']), 'deleted product dropped');
assert_eq(1, $r['dropped'], 'deleted product counted as dropped');

// A product belonging to another store never resolves (no cross-store leak).
$r = SelfServe::parseProductsParam('s1', $other['id'] . ',' . $coffee['id']);
assert_eq(1, count($r['items']), 'cross-store product dropped');
assert_eq(1, $r['dropped'], 'cross-store product counted as dropped');

// All dropped -> empty items, caller decides (pay.php 404s).
$r = SelfServe::parseProductsParam('s1', $off['id']);
assert_eq(0, count($r['items']), 'all-dropped link resolves to no items');
assert_eq(1, $r['dropped'], 'single dropped item counted');

// ---------------- parseProductsParam: malformed input throws ----------------

assert_rejects(fn() => SelfServe::parseProductsParam('s1', ''), 'empty param rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', 'not-a-product'), 'garbage id rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', $coffee['id'] . ':0'), 'zero quantity rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', $coffee['id'] . ':1000'), 'quantity over 999 rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', $coffee['id'] . ':-1'), 'negative quantity rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', $coffee['id'] . ':1.5'), 'fractional quantity rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', $coffee['id'] . ',,' . $tea['id']), 'empty token rejected');
assert_rejects(fn() => SelfServe::parseProductsParam('s1', "prod_zz' OR 1=1--"), 'injection-looking id rejected');
// Over the distinct-products cap.
$many = implode(',', array_fill(0, SelfServe::MAX_LINK_PRODUCTS + 1, $coffee['id']));
assert_rejects(fn() => SelfServe::parseProductsParam('s1', $many), 'too many tokens rejected');

// ---------------- buildTipLine ----------------

// No tip.
assert_eq(null, SelfServe::buildTipLine('s1', '', '', '', 1000), 'empty mode = no tip');
assert_eq(null, SelfServe::buildTipLine('s1', 'none', '', '', 1000), "'none' mode = no tip");

// Percent presets compute on the sats subtotal.
$t = SelfServe::buildTipLine('s1', 'percent', '10', '', 1000);
assert_eq('100', $t['price'], '10% of 1000 sats');
assert_eq('sat', $t['currency'], 'percent tip denominated in sats');
assert_eq('Tip (10%)', $t['title'], 'percent tip titled with the rate');
assert_eq(1, $t['quantity'], 'tip line quantity 1');

$t = SelfServe::buildTipLine('s1', 'percent', '15', '', 999);
assert_eq('150', $t['price'], '15% of 999 rounds to 150');

// A percent tip that rounds to zero sats is simply no tip.
assert_eq(null, SelfServe::buildTipLine('s1', 'percent', '10', '', 4), 'sub-1-sat percent tip = no tip');

// Only the advertised presets are accepted.
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'percent', '99', '', 1000), 'non-preset percent rejected');
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'percent', '', '', 1000), 'missing percent rejected');
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'percent', '10.5', '', 1000), 'fractional percent rejected');

// Custom tip on a sat store: whole sats in a 'sat' line.
$t = SelfServe::buildTipLine('s1', 'custom', '', '250', 1000);
assert_eq('250', $t['price'], 'custom sat tip kept verbatim');
assert_eq('sat', $t['currency'], 'custom tip in the store currency (sat)');
assert_eq('Tip', $t['title'], 'custom tip titled Tip');

// Empty custom amount = no tip; malformed custom amounts throw.
assert_eq(null, SelfServe::buildTipLine('s1', 'custom', '', '', 1000), 'blank custom amount = no tip');
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'custom', '', '0', 1000), 'zero custom tip rejected');
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'custom', '', '-5', 1000), 'negative custom tip rejected');
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'custom', '', '1.5', 1000), 'fractional sat custom tip rejected');
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'custom', '', 'abc', 1000), 'non-numeric custom tip rejected');

// Custom tip on a fiat store: 2-decimal fiat amount in the store currency.
Database::update('stores', ['default_currency' => 'USD'], 'id = ?', ['s2']);
$t = SelfServe::buildTipLine('s2', 'custom', '', '2.50', 1000);
assert_eq('2.50', $t['price'], 'fiat custom tip kept verbatim');
assert_eq('USD', $t['currency'], 'fiat custom tip in the store currency');
assert_rejects(fn() => SelfServe::buildTipLine('s2', 'custom', '', '2.505', 1000), 'over-precision fiat tip rejected');

// Unknown mode throws.
assert_rejects(fn() => SelfServe::buildTipLine('s1', 'surprise', '', '', 1000), 'unknown tip mode rejected');

echo "OK test_selfserve_product_links\n";
