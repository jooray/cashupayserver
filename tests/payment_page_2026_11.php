<?php
/**
 * Checkout page fixes from the 2026-11 ARGON audit (UX-06.7):
 *  - The amount in the mint's unit is shown in the unit the invoice was created in, not
 *    the store's current one (the store may have switched mints since).
 *  - When the countdown runs out, "Open in Wallet" and "Copy Invoice" are hidden, not
 *    left clickable next to the dimmed QR code.
 *
 * The real payment.php under PHP's built-in server, against the mock mint.
 *
 * Run: php tests/payment_page_2026_11.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-paypage2611-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';

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

function startServer(array $cmd, array $env) {
    global $procs, $root;
    $port = testFreePort();
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
    $procs[] = $proc;
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    return [$port, $proc];
}

[$mintPort, $mintProc] = startServer(['php', '-S', '127.0.0.1:{port}', "$root/tests/mock_mint_router.php"],
    ['MOCK_MINT_STATE' => "$dataDir/mint.json"]);
$mintUrl = "http://127.0.0.1:$mintPort";

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true);
Database::insert('stores', ['id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp()]);
Invoice::initializeWalletForStore('s1', false);

$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . '); chdir('
    . var_export($root, true) . '); require ' . var_export("$root/payment.php", true) . ';');
[$port] = startServer(['php', '-S', '127.0.0.1:{port}', $router], []);
Config::set('base_url', "http://127.0.0.1:$port");
$page = "http://127.0.0.1:$port/payment.php";

$get = function (string $url): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 60]]);
    $start = microtime(true);
    $body = (string)@file_get_contents($url, false, $ctx);
    return [$body, microtime(true) - $start];
};
$pay = fn(string $quote) => @file_get_contents("$mintUrl/__control/pay", false, stream_context_create(['http' => [
    'method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['quote' => $quote])]]));

echo "The amount uses the invoice's own unit\n";
$inv = Invoice::create('s1', ['amount' => 21, 'currency' => 'sat']);
// An invoice from when this store used a dollar mint: 150 cents, store now on sats.
Database::update('invoices', ['currency' => 'EUR', 'amount' => '1.40', 'amount_sats' => 150, 'mint_unit' => 'usd'],
    'id = ?', [$inv['id']]);
[$html] = $get("$page?id={$inv['id']}");
check(str_contains($html, '1.40 EUR (1.50 USD)'), 'shown as 1.50 USD, the unit it was created in');
check(!str_contains($html, '150 sats'), 'not as 150 sats in the store\'s current unit');

echo "After expiry the pay buttons go away\n";
check(str_contains($html, 'id="open-wallet"') && str_contains($html, 'id="copy-invoice"'), 'the buttons carry ids');
preg_match('#if \(remaining <= 0\) \{(.*?)return;#s', $html, $m);
$expiredBranch = $m[1] ?? '';
check(str_contains($expiredBranch, "'open-wallet', 'copy-invoice', 'invoice-text'")
    && str_contains($expiredBranch, "style.display = 'none'") && str_contains($expiredBranch, 'invoiceExpired = true'),
    'the countdown hides "Open in Wallet", "Copy Invoice" and the invoice text when it runs out');
check(str_contains($html, 'if (invoiceExpired) return;'), 'and copying an expired invoice does nothing');

echo "payment_page_2026_11: OK\n";
