<?php
/**
 * The health endpoint for uptime monitors (health.php, includes/health.php).
 *
 *  - Health::report(): which states are ok / degraded / unavailable, including the
 *    background heartbeat and a payment path that keeps failing.
 *  - The real endpoint under PHP's built-in server, through router.php: the public
 *    answer carries the status and nothing else; the monitoring key, the cron key and
 *    an admin session get details; a wrong key is a 403; GET/HEAD only; no stack traces;
 *    and answering changes nothing in the data folder or the database.
 *
 * Run: php tests/health_endpoint.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-health-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/health.php';
require_once $root . '/includes/background_runner.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
register_shutdown_function(function () use ($dataDir) {
    global $procs;
    foreach ($procs as $p) { testStopProcess($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

/** @return array<string,string> check name => status */
function checkStatuses(array $report): array {
    return array_column($report['checks'], 'status', 'name');
}

echo "The payment-path task list matches the runner's\n";
check(Health::PAYMENT_PATH_TASKS === array_values(array_diff(BackgroundRunner::ALWAYS_RUN, ['update_check'])),
    'Health::PAYMENT_PATH_TASKS is BackgroundRunner::ALWAYS_RUN without the update check');

echo "Before setup\n";
$r = Health::report();
check($r['status'] === Health::UNAVAILABLE && !$r['databaseOk'], 'no database: unavailable');
check(!file_exists(Database::getDbPath()), 'and the check did not create one');

Database::initialize();
$r = Health::report();
check($r['status'] === Health::UNAVAILABLE && checkStatuses($r)['setup'] === Health::FAIL, 'setup not finished: unavailable');

echo "States after setup\n";
Config::set('setup_complete', true);
$r = Health::report();
check($r['status'] === Health::UNAVAILABLE && checkStatuses($r)['stores'] === Health::FAIL,
    'no store ready after setup: unavailable (no payment can be taken)');
check(checkStatuses($r)['background'] === Health::WARN, 'background never ran: a warning');

// A store without a wallet account is skipped by the background runner: not ready.
Database::insert('stores', [
    'id' => 'store_0', 'name' => 'Half done', 'wallet_account_id' => null,
    'mint_url' => 'https://mint.secret-example.com', 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp(),
]);
check(checkStatuses(Health::report())['stores'] === Health::FAIL, 'a store without a wallet account is not ready');
Database::query("DELETE FROM stores WHERE id = 'store_0'");

Database::insert('stores', [
    'id' => 'store_1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'https://mint.secret-example.com', 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp(),
]);
$now = time();
Config::set('cron_last_run', (string)$now);
Config::set('cron_task_health', ['poll_quotes' => ['last_ok' => $now]]);
Config::clearCache();
$r = Health::report($now);
check($r['status'] === Health::OK, 'store ready, background ran just now: ok');

$r = Health::report($now + Health::HEARTBEAT_STALE_SECONDS + 1);
check($r['status'] === Health::DEGRADED && checkStatuses($r)['background'] === Health::WARN,
    'heartbeat older than ' . Health::HEARTBEAT_STALE_SECONDS . 's: degraded, not unavailable');

Config::set('cron_task_health', ['poll_quotes' => ['last_error' => $now, 'failures' => 1, 'failing_since' => $now, 'message' => 'timeout']]);
Config::clearCache();
$r = Health::report($now);
check($r['status'] === Health::DEGRADED && checkStatuses($r)['payments'] === Health::WARN, 'one payment-path failure: degraded');

$since = $now - Health::PAYMENT_PATH_FAILING_SECONDS - 1;
Config::set('cron_task_health', ['webhook_outbox' => ['last_error' => $now, 'failures' => Health::PAYMENT_PATH_FAILURES,
    'failing_since' => $since, 'message' => 'mint unreachable']]);
Config::clearCache();
$r = Health::report($now);
check($r['status'] === Health::UNAVAILABLE && checkStatuses($r)['payments'] === Health::FAIL,
    'payment path failing ' . Health::PAYMENT_PATH_FAILURES . ' times for 10+ minutes: unavailable');

Config::set('cron_task_health', ['cleanup_invoices' => ['last_error' => $now, 'failures' => 9, 'failing_since' => $since, 'message' => 'x']]);
Config::clearCache();
$r = Health::report($now);
check($r['status'] === Health::DEGRADED && checkStatuses($r)['payments'] === Health::PASS
    && checkStatuses($r)['maintenance'] === Health::WARN, 'housekeeping failing: degraded, payments still fine');

