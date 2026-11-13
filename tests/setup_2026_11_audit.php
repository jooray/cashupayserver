<?php
/**
 * Setup fixes from the 2026-11 audit (AUDIT-KIMIK31 #1), driven through the real setup.php
 * and admin.php under PHP's built-in server:
 *  - a stranger who claimed setup first and chose a password is logged in; once the owner
 *    takes over with the recovery code, that admin session must end, and it must not come
 *    back after the owner chooses a password and setup completes.
 *
 * Run: php tests/setup_2026_11_audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-setup2611-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);

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

// setup.php and admin.php, plus a test-only hook that runs the step cashupay_complete_setup()
// uses to keep only the finishing browser logged in.
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' if ($path === "/__complete") { require ' . var_export("$root/includes/auth.php", true) . ';'
    . '   Auth::initSession(); Auth::retireOtherSessions(); echo "done"; return true; }'
    . ' if ($path === "/setup.php") { require ' . var_export("$root/setup.php", true) . '; return true; }'
    . ' if ($path === "/admin.php") { require ' . var_export("$root/admin.php", true) . '; return true; }'
    . ' return false;');
$port = testFreePort();
$procs[] = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, $router],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$origin = "http://127.0.0.1:$port";
$base = "$origin/setup.php";

/** @return array{0:int,1:string} */
function req(string $jar, string $method, string $url, array $post = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}
function csrf(string $body): string {
    preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m);
    return $m[1] ?? '';
}
function db(): PDO {
    return new PDO('sqlite:' . CASHUPAY_TEST_DB);
}
define('CASHUPAY_TEST_DB', "$dataDir/cashupay.sqlite");

/** HTTP status of an authenticated admin API call with this browser's cookies. */
function adminStatus(string $jar): int {
    global $origin;
    [$status] = req($jar, 'GET', "$origin/admin.php?api=dashboard");
    return $status;
}

$stranger = "$dataDir/stranger.jar";
$owner = "$dataDir/owner.jar";

echo "A stranger claims setup and chooses a password\n";
[, $body] = req($stranger, 'GET', $base);
[, $body] = req($stranger, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password'), 'the stranger holds the wizard');
[, $body] = req($stranger, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'stranger-pass', 'confirm_password' => 'stranger-pass']);
check(str_contains($body, 'Create Store'), 'the stranger chose a password and moved on');
// Mark setup finished only to probe the admin API; the wizard is reopened below.
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('setup_complete', 'true', 0, 0)");
check(adminStatus($stranger) === 200, 'the stranger\'s browser is an admin session');
db()->exec("DELETE FROM config WHERE key = 'setup_complete'");

echo "The owner takes over with the recovery code\n";
$code = trim((string)file_get_contents(glob("$dataDir/setup-token-*.txt")[0]));
[, $body] = req($owner, 'GET', $base);
[, $body] = req($owner, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'setup_token' => $code,
    'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password'), 'the owner holds the wizard');
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('setup_complete', 'true', 0, 0)");
check(adminStatus($stranger) === 401, 'the takeover ends the stranger\'s admin session');
check(adminStatus($owner) === 401, 'and the owner is not an admin before choosing a password');
db()->exec("DELETE FROM config WHERE key = 'setup_complete'");

echo "The owner chooses a password and finishes setup\n";
[, $body] = req($owner, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'owner-pass-1', 'confirm_password' => 'owner-pass-1']);
check(str_contains($body, 'Create Store'), 'the owner chose a password');
[, $out] = req($owner, 'GET', "$origin/__complete");
check($out === 'done', 'setup completion retires other sessions');
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('setup_complete', 'true', 0, 0)");
check(adminStatus($stranger) === 401, 'the stranger\'s old cookie is still not an admin session');
check(adminStatus($owner) === 200, 'the owner stays logged in');

echo "Choosing the password retires a session issued before it\n";
// A session stamped with the generation before the owner's password (as an old
// stranger session would be, had the takeover not bumped it) must not survive.
db()->exec("DELETE FROM config WHERE key = 'setup_complete'");
$prior = (string)db()->query("SELECT value FROM config WHERE key = 'admin_password_version'")->fetchColumn();
[, $body] = req($owner, 'GET', "$base?step=2");
[, $body] = req($owner, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'owner-pass-2', 'confirm_password' => 'owner-pass-2']);
$after = (string)db()->query("SELECT value FROM config WHERE key = 'admin_password_version'")->fetchColumn();
check($prior !== '' && $after !== $prior, 'setting the initial password starts a new session generation');
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('setup_complete', 'true', 0, 0)");
check(adminStatus($owner) === 200, 'and the browser that chose it is logged in with it');

echo "All 2026-11 setup audit checks passed.\n";
