<?php
/**
 * Fiat amounts in the dashboard's "Request" modal, and a store's display currency.
 *
 *  - price_preview: the server converts what the operator typed (same function, fee and
 *    rounding as invoice pricing), refuses currencies outside the whitelist, too many
 *    decimals and odd number formats, and says so when no usable rate exists.
 *  - create_invoice: priced on the server from the typed amount; the browser's figure
 *    only detects a rate that moved (409), it never becomes the amount.
 *  - display_currency: whitelisted, display only.
 *  - Greenfield compatibility: with a display currency set, the API's store and invoice
 *    responses and the webhook payloads are exactly what they were without one.
 *
 * The real admin.php and api.php under PHP's built-in server, against the mock mint.
 * Price providers are unreachable (a dead proxy), so only seeded rates exist.
 *
 * Run: php tests/fiat_request_2026_11.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-fiatreq-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';
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

// No price provider can be reached from the servers: only the rates seeded below exist.
$offline = ['https_proxy' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9',
    'http_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9',
    'no_proxy' => '127.0.0.1,localhost', 'NO_PROXY' => '127.0.0.1,localhost'];

function startServer(array $cmd, array $env): int {
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
Database::insert('stores', ['id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp()]);
Invoice::initializeWalletForStore('s1', false);
$apiKey = Auth::createApiKey('s1', 'shop', ['btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices',
    'btcpay.store.canviewstoresettings'])['key'];
Database::insert('webhooks', ['id' => 'wh1', 'store_id' => 's1', 'url' => 'https://shop.example.com/hook',
    'secret' => 'sekret', 'events' => json_encode(['InvoiceCreated']), 'enabled' => 1, 'created_at' => time()]);

$seedRates = function (int $age = 0): void {
    foreach (['eur' => 60000.0, 'usd' => 65000.0, 'jpy' => 10000000.0] as $cur => $rate) {
        Config::set("rate_$cur", ['rate' => $rate, 'timestamp' => time() - $age, 'provider' => 'test']);
    }
};
$seedRates();

$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' return require ' . var_export("$root/router.php", true) . ';');
$port = startServer([PHP_BINARY, '-S', '127.0.0.1:{port}', $router], $offline);
$base = "http://127.0.0.1:$port";
Config::set('base_url', $base);

$cookies = [];
/** @return array{0:int,1:array|null,2:string} */
$admin = function (string $method, string $query = '', array $post = []) use ($base, &$cookies): array {
    $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($post);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => implode("\r\n", $headers), 'content' => $content]]);
    $body = (string)@file_get_contents("$base/admin.php$query", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = $m[2]; }
    }
    return [$code, json_decode($body, true), $body];
};
/** @return array{0:int,1:array|null} */
$api = function (string $method, string $path, ?array $body = null) use ($base, $apiKey): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Authorization: token $apiKey\r\nContent-Type: application/json",
        'content' => $body === null ? '' : json_encode($body)]]);
    $resp = (string)@file_get_contents("$base$path", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
    }
    return [$code, json_decode($resp, true)];
};

[, , $page] = $admin('GET');
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
[$code, $login] = $admin('POST', '', ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $m[1] ?? '']);
$csrf = (string)($login['csrfToken'] ?? '');
check($code === 200 && $csrf !== '', 'admin login');

// The dashboard's script must still parse after the changes (rendered once logged in).
[, , $page] = $admin('GET');
check(str_contains($page, 'id="request-currency"') && str_contains($page, 'id="display-currency"')
    && str_contains($page, 'const DASHBOARD_FIAT = ["USD","EUR"'), 'the dashboard renders the currency selectors');
if (trim((string)shell_exec('command -v node')) !== '') {
    preg_match_all('#<script>(.*?)</script>#s', $page, $scripts);
    $js = "$dataDir/admin-scripts.js";
    file_put_contents($js, implode("\n;\n", $scripts[1]));
    exec('node --check ' . escapeshellarg($js) . ' 2>&1', $out, $rc);
    check($rc === 0, 'and its inline scripts are valid JavaScript' . ($rc ? ': ' . implode(' ', $out) : ''));
}

$preview = fn(string $amount, string $currency) => $admin('GET', '?api=price_preview&store_id=s1&amount='
    . rawurlencode($amount) . '&currency=' . rawurlencode($currency));

echo "Preview\n";
[$code, $p] = $preview('10', 'EUR');
check($code === 200 && $p['amountInMintUnit'] === 16667 && $p['currency'] === 'EUR' && $p['mintUnit'] === 'sat'
    && $p['converted'] === true, '10 EUR at 60,000 EUR/BTC is 16,667 sats (rounded up, never down)');
check(($p['rates'][0]['currency'] ?? null) === 'EUR' && $p['rates'][0]['rate'] == 60000
    && is_int($p['rates'][0]['ageSeconds']), 'with the rate it used and how old it is');
