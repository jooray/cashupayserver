<?php
/**
 * StrikeClient against the mock Strike API:
 *
 *   1. Pure helpers: sat<->BTC conversion is string-exact (no float drift),
 *      key shape validation, masking never reveals more than the tail.
 *   2. createInvoiceWithQuote happy path: BTC-denominated create for the
 *      exact sat amount, description + correlationId forwarded (and the
 *      41-char-plus correlationId omitted), bolt11 + invoice id returned.
 *   3. findInvoice maps UNPAID/PAID/CANCELLED onto pending/paid/cancelled.
 *   4. Failure mapping: wrong key -> 401 -> the fixed "rejected the key"
 *      phrase; 429 -> rate-limit phrase; 5xx -> server-error phrase; a dead
 *      host -> "could not be reached". No API text leaks into the phrases.
 *   5. Amount-mismatch refusal: a quote whose sourceAmount disagrees with
 *      the requested sats throws instead of returning the bolt11.
 *   6. probeKey exercises create+quote+read and reports ok/error.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/app/includes/strike/client.php';

// ---------- 1. pure helpers ----------
assert_eq('0.00000001', StrikeClient::satsToBtc(1), '1 sat');
assert_eq('0.00000100', StrikeClient::satsToBtc(100), '100 sats');
assert_eq('1.23456789', StrikeClient::satsToBtc(123456789), 'over 1 BTC');
assert_eq('21000000.00000000', StrikeClient::satsToBtc(2100000000000000), 'all the coins');
assert_eq(1, StrikeClient::btcToSats('0.00000001'), 'parse 1 sat');
assert_eq(100, StrikeClient::btcToSats('0.00000100'), 'parse 100 sats');
assert_eq(100, StrikeClient::btcToSats('0.000001'), 'short fraction pads');
assert_eq(123456789, StrikeClient::btcToSats('1.23456789'), 'parse over 1 BTC');
assert_eq(5, StrikeClient::btcToSats('0.0000000500'), 'trailing sub-sat zeros ok');
assert_null(StrikeClient::btcToSats('0.000000015'), 'sub-sat precision rejected');
assert_null(StrikeClient::btcToSats('1,5'), 'non-decimal rejected');
assert_null(StrikeClient::btcToSats(''), 'empty rejected');
// Round trip for a spread of values.
foreach ([1, 99, 100000000, 2100000000000000] as $sats) {
    assert_eq($sats, StrikeClient::btcToSats(StrikeClient::satsToBtc($sats)), "round trip {$sats}");
}

assert_true(StrikeClient::isValidKey('4280FA695043ACA084565C53EF6F97B742EFE246FA71A9DEDCDD9056EC9A3A74'), 'hex key valid');
assert_true(StrikeClient::isValidKey('abcDEF0123456789'), '16-char alnum valid');
assert_false(StrikeClient::isValidKey('short'), 'too short rejected');
assert_false(StrikeClient::isValidKey('has space in it aaaaaaaa'), 'spaces rejected');
assert_false(StrikeClient::isValidKey('me@strike.me1234567'), 'lightning address is not a key');
assert_false(StrikeClient::isValidKey(str_repeat('a', 300)), 'absurd length rejected');

$mask = StrikeClient::maskKey('4280FA695043ACA084565C53EF6F97B742EFE246FA71A9DEDCDD9056EC9A3A74');
assert_eq('Strike API (…3A74)', $mask, 'mask keeps only the 4-char tail');

// ---------- mock server ----------
$KEY = 'TESTKEY' . str_repeat('A', 32);
[$pid, $port, $dir] = start_strike_mock($KEY);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

try {
    // ---------- 2. create + quote happy path ----------
    $made = StrikeClient::createInvoiceWithQuote($KEY, 100, 'Test Store - Order 42', 'inv_abc123');
    assert_true(str_starts_with($made['bolt11'], 'lnbcmock'), 'bolt11 from the quote');
    assert_true($made['invoice_id'] !== '', 'invoice id returned');
    assert_eq(300, $made['expiration_in_sec'], 'quote expiration surfaced');

    $captured = strike_mock_invoices($dir);
    $req = $captured[$made['invoice_id']] ?? null;
    assert_not_null($req, 'mock captured the create body');
    assert_eq('0.00000100', $req['amount']['amount'], 'BTC-denominated for the exact sat amount');
    assert_eq('BTC', $req['amount']['currency'], 'currency is BTC');
    assert_eq('Test Store - Order 42', $req['description'], 'description forwarded');
    assert_eq('inv_abc123', $req['correlationId'], 'correlationId forwarded');

    // A correlationId over Strike's 40-char cap is omitted, not truncated.
    $made2 = StrikeClient::createInvoiceWithQuote($KEY, 1, null, str_repeat('x', 41));
    $req2 = strike_mock_invoices($dir)[$made2['invoice_id']];
    assert_false(array_key_exists('correlationId', $req2), 'overlong correlationId omitted');
    assert_false(array_key_exists('description', $req2), 'empty description omitted');

    // ---------- 3. findInvoice state mapping ----------
    assert_eq('pending', StrikeClient::findInvoice($KEY, $made['invoice_id'])['state'], 'UNPAID -> pending');
    file_put_contents($dir . '/state', 'PAID');
    assert_eq('paid', StrikeClient::findInvoice($KEY, $made['invoice_id'])['state'], 'PAID -> paid');
    file_put_contents($dir . '/state', 'CANCELLED');
    assert_eq('cancelled', StrikeClient::findInvoice($KEY, $made['invoice_id'])['state'], 'CANCELLED -> cancelled');
    file_put_contents($dir . '/state', 'UNPAID');

    // ---------- 4. failure mapping ----------
    $wrongKey = 'WRONGKEY' . str_repeat('B', 32);
    try {
        StrikeClient::createInvoiceWithQuote($wrongKey, 100);
        fail('wrong key must throw');
    } catch (StrikeException $e) {
        assert_eq(401, $e->httpStatus, 'wrong key -> 401');
        assert_eq(
            'the Strike API rejected the key (check the key and that it has the create, quote, and read invoice scopes)',
            StrikeClient::describeFailure($e),
            '401 maps to the rejected-key phrase'
        );
        // The raw key must never appear in the exception text.
        assert_false(strpos($e->getMessage(), $wrongKey) !== false, 'key not echoed in message');
    }

    file_put_contents($dir . '/fail_create', '429');
    try {
        StrikeClient::createInvoiceWithQuote($KEY, 100);
        fail('429 must throw');
    } catch (StrikeException $e) {
        assert_eq('the Strike API is rate-limiting requests', StrikeClient::describeFailure($e), '429 phrase');
    }
    file_put_contents($dir . '/fail_create', '500');
    try {
        StrikeClient::createInvoiceWithQuote($KEY, 100);
        fail('500 must throw');
    } catch (StrikeException $e) {
        assert_eq('the Strike API reported a server error', StrikeClient::describeFailure($e), '5xx phrase');
    }
    unlink($dir . '/fail_create');

    // A key that can create but not quote (missing scope) fails at the quote.
    file_put_contents($dir . '/fail_quote', '403');
    try {
        StrikeClient::createInvoiceWithQuote($KEY, 100);
        fail('quote 403 must throw');
    } catch (StrikeException $e) {
        assert_eq(403, $e->httpStatus, 'missing quote scope surfaces as 403');
    }
    unlink($dir . '/fail_quote');

    // Dead host -> transport phrase.
    putenv('CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:1/v1');
    try {
        StrikeClient::createInvoiceWithQuote($KEY, 100, null, null, 2);
        fail('dead host must throw');
    } catch (StrikeException $e) {
        assert_eq('the Strike API could not be reached', StrikeClient::describeFailure($e), 'transport phrase');
    }
    putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

    // ---------- 5. amount-mismatch refusal ----------
    file_put_contents($dir . '/quote_btc_override', '0.00000099');
    try {
        StrikeClient::createInvoiceWithQuote($KEY, 100);
        fail('mismatched quote must throw');
    } catch (StrikeException $e) {
        assert_true(strpos($e->getMessage(), '99 sats for a 100 sat invoice') !== false,
            'mismatch names both amounts');
    }
    unlink($dir . '/quote_btc_override');

    // ---------- 6. probeKey ----------
    $probe = StrikeClient::probeKey($KEY);
    assert_true($probe['ok'], 'good key probes ok');
    assert_null($probe['error'], 'no error on ok probe');

    $probe = StrikeClient::probeKey($wrongKey);
    assert_false($probe['ok'], 'wrong key fails the probe');
    assert_true(strpos((string)$probe['error'], 'rejected the key') !== false, 'probe error uses the fixed phrase');
    assert_false(strpos((string)$probe['error'], $wrongKey) !== false, 'probe error never contains the key');

    file_put_contents($dir . '/fail_read', '403');
    $probe = StrikeClient::probeKey($KEY);
    assert_false($probe['ok'], 'missing read scope fails the probe');
    unlink($dir . '/fail_read');
} finally {
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
}

echo "test_strike_client: ok\n";
