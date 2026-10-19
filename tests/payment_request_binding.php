<?php
/**
 * NUT-18 payment requests are bound to the token that pays them (OPUS-004), accept the
 * spec's PaymentRequestPayload body, take the mint fee rule into account (OPUS-013), and
 * publish a creqB alongside creqA (NUT-26).
 *
 * Exercises the real receive.php under PHP's built-in server against the mock mint.
 *
 * Run: php tests/payment_request_binding.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-prbind-' . bin2hex(random_bytes(8));
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
    $port = random_int(18100, 18999);
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $root, $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return $port;
}

/** @return array{0:int,1:string} */
function http(string $method, string $url, ?string $body = null): array {
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Content-Type: application/json\r\n", 'content' => $body ?? '',
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
    . ' require ' . var_export("$root/receive.php", true) . ';');
$port = startServer(['php', '-S', '127.0.0.1:{port}', $router], []);
Config::set('base_url', "http://127.0.0.1:$port");
$base = "http://127.0.0.1:$port/receive.php";

// A customer's wallet with its own ecash at the mint.
$payer = new \Cashu\Wallet($mintUrl, 'sat', "$dataDir/payer.sqlite");
$payer->loadMint();
$payer->initializeNewFromMnemonic(\Cashu\Mnemonic::generate());
$quote = $payer->requestMintQuote(512);
@file_get_contents("$mintUrl/__control/pay", false, stream_context_create(['http' => [
    'method' => 'POST', 'header' => 'Content-Type: application/json',
    'content' => json_encode(['quote' => $quote->quote]),
]]));
$payer->mint($quote->quote, 512);

/** Proofs worth $amount, as a NUT-18 PaymentRequestPayload for request $id. */
function payload(\Cashu\Wallet $payer, string $id, int $amount, string $mintUrl): array {
    $split = $payer->split($payer->getStoredProofs(), $amount);
    // Handed over: the payer must not spend these again.
    $payer->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $split['send']), \Cashu\ProofState::SPENT);
    return [
        'id' => $id,
        'mint' => $mintUrl,
        'unit' => 'sat',
        'proofs' => array_map(fn($p) => $p->toArray(true), $split['send']),
    ];
}

function newRequest(string $base, int $amount): array {
    [, $body] = http('GET', "$base?store_id=store_1&amount=$amount&format=json");
    return json_decode($body, true);
}

echo "A request is published in both encodings\n";
$req = newRequest($base, 21);
check(str_starts_with($req['request'], 'creqA'), 'creqA for every wallet');
check(str_starts_with($req['requestB'] ?? '', 'CREQB1'), 'and creqB (NUT-26) for newer ones');
$fromB = \Cashu\PaymentRequest::parse($req['requestB']);
check($fromB->id === $req['id'] && $fromB->amount === 21 && $fromB->transport->type === 'post',
    'the creqB carries the same id, amount and HTTP transport');

[$code, $html] = http('GET', "$base?store_id=store_1&amount=21");
check($code === 200 && str_contains($html, 'CREQB1') && str_contains($html, 'pollStatus'),
    'the request page carries both codes and watches for the payment');
if (trim((string)shell_exec('command -v node')) !== '') {
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
    file_put_contents("$dataDir/page.js", implode("\n;\n", $m[1]));
    exec('node --check ' . escapeshellarg("$dataDir/page.js") . ' 2>&1', $out, $rc);
    check($rc === 0, 'and its script parses');
}

echo "A spec-compliant wallet pays with a PaymentRequestPayload\n";
$pay = payload($payer, $req['id'], 21, $mintUrl);
[$code, $body] = http('POST', "$base?request_id={$req['id']}", json_encode($pay));
$res = json_decode($body, true);
check($code === 200 && ($res['success'] ?? false) === true, "the payload is accepted (HTTP $code: " . substr($body, 0, 160) . ')');
check(PaymentRequest::getById($req['id'])['status'] === 'paid', 'the request is marked paid');
[$code, $body] = http('GET', "$base?status=1&request_id={$req['id']}");
check(json_decode($body, true)['status'] === 'paid', 'and the request page can see it');

