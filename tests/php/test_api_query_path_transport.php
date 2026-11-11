<?php
/**
 * api.php cashupay_path query transport.
 *
 * Hosts with no .htaccess rewrites AND no PATH_INFO (nginx with a stock
 * WordPress config — Local WP) can only reach PHP through URLs that end in
 * .php, so api.php accepts the API path as an explicit query parameter:
 * /api.php?cashupay_path=/api/v1/... — the transport the WordPress plugin's
 * API bridge replays caught requests over. Pinned here: the query path
 * routes exactly like a rewritten path (both the /api/v1 form and the
 * BTCPay-compatible /v1 alias), PATH_INFO still wins when both are present
 * (router.php sets it authoritatively), a non-absolute value is ignored
 * rather than trusted, and without any transport the bare /api.php request
 * stays a 404.
 *
 * api.php echoes JSON and exits, so each request runs in a subprocess against
 * a shared initialized database with setup_complete set — /api/v1/server/info
 * is public and answers 200 with the isCashuPayServer marker, which is the
 * proof the route resolved (a missing route is a 404 JSON body instead).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/database.php';
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

Database::initialize();
Config::set('setup_complete', true);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/api_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'wp.test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
// The shape a rewrite-hostile host delivers: the script itself is the URL,
// no PATH_INFO, everything else rides the query string.
$_SERVER['SCRIPT_NAME'] = '/barebits/api.php';
$_SERVER['REQUEST_URI'] = '/barebits/api.php' . (getenv('T_QUERY') !== false ? '?' . getenv('T_QUERY') : '');
if (getenv('T_QUERY') !== false) {
    parse_str((string)getenv('T_QUERY'), $_GET);
}
if (getenv('T_PATH_INFO') !== false) {
    $_SERVER['PATH_INFO'] = getenv('T_PATH_INFO');
}
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP, var_export($root . '/api.php', true)));

/**
 * Run api.php once in a subprocess.
 *
 * @return array{status:int, json:?array}
 */
function api_request(?string $query, ?string $pathInfo = null): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir);
    if ($query !== null) {
        $env .= ' T_QUERY=' . escapeshellarg($query);
    }
    if ($pathInfo !== null) {
        $env .= ' T_PATH_INFO=' . escapeshellarg($pathInfo);
    }
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("api runner produced unparseable output (rc=$rc): " . substr($raw, 0, 500));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

// --- No transport at all: bare /api.php resolves no route --------------------
$res = api_request(null);
assert_eq(404, $res['status'], 'bare /api.php with no path is a 404');
assert_eq('not-found', $res['json']['code'] ?? null, 'with the not-found JSON body');

// --- The query transport routes like a rewritten path ------------------------
$res = api_request('cashupay_path=' . rawurlencode('/api/v1/server/info'));
assert_eq(200, $res['status'], 'cashupay_path=/api/v1/server/info resolves');
assert_true(!empty($res['json']['isCashuPayServer']), 'and reaches the real server-info endpoint');

// The BTCPay-compatible /v1 alias normalizes exactly like the rewrite does.
$res = api_request('cashupay_path=' . rawurlencode('/v1/server/info'));
assert_eq(200, $res['status'], 'the /v1 alias resolves through the query transport');
assert_true(!empty($res['json']['isCashuPayServer']), 'to the same endpoint');

// Additional query parameters ride alongside without confusing the path.
$res = api_request('cashupay_path=' . rawurlencode('/api/v1/server/info') . '&skip=0&take=5');
assert_eq(200, $res['status'], 'extra query parameters coexist with the path');

// --- Robustness ---------------------------------------------------------------
// A non-absolute value is ignored (falls back to REQUEST_URI parsing → 404),
// never trusted as a path.
$res = api_request('cashupay_path=' . rawurlencode('api/v1/server/info'));
assert_eq(404, $res['status'], 'a non-absolute cashupay_path is ignored');

// A path outside the API namespace stays a 404 — the transport moves the
// path, it does not widen what routes.
$res = api_request('cashupay_path=' . rawurlencode('/user_config.php'));
assert_eq(404, $res['status'], 'a non-API cashupay_path resolves nothing');

// PATH_INFO (router.php's authoritative hand-off) wins over a query path.
$res = api_request('cashupay_path=' . rawurlencode('/api/v1/nonsense'), '/api/v1/server/info');
assert_eq(200, $res['status'], 'PATH_INFO takes precedence over cashupay_path');
assert_true(!empty($res['json']['isCashuPayServer']), 'and routes to its own path');

echo "test_api_query_path_transport: ok\n";
