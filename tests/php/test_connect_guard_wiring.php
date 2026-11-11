<?php
/**
 * The wallet library's \Cashu\ConnectGuard is wired to SafeHttp at bootstrap
 * (includes/cashu_guard.php, installed from security.php / safe_http.php).
 *
 *  - after bootstrap the guard is installed;
 *  - purpose 'mint': private targets only with the operator opt-in;
 *  - purpose 'lnurl': public only, except when the CASHU_LNURL_URL_TEMPLATE
 *    test seam or the opt-in is set;
 *  - a refusal is a plain \Cashu\CashuException (never CashuNetworkException,
 *    so it can never trigger offline acceptance) and happens before any
 *    socket is opened;
 *  - with the library present, MintClient('http://127.0.0.1:1')->get('info')
 *    is refused by the guard when the opt-in is off.
 *
 * Skips (exit 0) when the library does not ship ConnectGuard yet — it is a
 * separate repository that may lag the server.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

if (!class_exists('\Cashu\ConnectGuard', false)) {
    echo "skip: cashu-wallet-php has no ConnectGuard yet (guard wiring is a no-op until it does)\n";
    exit(0);
}

assert_true(CashuGuard::isInstalled(), 'guard installed at bootstrap');

// ---- policy via the guard callable directly ------------------------------------
putenv('CASHU_LNURL_URL_TEMPLATE');
Config::set('allow_private_endpoints', false);
assert_false(CashuGuard::lnurlPrivateAllowed(), 'lnurl: public only by default');

foreach (['mint', 'lnurl'] as $purpose) {
    foreach (['http://127.0.0.1:1/v1/info', 'http://169.254.169.254/', 'http://100.64.0.1/', 'http://[64:ff9b::7f00:1]/'] as $url) {
        $threw = null;
        try {
            CashuGuard::resolve($url, $purpose);
        } catch (\Throwable $e) {
            $threw = $e;
        }
        assert_not_null($threw, "$purpose refuses $url");
        assert_true($threw instanceof \Cashu\CashuException, "$purpose refusal is a CashuException (" . get_class($threw) . ')');
        assert_false($threw instanceof \Cashu\CashuNetworkException, "$purpose refusal is NOT a network exception");
    }
}

// Opt-in: mints may be private, LNURL too (same switch).
Config::set('allow_private_endpoints', true);
assert_eq(['127.0.0.1:1:127.0.0.1'], CashuGuard::resolve('http://127.0.0.1:1/v1/info', 'mint'), 'mint pin with opt-in');
assert_eq(['127.0.0.1:1:127.0.0.1'], CashuGuard::resolve('http://127.0.0.1:1/x', 'lnurl'), 'lnurl pin with opt-in');

// The LNURL test seam alone opens loopback for lnurl, not for mints.
Config::set('allow_private_endpoints', false);
putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:1/.well-known/lnurlp/{user}');
assert_true(CashuGuard::lnurlPrivateAllowed(), 'lnurl seam set');
assert_eq(['127.0.0.1:1:127.0.0.1'], CashuGuard::resolve('http://127.0.0.1:1/x', 'lnurl'), 'lnurl allowed via the seam');
$threw = false;
try {
    CashuGuard::resolve('http://127.0.0.1:1/v1/info', 'mint');
} catch (\Cashu\CashuException $e) {
    $threw = true;
}
assert_true($threw, 'the lnurl seam does not open mints');
putenv('CASHU_LNURL_URL_TEMPLATE');

// Public hosts resolve through the seam to a pin.
SafeHttp::$resolverForTesting = fn(string $h) => ['93.184.216.34'];
assert_eq(['mint.example:443:93.184.216.34'], CashuGuard::resolve('https://mint.example/v1/info', 'mint'), 'public mint pinned');
assert_eq(['ln.example:443:93.184.216.34'], CashuGuard::resolve('https://ln.example/.well-known/lnurlp/a', 'lnurl'), 'public lnurl pinned');
SafeHttp::$resolverForTesting = null;

// A host that cannot be resolved is "unreachable", not a policy refusal: the
// guard throws the library's NETWORK exception so a DNS outage keeps behaving
// like any other mint outage (offline acceptance / retries), never as a
// rejected token — and never an empty pin list that would let curl resolve
// on its own.
SafeHttp::$resolverForTesting = fn(string $h) => [];
$threw = null;
try {
    CashuGuard::resolve('https://mint.invalid/v1/info', 'mint');
} catch (\Throwable $e) {
    $threw = $e;
}
assert_not_null($threw, 'unresolvable host throws');
assert_true($threw instanceof \Cashu\CashuNetworkException, 'DNS failure is a CashuNetworkException: ' . get_class($threw));
$threw = null;
try {
    (new \Cashu\MintClient('https://mint.invalid'))->get('info');
} catch (\Throwable $e) {
    $threw = $e;
}
assert_true($threw instanceof \Cashu\CashuNetworkException, 'library passes the network exception through: ' . get_class($threw));
SafeHttp::$resolverForTesting = null;

// Userinfo and odd schemes are refused regardless.
foreach (['https://u:p@mint.example/', 'file:///etc/passwd', 'gopher://x/'] as $url) {
    $threw = false;
    try {
        CashuGuard::resolve($url, 'mint');
    } catch (\Cashu\CashuException $e) {
        $threw = true;
    }
    assert_true($threw, "refused: $url");
}

// ---- through the library -------------------------------------------------------
Config::set('allow_private_endpoints', false);
$t0 = microtime(true);
$threw = null;
try {
    (new \Cashu\MintClient('http://127.0.0.1:1'))->get('info');
} catch (\Throwable $e) {
    $threw = $e;
}
assert_not_null($threw, 'MintClient refused');
assert_true($threw instanceof \Cashu\CashuException, 'library refusal surfaces as CashuException: ' . get_class($threw));
assert_false($threw instanceof \Cashu\CashuNetworkException, 'not a network exception (would trigger offline acceptance)');
assert_true(stripos($threw->getMessage(), 'refused') !== false, 'message says refused: ' . $threw->getMessage());
assert_true(microtime(true) - $t0 < 2.0, 'refused before connecting');

echo "ok\n";
