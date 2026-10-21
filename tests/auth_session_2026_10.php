<?php
/**
 * Login lockout, sessions and the pairing login, 2026-10 audits.
 *
 * - Forwarded identities are also counted per direct peer (a logged warning, not a
 *   refusal, so a shared proxy cannot be used to lock the operator out);
 *   IPv6 clients are counted per /64; 100.64.0.0/10 peers are no longer trusted as
 *   proxies in "auto" mode (DS41 L-11, OPUS N5).
 * - Sessions end after an hour idle, the 24 h limit stays, and session IDs this server
 *   never issued are refused (DS41 L-10).
 * - WordPress multisite: only a super admin counts as the operator (OPUS C6).
 * - The pairing page's post-login redirect stays on this origin (OPUS C8).
 *
 * Run: php tests/auth_session_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);

// Child mode: WordPress stubs must not leak into the standalone checks below.
if (($argv[1] ?? '') === 'wp-multisite') {
    define('CASHUPAY_DATA_DIR', $argv[2]);
    define('CASHUPAY_WORDPRESS', true);
    $GLOBALS['superAdmin'] = $argv[3] === 'super';
    $GLOBALS['multisite'] = $argv[3] !== 'single';
    function is_multisite(): bool { return $GLOBALS['multisite']; }
    function is_super_admin(): bool { return $GLOBALS['superAdmin']; }
    function current_user_can(string $cap): bool { return $cap === 'manage_options'; }
    function wp_get_current_user(): object {
        static $user = null;
        $user ??= (object)['ID' => 7, 'user_pass' => password_hash('wp-correct-horse', PASSWORD_DEFAULT)];
        return $user;
    }
    function wp_check_password(string $password, string $hash, int $userId = 0): bool {
        return password_verify($password, $hash);
    }
    require_once $root . '/includes/database.php';
    require_once $root . '/includes/config.php';
    require_once $root . '/includes/auth.php';
    // The WordPress session capability is not a fresh proof: the seed/backup prompts
    // must check the signed-in user's actual WordPress password (MINIMAX 4.1).
    echo json_encode([
        Auth::isLoggedIn(),
        Auth::verifyAdminPassword('wp-correct-horse'),
        Auth::verifyAdminPassword(''),
        Auth::verifyAdminPassword('wrong'),
    ]);
    exit(0);
}

ob_start(); // the session module wants to send its cookie header
$dataDir = sys_get_temp_dir() . '/cashupay-authsess-' . bin2hex(random_bytes(6));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';

function check(bool $cond, string $what): void {
    if (!$cond) { ob_end_flush(); fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
register_shutdown_function(function () use ($dataDir) {
    global $procs;
    foreach ($procs as $p) { proc_terminate($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

Database::initialize();
Config::set('setup_complete', true);

function setPeer(string $remote, array $headers = []): void {
    foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CF_CONNECTING_IP'] as $h) {
        unset($_SERVER[$h]);
    }
    $_SERVER['REMOTE_ADDR'] = $remote;
    foreach ($headers as $k => $v) {
        $_SERVER[$k] = $v;
    }
}

echo "Forwarded identities are counted per peer too, but only warn (DS41 L-11, OPUS N5)\n";
$granted = 0;
for ($i = 1; $i <= 40; $i++) {
    setPeer('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => "198.51.100.$i", 'HTTP_X_REAL_IP' => '203.0.113.200']);
    $granted += Security::reserveLoginAttempt(Security::getClientIp()) ? 1 : 0;
}
// Behind a real reverse proxy every visitor shares the peer address: refusing on the
// peer count would let anyone lock the operator out.
check($granted === 40, "many forwarded identities through one proxy are not refused as a group ($granted)");
setPeer('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.99']);
check(Security::getLockoutRemaining(Security::getClientIp()) === 0, 'the operator behind the same proxy is not locked out');
setPeer('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
for ($i = 0; $i < 5; $i++) { Security::reserveLoginAttempt(Security::getClientIp()); }
check(Auth::attemptLogin('whatever') === 'locked', 'one forwarded identity still has its own 5-attempt lockout');

$granted = 0;
for ($i = 1; $i <= 8; $i++) {
    setPeer('203.0.113.10');
    $granted += Security::reserveLoginAttempt(Security::getClientIp()) ? 1 : 0;
}
check($granted === 5, "a direct public client keeps its 5 attempts ($granted)");
setPeer('203.0.113.11');
check(Security::reserveLoginAttempt(Security::getClientIp()), 'and its neighbour is unaffected');

$granted = 0;
for ($i = 1; $i <= 8; $i++) {
    setPeer("2001:db8:1:2::$i");
    $granted += Security::reserveLoginAttempt(Security::getClientIp()) ? 1 : 0;
}
check($granted === 5, "addresses of one IPv6 /64 share one budget ($granted)");
setPeer('2001:db8:1:3::1');
check(Security::reserveLoginAttempt(Security::getClientIp()), 'the next /64 has its own');
check(Security::isLockedOut('2001:db8:1:2:ffff::1'), 'isLockedOut sees the /64 too');

setPeer('100.64.1.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.7']);
check(Security::getClientIp() === '100.64.1.1', 'a CGNAT/VPN peer is not a trusted proxy in auto mode');
setPeer('10.0.0.7', ['HTTP_X_FORWARDED_FOR' => '198.51.100.7']);
check(Security::getClientIp() === '198.51.100.7', 'a private LAN proxy still is');

echo "Session lifetime (DS41 L-10)\n";
setPeer('203.0.113.50');
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
check(Auth::login('correct horse'), 'login works');
check(Auth::isLoggedIn(), 'and the session is logged in');
$id = session_id();
check(abs((int)$_SESSION['last_seen'] - time()) <= 1, 'login records last_seen');

$_SESSION['last_seen'] = time() - 3500;
session_write_close();
session_id($id);
check(Auth::isLoggedIn(), 'a session idle for 58 minutes is still valid');
check((int)$_SESSION['last_seen'] >= time() - 1, 'and the request refreshed last_seen');

$_SESSION['last_seen'] = time() - 3700;
session_write_close();
session_id($id);
check(!Auth::isLoggedIn(), 'a session idle for over an hour has ended');
session_write_close();

check(Auth::login('correct horse'), 'login again');
$id = session_id();
$_SESSION['login_time'] = time() - 86500;
session_write_close();
session_id($id);
check(!Auth::isLoggedIn(), 'the 24 h absolute limit still applies to an active session');
session_write_close();

check(Auth::login('correct horse'), 'login again');
$id = session_id();
unset($_SESSION['last_seen']);
$_SESSION['login_time'] = time() - 7200;
session_write_close();
session_id($id);
check(!Auth::isLoggedIn(), 'a session from before last_seen existed counts idle time from login');
session_write_close();

session_id('plantedbyattacker0123456789abcdef');
Auth::initSession();
check(session_id() !== 'plantedbyattacker0123456789abcdef', 'an unknown, client-chosen session ID is replaced (strict mode)');
session_write_close();

echo "WordPress multisite (OPUS C6)\n";
Config::delete('admin_password_hash');
$wp = static fn(string $mode) => json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(__FILE__) . ' wp-multisite ' . escapeshellarg($dataDir) . ' ' . $mode . ' 2>&1'), true);
check($wp('subsite') === [false, false, false, false], 'a subsite admin is not the operator, even with the right password');
check($wp('super') === [true, true, false, false], 'a super admin is, and only the real WordPress password is a fresh proof');
check($wp('single') === [true, true, false, false], 'a single-site administrator still is');

echo "Pairing login redirect (OPUS C8)\n";
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
$router = "$dataDir/router-wrapper.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' return require ' . var_export("$root/router.php", true) . ';');
$port = random_int(18100, 18999);
$procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }

$jar = "$dataDir/cookies.txt";
$request = static function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $resp = (string)curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), substr($resp, 0, $size), substr($resp, $size)];
};

$query = '?applicationName=Shop&permissions=btcpay.store.canviewinvoices';
foreach (['//evil.example/api-keys/authorize', '/\\evil.example/api-keys/authorize'] as $path) {
    [$code] = $request($path . $query);
    check($code === 400, "the router refuses a path that reads as another host ($path)");
}
foreach (['/api-keys/authorize'] as $path) {
    @unlink($jar);
    [$code, , $html] = $request($path . $query);
    check($code === 200 && preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $html, $m) === 1,
        "the pairing login page renders for $path");
    [$code, $headers] = $request($path . $query, ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $m[1]]);
    preg_match('/^Location:\s*(\S+)/mi', $headers, $loc);
    $location = $loc[1] ?? '';
    check($code === 302 && $location === '/api-keys/authorize' . $query,
        "after login it redirects to this origin's own path ($location)");
}

echo "All auth and session checks passed.\n";
ob_end_flush();
