<?php
/**
 * EndpointHealth cooldown lifecycle: a recorded failure puts the endpoint in
 * cooldown for the requested window, a success clears it, an expired window
 * clears itself, and unknown endpoints are never "cooling down". The tracker
 * is the ordering hint behind provider/host skipping in ExchangeRates and
 * EsploraProvider — it must fail open (no cooldown) rather than ever block.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/endpoint_health.php';

// Unknown endpoint: not cooling down.
assert_false(EndpointHealth::isCoolingDown('rates:nosuch'), 'unknown endpoint is not cooling down');

// Failure -> cooling down.
EndpointHealth::recordFailure('rates:coingecko');
assert_true(EndpointHealth::isCoolingDown('rates:coingecko'), 'failure starts a cooldown');
// Other endpoints unaffected.
assert_false(EndpointHealth::isCoolingDown('rates:kraken'), 'cooldown is per-endpoint');

// Success clears it.
EndpointHealth::recordSuccess('rates:coingecko');
assert_false(EndpointHealth::isCoolingDown('rates:coingecko'), 'success clears the cooldown');

// recordSuccess on a clean endpoint is a no-op (must not throw / write).
EndpointHealth::recordSuccess('rates:coingecko');
assert_false(EndpointHealth::isCoolingDown('rates:coingecko'), 'success on clean endpoint stays clean');

// An expired window clears itself: back-date the stored record.
EndpointHealth::recordFailure('esplora:https://mempool.space/api', 1);
// The key sanitizer collapses URL characters; recompute it the same way.
$key = 'endpoint_health_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', 'esplora:https://mempool.space/api');
$data = Config::get($key);
assert_not_null($data, 'failure record persisted');
$data['until'] = time() - 5;
Config::set($key, $data);
assert_false(EndpointHealth::isCoolingDown('esplora:https://mempool.space/api'), 'expired cooldown is over');

// A fresh failure re-arms it, and the window honours the requested length.
EndpointHealth::recordFailure('esplora:https://mempool.space/api', 3600);
assert_true(EndpointHealth::isCoolingDown('esplora:https://mempool.space/api'), 'new failure re-arms the cooldown');
$data = Config::get($key);
assert_true((int)$data['until'] > time() + 3000, 'cooldown window honours the requested length');

fwrite(STDERR, "test_endpoint_health: all assertions passed\n");