echo "A retry of the same payment replays the answer instead of paying twice\n";
[$code2, $body2] = http('POST', "$base?request_id={$req['id']}", json_encode($pay));
check($code2 === 200 && json_decode($body2, true) == $res, 'the stored result is replayed');

echo "A second token for a paid request is not taken\n";
$other = payload($payer, $req['id'], 21, $mintUrl);
[$code3] = http('POST', "$base?request_id={$req['id']}", json_encode($other));
$states = (new \Cashu\Wallet($mintUrl, 'sat'))->loadMint();
$check = new \Cashu\Wallet($mintUrl, 'sat');
$check->loadMint();
$otherProofs = array_map(fn($p) => \Cashu\Proof::fromArray($p), $other['proofs']);
check(strtoupper($check->checkProofState($otherProofs)[0]['state']) === 'UNSPENT',
    "the second token stays the payer's (HTTP $code3)");

echo "While one token is paying a request, another is refused\n";
$busy = newRequest($base, 21);
Database::update('payment_requests',
    ['status' => 'receiving', 'input_secrets' => json_encode(['someone-elses-secret']), 'receiving_since' => time()],
    'id = ?', [$busy['id']]);
[$code] = http('POST', "$base?request_id={$busy['id']}", json_encode(payload($payer, $busy['id'], 21, $mintUrl)));
check($code === 409, "a different token gets 409 (HTTP $code)");

echo "A retry after an attempt that never reached the mint is paid normally\n";
$retry = newRequest($base, 21);
$retryPay = payload($payer, $retry['id'], 21, $mintUrl);
$secrets = array_map(fn($p) => $p['secret'], $retryPay['proofs']);
sort($secrets, SORT_STRING);
Database::update('payment_requests',
    ['status' => 'receiving', 'input_secrets' => json_encode($secrets), 'receiving_since' => time() - 600],
    'id = ?', [$retry['id']]);
[$code, $body] = http('POST', "$base?request_id={$retry['id']}", json_encode($retryPay));
check($code === 200 && (json_decode($body, true)['success'] ?? false), "the same token is accepted (HTTP $code: " . substr($body, 0, 120) . ')');
check(PaymentRequest::getById($retry['id'])['status'] === 'paid', 'and the request is paid');

echo "A swap that finished after the sender was answered is recognized in the background\n";
$late = newRequest($base, 21);
$firstSecrets = array_map(fn($p) => $p['secret'], $pay['proofs']);
sort($firstSecrets, SORT_STRING);
// The first payment's inputs are SPENT in the store's wallet: as if this request's
// answer had been lost after its swap went through.
Database::update('payment_requests',
    ['status' => 'receiving', 'input_secrets' => json_encode($firstSecrets), 'receiving_since' => time() - 600],
    'id = ?', [$late['id']]);
$result = PaymentRequest::reconcileReceiving();
$row = PaymentRequest::getById($late['id']);
check($row['status'] === 'paid' && (int)$row['received_amount'] > 0, 'the background task marks it paid (' . json_encode($result) . ')');

echo "A claim whose token never arrived is released for payment again\n";
$lost = newRequest($base, 21);
Database::update('payment_requests',
    ['status' => 'receiving', 'input_secrets' => json_encode(['never-sent']), 'receiving_since' => time() - 600],
    'id = ?', [$lost['id']]);
PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($lost['id'])['status'] === 'pending', 'it is payable again');

echo "Underpayment is still refused, and frees the request\n";
$under = newRequest($base, 50);
[$code] = http('POST', "$base?request_id={$under['id']}", json_encode(payload($payer, $under['id'], 20, $mintUrl)));
check($code === 400, "a 20 sat token for a 50 sat request is refused (HTTP $code)");
check(PaymentRequest::getById($under['id'])['status'] === 'pending', 'and the request stays payable');

echo "payment_request_binding: OK\n";
