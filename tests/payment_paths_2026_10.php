<?php
/**
 * Public payment paths, 2026-10 audits:
 * - C3/M-1: a token the shop already received (a donation, or another request's
 *   payment) never marks a NUT-18 payment request paid, on a retry, in the error path
 *   or in the background reconcile.
 * - C1: receive.php refuses tokens with more than 100 proofs or a repeated proof
 *   before any mint or EC work.
 * - N8/N-2: receive.php and api.php refuse oversized bodies with 413 before decoding.
 * - Obs 4: the "sender did not add the fee" leniency is bounded by the fee of the
 *   minimal split of the requested amount.
 * - Hardening: a non-library exception's text never reaches the payer.
 *
 * Exercises the real receive.php and api.php under PHP's built-in server against the
 * mock mint (fee 100 ppk).
 *
 * Run: php tests/payment_paths_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-paypaths-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';
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
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $root, $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return $port;
}

/** @return array{0:int,1:string} */
function http(string $method, string $url, ?string $body = null, string $headers = ''): array {
    // receive.php allows 20 POSTs a minute per IP; this test sends more than that.
    try { Database::query('DELETE FROM rate_limits'); } catch (Throwable $e) {}
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Content-Type: application/json\r\n" . $headers, 'content' => $body ?? '',
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
    }
    return [$code, (string)$resp];
}

$mintPort = startServer(['php', '-S', '127.0.0.1:{port}', "$root/tests/mock_mint_router.php"],
    ['MOCK_MINT_STATE' => "$dataDir/mint.json"]);
$mintUrl = "http://127.0.0.1:$mintPort";

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true); // the mock mint listens on 127.0.0.1
Database::insert('stores', [
    'id' => 'store_1', 'name' => 'Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore('store_1', false);

$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' if (str_starts_with((string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/api/")) {'
    . '   $_SERVER["PATH_INFO"] = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . '   require ' . var_export("$root/api.php", true) . '; return; }'
    . ' require ' . var_export("$root/receive.php", true) . ';');
$port = startServer(['php', '-S', '127.0.0.1:{port}', $router], []);
Config::set('base_url', "http://127.0.0.1:$port");
$base = "http://127.0.0.1:$port/receive.php";

$payer = new \Cashu\Wallet($mintUrl, 'sat', "$dataDir/payer.sqlite");
$payer->loadMint();
$payer->initializeNewFromMnemonic(\Cashu\Mnemonic::generate());
// The quote is asked for directly: the mock mint's invoices are placeholders, not BOLT11.
$post = fn(string $path, array $body) => json_decode((string)@file_get_contents("$mintUrl$path", false,
    stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => json_encode($body)]])), true);
$quote = $post('/v1/mint/quote/bolt11', ['amount' => 1024, 'unit' => 'sat']);
$post('/__control/pay', ['quote' => $quote['quote']]);
$payer->mint($quote['quote'], 1024);

/** Hand over proofs: the payer must not spend them again. @param \Cashu\Proof[] $proofs */
function handOver(\Cashu\Wallet $payer, array $proofs): void {
    $payer->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $proofs), \Cashu\ProofState::SPENT);
}

/** @param \Cashu\Proof[] $proofs */
function asPayload(array $proofs, string $mintUrl, ?string $id = null): array {
    return array_filter([
        'id' => $id,
        'mint' => $mintUrl,
        'unit' => 'sat',
        'proofs' => array_map(fn($p) => $p->toArray(true), $proofs),
    ], fn($v) => $v !== null);
}

/** @return \Cashu\Proof[] worth $amount */
function proofsWorth(\Cashu\Wallet $payer, int $amount): array {
    $split = $payer->split($payer->getStoredProofs(), $amount);
    handOver($payer, $split['send']);
    return $split['send'];
}

function newRequest(string $base, int $amount): array {
    [, $body] = http('GET', "$base?store_id=store_1&amount=$amount&format=json");
    return json_decode($body, true);
}

function status(string $base, string $id): string {
    [, $body] = http('GET', "$base?status=1&request_id=$id");
    return (string)(json_decode($body, true)['status'] ?? '');
}

