<?php
/**
 * Cart::checkout must reject a non-http(s) checkout redirect URL before it is
 * persisted. The redirect is later rendered as an <a href> on the public
 * payment page, where a javascript:/data: scheme would execute on click; the
 * Greenfield API path already validates this and the cart path must match.
 *
 * The bad-redirect rejection fires before Invoice::create, so this test needs
 * no mint/network — a sat-priced custom line item prices entirely offline.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/cart.php';

make_store('store_cart', null, 'sat');

$items = [[
    'title' => 'Widget',
    'price' => '1000',
    'currency' => 'sat',
    'quantity' => 1,
]];

// Sanity: the cart prices offline (proves a later throw is the redirect, not
// a pricing/network failure).
$priced = Cart::priceItems('store_cart', $items);
assert_eq(1000, $priced['totalSats'], 'sat custom line prices to face value');

// javascript: redirect must be rejected.
$threw = false;
try {
    Cart::checkout('store_cart', $items, null, 'javascript:alert(document.cookie)');
} catch (InvalidArgumentException $e) {
    $threw = true;
    assert_true(stripos($e->getMessage(), 'redirect') !== false, 'message mentions redirect: ' . $e->getMessage());
}
assert_true($threw, 'javascript: redirect must throw');

// data: redirect must also be rejected.
$threw = false;
try {
    Cart::checkout('store_cart', $items, null, 'data:text/html,<script>alert(1)</script>');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'data: redirect must throw');

fwrite(STDERR, "ok\n");
