<?php
/**
 * Public-surface fixes from the 2026-11 audit verification (AUDIT-KIMIK31-VERIFICATION.md):
 *
 * - A public mint over plain http is refused when it is entered and when it is
 *   connected to (a stored one), with a plain "use the https:// address" message.
 * - Rate limits count an IPv6 /64 as one visitor.
 * - An uncaught exception on a standalone page (a database from a newer version) is a
 *   plain 503 with no stack trace or server path; `?key[]=x` on cron.php is a plain 403;
 *   the WordPress plugin build reached directly answers 403 before any startup check
 *   can name a path.
 * - The payment page keeps polling when the QR code cannot be drawn and ignores
 *   answers that are not a known status.
 *
 * Run: php tests/surface_2026_11_audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$app = sys_get_temp_dir() . '/cashupay-surface-' . bin2hex(random_bytes(8));
mkdir($app, 0700, true);
foreach (['includes', 'assets', 'api-keys', 'cashu-wallet-php'] as $dir) {
    exec('cp -R ' . escapeshellarg("$root/$dir") . ' ' . escapeshellarg("$app/$dir"));
}
foreach (glob("$root/*.php") as $file) {
    copy($file, "$app/" . basename($file));
}
@unlink("$app/includes/config.local.php");
@unlink("$app/includes/data-location.txt");
mkdir("$app/data", 0700);
define('CASHUPAY_DATA_DIR', "$app/data");

require_once "$app/includes/database.php";
require_once "$app/includes/config.php";
require_once "$app/includes/security.php";
require_once "$app/includes/mint_helpers.php";

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$proc = null;
register_shutdown_function(function () use (&$proc, $app) {
    if ($proc) { testStopProcess($proc); }
    @chmod("$app/data", 0700);
    exec('rm -rf ' . escapeshellarg($app));
});

Database::initialize();
Config::set('setup_complete', true);
Config::set('cron_key', 'k_' . bin2hex(random_bytes(8)));

// ---------------------------------------------------------------------------
echo "Plain-http mints\n";
Security::setResolverForTesting(fn(string $host): array => [
    'mint.example.com' => ['93.184.216.34'],
    'lan-mint.example.com' => ['192.168.1.20'],
    'mixed.example.com' => ['192.168.1.20', '93.184.216.34'],
][$host] ?? []);

$refused = function (callable $fn): ?string {
    try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); }
};
check(MintHelpers::assertSafeMintUrl('https://mint.example.com') === 'https://mint.example.com', 'https public mint accepted');
$msg = $refused(fn() => MintHelpers::assertSafeMintUrl('http://mint.example.com'));
check($msg !== null && str_contains($msg, 'Use the https:// address of the mint'), 'http public mint refused with a plain message');
$msg = $refused(fn() => MintHelpers::assertSafeMintUrl('http://lan-mint.example.com'));
check($msg !== null && str_contains($msg, 'private or local network'), 'http LAN mint refused while private mints are off');
Config::set('allow_private_mints', true);
check($refused(fn() => MintHelpers::assertSafeMintUrl('http://lan-mint.example.com')) === null, 'http LAN mint allowed once private mints are on');
$msg = $refused(fn() => MintHelpers::assertSafeMintUrl('http://mint.example.com'));
check($msg !== null && str_contains($msg, 'https://'), 'http public mint still refused with private mints on');
check($refused(fn() => MintHelpers::assertSafeMintUrl('http://mixed.example.com')) !== null, 'http mint with any public address refused');

echo "Connect-time policy refuses a stored http public mint\n";
$msg = $refused(fn() => MintHelpers::connectGuard('http://mint.example.com/v1/info', 'mint'));
check($msg !== null && str_contains($msg, 'https://'), 'connect to http public mint refused');
check($refused(fn() => MintHelpers::connectGuard('https://mint.example.com/v1/info', 'mint')) === null, 'https connect allowed');
check($refused(fn() => MintHelpers::connectGuard('http://lan-mint.example.com/v1/info', 'mint')) === null, 'http LAN connect allowed (private mints on)');
check(MintHelpers::isPublicHttpMint('http://mint.example.com'), 'dashboard check flags the stored http public mint');
check(!MintHelpers::isPublicHttpMint('https://mint.example.com') && !MintHelpers::isPublicHttpMint('http://lan-mint.example.com'),
    'and not https or allowed LAN mints');
Config::set('allow_private_mints', false);
Security::setResolverForTesting(null);

// ---------------------------------------------------------------------------
echo "Rate limits bucket IPv6 by /64\n";
check(Security::rateLimitIdentifier('2001:db8:1:2::5') === Security::rateLimitIdentifier('2001:db8:1:2:ffff:1:2:3'),
    'two addresses in one /64 share a bucket');
check(Security::rateLimitIdentifier('2001:db8:1:3::5') !== Security::rateLimitIdentifier('2001:db8:1:2::5'), 'another /64 is separate');
check(Security::rateLimitIdentifier('203.0.113.7') === '203.0.113.7' && Security::rateLimitIdentifier('store_1') === 'store_1',
    'IPv4 addresses and store ids are their own bucket');
check(Security::checkRateLimit('t6', '2001:db8:9:9::1', 2), 'first request');
check(Security::checkRateLimit('t6', '2001:db8:9:9::2', 2), 'second, from a neighbour address');
check(!Security::checkRateLimit('t6', '2001:db8:9:9:abcd::3', 2), 'third from the same /64 is refused');
check(Security::checkRateLimit('t6', '2001:db8:9:a::1', 2), 'another /64 still passes');

echo "Receive and payment page sources\n";
$receive = (string)file_get_contents("$root/receive.php");
check(str_contains($receive, "checkRateLimit('receive_store', \$storeId") && str_contains($receive, "checkRateLimit('receive_request_store', \$storeId"),
    'receive POST and request creation have per-store limits');
check(str_contains($receive, "cashupay_acquire_lock_wait('receive_' . \$storeId, 10.0)"), 'store lock wait shortened');
check(strpos($receive, '$wallet->loadMint();') < strpos($receive, 'PaymentRequest::create($storeId'),
    'request row is created only after the mint answered');
$payment = (string)file_get_contents("$root/payment.php");
check(str_contains($payment, 'response.ok') && str_contains($payment, 'knownStatuses.includes(data.status)'),
    'payment poll requires an OK answer with a known status');
check(str_contains($payment, "do not pay again &mdash; we'll keep checking"), 'and says so after repeated failures');
check(strpos($payment, 'drawInvoiceQr();') > strpos($payment, "            pollStatus();\n"), 'QR is drawn after polling started');

// ---------------------------------------------------------------------------
$port = testFreePort();
$proc = proc_open(['php', '-S', "127.0.0.1:$port", "$app/router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $app);
testWaitForServer($proc, $port);
$get = function (string $path) use ($port): array {
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false,
        stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));
    $code = (int)substr((string)($http_response_header[0] ?? ''), 9, 3);
    return [$code, $body];
};
$leaks = fn(string $body): bool => str_contains($body, 'Stack trace') || str_contains($body, $app)
    || str_contains($body, 'Fatal error') || str_contains($body, 'Warning:') || str_contains($body, '.php on line');

echo "cron.php key must be a string\n";
[$code, $body] = $get('/cron.php?key[]=x');
check($code === 403 && trim($body) === 'Invalid cron key', "?key[]=x is a plain 403 (HTTP $code: " . substr($body, 0, 80) . ')');
[$code, $body] = $get('/cron.php?key=wrong');
check($code === 403 && !$leaks($body), 'a wrong key is a plain 403');

echo "Dashboard: http mint warning, background checks card, Tell the shop again\n";
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
Config::set('update_check_enabled', false);
Config::set('last_background_trigger', (string)(time() + 3600));
Config::set('base_url', "http://127.0.0.1:$port");
// An IP literal: the dashboard check must not need DNS here.
Database::insert('stores', ['id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'http://93.184.216.34', 'mint_unit' => 'sat', 'created_at' => Database::timestamp()]);
Database::insert('webhooks', ['id' => 'wh1', 'store_id' => 's1', 'url' => 'https://127.0.0.1:9/hook',
    'secret' => 'sekret', 'events' => json_encode(['InvoiceSettled']), 'enabled' => 1, 'created_at' => time()]);
Database::insert('webhook_deliveries', ['id' => 'd1', 'webhook_id' => 'wh1', 'invoice_id' => 'inv1',
    'event_type' => 'InvoiceSettled', 'payload' => json_encode(['metadata' => ['orderId' => '1042']]),
    'status_code' => 500, 'created_at' => time() - 600, 'attempts' => 80, 'next_attempt_at' => time() + 3600,
    'last_attempt_at' => time() - 60, 'target_url' => 'https://127.0.0.1:9/hook', 'signing_secret' => 'sekret']);
$cookies = [];
$admin = function (string $method, string $query = '', array $post = []) use ($port, &$cookies): array {
    $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($post);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => implode("\r\n", $headers), 'content' => $content]]);
    $body = (string)@file_get_contents("http://127.0.0.1:$port/admin.php$query", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = $m[2]; }
    }
    return [$code, json_decode($body, true), $body];
};
[, , $page] = $admin('GET');
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
[$code, $login] = $admin('POST', '', ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $m[1] ?? '']);
$csrf = (string)($login['csrfToken'] ?? '');
check($code === 200 && $csrf !== '', 'admin login');
check(str_contains($page, 'Background checks') && str_contains($page, 'Run once now'), 'Settings has the Background checks card');
check(!str_contains($page, (string)Config::get('cron_key')), 'the shell (served before login) does not carry the cron key');
[$code, $diag] = $admin('GET', '?api=diagnostics');
check($code === 200 && count($diag['insecureMints'] ?? []) === 1 && $diag['needsAttention'] === true,
    'a stored http public mint needs attention');
$u = $diag['undeliveredWebhooks'][0] ?? [];
check(($u['deliveryId'] ?? '') === 'd1' && ($u['orderId'] ?? '') === '1042' && ($u['gaveUp'] ?? false) === true,
    'the unconfirmed shop notification is listed with its order and "gave up"');
[$code, $bg] = $admin('GET', '?api=background_checks');
check($code === 200 && ($bg['mode'] ?? '') === 'standalone'
    && str_contains((string)($bg['cronUrl'] ?? ''), 'key=' . Config::get('cron_key')), 'background card gets the cron address with key');
[$code] = $admin('POST', '', ['action' => 'redeliver_webhook', 'delivery_id' => 'd1']);
check($code === 403, 'redelivery without the CSRF token is refused');
[$code, $r] = $admin('POST', '', ['action' => 'redeliver_webhook', 'delivery_id' => 'd1', 'csrf_token' => $csrf]);
$row = Database::fetchOne('SELECT attempts, delivered_at FROM webhook_deliveries WHERE id = ?', ['d1']);
check($code === 200 && ($r['success'] ?? false) === true && (int)$row['attempts'] <= 1 && $row['delivered_at'] === null,
    "Tell the shop again requeues it with a fresh retry budget (HTTP $code, attempts {$row['attempts']})");
[$code] = $admin('POST', '', ['action' => 'redeliver_webhook', 'delivery_id' => 'nope', 'csrf_token' => $csrf]);
check($code === 404, 'an unknown delivery is a 404');

echo "A database from a newer version is a plain 503\n";
$schema = (int)Database::getInstance()->query('PRAGMA user_version')->fetchColumn();
Database::getInstance()->exec('PRAGMA user_version = 99');
foreach (['/', '/admin.php', '/index.php'] as $path) {
    [$code, $body] = $get($path);
    check($code === 503 && !$leaks($body) && str_contains($body, 'newer version of CashuPayServer'),
        "$path explains it (HTTP $code)");
}
[$code, $body] = $get('/payment.php?id=abc');
check($code === 503 && !$leaks($body) && str_contains($body, 'do not pay again'), "the payment page speaks to the customer (HTTP $code)");
[$code, $body] = $get('/cron.php?key=x');
check($code === 503 && !$leaks($body) && json_decode($body, true) !== null, "cron answers JSON 503 (HTTP $code)");
Database::getInstance()->exec('PRAGMA user_version = ' . $schema);

echo "The WordPress plugin build reached directly is refused before any path-revealing check\n";
touch("$app/cashupay.php");
touch("$app/bootstrap.php");
chmod("$app/data", 0555);
[$code, $body] = $get('/setup.php');
check($code === 403 && str_contains($body, 'must be reached through WordPress') && !str_contains($body, 'cannot save anything')
    && !$leaks($body), "403 without naming the data folder (HTTP $code)");
chmod("$app/data", 0700);

echo "All surface checks passed\n";
