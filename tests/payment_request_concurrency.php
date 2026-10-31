<?php
/**
 * One token posted at the same moment to two open payment requests pays exactly one of
 * them (S1, 2026-10-28). Before the per-store receive lock, both posts passed the
 * "does the wallet already hold these secrets?" check before either had imported the
 * proofs, and both requests ended up paid while the store was credited once.
 *
 * Real concurrency: the real receive.php under a multi-worker PHP built-in server,
 * against the mock mint.
 *
 * Run: php tests/payment_request_concurrency.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-prconc-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/payment_request.php';

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

function startServer(array $cmd, array $env): int {
    global $procs, $root;
    $port = random_int(19100, 19999);
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    return $port;
}

$php = ['php', '-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0', '-S'];
$mintPort = startServer(array_merge($php, ['127.0.0.1:{port}', "$root/tests/mock_mint_router.php"]),
    ['MOCK_MINT_STATE' => "$dataDir/mint.json", 'PHP_CLI_SERVER_WORKERS' => '4']);
$mintUrl = "http://127.0.0.1:$mintPort";

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true);
Database::insert('stores', ['id' => 'store_1', 'name' => 'Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp()]);
Invoice::initializeWalletForStore('store_1', false);

$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . '); chdir('
    . var_export($root, true) . '); require ' . var_export("$root/receive.php", true) . ';');
$port = startServer(array_merge($php, ['127.0.0.1:{port}', $router]), ['PHP_CLI_SERVER_WORKERS' => '4']);
Config::set('base_url', "http://127.0.0.1:$port");
$base = "http://127.0.0.1:$port/receive.php";

$payer = new \Cashu\Wallet($mintUrl, 'sat', "$dataDir/payer.sqlite");
$payer->loadMint();
$payer->initializeNewFromMnemonic(\Cashu\Mnemonic::generate());
$post = fn(string $path, array $body) => json_decode((string)@file_get_contents("$mintUrl$path", false,
    stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => json_encode($body)]])), true);
$quote = $post('/v1/mint/quote/bolt11', ['amount' => 256, 'unit' => 'sat']);
$post('/__control/pay', ['quote' => $quote['quote']]);
$payer->mint($quote['quote'], 256);

$walletId = Invoice::getWalletForStore('store_1', $mintUrl, 'sat')->getStorage()->getWalletId();
$balance = fn() => (int)Database::fetchOne(
    "SELECT COALESCE(SUM(amount), 0) AS s FROM cashu_proofs WHERE wallet_id = ? AND state = 'UNSPENT'", [$walletId])['s'];

echo "One token posted concurrently to two payment requests (S1)\n";
for ($trial = 1; $trial <= 3; $trial++) {
    $token = $payer->split($payer->getStoredProofs(), 21)['send'];
    $payer->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $token), \Cashu\ProofState::SPENT);
    $a = PaymentRequest::create('store_1', 21, 'sat', $mintUrl, 'A');
    $b = PaymentRequest::create('store_1', 21, 'sat', $mintUrl, 'B');
    $before = $balance();

    $multi = curl_multi_init();
    $handles = [];
    foreach ([$a, $b] as $request) {
        $h = curl_init("$base?request_id={$request['id']}");
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['id' => $request['id'], 'mint' => $mintUrl, 'unit' => 'sat',
                'proofs' => array_map(fn($p) => $p->toArray(true), $token)])]);
        curl_multi_add_handle($multi, $h);
        $handles[] = $h;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
    } while ($running);
    $codes = array_map(fn($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $handles);
    sort($codes);

    PaymentRequest::reconcileReceiving();
    $paid = count(array_filter([$a, $b], fn($r) => PaymentRequest::getById($r['id'])['status'] === 'paid'));
    check($paid === 1, "trial $trial: exactly one request is paid");
    check($codes === [200, 400], "trial $trial: one post succeeds and the other is told the token was already received");
    check($balance() - $before === 20, "trial $trial: the store is credited once (21 sat less the 1 sat fee)");
}

echo "All payment request concurrency checks passed.\n";
