<?php
/**
 * The public NUT-18 endpoint, exercised through the real receive.php under PHP's
 * built-in server (not just the PaymentRequest class): an import in receive.php once
 * shadowed the global PaymentRequest with the library DTO, so every request fataled
 * while the unit tests of the class kept passing.
 *
 * Run: php tests/receive_endpoint.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-receive-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
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

function startServer(array $cmd, array $env, string $probe): int {
    global $procs, $root;
    $port = random_int(18100, 18999);
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $root, $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return $port;
}

/** @return array{0:int,1:string} */
function http(string $method, string $url, ?string $body = null, string $type = 'application/json'): array {
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: $type\r\n", 'content' => $body ?? '',
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
    }
    return [$code, (string)$resp];
}

$mintPort = startServer(['php', '-S', '127.0.0.1:{port}', "$root/tests/mock_mint_router.php"],
    ['MOCK_MINT_STATE' => "$dataDir/mint.json"], '/v1/info');
$mintUrl = "http://127.0.0.1:$mintPort";

Database::initialize();
Config::set('setup_complete', true);
// The mock mint listens on 127.0.0.1; the connect guard refuses that unless allowed.
Config::set('allow_private_mints', true);
Database::insert('stores', [
    'id' => 'store_1', 'name' => 'Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp(),
]);

// A router that points the app at the throwaway data dir, then runs the real file.
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' ini_set("display_errors", "1");'
    . ' chdir(' . var_export($root, true) . ');'
    . ' require ' . var_export("$root/receive.php", true) . ';');
$port = startServer(['php', '-S', '127.0.0.1:{port}', $router], [], '/');
Config::set('base_url', "http://127.0.0.1:$port");
$base = "http://127.0.0.1:$port/receive.php";

echo "Generating a request goes through the application's PaymentRequest\n";
[$code, $body] = http('GET', "$base?store_id=store_1&amount=21&format=json");
$json = json_decode($body, true);
check($code === 200 && is_array($json) && isset($json['id'], $json['request']),
    "GET creates a request (HTTP $code: " . substr($body, 0, 160) . ')');
check(PaymentRequest::getById($json['id']) !== null, 'and the request is stored');
check(!str_contains($body, 'Fatal error') && !str_contains($body, 'Stack trace'), 'no PHP error page');

echo "Resolving a request on POST\n";
[$code, $body] = http('POST', "$base?request_id=" . str_repeat('0', 32), json_encode(['token' => 'cashuAxx']));
check($code === 404 && str_contains($body, 'Unknown payment request'), "an unknown id is a 404 (HTTP $code)");
[$code, $body] = http('POST', "$base?request_id=" . $json['id'], json_encode(['token' => 'cashuAnot-a-token']));
check($code === 400 && is_array(json_decode($body, true)), "a known id with a bad token is a JSON 400 (HTTP $code)");

echo "Malformed input is a 400, never a fatal\n";
foreach (['"x"', '123', 'true'] as $scalar) {
    [$code, $body] = http('POST', $base, $scalar);
    check($code === 400 && str_contains($body, 'Invalid request body'), "a JSON scalar body $scalar is a 400 (HTTP $code)");
}
[$code, $body] = http('POST', $base, json_encode(['id' => ['a'], 'token' => 'x']));
check($code === 400 && !str_contains($body, 'Fatal'), "an array-shaped id is a 400 (HTTP $code)");
[$code, $body] = http('POST', $base, 'store_id[]=x&token=y', 'application/x-www-form-urlencoded');
check($code === 400 && !str_contains($body, 'Fatal'), "an array-shaped form field is a 400 (HTTP $code)");
[$code, $body] = http('GET', "$base?store_id[]=x");
check($code === 400 && !str_contains($body, 'Fatal'), "an array-shaped store_id on GET is a 400 (HTTP $code)");
[$code, $body] = http('GET', "$base?store_id=store_1&amount[]=1&format=json");
check($code === 400 && !str_contains($body, 'Fatal'), "an array-shaped amount is a 400 (HTTP $code)");

echo "The amount field matches what the parser accepts\n";
[$code, $body] = http('GET', "$base?store_id=store_1");
check($code === 200 && str_contains($body, 'step="1"'), 'a sat store asks for whole numbers');
Database::update('stores', ['mint_unit' => 'usdt'], 'id = ?', ['store_1']);
[$code, $body] = http('GET', "$base?store_id=store_1");
check(str_contains($body, 'step="0.01"'),
    'usdt is priced in cents everywhere, so the form asks for two decimals');
Database::update('stores', ['mint_unit' => 'btc'], 'id = ?', ['store_1']);
[$code, $body] = http('GET', "$base?store_id=store_1");
check(str_contains($body, 'step="0.00000001"'), 'a btc store asks for satoshi precision');
Database::update('stores', ['mint_unit' => 'eur'], 'id = ?', ['store_1']);
[$code, $body] = http('GET', "$base?store_id=store_1");
check(str_contains($body, 'step="0.01"'), 'a cent-based unit asks for two decimals');
Database::update('stores', ['mint_unit' => 'sat'], 'id = ?', ['store_1']);

echo "Request generation is rate-limited\n";
$limited = false;
for ($i = 0; $i < 40 && !$limited; $i++) {
    [$code] = http('GET', "$base?store_id=store_1&amount=1&format=json");
    $limited = $code === 429;
}
check($limited, 'an anonymous GET loop is eventually answered with 429');

echo "All receive endpoint checks passed.\n";
