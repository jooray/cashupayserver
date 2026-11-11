<?php
/**
 * bitcoind RPC credentials never travel inside the URL SafeHttp sees.
 *
 * Operators configure the RPC endpoint the way bitcoind documents it,
 * `http://rpcuser:rpcpassword@127.0.0.1:8332/`. SafeHttp refuses userinfo
 * (a credential-bearing URL is one log line away from leaking, and curl and
 * parse_url can disagree about where the authority ends around '@'), so the
 * provider peels user:pass off and sends them as HTTP basic auth. Proved
 * against a local listener that records what arrived.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';
$dir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/safe_http.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

// ---- pure split -------------------------------------------------------------
[$url, $headers] = BitcoindRpcProvider::splitCredentials('http://alice:s3cret%20p%40ss@127.0.0.1:18443/');
assert_eq('http://127.0.0.1:18443/', $url, 'userinfo stripped, port and path kept');
assert_eq(['Authorization: Basic ' . base64_encode('alice:s3cret p@ss')], $headers, 'percent-decoded basic auth header');

[$url, $headers] = BitcoindRpcProvider::splitCredentials('http://127.0.0.1:18443/wallet/w1?x=1');
assert_eq('http://127.0.0.1:18443/wallet/w1?x=1', $url, 'no userinfo -> untouched');
assert_eq([], $headers, 'no header without credentials');

[$url, $headers] = BitcoindRpcProvider::splitCredentials('https://bob@[::1]:8332/');
assert_eq('https://[::1]:8332/', $url, 'IPv6 literal host survives the rebuild');
assert_eq(['Authorization: Basic ' . base64_encode('bob:')], $headers, 'user without password');

// ---- over the wire ----------------------------------------------------------
$sink = RequestSink::start($dir, 200, json_encode(['result' => 123456, 'error' => null, 'id' => 'cashupay']));
$provider = new BitcoindRpcProvider('http://rpcuser:rpc%2Fpass@127.0.0.1:' . $sink->port . '/');
$height = $provider->rpc('getblockcount', []);
assert_eq(123456, $height, 'RPC answer parsed');
$reqs = $sink->requests();
assert_eq(1, count($reqs), 'exactly one request reached the node');
assert_eq('POST', $reqs[0]['method'], 'JSON-RPC POST');
assert_eq('/', $reqs[0]['uri'], 'path kept');
assert_eq('Basic ' . base64_encode('rpcuser:rpc/pass'), $reqs[0]['headers']['HTTP_AUTHORIZATION'] ?? null, 'credentials arrived as basic auth');
assert_eq('127.0.0.1:' . $sink->port, $reqs[0]['headers']['HTTP_HOST'] ?? null, 'Host header carries no userinfo');
$sink->stop();

// SafeHttp itself still refuses a credential-bearing URL (the provider is
// the only place that translates them).
$threw = false;
try {
    SafeHttp::validateUrl('http://u:p@127.0.0.1:1/', true);
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'credentials');
}
assert_true($threw, 'SafeHttp keeps refusing userinfo');

echo "ok\n";
