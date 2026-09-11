<?php
/**
 * The Bitcoin checkout discount: the pure pieces of
 * wordpress/payment-discount.php.
 *
 * These functions decide everything about the money and the messaging — how
 * the merchant's answer is validated and normalized, how large the negative
 * fee is, and what title customers see — and they are pure (no WordPress
 * calls) precisely so this file can pin them without a WordPress install.
 * The live wiring around them (cart fee hook, Store API sync, settings
 * forms) is covered by the WordPress e2e suite. The ABSPATH define satisfies
 * the file's direct-access guard; the add_action/add_filter stubs swallow
 * the hook registrations the file makes at load time.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/');
function add_action(...$args): void {}
function add_filter(...$args): void {}
require_once dirname(__DIR__, 2) . '/wordpress/payment-discount.php';

// --- Discount percent parsing ---------------------------------------------
//
// 0–100 with up to two decimal places (the whole-number restriction died
// with the ELEX plugin whose step-1 form input forced it). Empty means "no
// discount", not an error; null means re-render the form with an error
// rather than saving anything.
assert_eq(0.0, barebits_parse_discount_percent(''), 'empty input reads as declining the discount');
assert_eq(0.0, barebits_parse_discount_percent('0'), 'zero is a valid answer');
assert_eq(5.0, barebits_parse_discount_percent('5'), 'a plain whole percent parses');
assert_eq(7.0, barebits_parse_discount_percent(' 7 '), 'surrounding whitespace is tolerated');
assert_eq(2.5, barebits_parse_discount_percent('2.5'), 'one decimal place parses');
assert_eq(2.25, barebits_parse_discount_percent('2.25'), 'two decimal places parse');
assert_eq(100.0, barebits_parse_discount_percent('100'), 'the top of the range is inclusive');
assert_eq(100.0, barebits_parse_discount_percent('100.00'), 'the top of the range parses with decimals');
assert_null(barebits_parse_discount_percent('100.01'), 'above 100 is rejected');
assert_null(barebits_parse_discount_percent('101'), 'above 100 is rejected');
assert_null(barebits_parse_discount_percent('-1'), 'negative is rejected');
assert_null(barebits_parse_discount_percent('2.125'), 'three decimal places are rejected, not rounded');
assert_null(barebits_parse_discount_percent('2,5'), 'comma decimal separators are rejected, not guessed at');
assert_null(barebits_parse_discount_percent('.5'), 'a bare leading dot is rejected (write 0.5)');
assert_null(barebits_parse_discount_percent('5.'), 'a trailing dot is rejected');
assert_null(barebits_parse_discount_percent('abc'), 'non-numeric input is rejected');
assert_null(barebits_parse_discount_percent('5%'), 'a percent sign is rejected');
assert_null(barebits_parse_discount_percent('1e2'), 'scientific notation is rejected');

// --- Canonical formatting --------------------------------------------------
//
// The formatted value lands in the stored option, the fee label, and the
// title suffix, so it must read the way a human would write it.
assert_eq('0', barebits_format_discount_percent(0.0), 'zero formats bare');
assert_eq('5', barebits_format_discount_percent(5.0), 'whole numbers lose the decimal point');
assert_eq('2.5', barebits_format_discount_percent(2.5), 'a single decimal survives');
assert_eq('2.5', barebits_format_discount_percent(2.50), 'trailing zeros are trimmed');
assert_eq('2.25', barebits_format_discount_percent(2.25), 'two decimals survive');
assert_eq('100', barebits_format_discount_percent(100.0), 'the maximum formats bare');

// Round-trip: whatever parse accepts, format+parse preserves.
foreach (['0', '5', '2.5', '2.25', '99.99', '100'] as $input) {
    $parsed = barebits_parse_discount_percent($input);
    assert_eq($parsed, barebits_parse_discount_percent(barebits_format_discount_percent($parsed)),
        "parse/format round-trips for {$input}");
}

// --- Fee amount ------------------------------------------------------------
//
// Percent of the cart items' subtotal, rounded at the shop's price
// precision. The BTC test shops run 8 decimals; fiat shops run 2.
function assert_close(float $expected, float $actual, string $msg = ''): void {
    assert_true(abs($expected - $actual) < 1e-12, $msg . " (expected {$expected}, got {$actual})");
}
assert_close(2.0, barebits_discount_amount(100.0, 2.0, 2), 'plain percentage of the subtotal');
assert_close(2.5, barebits_discount_amount(100.0, 2.5, 2), 'fractional percents apply exactly');
assert_close(0.33, barebits_discount_amount(13.13, 2.5, 2), 'rounds to the given precision (0.32825)');
assert_close(0.00000038, barebits_discount_amount(0.00001500, 2.5, 8), 'BTC-scale amounts keep 8-decimal precision');
assert_eq(0.0, barebits_discount_amount(0.0, 5.0, 2), 'an empty subtotal earns no fee');
assert_eq(0.0, barebits_discount_amount(-10.0, 5.0, 2), 'a negative subtotal earns no fee');
assert_eq(0.0, barebits_discount_amount(100.0, 0.0, 2), 'zero percent earns no fee');
assert_close(100.0, barebits_discount_amount(100.0, 100.0, 2), '100% discounts the whole subtotal');

// --- Fee label --------------------------------------------------------------
assert_eq('Bitcoin discount (2%)', barebits_discount_fee_label(2.0), 'whole percent label');
assert_eq('Bitcoin discount (2.5%)', barebits_discount_fee_label(2.5), 'fractional percent label');

// --- Title suffix -----------------------------------------------------------
//
// Appended at read time to whatever title is stored; never applied twice,
// never applied over a merchant's own discount wording.
assert_eq('BareBits (Bitcoin + Lightning) (5% discount)',
    barebits_discount_title('BareBits (Bitcoin + Lightning)', 5.0),
    'the standard branding title gains the suffix');
assert_eq('BareBits (Bitcoin + Lightning) (2.5% discount)',
    barebits_discount_title('BareBits (Bitcoin + Lightning)', 2.5),
    'fractional percents are advertised exactly');
assert_eq('Pay with Bitcoin (3% discount)',
    barebits_discount_title('Pay with Bitcoin', 3.0),
    'a merchant-customized title gains the suffix too — that is the point of the runtime approach');
assert_eq('BareBits (Bitcoin + Lightning)',
    barebits_discount_title('BareBits (Bitcoin + Lightning)', 0.0),
    'no discount, no suffix');
assert_eq('Bitcoin — 5% discount!',
    barebits_discount_title('Bitcoin — 5% discount!', 3.0),
    'a title already advertising a discount is left alone, whatever its number says');
assert_eq('Bitcoin (10% DISCOUNT)',
    barebits_discount_title('Bitcoin (10% DISCOUNT)', 3.0),
    'the already-advertising check is case-insensitive');
assert_eq('', barebits_discount_title('', 5.0), 'an empty title stays empty rather than gaining a dangling suffix');
assert_eq('Pay with Bitcoin', barebits_discount_title("  Pay with Bitcoin  ", 0.0), 'titles are trimmed');

// --- Write-side suffix stripping --------------------------------------------
//
// WooCommerce read-modify-writes the gateway settings option in contexts
// where the read filter is active; the stripper keeps any suffix we
// generated out of the stored value, whatever percent it advertises.
assert_eq('BareBits (Bitcoin + Lightning)',
    barebits_strip_discount_title_suffix('BareBits (Bitcoin + Lightning) (5% discount)'),
    'a whole-percent suffix is stripped');
assert_eq('BareBits (Bitcoin + Lightning)',
    barebits_strip_discount_title_suffix('BareBits (Bitcoin + Lightning) (2.5% discount)'),
    'a fractional suffix is stripped, even one from an older percent');
assert_eq('BareBits (Bitcoin + Lightning)',
    barebits_strip_discount_title_suffix('BareBits (Bitcoin + Lightning)'),
    'a clean title passes through untouched');
assert_eq('Bitcoin — 5% discount!',
    barebits_strip_discount_title_suffix('Bitcoin — 5% discount!'),
    'merchant discount wording that is not our exact trailing suffix survives');
assert_eq('', barebits_strip_discount_title_suffix(''), 'empty stays empty');

// Round-trip: whatever the read filter appends, the write filter removes.
foreach ([['BareBits (Bitcoin + Lightning)', 5.0], ['Pay with Bitcoin', 2.5]] as [$title, $pct]) {
    assert_eq($title,
        barebits_strip_discount_title_suffix(barebits_discount_title($title, $pct)),
        "suffix round-trips away for {$title} at {$pct}%");
}

echo "OK\n";
