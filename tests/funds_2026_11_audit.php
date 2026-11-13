<?php
/**
 * Funds safety fixes from the 2026-11 audit verification.
 *
 *  - UX-F3/F4: the stranded scanner counts UNKNOWN/PENDING ecash and journal-only
 *    namespaces as non-exportable entries with a plain note; an export never puts an
 *    input an unfinished operation lists into a token.
 *  - UX-F2: writing off a stranded token whose mint cannot be asked needs the second
 *    confirmation (an unclaimed token is covered by tests/integration_keyset_v2.php).
 *  - Fiat price snapshot: Invoice::create's trusted '_priced' amount is the invoice
 *    amount even when the rate has moved since it was guarded.
 *  - UX-F7 / UX-F6 / UX-F2 through the real admin.php: delete_store and a mint change
 *    wait for the funds lock (409), the recovery sheet page is marked kept only by its
 *    button, mark_stranded_recovered answers mint_unreachable until confirmed.
 *
 * Against the mock mint; the "unreachable" mint is a closed loopback port.
 *
 * Run: php tests/funds_2026_11_audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-funds-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/locks.php';
require_once $root . '/includes/recovery_kit.php';

use Cashu\WalletStorage;

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

function proof(string $walletId, int $amount, string $state, ?string $secret = null): string {
    static $n = 0;
    $n++;
    $secret ??= "fsecret$n";
    Database::query(
        "INSERT INTO cashu_proofs (wallet_id, keyset_id, amount, secret, C, state, created_at)
         VALUES (?, '00ab', ?, ?, '02c', ?, ?)",
        [$walletId, $amount, $secret, $state, time()]
    );
    return $secret;
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
$deadMint = 'http://127.0.0.1:9';

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
Invoice::initializeWalletForStore('s1', false);
// An earlier mint of this store, as an invoice remembers it.
Database::insert('invoices', ['id' => 'inv_old', 'store_id' => 's1', 'amount' => '1', 'currency' => 'SAT',
    'amount_sats' => 1, 'status' => 'Settled', 'mint_url' => $deadMint, 'mint_unit' => 'sat',
    'expiration_time' => time(), 'created_at' => Database::timestamp()]);
$oldWallet = WalletStorage::deriveWalletId($deadMint, 'sat', $acct);

echo "Stranded scan: unfinished value is listed, never exported (UX-F3, UX-F4)\n";
$find = function () use ($oldWallet): ?array {
    foreach (Invoice::scanStrandedNamespaces() as $s) {
        if ($s['wallet_id'] === $oldWallet) { return $s; }
    }
    return null;
};
Database::query(
    "INSERT INTO cashu_pending_operations (id, wallet_id, type, data, created_at) VALUES ('op1', ?, 'mint', '{}', ?)",
    [$oldWallet, time()]
);
$s = $find();
check($s !== null && $s['amount'] === 0 && $s['journals'] === 1 && $s['exportable'] === false,
    'a namespace with only an unfinished operation is found, not exportable');
check(str_contains((string)$s['note'], 'unfinished payment at 127.0.0.1: add this mint as a backup mint'),
    'with the plain way to finish it: ' . $s['note']);
Database::query('DELETE FROM cashu_pending_operations');
proof($oldWallet, 30, 'UNKNOWN');
proof($oldWallet, 12, 'PENDING');
$s = $find();
check($s !== null && $s['unsettled_amount'] === 42 && $s['exportable'] === false && str_contains((string)$s['note'], '42 sat'),
    'UNKNOWN and PENDING ecash is listed with its amount, not exportable');
check(count(array_filter(StoreSafety::strandedForStore('s1'), fn($x) => $x['wallet_id'] === $oldWallet && $x['amount'] === 42)) === 1,
    'and blocks deleting the store whose account unlocks it');

$free = proof($oldWallet, 8, 'UNSPENT');
$reserved = proof($oldWallet, 16, 'UNSPENT');
Database::query(
    "INSERT INTO cashu_pending_operations (id, wallet_id, type, data, created_at) VALUES ('op2', ?, 'swap', ?, ?)",
    [$oldWallet, json_encode(['input_secrets' => [$reserved]]), time()]
);
$exp = Invoice::exportNamespaceAsToken($deadMint, 'sat', $acct);
check($exp['amount'] === 8 && $exp['secrets'] === [$free], 'the export leaves out an input an unfinished operation lists');
check(Database::fetchOne('SELECT state FROM cashu_proofs WHERE secret = ?', [$reserved])['state'] === 'UNSPENT',
    'and does not touch it');
Database::query('DELETE FROM cashu_pending_operations');

echo "Write-off when the mint cannot be asked (UX-F2)\n";
$code = null;
try {
    Invoice::markNamespaceRecovered($deadMint, 'sat', $acct, $exp['secrets']);
} catch (Exception $e) {
    $code = $e->getCode();
}
check($code === Invoice::STRANDED_UNVERIFIED, 'refused without the second confirmation');
check(Database::fetchOne('SELECT state FROM cashu_proofs WHERE secret = ?', [$free])['state'] === 'EXPORTED', 'nothing written off');
check(Invoice::markNamespaceRecovered($deadMint, 'sat', $acct, $exp['secrets'], true) === 1, 'written off once confirmed');
Database::query('DELETE FROM cashu_proofs WHERE wallet_id = ?', [$oldWallet]);

echo "A pre-priced amount is the invoice amount (fiat snapshot)\n";
Config::set('rate_eur', ['rate' => 60000.0, 'timestamp' => time(), 'provider' => 'test']);
$guarded = ExchangeRates::convertToMintUnit('10', 'EUR', 'sat', 0, 'coingecko', 'binance');
check($guarded === 16667, 'guarded at 60,000 EUR/BTC: 16,667 sats');
Config::set('rate_eur', ['rate' => 50000.0, 'timestamp' => time(), 'provider' => 'test']); // the rate moves
$inv = Invoice::create('s1', ['amount' => '10', 'currency' => 'EUR',
    '_priced' => ['amount_in_mint_unit' => $guarded, 'mint_unit' => 'sat']]);
check((int)$inv['amount_sats'] === $guarded, 'the invoice asks for the guarded amount, not a re-conversion at the new rate');
$inv = Invoice::create('s1', ['amount' => '10', 'currency' => 'EUR',
    '_priced' => ['amount_in_mint_unit' => $guarded, 'mint_unit' => 'usd']]);
check((int)$inv['amount_sats'] === 20000, 'a figure priced for another unit is ignored and the amount re-priced');

echo "Through the dashboard\n";
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' return require ' . var_export("$root/router.php", true) . ';');
$port = startServer([PHP_BINARY, '-S', '127.0.0.1:{port}', $router]);
$base = "http://127.0.0.1:$port";
Config::set('base_url', $base);
$cookies = [];
$admin = function (string $method, array $post = []) use ($base, &$cookies): array {
    $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($post);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 60,
        'header' => implode("\r\n", $headers), 'content' => $content]]);
    $body = (string)@file_get_contents("$base/admin.php", false, $ctx);
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
if (trim((string)shell_exec('command -v node')) !== '') {
    preg_match_all('#<script>(.*?)</script>#s', $page, $scripts);
    $js = "$dataDir/admin-scripts.js";
    file_put_contents($js, implode("\n;\n", $scripts[1]));
    exec('node --check ' . escapeshellarg($js) . ' 2>&1', $out, $rc);
    check($rc === 0, 'the dashboard scripts are valid JavaScript' . ($rc ? ': ' . implode(' ', $out) : ''));
}

$held = cashupay_acquire_lock('funds_s1');
[$code, $r] = $admin('POST', ['action' => 'delete_store', 'store_id' => 's1', 'confirm_fund_loss' => '1', 'csrf_token' => $csrf]);
check($code === 409 && ($r['error'] ?? null) === 'store_busy' && Config::getStore('s1') !== null,
    'delete_store while a withdrawal holds the funds lock: 409, nothing deleted (UX-F7)');
[$code, $r] = $admin('POST', ['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintUrl . '/', 'mint_unit' => 'usd',
    'confirm_stranding' => '1', 'csrf_token' => $csrf]);
Config::clearCache();
check($code === 409 && str_contains((string)($r['error'] ?? ''), 'Try again in a minute')
    && Config::getStore('s1')['mint_unit'] === 'sat', 'a mint change waits too, in plain words');
cashupay_release_lock($held);

[$code, , $body] = $admin('POST', ['action' => 'recovery_kit', 'store_id' => 's1', 'password' => 'correct horse',
    'format' => 'html', 'csrf_token' => $csrf]);
Config::clearCache();
check($code === 200 && str_contains($body, 'recovery_kit_taken') && RecoveryKit::reminder('s1') === 'never',
    'the printable sheet alone is not counted as kept (UX-F6)');
preg_match('/name="fingerprint" value="([0-9a-f]{64})"/', $body, $fp);
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $tok);
[$code, , $body] = $admin('POST', ['action' => 'recovery_kit_taken', 'store_id' => 's1',
    'fingerprint' => $fp[1] ?? '', 'csrf_token' => $tok[1] ?? '']);
Config::clearCache();
check($code === 200 && str_contains($body, 'Thank you') && RecoveryKit::reminder('s1') === null, 'its button marks it kept');
[$code] = $admin('POST', ['action' => 'recovery_kit_taken', 'store_id' => 's1', 'fingerprint' => $fp[1] ?? '']);
check($code === 403, 'and needs the CSRF token');
Config::delete('recovery_kit_s1');
[$code, , $body] = $admin('POST', ['action' => 'recovery_kit', 'store_id' => 's1', 'password' => 'correct horse',
    'format' => 'text', 'csrf_token' => $csrf]);
Config::clearCache();
check($code === 200 && str_contains($body, 'Recovery words') && RecoveryKit::reminder('s1') === null,
    'a downloaded file counts as kept');

proof($oldWallet, 5, 'UNSPENT');
[$code, $r] = $admin('POST', ['action' => 'export_stranded_funds', 'mint_url' => $deadMint, 'unit' => 'sat',
    'account' => $acct, 'csrf_token' => $csrf]);
check($code === 200 && ($r['amount'] ?? null) === 5, 'a stranded token is exported from the dead mint');
$mark = ['action' => 'mark_stranded_recovered', 'mint_url' => $deadMint, 'unit' => 'sat', 'account' => $acct,
    'export_id' => $r['export_id'] ?? '', 'csrf_token' => $csrf];
[$code, $r2] = $admin('POST', $mark);
check($code === 409 && ($r2['error'] ?? null) === 'mint_unreachable' && str_contains((string)$r2['message'], 'could not be reached'),
    'marking it recovered asks again when the mint cannot confirm');
[$code, $r2] = $admin('POST', $mark + ['confirm_unverified' => '1']);
check($code === 200 && ($r2['marked'] ?? null) === 1, 'and writes it off after the second confirmation');

echo "funds_2026_11_audit: OK\n";
