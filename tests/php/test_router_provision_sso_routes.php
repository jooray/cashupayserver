<?php
/**
 * Router dispatch for the provisioning and SSO endpoints.
 *
 * Clean-URL and front-controller installs reach the new endpoints as
 * /provision and /sso (router.php routes), not as bare .php files — the
 * contract tests (test_provision_endpoint / test_sso_endpoint) drive the
 * files directly, so this file pins the routing layer: both extension-less
 * routes AND the /provision.php-style compatibility paths must land in the
 * right endpoint. Each scenario asserts a response only that endpoint
 * produces (its JSON bodies / Allow header), never the router's own plain
 * "Not found", so a silently broken route can't pass.
 *
 * router.php requires-and-exits, so each request runs in a subprocess whose
 * driver fakes PATH_INFO the way front-controller mode receives it.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

$provisionToken = bin2hex(random_bytes(32));
$ssoKey = bin2hex(random_bytes(32));

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/router_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
if (getenv('T_PROVISION_HASH') !== false) {
    define('CASHUPAY_PROVISION_TOKEN_HASH', getenv('T_PROVISION_HASH'));
}
if (getenv('T_SSO_HASH') !== false) {
    define('CASHUPAY_SSO_KEY_HASH', getenv('T_SSO_HASH'));
}
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/router.php';
$_SERVER['REQUEST_URI'] = '/router.php' . getenv('T_PATH');
$_SERVER['PATH_INFO'] = getenv('T_PATH');
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD') ?: 'GET';
if (getenv('T_HEADER_TOKEN') !== false) {
    $_SERVER['HTTP_X_PROVISION_TOKEN'] = getenv('T_HEADER_TOKEN');
}
if (getenv('T_HEADER_SSO') !== false) {
    $_SERVER['HTTP_X_SSO_KEY'] = getenv('T_HEADER_SSO');
}
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP, var_export($root . '/router.php', true)));

/**
 * Run router.php once in a subprocess for one faked request.
 *
 * @param array{provision_hash?:string, sso_hash?:string, token?:string, sso?:string} $opts
 * @return array{status:int, body:string, json:?array}
 */
function route(string $method, string $path, array $opts = []): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_PATH=' . escapeshellarg($path)
        . ' T_METHOD=' . escapeshellarg($method);
    if (isset($opts['provision_hash'])) {
        $env .= ' T_PROVISION_HASH=' . escapeshellarg($opts['provision_hash']);
    }
    if (isset($opts['sso_hash'])) {
        $env .= ' T_SSO_HASH=' . escapeshellarg($opts['sso_hash']);
    }
    if (isset($opts['token'])) {
        $env .= ' T_HEADER_TOKEN=' . escapeshellarg($opts['token']);
    }
    if (isset($opts['sso'])) {
        $env .= ' T_HEADER_SSO=' . escapeshellarg($opts['sso']);
    }
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("router runner produced unparseable output (rc=$rc): " . substr($raw, 0, 500));
    }
    $body = trim($m[1]);
    return ['status' => (int)$m[2], 'body' => $body, 'json' => json_decode($body, true)];
}

// --- Unarmed deployments: the routes exist but the endpoints play dead -------
//
// The JSON not-found body (vs the router's own plain-text "Not found") proves
// the request reached the endpoint, not the router's 404 fallback.
foreach (['/provision', '/provision.php'] as $path) {
    $res = route('POST', $path);
    assert_eq(404, $res['status'], "$path unarmed answers 404");
    assert_eq(['error' => 'Not found'], $res['json'], "$path unarmed reached provision.php, not the router fallback");
}
foreach (['/sso', '/sso.php'] as $path) {
    $res = route('GET', $path);
    assert_eq(404, $res['status'], "$path unarmed answers 404");
    assert_eq(['error' => 'Not found'], $res['json'], "$path unarmed reached sso.php, not the router fallback");
}

// --- Armed: each route lands in the endpoint's own method/auth handling ------

$provisionHash = hash('sha256', $provisionToken);
$res = route('GET', '/provision', ['provision_hash' => $provisionHash]);
assert_eq(405, $res['status'], 'GET /provision is refused by provision.php');
assert_eq(['error' => 'POST required'], $res['json'], 'with provision.php\'s method error');

// Setup is complete (harness default) but no store exists: a valid token
// through the ROUTE answers pending — the endpoint's full logic ran.
$res = route('POST', '/provision', ['provision_hash' => $provisionHash, 'token' => $provisionToken]);
assert_eq(200, $res['status'], 'POST /provision with the token dispatches');
assert_eq(['status' => 'pending'], $res['json'], 'and runs provision.php\'s store-less pending answer');

$ssoHash = hash('sha256', $ssoKey);
$res = route('POST', '/sso', ['sso_hash' => $ssoHash, 'sso' => bin2hex(random_bytes(32))]);
assert_eq(403, $res['status'], 'POST /sso with a wrong key is refused');
assert_eq(['error' => 'Invalid SSO key'], $res['json'], 'by sso.php\'s own key check');

$res = route('POST', '/sso', ['sso_hash' => $ssoHash, 'sso' => $ssoKey]);
assert_eq(200, $res['status'], 'POST /sso with the right key dispatches');
assert_eq(['status' => 'pending'], $res['json'], 'and runs sso.php\'s no-admin-yet pending answer');

// --- Non-matching neighbors still fall through to the router 404 -------------
$res = route('GET', '/provisioning', ['provision_hash' => $provisionHash]);
assert_eq(404, $res['status'], 'a neighboring path is not swallowed by the route');
assert_null($res['json'], 'it gets the router\'s plain-text 404, not an endpoint body');

echo "test_router_provision_sso_routes: ok\n";
