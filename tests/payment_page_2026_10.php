<?php
/**
 * The checkout page never tells a customer who already paid to pay again (2026-10-28
 * audit, C1-C5).
 *
 *  - An invoice that expired, or that the shop cancelled, is still checked against the
 *    mint by the page's status poll, and turns to Settled when the late payment arrives.
 *  - The expired and cancelled messages say not to pay again, and every state shows a
 *    reference (and the shop's order number) the customer can quote.
 *  - The first render does not wait for the mint.
 *
 * The real payment.php under PHP's built-in server, against the mock mint.
 *
 * Run: php tests/payment_page_2026_10.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-paypage-' . bin2hex(random_bytes(8));
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

echo "Every state shows a reference the customer can quote (C3)\n";
$inv = Invoice::create('s1', ['amount' => 21, 'currency' => 'sat', 'metadata' => ['orderId' => 'WC-1043']]);
[$html] = $get("$page?id={$inv['id']}");
check(str_contains($html, $inv['id']) && str_contains($html, 'Order WC-1043'), 'the reference and the order number are shown');

echo "A payment that lands after expiry is still found by the page (C1)\n";
Database::update('invoices', ['status' => 'Expired', 'expiration_time' => time() - 60], 'id = ?', [$inv['id']]);
[$html] = $get("$page?id={$inv['id']}");
check(str_contains($html, 'If you already paid, do not pay again'), 'the expired page tells a payer not to pay again');
check(!str_contains($html, 'Please request a new one'), 'and no longer just says to request a new invoice');
$pay($inv['quote_id']);
[$json] = $get("$page?id={$inv['id']}&json=1");
check((json_decode($json, true)['status'] ?? null) === 'Settled', "the page's status check settles it ($json)");
check(Invoice::getBalance('s1') === 21, 'and the payment reaches the balance');

echo "A paid order the shop cancelled is collected too (C2)\n";
$inv2 = Invoice::create('s1', ['amount' => 13, 'currency' => 'sat']);
Database::update('invoices', ['status' => 'Invalid'], 'id = ?', [$inv2['id']]);
[$html] = $get("$page?id={$inv2['id']}");
check(str_contains($html, 'This order was cancelled. If you already paid, do not pay again'), 'the cancelled page says not to pay again');
$pay($inv2['quote_id']);
[$json] = $get("$page?id={$inv2['id']}&json=1");
check((json_decode($json, true)['status'] ?? null) === 'Settled', 'its status check settles it');

echo "The first render does not wait for the mint (C5)\n";
$inv3 = Invoice::create('s1', ['amount' => 8, 'currency' => 'sat']);
testStopProcess($mintProc);
usleep(300000);
[$html, $took] = $get("$page?id={$inv3['id']}");
check(str_contains($html, 'Waiting for payment') && $took < 3.0, sprintf('the page renders with the mint down (%.2f s)', $took));

echo "payment_page_2026_10: OK\n";