Config::set('cron_task_health', ['poll_quotes' => ['last_ok' => $now]]);
Config::clearCache();

echo "Views\n";
$r = Health::report($now);
check(Health::publicView($r) === ['status' => 'ok'], 'the public view is the status alone');
check(Health::httpCode(Health::OK) === 200 && Health::httpCode(Health::DEGRADED) === 200
    && Health::httpCode(Health::UNAVAILABLE) === 503, '200 for ok and degraded, 503 for unavailable');
$d = Health::detailedView($r, $now);
check(isset($d['checks'], $d['background'], $d['version']) && $d['pendingInvoices'] === 0, 'the detailed view has checks, heartbeat, version');
$json = json_encode($d);
check(!str_contains($json, 'Yarn Shop') && !str_contains($json, 'secret-example') && !str_contains($json, $dataDir),
    'not even the detailed view names a store, a mint or a path');

echo "Keys\n";
check(!Health::keyMatches('') && !Health::keyMatches('anything'), 'no key configured: nothing matches');
$healthKey = Health::rotateKey();
check(strlen($healthKey) === 48 && Health::keyMatches($healthKey), 'a monitoring key matches');
check(!Health::keyMatches(substr($healthKey, 0, -1)) && !Health::keyMatches($healthKey . 'x'), 'a near miss does not');
Config::set('cron_key', $cronKey = bin2hex(random_bytes(16)));
check(Health::keyMatches($cronKey), 'the cron key matches too');
$oldKey = $healthKey;
$healthKey = Health::rotateKey();
check(!Health::keyMatches($oldKey) && Health::keyMatches($healthKey), 'rotating the key retires the old one');

echo "The endpoint\n";
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
// No background self-requests or update checks from the admin login below.
Config::set('update_check_enabled', false);
Config::set('last_background_trigger', (string)(time() + 3600));

$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' return require ' . var_export("$root/router.php", true) . ';');
$port = testFreePort();
$procs[] = $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-S', "127.0.0.1:$port", $router],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
testWaitForServer($proc, $port);
$base = "http://127.0.0.1:$port";
Config::set('base_url', $base);

/** @return array{0:int,1:string,2:array<string,string>} code, body, headers (lowercased names) */
function http(string $method, string $url, array $headers = []): array {
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 20,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; continue; }
        [$name, $value] = array_pad(explode(':', $h, 2), 2, '');
        $out[strtolower(trim($name))] = trim($value);
    }
    return [$code, (string)$body, $out];
}

/**
 * What a request could have changed: every row of every table, and which files exist
 * in the data folder. (Not the database file's bytes: a connection closing checkpoints
 * the WAL, which rewrites pages without changing a single row.)
 */
function dataFingerprint(string $dir): string {
    clearstatcache();
    $parts = [];
    foreach (Database::fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $t) {
        $parts[] = $t['name'] . ':' . hash('sha256', json_encode(Database::fetchAll('SELECT * FROM "' . $t['name'] . '"')));
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $name = substr($file->getPathname(), strlen($dir));
        if (!preg_match('/\.sqlite(-wal|-shm)?$/', $name)) {
            $parts[] = $name;
        }
    }
    sort($parts);
    return hash('sha256', implode("\n", $parts));
}
$before = dataFingerprint($dataDir);

// router.php/health (front-controller mode) needs PATH_INFO, which the built-in server
// does not set; tests/apache_authorization_header.php covers it under Apache.
foreach (['/health', '/health.php'] as $path) {
    [$code, $body, $h] = http('GET', $base . $path);
    check($code === 200 && $body === '{"status":"ok"}', "GET $path: 200 {\"status\":\"ok\"} ($code $body)");
}
[, , $h] = http('GET', "$base/health");
check(str_starts_with($h['content-type'] ?? '', 'application/json') && ($h['cache-control'] ?? '') === 'no-store',
    'JSON, never cached');

[$code, $body] = http('HEAD', "$base/health");
check($code === 200 && $body === '', 'HEAD: the status code, no body');
[$code, , $h] = http('POST', "$base/health");
check($code === 405 && ($h['allow'] ?? '') === 'GET, HEAD', 'POST: 405 with Allow: GET, HEAD');

[$code, $body] = http('GET', "$base/health", ['X-Health-Key: ' . $healthKey]);
$detail = json_decode($body, true);
check($code === 200 && ($detail['status'] ?? null) === 'ok' && isset($detail['checks'], $detail['background']['lastRun']),
    'the monitoring key in X-Health-Key: details');
