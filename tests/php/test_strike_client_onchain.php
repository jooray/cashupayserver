<?php
/**
 * StrikeClient's on-chain receive-request surface, against the shared mock:
 *
 *   1. createOnchainReceiveRequest: POSTs a BTC-denominated sat-exact
 *      onchain.amount, returns the address + receiveRequestId.
 *   2. Refusals: sub-1-sat amount, a btcAmount that isn't sat-exact for what
 *      was asked, missing address in the response, HTTP failures (with the
 *      status carried on the StrikeException).
 *   3. probeOnchainKey: ok on a scoped key; a 403 maps to the operator-facing
 *      "needs the create receive requests scope" phrase; transport failures
 *      map to "could not be reached". No message ever contains the key.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/includes/strike/client.php';

$KEY = 'ONCHAINKEY0' . str_repeat('B', 29);

[$pid, $port, $dir] = start_strike_mock($KEY);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

try {
    // ---------- 1. happy path ----------
    $made = StrikeClient::createOnchainReceiveRequest($KEY, 12345);
    assert_true($made['receive_request_id'] !== '', 'receive request id returned');
    assert_true(str_starts_with($made['address'], 'bc1'), 'a mainnet address returned');

    $captured = strike_mock_receive_requests($dir);
    $req = $captured[$made['receive_request_id']] ?? null;
    assert_not_null($req, 'mock captured the receive request');
    assert_eq('0.00012345', $req['body']['onchain']['amount']['amount'],
        'BTC amount is the exact sat value');
    assert_eq('BTC', $req['body']['onchain']['amount']['currency'],
        'request is BTC-denominated');
    assert_eq($req['address'], $made['address'], 'client returned the address the API handed out');

    // ---------- 2. refusals ----------
    $threw = false;
    try {
        StrikeClient::createOnchainReceiveRequest($KEY, 0);
    } catch (StrikeException $e) {
        $threw = true;
    }
    assert_true($threw, 'sub-1-sat amount refused');

    // btcAmount mismatch: the API "suggesting" a different amount than asked
    // must be refused, not passed to the customer.
    file_put_contents($dir . '/receive_btc_override', '0.00099999');
    $threw = false;
    try {
        StrikeClient::createOnchainReceiveRequest($KEY, 12345);
    } catch (StrikeException $e) {
        $threw = true;
        assert_true(strpos($e->getMessage(), '99999') !== false, 'mismatch message names the suggested sats');
    }
    assert_true($threw, 'btcAmount mismatch refused');
    unlink($dir . '/receive_btc_override');

    // Empty address in the response.
    file_put_contents($dir . '/receive_address_override', '__EMPTY__');
    $threw = false;
    try {
        StrikeClient::createOnchainReceiveRequest($KEY, 100);
    } catch (StrikeException $e) {
        $threw = true;
    }
    assert_true($threw, 'missing address refused');
    unlink($dir . '/receive_address_override');

    // HTTP failure carries the status.
    file_put_contents($dir . '/fail_receive_request', '429');
    $threw = false;
    try {
        StrikeClient::createOnchainReceiveRequest($KEY, 100);
    } catch (StrikeException $e) {
        $threw = true;
        assert_eq(429, $e->httpStatus, 'HTTP status carried on the exception');
    }
    assert_true($threw, 'HTTP failure throws');
    unlink($dir . '/fail_receive_request');

    // ---------- 3. probeOnchainKey ----------
    $probe = StrikeClient::probeOnchainKey($KEY);
    assert_true($probe['ok'], 'scoped key probes ok');
    assert_null($probe['error'], 'no error on ok probe');

    file_put_contents($dir . '/fail_receive_request', '403');
    $probe = StrikeClient::probeOnchainKey($KEY);
    assert_false($probe['ok'], 'missing scope fails the probe');
    assert_true(strpos((string)$probe['error'], 'create receive requests') !== false,
        'error names the missing scope');
    assert_false(strpos((string)$probe['error'], $KEY) !== false, 'error never contains the key');
    unlink($dir . '/fail_receive_request');

    // Wrong key: the mock's 401 also maps to the scope/key phrase.
    $probe = StrikeClient::probeOnchainKey('WRONGKEY0' . str_repeat('C', 31));
    assert_false($probe['ok'], 'rejected key fails the probe');

    // Transport failure (dead port).
    putenv('CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:1/v1');
    $probe = StrikeClient::probeOnchainKey($KEY);
    assert_false($probe['ok'], 'unreachable API fails the probe');
    assert_eq('the Strike API could not be reached', $probe['error'], 'transport phrase');
} finally {
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
}

echo "test_strike_client_onchain: ok\n";
