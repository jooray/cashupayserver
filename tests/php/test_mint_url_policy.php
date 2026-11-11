<?php
/**
 * Mint URL policy at the choke point.
 *
 * Config::validateMintUrl(): trims, strips the trailing slash, http(s) only
 * (the library hands the URL to curl, which would speak file://, gopher://,
 * dict://), no userinfo, no fragment, no whitespace/control characters.
 *
 * Config::testMintConnection(): refuses before any socket is opened — a
 * private/loopback target (when the opt-in is off) and non-http(s) schemes
 * never reach the mint client, and the refusal is a generic message. With
 * the opt-in on, a loopback target is attempted and fails with a generic
 * "could not reach" (port 1 is closed), never a connect timeout.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// ---- validateMintUrl -----------------------------------------------------
assert_eq('https://mint.example', Config::validateMintUrl('  https://mint.example/  '), 'trim + trailing slash');
assert_eq('http://cashu_mint:3338', Config::validateMintUrl('http://cashu_mint:3338/'), 'docker service name ok');
assert_eq('https://mint.example/path', Config::validateMintUrl('https://mint.example/path'), 'path kept');

$bad = [
    '' => 'required',
    'file:///etc/passwd' => 'http',
    'gopher://x' => 'http',
    'dict://127.0.0.1:11211/' => 'http',
    'javascript:alert(1)' => 'http',
    'not a url' => 'Mint URL',
    'mint.example' => 'http',
    'ftp://mint.example' => 'http',
    'https://user:pw@mint.example' => 'credentials',
    'https://user@mint.example' => 'credentials',
    'https://mint.example/#frag' => 'fragment',
    "https://mint.ex\nample/" => 'whitespace',
    "https://mint.exa\tmple/" => 'whitespace',
];
foreach ($bad as $url => $needle) {
    $msg = null;
    try {
        Config::validateMintUrl((string)$url);
    } catch (\InvalidArgumentException $e) {
        $msg = $e->getMessage();
    }
    assert_not_null($msg, "refused: " . json_encode($url));
    assert_true(stripos((string)$msg, $needle) !== false, "message for " . json_encode($url) . " mentions '$needle': $msg");
}

// ---- testMintConnection: refusals happen before any connection -------------
// The harness exports CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1; the DB key wins, so
// turn the opt-in OFF here.
Config::set('allow_private_endpoints', false);
assert_false(SafeHttp::privateEndpointsAllowed(), 'private endpoints disallowed for this block');

$t0 = microtime(true);
$r = Config::testMintConnection('file:///etc/passwd');
assert_false($r['success'], 'file:// refused');
assert_true(stripos($r['error'], 'http') !== false, 'scheme error is explicit: ' . $r['error']);
assert_null($r['info'], 'no body');

$r = Config::testMintConnection('http://127.0.0.1:1');
assert_false($r['success'], 'loopback refused with opt-in off');
assert_true(stripos($r['error'], 'private or local network') !== false, 'generic private-network message: ' . $r['error']);
assert_true(stripos($r['error'], '127.0.0.1') === false, 'the refused address is not echoed back');

$r = Config::testMintConnection('http://169.254.169.254/latest/meta-data/');
assert_false($r['success'], 'link-local (cloud metadata) refused');
assert_true(stripos($r['error'], 'private or local network') !== false, 'generic message: ' . $r['error']);

$r = Config::testMintConnection('http://100.64.0.1:3338');
assert_false($r['success'], 'CGNAT refused');

$r = Config::testMintConnection('https://u:p@mint.example');
assert_false($r['success'], 'userinfo refused');
assert_true(stripos($r['error'], 'credentials') !== false, 'userinfo message: ' . $r['error']);

$elapsed = microtime(true) - $t0;
assert_true($elapsed < 2.0, "refusals never opened a socket (elapsed {$elapsed}s < connect timeout)");

// Unresolvable public-looking host: refused by DNS, generic message.
SafeHttp::$resolverForTesting = fn(string $h) => [];
$r = Config::testMintConnection('https://no-such-mint.invalid');
assert_false($r['success'], 'unresolvable host refused');
assert_true(stripos($r['error'], 'could not reach') !== false, 'generic unreachable message: ' . $r['error']);
SafeHttp::$resolverForTesting = null;

// ---- opt-in on: loopback is attempted, closed port fails generically --------
Config::set('allow_private_endpoints', true);
$t0 = microtime(true);
$r = Config::testMintConnection('http://127.0.0.1:1');
$elapsed = microtime(true) - $t0;
assert_false($r['success'], 'closed loopback port fails');
assert_true(is_string($r['error']) && $r['error'] !== '', 'an error is reported');
assert_true($elapsed < 5.0, "connection refused fast, not a timeout ({$elapsed}s)");

echo "ok\n";
