<?php
/**
 * The enable gate + flag storage for "also accept on-chain payments via
 * Strike" (OnchainConfig::gateStrikeOnchainEnable / setStrikeEnabled):
 *
 *   1. Flag storage round-trip; default off; invalid values refused.
 *   2. Gate refusals: no Strike key in the chain; a non-mainnet on-chain
 *      network; a key that fails the receive-request probe (403 -> message
 *      naming the missing scope, never the key).
 *   3. Probe economy: enabling probes every key (one receive request each);
 *      re-saving with the flag already on and the same stored keys probes
 *      nothing; a NEW key while on is probed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/includes/onchain/config.php';
require_once dirname(__DIR__, 2) . '/includes/store_ln_addresses.php';

$KEY = 'GATEKEY0000' . str_repeat('E', 29);
$KEY2 = 'GATEKEY2000' . str_repeat('F', 29);

[$pid, $port, $dir] = start_strike_mock($KEY);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

$gateThrows = function (callable $fn): ?string {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return null;
};

try {
    $store = 'store_gate';
    make_store($store);
    Database::update('stores', ['onchain_network' => 'mainnet'], 'id = ?', [$store]);

    // ---------- 1. flag storage ----------
    assert_false(OnchainConfig::strikeEnabledForStore($store), 'default off');
    OnchainConfig::setStrikeEnabled($store, 1);
    assert_true(OnchainConfig::strikeEnabledForStore($store), 'on after enable');
    OnchainConfig::setStrikeEnabled($store, 0);
    assert_false(OnchainConfig::strikeEnabledForStore($store), 'off after disable');
    $threw = false;
    try {
        OnchainConfig::setStrikeEnabled($store, 2);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'invalid flag value refused');
    assert_false(OnchainConfig::strikeEnabledForStore('missing_store'), 'missing store reads off');

    // ---------- 2. gate refusals ----------
    $msg = $gateThrows(fn() => OnchainConfig::gateStrikeOnchainEnable($store, [], [], false));
    assert_not_null($msg, 'no key -> refused');
    assert_true(strpos($msg, 'add a Strike API key') !== false, 'message says to add a key');

    Database::update('stores', ['onchain_network' => 'testnet'], 'id = ?', [$store]);
    $msg = $gateThrows(fn() => OnchainConfig::gateStrikeOnchainEnable($store, [$KEY], [], false));
    assert_not_null($msg, 'non-mainnet -> refused');
    assert_true(strpos($msg, 'testnet') !== false, 'message names the configured network');
    Database::update('stores', ['onchain_network' => 'mainnet'], 'id = ?', [$store]);

    file_put_contents($dir . '/fail_receive_request', '403');
    $msg = $gateThrows(fn() => OnchainConfig::gateStrikeOnchainEnable($store, [$KEY], [], false));
    assert_not_null($msg, 'unscoped key -> refused');
    assert_true(strpos($msg, 'create receive requests') !== false, 'message names the missing scope');
    assert_false(strpos($msg, $KEY) !== false, 'message never contains the key');
    unlink($dir . '/fail_receive_request');

    // ---------- 3. probe economy ----------
    $count = fn() => count(strike_mock_receive_requests($dir));
    $before = $count();
    OnchainConfig::gateStrikeOnchainEnable($store, [$KEY], [$KEY => true], false);
    assert_eq($before + 1, $count(), 'off->on probes the stored key');

    $before = $count();
    OnchainConfig::gateStrikeOnchainEnable($store, [$KEY], [$KEY => true], true);
    assert_eq($before, $count(), 'already-on + unchanged stored key probes nothing');

    $before = $count();
    file_put_contents($dir . '/expected_key', $KEY2); // mock accepts the new key now
    OnchainConfig::gateStrikeOnchainEnable($store, [$KEY2], [$KEY => true], true);
    assert_eq($before + 1, $count(), 'a NEW key while on is probed');
} finally {
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
}

echo "test_strike_onchain_gate: ok\n";