echo "C3: a donated token cannot pay a payment request\n";
$donated = proofsWorth($payer, 30);
[$code, $body] = http('POST', "$base?store_id=store_1", json_encode(asPayload($donated, $mintUrl)));
check($code === 200, "the donation itself is received (HTTP $code: " . substr($body, 0, 120) . ')');
$x = newRequest($base, 21);
[$code1, $body1] = http('POST', "$base?request_id={$x['id']}", json_encode(asPayload($donated, $mintUrl, $x['id'])));
check($code1 >= 400 && $code1 < 500, "the first replay is refused (HTTP $code1: " . substr($body1, 0, 140) . ')');
check(PaymentRequest::getById($x['id'])['status'] === 'pending', 'and the request is free again');
[$code2] = http('POST', "$base?request_id={$x['id']}", json_encode(asPayload($donated, $mintUrl, $x['id'])));
check($code2 >= 400 && $code2 < 500 && status($base, $x['id']) === 'pending', "a second identical replay is refused too (HTTP $code2)");
$r = PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($x['id'])['status'] === 'pending', 'the background task does not mark it paid (' . json_encode($r) . ')');

echo "C3: another request's payment cannot pay a second request\n";
$y = newRequest($base, 21);
$paid = proofsWorth($payer, 21);
[$code] = http('POST', "$base?request_id={$y['id']}", json_encode(asPayload($paid, $mintUrl, $y['id'])));
check($code === 200 && status($base, $y['id']) === 'paid', "the genuine payment pays its request (HTTP $code)");
$z = newRequest($base, 21);
[$code, $body] = http('POST', "$base?request_id={$z['id']}", json_encode(asPayload($paid, $mintUrl, $z['id'])));
check($code >= 400 && $code < 500, "the replay for another request is refused (HTTP $code: " . substr($body, 0, 140) . ')');
check(status($base, $z['id']) === 'pending', 'and that request is not paid');

echo "C3: a claim bound to old secrets is released, never paid, by retry and reconcile\n";
$secrets = array_map(fn($p) => $p->secret, $donated);
sort($secrets, SORT_STRING);
// The donation's rows predate the claim (in real life by more than the same second).
$walletId = Invoice::getWalletForStore('store_1', $mintUrl, 'sat')->getStorage()->getWalletId();
$in = implode(',', array_fill(0, count($secrets), '?'));
Database::query("UPDATE cashu_proofs SET created_at = created_at - 100 WHERE wallet_id = ? AND secret IN ($in)",
    array_merge([$walletId], $secrets));
$w = newRequest($base, 21);
$bind = fn() => Database::update('payment_requests',
    ['status' => 'receiving', 'input_secrets' => json_encode($secrets), 'receiving_since' => time()],
    'id = ?', [$w['id']]);
$bind();
$r = PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($w['id'])['status'] === 'pending', 'reconcileReceiving releases it (' . json_encode($r) . ')');
$bind();
[$code, $body] = http('POST', "$base?request_id={$w['id']}", json_encode(asPayload($donated, $mintUrl, $w['id'])));
check($code >= 400 && $code < 500, "a retry of the bound token is refused (HTTP $code: " . substr($body, 0, 140) . ')');
check(PaymentRequest::getById($w['id'])['status'] === 'pending', 'and the catch path frees the request instead of keeping it bound');

echo "C3: a genuine swap whose answer was lost is still finished in the background\n";
$late = newRequest($base, 21);
$paidSecrets = array_map(fn($p) => $p->secret, $paid);
sort($paidSecrets, SORT_STRING);
Database::update('payment_requests',
    ['status' => 'receiving', 'input_secrets' => json_encode($paidSecrets), 'receiving_since' => time() - 600],
    'id = ?', [$late['id']]);
PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($late['id'])['status'] === 'paid', 'rows created after the claim still count as paid');

