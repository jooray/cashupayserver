<?php
/**
 * OPT-IN live test against the real Strike API. Skipped (exit 0) unless the
 * STRIKE_TEST_API_KEY env var carries a real key — never commit a key; pass
 * it per-run:
 *
 *   STRIKE_TEST_API_KEY=... tests/bin/php-8.3.31/php tests/php/test_strike_live_api.php
 *
 * Exercises the real create + quote + read-back flow with a 1-sat invoice
 * (nothing is paid; the quote simply expires) and the probeKey round trip.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$key = getenv('STRIKE_TEST_API_KEY');
if ($key === false || trim($key) === '') {
    echo "test_strike_live_api: skipped (STRIKE_TEST_API_KEY not set)\n";
    exit(0);
}
$key = trim($key);

require_once dirname(__DIR__, 2) . '/app/includes/strike/client.php';

assert_true(StrikeClient::isValidKey($key), 'provided key passes the shape check');

$made = StrikeClient::createInvoiceWithQuote(
    $key, 1, 'BareBits live test (1 sat) — safe to ignore, never paid'
);
assert_true(str_starts_with($made['bolt11'], 'ln'), 'real BOLT11 returned');
assert_true($made['invoice_id'] !== '', 'invoice id returned');
assert_true($made['expiration_in_sec'] === null || $made['expiration_in_sec'] > 0,
    'expiration sane when present');

$found = StrikeClient::findInvoice($key, $made['invoice_id']);
assert_eq('pending', $found['state'], 'fresh invoice reads back as pending');

$probe = StrikeClient::probeKey($key);
assert_true($probe['ok'], 'probeKey succeeds against the live API: ' . (string)$probe['error']);

echo "test_strike_live_api: ok\n";