[$code, $p] = $preview('10', 'eur');
check($code === 200 && $p['currency'] === 'EUR', 'lower-case codes are accepted');
[$code, $p] = $preview('100', 'sat');
check($code === 200 && $p['amountInMintUnit'] === 100 && $p['converted'] === false && $p['rates'] === [],
    'the store\'s own unit needs no rate');
[$code, $p] = $preview('1000', 'JPY');
check($code === 200 && $p['amountInMintUnit'] === 10000, 'a currency without cents (1000 JPY = 10,000 sats)');

Database::update('stores', ['exchange_fee_percent' => 1.0], 'id = ?', ['s1']);
[$code, $p] = $preview('10', 'EUR');
check($code === 200 && $p['amountInMintUnit'] === 16834 && $p['feePercent'] == 1.0,
    'the store\'s conversion fee is included, as in invoice pricing (16,833.33 -> 16,834)');
Database::update('stores', ['exchange_fee_percent' => 0], 'id = ?', ['s1']);

foreach ([
    ['10', 'XYZ', 'a currency outside the whitelist'],
    ['10', 'BTC', 'BTC (not offered in the dashboard)'],
    ['10', '<script>', 'markup as a currency'],
    ['10.001', 'EUR', 'more decimals than EUR has'],
    ['10.5', 'JPY', 'decimals on a whole-number currency'],
    ['1.5', 'sat', 'a fraction of a sat'],
    ['1e3', 'EUR', 'scientific notation'],
    ['-5', 'EUR', 'a negative amount'],
    ['0', 'EUR', 'zero'],
    ['12,50', 'EUR', 'a comma'],
    ['', 'EUR', 'nothing'],
] as [$amount, $currency, $what]) {
    [$code, $p] = $preview($amount, $currency);
    check($code === 400 && is_string($p['error'] ?? null), "refused: $what ($code " . ($p['error'] ?? '') . ')');
}
[$code] = $admin('GET', '?api=price_preview&store_id=s1&amount[]=1&currency=EUR');
check($code === 400, 'refused: an array amount');
[$code] = $admin('GET', '?api=price_preview&store_id=nope&amount=1&currency=EUR');
check($code === 404, 'an unknown store is a 404');

echo "No usable rate\n";
$seedRates(400); // older than the 5-minute cache, and no provider answers
[$code, $p] = $preview('10', 'EUR');
check($code === 200 && $p['rates'][0]['stale'] === true && $p['rates'][0]['ageSeconds'] >= 400,
    'no source answers: the last rate (under an hour old) is used, as invoice pricing does, and marked stale');
$seedRates(3700); // older than the hour invoice pricing falls back to
[$code, $p] = $preview('10', 'EUR');
check($code === 503 && ($p['code'] ?? null) === 'rate_unavailable' && str_contains($p['error'], 'paused'),
    'a rate over an hour old is not used: 503 with the plain explanation');
[$code, $p] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '10',
    'currency' => 'EUR', 'csrf_token' => $csrf]);
check($code === 400 && str_contains((string)($p['error'] ?? ''), 'paused')
    && (int)Database::fetchOne('SELECT COUNT(*) AS n FROM invoices')['n'] === 0, 'and no invoice is created from it');
$seedRates();

echo "Creating the invoice\n";
$invoices = fn() => (int)Database::fetchOne('SELECT COUNT(*) AS n FROM invoices')['n'];
[$code, $r] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '10',
    'currency' => 'EUR', 'expected_amount' => '1', 'csrf_token' => $csrf]);
check($code === 409 && ($r['code'] ?? null) === 'rate_changed' && ($r['preview']['amountInMintUnit'] ?? null) === 16667
    && $invoices() === 0, 'a preview figure far from the server\'s price: 409 with the new price, no invoice');
[$code] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '10',
    'currency' => 'EUR', 'expected_amount' => 'abc', 'csrf_token' => $csrf]);
check($code === 400 && $invoices() === 0, 'a malformed preview figure is a 400');
[$code, $r] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '10',
    'currency' => 'EUR', 'expected_amount' => '16600', 'csrf_token' => $csrf]);
$row = Database::fetchOne('SELECT * FROM invoices WHERE id = ?', [(string)($r['id'] ?? '')]);
check($code === 200 && $row !== null && $row['currency'] === 'EUR' && $row['amount'] === '10'
    && (int)$row['amount_sats'] === 16667 && $row['mint_unit'] === 'sat',
    'within 2% of what the operator saw: a 10 EUR invoice priced on the server at 16,667 sats');
check(($r['amountInMintUnit'] ?? null) === 16667 && ($r['currency'] ?? null) === 'EUR', 'the answer is the usual invoice shape');
[$code] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '10',
    'currency' => 'XYZ', 'csrf_token' => $csrf]);
check($code === 400, 'creating in a currency outside the whitelist is refused too');
[$code, $r] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '100',
    'currency' => 'sat', 'csrf_token' => $csrf]);