echo "C1: too many proofs, or the same proof twice, are refused before any mint work\n";
$one = proofsWorth($payer, 2)[0];
$many = [];
for ($i = 0; $i < 101; $i++) {
    $many[] = new \Cashu\Proof($one->id, $one->amount, bin2hex(random_bytes(4)), $one->C);
}
$token = \Cashu\TokenSerializer::serializeV4($mintUrl, $many, 'sat');
[$code, $body] = http('POST', "$base?store_id=store_1", json_encode(['token' => $token]));
check($code === 400 && str_contains($body, 'too many proofs'), "101 proofs: HTTP $code " . substr($body, 0, 120));
$token = \Cashu\TokenSerializer::serializeV4($mintUrl, [$one, $one], 'sat');
[$code, $body] = http('POST', "$base?store_id=store_1", json_encode(['token' => $token]));
check($code === 400 && str_contains($body, 'more than once'), "a repeated proof: HTTP $code " . substr($body, 0, 120));

echo "N8: oversized bodies are refused before decoding\n";
$big = json_encode(['store_id' => 'store_1', 'token' => str_repeat('a', \Cashu\TokenSerializer::MAX_TOKEN_LENGTH + 20000)]);
[$code] = http('POST', "$base?store_id=store_1", $big);
check($code === 413, "receive.php: HTTP $code");
$key = Auth::createApiKey('store_1', 'test');
[$code, $body] = http('POST', "http://127.0.0.1:$port/api/v1/stores/store_1/invoices",
    json_encode(['amount' => '1', 'currency' => 'SAT', 'metadata' => ['pad' => str_repeat('a', 70000)]]),
    "Authorization: token {$key['key']}\r\n");
check($code === 413, "api.php: HTTP $code " . substr($body, 0, 120));
[$code, $body] = http('GET', "http://127.0.0.1:$port/api/v1/stores/store_1/invoices", null,
    "Authorization: token {$key['key']}\r\n");
check($code === 200, "a normal API call still works (HTTP $code)");

echo "Obs 4: the fee leniency is bounded by the minimal split\n";
// 64 one-sat proofs: gross 64, mint fee 7 (100 ppk each), net 57.
$src = proofsWorth($payer, 72);
$payer->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $src), \Cashu\ProofState::UNSPENT);
$out = $payer->swap($src, array_merge(array_fill(0, 64, 1), [4, 2, 1]));
$ones = array_slice(array_values(array_filter($out, fn($p) => $p->amount === 1)), 0, 64);
handOver($payer, $out);
$fee64 = newRequest($base, 64);
[$code, $body] = http('POST', "$base?request_id={$fee64['id']}", json_encode(asPayload($ones, $mintUrl, $fee64['id'])));
check($code === 400 && str_contains($body, "mint's fee"), "64 one-sat proofs for 64 sat are refused (HTTP $code: " . substr($body, 0, 140) . ')');
check(status($base, $fee64['id']) === 'pending', 'and the request stays payable');
$single = proofsWorth($payer, 64);
check(count($single) === 1, 'a single 64-sat proof (fee 1, the minimal split)');
[$code, $body] = http('POST', "$base?request_id={$fee64['id']}", json_encode(asPayload($single, $mintUrl, $fee64['id'])));
check($code === 200 && status($base, $fee64['id']) === 'paid', "an old wallet sending exactly the amount is still accepted (HTTP $code: " . substr($body, 0, 140) . ')');

echo "Hardening: internal errors are not shown to the payer\n";
$fresh = proofsWorth($payer, 8);
// A database error makes the wallet layer fail with a PDOException; its text (SQL,
// table names, paths) must stay in the log.
Database::query("CREATE TRIGGER fail_insert BEFORE INSERT ON cashu_proofs BEGIN SELECT RAISE(ABORT, 'internal detail /var/lib/x'); END");
[$code, $body] = http('POST', "$base?store_id=store_1", json_encode(asPayload($fresh, $mintUrl)));
$msg = (string)(json_decode($body, true)['error'] ?? '');
check($code === 500 && $msg !== '' && !str_contains($msg, 'SQLSTATE') && !str_contains($msg, 'internal detail'),
    "the payer gets a plain message (HTTP $code: $msg)");

echo "payment_paths_2026_10: OK\n";
