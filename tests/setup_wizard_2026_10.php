<?php
/**
 * Setup wizard fixes from the 2026-10 audits, driven through the real setup.php under
 * PHP's built-in server:
 *  - C4/H-1: add_store (and a finished install) cannot reach the password step.
 *  - L-6: standalone setup cannot reach the store steps without an admin password.
 *  - M-2/M-3: a new claim (recovery takeover, idle release) forgets the previous
 *    claimant's pinned base_url, url_mode and unfinished stores; a store is adopted by
 *    name only when this session started it; another host in detected_base_url needs
 *    an explicit confirmation.
 *  - N-4: claiming regenerates the session id.
 *
 * Run: php tests/setup_wizard_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-wizard2610-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
register_shutdown_function(function () use ($dataDir) {
    global $procs;
    foreach ($procs as $p) { proc_terminate($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

// setup.php, plus a test-only login hook that calls the real Auth::login().
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' ini_set("display_errors", "1");'
    . ' $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' if ($path === "/__login") { require ' . var_export("$root/includes/auth.php", true) . ';'
    . '   Auth::initSession(); echo Auth::login((string)($_POST["p"] ?? "")) ? "in" : "out"; return true; }'
    . ' if ($path !== "/setup.php") { return false; }'
    . ' require ' . var_export("$root/setup.php", true) . ';');
$port = random_int(18100, 18999);
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
function sessionId(string $jar): ?string {
    foreach (file($jar) ?: [] as $line) {
        $f = explode("\t", trim($line));
        if (count($f) === 7 && $f[5] === 'cashupay_session') {
            return $f[6];
        }
    }
    return null;
}
function db(): PDO {
    return new PDO('sqlite:' . CASHUPAY_TEST_DB);
}
function cfg(string $key): ?string {
    $v = db()->query('SELECT value FROM config WHERE key = ' . db()->quote($key))->fetchColumn();
    return $v === false ? null : (string)$v;
}
function storeNames(): array {
    return db()->query('SELECT name FROM stores ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}
define('CASHUPAY_TEST_DB', "$dataDir/cashupay.sqlite");

$attacker = "$dataDir/attacker.jar";
$merchant = "$dataDir/merchant.jar";
$third = "$dataDir/third.jar";

echo "Claiming regenerates the session id (N-4)\n";
[, $body] = req($attacker, 'GET', $base);
$before = sessionId($attacker);
check($before !== null, 'the first page view hands out a session');
[, $body] = req($attacker, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'security_acknowledged' => '1',
    'detected_base_url' => 'https://attacker.example/cashupay']);
$after = sessionId($attacker);
check($after !== null && $after !== $before, 'the claiming POST gets a fresh session id');
check(!is_file("$dataDir/sessions/sess_$before"), 'and the old session file is gone');

echo "A detected address on another host is not pinned silently (M-2)\n";
check(str_contains($body, 'attacker.example') && str_contains($body, 'name="confirm_other_host"'),
    'the operator is asked to confirm the other host');
check(cfg('base_url') === null, 'and nothing was pinned');
[, $body] = req($attacker, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'security_acknowledged' => '1',
    'detected_base_url' => 'https://attacker.example/cashupay', 'confirm_other_host' => '1', 'detected_url_mode' => 'direct']);
check(str_contains($body, 'Admin Password') && cfg('base_url') === 'https://attacker.example/cashupay',
    'an explicit confirmation pins it');

echo "No store steps without an admin password (L-6)\n";
[, $body] = req($attacker, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'My Store']);
check(str_contains($body, 'Admin Password') && str_contains($body, 'Choose an admin password first'),
    'posting the store step goes back to the password step with a plain message');
check(storeNames() === [], 'and creates no store');
[, $body] = req($attacker, 'GET', "$base?step=6");
check(str_contains($body, 'Admin Password') && !str_contains($body, 'Store Wallet Seed'), 'the seed step is not shown either');
[, $body] = req($attacker, 'POST', $base, ['step' => '6', 'action' => 'confirm', 'seed_confirmed' => '1',
    'existing_seed' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'csrf_token' => csrf($body)]);
check(cfg('setup_complete') === null, 'and setup cannot be finished');

echo "A recovery takeover forgets the previous claimant's wizard state (M-2, M-3)\n";
[, $body] = req($attacker, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'attacker-pass', 'confirm_password' => 'attacker-pass']);
[, $body] = req($attacker, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'My Store']);
check(str_contains($body, 'Connect Cashu Mint') && storeNames() === ['My Store'], 'the attacker starts a store');
db()->exec("UPDATE stores SET mint_url = 'https://attacker-mint.example', mint_unit = 'sat'");
$seed = 'legal winner thank year wave sausage worth useful legal winner thank yellow';
db()->exec("INSERT INTO stores (id, name, wallet_account_id, mint_url, mint_unit, seed_phrase, created_at)
            VALUES ('store_done', 'Finished Shop', 'wa_done', 'https://mint.example', 'sat', '$seed', 0)");
$code = trim((string)file_get_contents(glob("$dataDir/setup-token-*.txt")[0]));

[, $body] = req($merchant, 'GET', $base);
$before = sessionId($merchant);
[, $body] = req($merchant, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'setup_token' => $code,
    'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password'), 'the merchant takes over with the recovery code');
check(sessionId($merchant) !== $before, 'with a fresh session id');
check(cfg('base_url') === null && cfg('url_mode') === null, 'the planted base_url and url_mode are gone');
check(storeNames() === ['Finished Shop'], 'the unfinished store is gone, the store with a seed is kept');
[, $body] = req($merchant, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'merchant-pass', 'confirm_password' => 'merchant-pass']);
[, $body] = req($merchant, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'My Store']);
$mint = db()->query("SELECT mint_url FROM stores WHERE name = 'My Store'")->fetchColumn();
check(str_contains($body, 'Connect Cashu Mint') && ($mint === null || $mint === ''),
    'the merchant\'s "My Store" is a new store without the attacker\'s mint');

echo "A store this session did not start is not adopted by name (M-3)\n";
db()->exec("INSERT INTO stores (id, name, wallet_account_id, mint_url, created_at)
            VALUES ('store_left', 'Leftover', 'wa_left', 'https://other-mint.example', 0)");
[, $body] = req($merchant, 'GET', "$base?step=4");
[, $body] = req($merchant, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'leftover']);
check(str_contains($body, 'already exists') && str_contains($body, 'Create Store'),
    'the merchant is asked for a different name');
[, $body] = req($merchant, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'my store']);
check(str_contains($body, 'Connect Cashu Mint') && !str_contains($body, 'already exists'),
    'the store this session started is still continued');

echo "An idle-released claim also forgets the wizard state\n";
db()->exec("DELETE FROM config WHERE key = 'admin_password_hash'");
db()->exec("UPDATE config SET value = '{\"hash\":\"x\",\"at\":1,\"seen\":1}' WHERE key = 'setup_claim'");
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('base_url', 'https://stale.example', 0, 0)");
[, $body] = req($third, 'GET', $base);
[, $body] = req($third, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password'), 'a new browser claims the released installation');
check(cfg('base_url') === null && storeNames() === ['Finished Shop'], 'the stale base_url and unfinished stores are gone');

echo "A finished installation cannot reset the admin password through setup (C4)\n";
[, $body] = req($third, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'owner-pass-1', 'confirm_password' => 'owner-pass-1']);
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('setup_complete', 'true', 0, 0)");
db()->exec("INSERT OR REPLACE INTO config (key, value, created_at, updated_at) VALUES ('admin_password_version', 'pv_test', 0, 0)");
[, $out] = req($third, 'POST', "$origin/__login", ['p' => 'owner-pass-1']);
check($out === 'in', 'the owner is logged in');
$hash = cfg('admin_password_hash');
[$status, $body] = req($third, 'GET', "$base?mode=add_store&step=2");
check($status === 200 && !str_contains($body, 'Admin Password') && !str_contains($body, 'name="confirm_password"'),
    'add_store&step=2 does not show the password form');
check(str_contains($body, 'Create Store') && str_contains($body, 'Settings'), 'it shows the store step and says where to change the password');
[, $body] = req($third, 'POST', "$base?mode=add_store", ['step' => '2', 'mode' => 'add_store', 'csrf_token' => csrf($body),
    'password' => 'thief-pass-1', 'confirm_password' => 'thief-pass-1']);
check(cfg('admin_password_hash') === $hash && cfg('admin_password_version') === 'pv_test',
    'posting step 2 changes neither the password nor the session generation');
check(storeNames() === ['Finished Shop'], 'and does not run the store step with the posted fields');
[$status] = req($third, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'thief-pass-1', 'confirm_password' => 'thief-pass-1']);
check($status === 302 && cfg('admin_password_hash') === $hash, 'without add_store the finished install redirects to the dashboard');

echo "All 2026-10 setup wizard checks passed.\n";