$row = Database::fetchOne('SELECT * FROM invoices WHERE id = ?', [(string)($r['id'] ?? '')]);
check($code === 200 && $row['currency'] === 'SAT' && (int)$row['amount_sats'] === 100,
    'a request in sats works as before (what older dashboards send)');
[$code] = $admin('POST', '', ['action' => 'create_invoice', 'store_id' => 's1', 'amount' => '10', 'currency' => 'EUR']);
check($code === 403, 'creating needs the CSRF token');

echo "Display currency\n";
[$code, $r] = $admin('GET', '?api=display_rate&store_id=s1');
check($code === 200 && $r === ['currency' => null], 'none set: nothing to show');
foreach (['XYZ', '<b>EUR</b>', 'BTC', 'sat'] as $bad) {
    [$code] = $admin('POST', '', ['action' => 'update_store', 'store_id' => 's1', 'display_currency' => $bad, 'csrf_token' => $csrf]);
    Config::clearCache();
    check($code === 400 && Database::fetchOne("SELECT display_currency FROM stores WHERE id='s1'")['display_currency'] === null,
        "refused and not stored: " . json_encode($bad));
}
[$code] = $admin('POST', '', ['action' => 'update_store', 'store_id' => 's1', 'display_currency' => 'eur', 'csrf_token' => $csrf]);
check($code === 200 && Database::fetchOne("SELECT display_currency FROM stores WHERE id='s1'")['display_currency'] === 'EUR',
    'EUR is stored (normalized to upper case)');
[$code, $r] = $admin('GET', '?api=display_rate&store_id=s1');
check($code === 200 && $r['currency'] === 'EUR' && $r['rate'] == 60000, 'the dashboard gets the EUR rate for the balance line');
[, $dash] = $admin('GET', '?api=dashboard&store_id=s1');
check(($dash['stores'][0]['display_currency'] ?? null) === 'EUR', 'and the store\'s setting');

echo "Greenfield API is unchanged by a display currency\n";
// Same requests with and without a display currency: identical shapes and amounts.
$snapshot = function () use ($api): array {
    [$c1, $store] = $api('GET', '/api/v1/stores/s1');
    [$c2, $sat] = $api('POST', '/api/v1/stores/s1/invoices', ['amount' => '100', 'currency' => 'SAT']);
    [$c3, $usd] = $api('POST', '/api/v1/stores/s1/invoices', ['amount' => '13', 'currency' => 'USD']);
    [$c4, $got] = $api('GET', '/api/v1/stores/s1/invoices/' . ($usd['id'] ?? ''));
    $payload = json_decode((string)Database::fetchOne(
        'SELECT payload FROM webhook_deliveries WHERE invoice_id = ?', [(string)($usd['id'] ?? '')])['payload'], true);
    return compact('c1', 'c2', 'c3', 'c4', 'store', 'sat', 'usd', 'got', 'payload');
};
$normalize = function ($v) use (&$normalize) {
    if (!is_array($v)) { return $v; }
    $out = [];
    foreach ($v as $k => $x) {
        // Per-invoice identifiers and times differ between any two invoices.
        $out[$k] = in_array($k, ['id', 'invoiceId', 'createdTime', 'expirationTime', 'checkoutLink', 'paymentLink',
            'destination', 'deliveryId', 'webhookId', 'originalDeliveryId', 'timestamp'], true) ? '*' : $normalize($x);
    }
    return $out;
};
$with = $snapshot();
check($with['c1'] === 200 && array_keys($with['store']) === ['id', 'name', 'createdTime'],
    'GET store: still id, name, createdTime (no display currency)');
check($with['c2'] === 200 && $with['sat']['currency'] === 'SAT' && $with['sat']['amount'] === '100'
    && $with['sat']['amountInMintUnit'] === 100, 'a SAT invoice stays SAT');
check($with['c3'] === 200 && $with['usd']['currency'] === 'USD' && $with['usd']['amount'] === '13'
    && $with['usd']['amountInMintUnit'] === 20000 && ($with['usd']['exchangeRate']['currency'] ?? null) === 'USD',
    'a USD invoice is priced in USD (13 USD at 65,000 = 20,000 sats), not in the display currency');
check($with['c4'] === 200 && $with['got']['currency'] === 'USD', 'and reads back as USD');
check(is_array($with['payload']) && !str_contains(json_encode($with['payload']), 'EUR'), 'the webhook payload does not mention EUR');

Database::update('stores', ['display_currency' => null], 'id = ?', ['s1']);
$without = $snapshot();
foreach (['store', 'sat', 'usd', 'got', 'payload'] as $part) {
    check($normalize($with[$part]) == $normalize($without[$part]) && array_keys($with[$part] ?? []) === array_keys($without[$part] ?? []),
        "$part: identical with and without a display currency");
}

echo "fiat_request_2026_11: OK\n";