[$code, $body] = http('GET', "$base/health?key=" . rawurlencode($healthKey));
check($code === 200 && isset(json_decode($body, true)['checks']), '... and in ?key= (for monitors that only take a URL)');
[$code, $body] = http('GET', "$base/health", ['X-Health-Key: ' . $cronKey]);
check($code === 200 && isset(json_decode($body, true)['checks']), 'the cron key: details');
[$code, $body] = http('GET', "$base/health", ['X-Health-Key: wrong']);
check($code === 403 && $body === '{"status":"forbidden"}', 'a wrong key: 403, nothing else');
[$code, $body] = http('GET', "$base/health?key[]=x");
check($code === 200 && $body === '{"status":"ok"}', '?key[]= is not a key (no TypeError, public answer)');

check(dataFingerprint($dataDir) === $before,
    'none of these requests changed a row in the database or created a file (no session either)');

[$code, $body] = http('GET', "$base/health", ['Cookie: cashupay_session=' . str_repeat('a', 26)]);
check($code === 200 && $body === '{"status":"ok"}', 'a session cookie that is not logged in: public answer');

echo "Logged in as the operator\n";
$cookies = [];
$admin = function (string $method, string $query = '', array $post = []) use ($base, &$cookies): array {
    $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($post);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20,
        'header' => implode("\r\n", $headers), 'content' => $content]]);
    $body = (string)@file_get_contents("$base/admin.php$query", false, $ctx);
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = $m[2]; }
    }
    return [$http_response_header[0] ?? '', $body];
};
[, $page] = $admin('GET');
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
$csrf = $m[1] ?? '';
[$status, $body] = $admin('POST', '', ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $csrf]);
$csrf = json_decode($body, true)['csrfToken'] ?? $csrf;
check(str_contains($status, '200'), "admin login ($status)");

[$code, $body] = http('GET', "$base/health", ['Cookie: cashupay_session=' . $cookies['cashupay_session']]);
check($code === 200 && isset(json_decode($body, true)['checks']), 'the admin session: details');

[, $body] = $admin('GET', '?api=monitoring');
$mon = json_decode($body, true);
check(($mon['publicUrl'] ?? null) === "$base/health.php" && str_contains((string)$mon['privateUrl'], rawurlencode($healthKey)),
    'the dashboard shows the address and the private link');
[, $body] = $admin('POST', '', ['action' => 'rotate_health_key', 'csrf_token' => $csrf]);
$newUrl = (string)(json_decode($body, true)['privateUrl'] ?? '');
check($newUrl !== '' && !str_contains($newUrl, $healthKey), 'replacing the private link from the dashboard');
[$code] = http('GET', "$base/health", ['X-Health-Key: ' . $healthKey]);
check($code === 403, 'the old link stops working');
[$code] = http('GET', $newUrl);
check($code === 200, 'the new one works');
[, $body] = $admin('POST', '', ['action' => 'rotate_health_key']);
check(str_contains($body, 'CSRF'), 'rotating needs the CSRF token');
[, $body] = $admin('POST', '', ['action' => 'delete_health_key', 'csrf_token' => $csrf]);
Config::clearCache();
check(Health::getKey() === null, 'removing the private link');

echo "Unavailable states over HTTP\n";
Config::set('cron_task_health', ['poll_quotes' => ['last_error' => time(), 'failures' => 5,
    'failing_since' => time() - 3600, 'message' => 'mint unreachable']]);
[$code, $body, $h] = http('GET', "$base/health");
check($code === 503 && $body === '{"status":"unavailable"}' && isset($h['retry-after']),
    'payment path down: 503 {"status":"unavailable"} with Retry-After');
[$code, $body] = http('GET', "$base/health", ['X-Health-Key: ' . $cronKey]);
$detail = json_decode($body, true);
check($code === 503 && ($detail['background']['failing'][0]['task'] ?? null) === 'poll_quotes',
    'the key holder sees which task is failing');

// A database the server cannot open: still a clean 503, no warning or path in the body.
Database::getInstance()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
rename(Database::getDbPath(), Database::getDbPath() . '.away');
file_put_contents(Database::getDbPath(), 'this is not a database');
[$code, $body] = http('GET', "$base/health");
check($code === 503 && $body === '{"status":"unavailable"}', "a broken database: 503 and nothing else ($body)");
[$code, $body] = http('GET', "$base/health", ['X-Health-Key: ' . $cronKey]);
check($code === 503 && $body === '{"status":"unavailable"}', 'a key cannot be checked then, and gets the public answer');
unlink(Database::getDbPath());
rename(Database::getDbPath() . '.away', Database::getDbPath());

echo "health_endpoint: OK\n";
