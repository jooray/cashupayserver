<?php
/**
 * Regression test for the blank "Pay with …" wallet-logo bug under clean URLs.
 *
 * The payment page is served at the sub-path /payment/{id} in clean mode. A
 * page-relative 'images/payment-methods/foo.svg' URL then resolves to
 * /payment/images/... and 404s, so the Cash App / Strike / Coinbase / Kraken /
 * Venmo / PayPal logos silently fail to load. Urls::images() must therefore
 * return a base-rooted ABSOLUTE URL that loads correctly from any sub-path,
 * exactly like Urls::assets() (see test_urls_assets_absolute.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

// Pin a known base so the assertion is deterministic (no auto-detect from
// $_SERVER, which is empty under the CLI test runner).
Config::set('base_url', 'https://pay.example.com');

$img = Urls::images('payment-methods/strike.png');

// Must be absolute (scheme://host/...), not a page-relative 'images/...'.
assert_true(
    strpos($img, 'https://pay.example.com/') === 0,
    "images() must be base-rooted absolute, got: {$img}"
);
assert_eq('https://pay.example.com/images/payment-methods/strike.png', $img, 'payment-method logo url');

// No-arg form points at the images root.
assert_eq('https://pay.example.com/images/', Urls::images(), 'images root url');

// Crucially it must NOT start with a bare 'images/' (the broken relative form
// that 404s on the /payment/{id} sub-path).
assert_true(strpos($img, 'images/') !== 0, 'images() must not be page-relative');

echo "ok\n";
