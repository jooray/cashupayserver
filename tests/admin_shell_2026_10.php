<?php
/**
 * The admin page shell, served before login, gives nothing away (2026-10 audit).
 *
 * - L-8: the absolute data-directory path was printed into the public HTML. It now
 *   comes from an authenticated ?api=data_folder call after unlock.
 * - Every admin response says Cache-Control: no-store.
 *
 * Runs the real admin.php under PHP's built-in server; no network.
 *
 * Run: php tests/admin_shell_2026_10.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-adminshell-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$proc = null;
register_shutdown_function(function () use ($dataDir, &$proc) {
    if ($proc) { testStopProcess($proc); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

Database::initialize();
Config::set('setup_complete', true);
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
// No background self-requests or update checks from this test.
Config::set('update_check_enabled', false);
Config::set('last_background_trigger', (string)(time() + 3600));

$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' require ' . var_export("$root/admin.php", true) . ';');
$port = testFreePort();
$proc = proc_open(['php', '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, $root, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
Config::set('base_url', "http://127.0.0.1:$port");
$url = "http://127.0.0.1:$port/admin.php";

$cookies = "$dataDir/cookies.txt";
/** @return array{0:int,1:string,2:string} code, headers, body */
function req(string $url, ?array $post = null): array {
    global $cookies;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies,
    ] + ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)] : []));
    $resp = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // flushes the cookie jar
    return [$code, substr($resp, 0, $size), substr($resp, $size)];
}

$realDir = realpath($dataDir);

Config::set('alert_email', 'owner@example.com');
echo "Before login\n";
[$code, $headers, $body] = req($url);
check(!str_contains($body, 'owner@example.com'), 'the shell does not contain the alert email address');
check($code === 200 && str_contains($body, 'id="data-folder-path"'), "the shell is served (HTTP $code)");
check(!str_contains($body, $dataDir) && !str_contains($body, $realDir), 'without the data folder path in it');
check((bool)preg_match('/^Cache-Control:\s*no-store/mi', $headers), 'with Cache-Control: no-store');
[$code, $headers, $body] = req("$url?api=data_folder");
check($code === 401 && !str_contains($body, $realDir), "the path API refuses an anonymous caller (HTTP $code)");

echo "After login\n";
[$code, , $body] = req($url, ['action' => 'login', 'password' => 'correct horse']);
$token = json_decode($body, true)['csrfToken'] ?? '';
[$code, , $body] = req($url, ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $token]);
check($code === 200 && (json_decode($body, true)['success'] ?? false) === true, "login works (HTTP $code: " . substr($body, 0, 120) . ')');
$fresh = json_decode($body, true)['csrfToken'] ?? '';
check($fresh !== '' && $fresh !== $token, 'login hands out a new CSRF token (N1)');
[$code] = req($url, ['action' => 'save_update_check', 'enabled' => 'true', 'csrf_token' => $token]);
check($code === 403, "the token from before login no longer works (HTTP $code)");
[$code] = req($url, ['action' => 'save_update_check', 'enabled' => 'true', 'csrf_token' => $fresh]);
check($code === 200, "the new one does (HTTP $code)");
[$code, $headers, $body] = req("$url?api=data_folder");
check($code === 200 && (json_decode($body, true)['path'] ?? null) === $realDir, "the path API returns the data folder (HTTP $code)");
check((bool)preg_match('/^Cache-Control:\s*no-store/mi', $headers), 'also with Cache-Control: no-store');

echo "admin_shell_2026_10: OK\n";
