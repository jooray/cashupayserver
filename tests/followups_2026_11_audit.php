<?php
/**
 * Follow-ups to the 2026-11 audit fixes.
 *
 *  - A keyset the mint no longer knows, skipped during recovery (the library's
 *    abandonedKeysets), is remembered per store in config and shown on the dashboard
 *    in plain words until the operator dismisses it (CSRF-protected).
 *  - Deleting a store removes the config entries kept under its id (mint history,
 *    recovery sheet, checkout failures, abandoned keysets).
 *
 * Against the mock mint and the real admin.php under `php -S`.
 *
 * Run: php tests/followups_2026_11_audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-followups-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/invoice.php';

use Cashu\WalletStorage;

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

function startServer(array $cmd, array $env = []): int {
    global $procs, $root;
    $port = testFreePort();
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
    $procs[] = $proc;
    testWaitForServer($proc, $port);
    return $port;
}

$mintPort = startServer([PHP_BINARY, '-S', '127.0.0.1:{port}', "$root/tests/mock_mint_router.php"],
    ['MOCK_MINT_STATE' => "$dataDir/mint.json"]);
$mintUrl = "http://127.0.0.1:$mintPort";

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true);
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
Config::set('update_check_enabled', false);
Config::set('last_background_trigger', (string)(time() + 3600));
$acct = Database::generateWalletAccountId();
Database::insert('stores', ['id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => $acct,
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp()]);

echo "A keyset the mint dropped during recovery is remembered after the restore ends\n";
// An imported seed: the account recovers before it can take payments. Start the scan,
// then mark a keyset of that session as one the mint no longer knows (what the
// library records when the mint answers "unknown keyset").
$wallet = Invoice::initializeWalletForStore('s1', true);
check($wallet->restoreStep(1)['status'] === 'pending', 'the recovery scan is under way');
$walletId = WalletStorage::deriveWalletId($mintUrl, 'sat', $acct);
Database::insert('cashu_restore_progress', [
    'wallet_id' => $walletId, 'keyset_id' => '00deadbeefcafe00', 'unit' => 'sat', 'next_counter' => 4,
    'highest_signed' => 1, 'empty_run' => 0, 'done' => WalletStorage::RESTORE_ABANDONED,
    'last_error' => 'The mint no longer knows keyset 00deadbeefcafe00', 'updated_at' => time(),
]);
$step = Invoice::advanceStoreRecovery('s1', 30.0);
check($step['ready'], 'recovery still completes: ' . json_encode($step));
Config::clearCache();
$stored = Config::get('abandoned_keysets_s1');
check(isset($stored[$walletId]['keysets']['00deadbeefcafe00']) && $stored[$walletId]['mint'] === $mintUrl,
    'kept in config under the store, per account: ' . json_encode($stored));
$notices = Invoice::abandonedKeysetNotices('s1');
check(count($notices) === 1 && $notices[0]['count'] === 1 && $notices[0]['store'] === 'Yarn Shop',
    'listed for the dashboard');
check(Invoice::abandonedKeysetNotices('other') === [], 'only for its own store');

echo "Through the dashboard\n";
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' return require ' . var_export("$root/router.php", true) . ';');
$port = startServer([PHP_BINARY, '-S', '127.0.0.1:{port}', $router]);
$base = "http://127.0.0.1:$port";
Config::set('base_url', $base);
$cookies = [];
$admin = function (string $method, array $post = [], string $query = '') use ($base, &$cookies): array {
    $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($post);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 60,
        'header' => implode("\r\n", $headers), 'content' => $content]]);
    $body = (string)@file_get_contents("$base/admin.php$query", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = $m[2]; }
    }
    return [$code, json_decode($body, true), $body];
};
[, , $page] = $admin('GET');
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
[$code, $login] = $admin('POST', ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $m[1] ?? '']);
$csrf = (string)($login['csrfToken'] ?? '');
check($code === 200 && $csrf !== '', 'admin login');
[, , $page] = $admin('GET');
check(str_contains($page, 'Your mint has removed an old ') && str_contains($page, 'Nothing else needs doing. ')
    && str_contains($page, 'dismiss_abandoned_keysets'), 'the dashboard has the plain-language notice and its dismiss action');
if (trim((string)shell_exec('command -v node')) !== '') {
    preg_match_all('#<script>(.*?)</script>#s', $page, $scripts);
    $js = "$dataDir/admin-scripts.js";
    file_put_contents($js, implode("\n;\n", $scripts[1]));
    exec('node --check ' . escapeshellarg($js) . ' 2>&1', $out, $rc);
    check($rc === 0, 'the dashboard scripts are valid JavaScript' . ($rc ? ': ' . implode(' ', $out) : ''));
}

[$code, $diag] = $admin('GET', [], '?api=diagnostics&store_id=s1');
check($code === 200 && count($diag['abandonedKeysets'] ?? []) === 1 && $diag['needsAttention'] === true,
    'diagnostics report it and ask for attention: ' . json_encode($diag['abandonedKeysets'] ?? null));

[$code] = $admin('POST', ['action' => 'dismiss_abandoned_keysets', 'store_id' => 's1']);
check($code === 403 && Invoice::abandonedKeysetNotices('s1') !== [], 'dismissing needs the CSRF token');
[$code, $r] = $admin('POST', ['action' => 'dismiss_abandoned_keysets', 'store_id' => 's1', 'csrf_token' => $csrf]);
Config::clearCache();
check($code === 200 && ($r['success'] ?? false) && Invoice::abandonedKeysetNotices('s1') === [], 'dismissed');
[$code, $diag] = $admin('GET', [], '?api=diagnostics&store_id=s1');
check(($diag['abandonedKeysets'] ?? null) === [], 'gone from the dashboard');
[$code] = $admin('POST', ['action' => 'dismiss_abandoned_keysets', 'store_id' => 'nope', 'csrf_token' => $csrf]);
check($code === 404, 'nothing to dismiss for an unknown store');

Invoice::recordAbandonedKeysets('s1', $mintUrl, 'sat', ['00deadbeefcafe00' => 'again']);
Config::clearCache();
check(Invoice::abandonedKeysetNotices('s1') === [], 'the same keyset seen again stays dismissed');
Invoice::recordAbandonedKeysets('s1', $mintUrl, 'sat', ['00feedfacefeed00' => 'another']);
Config::clearCache();
$notices = Invoice::abandonedKeysetNotices('s1');
check(count($notices) === 1 && $notices[0]['count'] === 1, 'a new one shows again');

echo "Deleting a store removes its config entries\n";
Config::recordStoreMint('s1', $mintUrl, 'sat');
Config::set('recovery_kit_s1', ['fingerprint' => 'x', 'at' => time()]);
Config::set('checkout_failures_s1', ['first' => time(), 'last' => time(), 'count' => 1, 'reason' => 'mint']);
Config::set('mint_history_s10', [['mint_url' => $mintUrl, 'unit' => 'sat']]);
Config::clearCache();
check(Config::get('mint_history_s1') !== null && Config::get('abandoned_keysets_s1') !== null, 'entries exist before');
Config::deleteStore('s1');
Config::clearCache();
check(Config::getStore('s1') === null, 'the store is gone');
foreach (['mint_history_s1', 'recovery_kit_s1', 'checkout_failures_s1', 'abandoned_keysets_s1'] as $key) {
    check(Config::get($key) === null, "$key removed");
}
check(Config::get('mint_history_s10') !== null, 'another store\'s entries are kept');

echo "followups_2026_11_audit: OK\n";
