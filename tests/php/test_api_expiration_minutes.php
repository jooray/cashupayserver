<?php
/**
 * checkout.expirationMinutes parsing for the Greenfield invoice endpoint:
 * whole numbers as int / integral float / digit string are accepted; signs,
 * fractions, exponents, booleans, arrays and junk are not. (The range check
 * and the long-lived decision are exercised end to end in
 * tests/e2e/test_long_lived_invoice.py.)
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/api/invoices.php';

assert_eq(15, apiExpirationMinutes(15), 'int');
assert_eq(15, apiExpirationMinutes(15.0), 'integral float');
assert_eq(15, apiExpirationMinutes('15'), 'digit string');
assert_eq(129600, apiExpirationMinutes(' 129600 '), 'padded digit string');
assert_eq(0, apiExpirationMinutes(0), 'zero parses (range check rejects it)');
assert_null(apiExpirationMinutes(15.5), 'fractional float');
assert_null(apiExpirationMinutes('15.5'), 'fractional string');
assert_null(apiExpirationMinutes('-15'), 'negative string');
assert_eq(-15, apiExpirationMinutes(-15.0), 'negative integral float parses; the 1..max range check rejects it');
assert_null(apiExpirationMinutes('1e3'), 'exponent');
assert_null(apiExpirationMinutes(true), 'bool');
assert_null(apiExpirationMinutes([15]), 'array');
assert_null(apiExpirationMinutes('abc'), 'junk');
assert_null(apiExpirationMinutes(INF), 'infinite');
assert_eq(129600, intdiv(Config::getSharedInvoiceLifetime(), 60), '90-day ceiling in minutes');

echo "PASS test_api_expiration_minutes\n";
