<?php
/**
 * API invoice amount validation: the Greenfield create-invoice body is
 * untrusted JSON, so normalizeApiInvoiceAmount must reject negatives, zero,
 * non-numerics, exponent notation, signs, oversized magnitudes and non-scalar
 * types — and normalize the accepted shapes to a plain decimal string.
 * See includes/api/invoices.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/api/invoices.php';

// ---- Accepted shapes ----

assert_eq('100', normalizeApiInvoiceAmount(100, 'sat'), 'int sats accepted');
assert_eq('100', normalizeApiInvoiceAmount('100', 'sat'), 'string sats accepted');
assert_eq('100', normalizeApiInvoiceAmount(' 100 ', 'sat'), 'surrounding whitespace trimmed');
assert_eq('7', normalizeApiInvoiceAmount('007', 'sat'), 'leading zeros stripped for sats');
assert_eq('100', normalizeApiInvoiceAmount(100.0, 'sat'), 'whole float accepted as sats');
assert_eq('5.25', normalizeApiInvoiceAmount('5.25', 'USD'), 'fiat with 2 decimals accepted');
assert_eq('5.7', normalizeApiInvoiceAmount(5.7, 'USD'), 'float fiat accepted');
assert_eq('0.12345678', normalizeApiInvoiceAmount('0.12345678', 'BTC'), 'BTC with 8 decimals accepted');
assert_eq('1', normalizeApiInvoiceAmount(1, 'msat'), 'msat integer accepted');
assert_eq('999999999999999', normalizeApiInvoiceAmount('999999999999999', 'sat'), '15-digit sats accepted');

// ---- Rejected: sign / zero / garbage ----

assert_eq(null, normalizeApiInvoiceAmount(-5, 'sat'), 'negative int rejected');
assert_eq(null, normalizeApiInvoiceAmount('-5', 'sat'), 'negative string rejected');
assert_eq(null, normalizeApiInvoiceAmount('+5', 'sat'), 'plus sign rejected');
assert_eq(null, normalizeApiInvoiceAmount(0, 'sat'), 'zero rejected');
assert_eq(null, normalizeApiInvoiceAmount('0', 'sat'), 'string zero rejected');
assert_eq(null, normalizeApiInvoiceAmount('0.00', 'USD'), 'fiat zero rejected');
assert_eq(null, normalizeApiInvoiceAmount('abc', 'sat'), 'letters rejected');
assert_eq(null, normalizeApiInvoiceAmount('abc', 'USD'), 'letters rejected for fiat (was a bcmath ValueError)');
assert_eq(null, normalizeApiInvoiceAmount('5abc', 'sat'), 'trailing letters rejected');
assert_eq(null, normalizeApiInvoiceAmount('1e5', 'sat'), 'exponent notation rejected');
assert_eq(null, normalizeApiInvoiceAmount('0x1A', 'sat'), 'hex rejected');
assert_eq(null, normalizeApiInvoiceAmount('1,000', 'USD'), 'thousands separator rejected');

// ---- Rejected: precision / magnitude per unit ----

assert_eq(null, normalizeApiInvoiceAmount('5.7', 'sat'), 'fractional sats rejected');
assert_eq(null, normalizeApiInvoiceAmount(5.7, 'sat'), 'fractional float sats rejected');
assert_eq(null, normalizeApiInvoiceAmount('1.999', 'USD'), 'fiat beyond 2 decimals rejected');
assert_eq(null, normalizeApiInvoiceAmount('0.123456789', 'BTC'), 'BTC beyond 8 decimals rejected');
assert_eq(null, normalizeApiInvoiceAmount('1000000000000000', 'sat'), '16-digit sats rejected');
assert_eq(null, normalizeApiInvoiceAmount(1.0E+20, 'sat'), 'huge float rejected');

// ---- Rejected: non-scalar / non-finite types ----

assert_eq(null, normalizeApiInvoiceAmount(true, 'sat'), 'bool rejected');
assert_eq(null, normalizeApiInvoiceAmount([], 'sat'), 'array rejected');
assert_eq(null, normalizeApiInvoiceAmount(['amount' => 5], 'sat'), 'object-shaped array rejected');
assert_eq(null, normalizeApiInvoiceAmount(null, 'sat'), 'null rejected');
assert_eq(null, normalizeApiInvoiceAmount(INF, 'sat'), 'INF rejected');
assert_eq(null, normalizeApiInvoiceAmount(NAN, 'sat'), 'NAN rejected');

echo "ok\n";
