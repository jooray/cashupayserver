<?php
/**
 * Shared mock Strike API for the PHP tests. Include this file and call
 * start_strike_mock() — it writes a router script into a fresh tempdir,
 * serves it with `php -S`, and returns control handles:
 *
 *   [$pid, $port, $dir] = start_strike_mock('THEKEY...');
 *   putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");
 *
 * Behaviour is driven by marker files in $dir so tests can flip state
 * without restarting the server:
 *
 *   expected_key   — the only bearer key accepted; anything else gets 401
 *                    with Strike's UNAUTHORIZED error shape.
 *   state          — UNPAID (default) | PAID | CANCELLED, returned by the
 *                    GET /v1/invoices/{id} read-back.
 *   fail_create    — HTTP status to fail POST /v1/invoices with.
 *   fail_quote     — HTTP status to fail POST /v1/invoices/{id}/quote with.
 *   fail_read      — HTTP status to fail GET /v1/invoices/{id} with.
 *   fail_receive_request — HTTP status to fail POST /v1/receive-requests with.
 *   quote_btc_override — BTC decimal string the quote reports as
 *                    sourceAmount instead of the created amount (for the
 *                    amount-mismatch refusal test).
 *   receive_address_override — address string POST /v1/receive-requests
 *                    returns instead of the valid-mainnet rotation (for the
 *                    invalid-address refusal test).
 *   receive_btc_override — BTC decimal string the receive request reports as
 *                    onchain.btcAmount instead of the requested amount.
 *
 * Each created invoice is persisted as $dir/invoices/<id>.json carrying the
 * request body (amount, description, correlationId), so tests can assert on
 * what the client actually sent. Receive requests persist the same way under
 * $dir/receive_requests/<id>.json (body + the address the mock handed out).
 */

declare(strict_types=1);

function start_strike_mock(string $expectedKey): array {
    $dir = sys_get_temp_dir() . '/strike_mock_' . bin2hex(random_bytes(4));
    mkdir($dir . '/invoices', 0750, true);
    mkdir($dir . '/receive_requests', 0750, true);
    file_put_contents($dir . '/expected_key', $expectedKey);
    file_put_contents($dir . '/state', 'UNPAID');

    $router = <<<'PHP'
<?php
$dir = __DIR__;
header('Content-Type: application/json');

function fail_with(int $status, string $code): void {
    http_response_code($status);
    echo json_encode(['data' => ['code' => $code, 'message' => 'mock failure']]);
    exit;
}
function marker(string $name): ?string {
    $v = @file_get_contents(__DIR__ . '/' . $name);
    return $v === false ? null : trim($v);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Unauthenticated health check so the starter can confirm it reached THIS
// mock (and not an orphaned server from an earlier run on the same port).
if ($path === '/__strike_mock') {
    echo json_encode(['mock' => 'strike', 'dir' => __DIR__]);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expected = marker('expected_key') ?? '';
if ($auth !== 'Bearer ' . $expected) {
    fail_with(401, 'UNAUTHORIZED');
}

// POST /v1/invoices — create
if ($method === 'POST' && $path === '/v1/invoices') {
    if (($f = marker('fail_create')) !== null && $f !== '') {
        fail_with((int)$f, 'MOCK_CREATE_FAIL');
    }
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = 'strk-' . bin2hex(random_bytes(8));
    file_put_contents($dir . '/invoices/' . $id . '.json', json_encode($body));
    http_response_code(201);
    echo json_encode([
        'invoiceId' => $id,
        'state' => 'UNPAID',
        'amount' => $body['amount'] ?? null,
        'created' => gmdate('c'),
    ]);
    exit;
}

// POST /v1/receive-requests — create (on-chain receive rail).
// Hands out REAL mainnet addresses (BIP173/BIP350 test vectors) in rotation
// because the payserver refuses any address that fails checksum validation.
if ($method === 'POST' && $path === '/v1/receive-requests') {
    if (($f = marker('fail_receive_request')) !== null && $f !== '') {
        fail_with((int)$f, 'MOCK_RECEIVE_REQUEST_FAIL');
    }
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = 'rr-' . bin2hex(random_bytes(8));
    $addr = marker('receive_address_override');
    if ($addr === '__EMPTY__') {
        // Sentinel: marker() trims, so a literal empty/whitespace override
        // can't be expressed directly. Used by the missing-address test.
        $addr = ' ';
    } elseif ($addr === null || $addr === '') {
        $pool = [
            'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
            'bc1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3qccfmv3',
            'bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297',
        ];
        $addr = $pool[count(glob($dir . '/receive_requests/*.json') ?: []) % count($pool)];
    }
    $btc = marker('receive_btc_override');
    if ($btc === null || $btc === '') {
        $btc = (string)($body['onchain']['amount']['amount'] ?? '0');
    }
    file_put_contents(
        $dir . '/receive_requests/' . $id . '.json',
        json_encode(['body' => $body, 'address' => $addr])
    );
    http_response_code(201);
    echo json_encode([
        'receiveRequestId' => $id,
        'created' => gmdate('c'),
        'onchain' => [
            'address' => $addr,
            'addressUri' => 'bitcoin:' . $addr . '?amount=' . $btc,
            'btcAmount' => $btc,
            'requestedAmount' => $body['onchain']['amount'] ?? null,
        ],
    ]);
    exit;
}

// POST /v1/invoices/{id}/quote — quote
if ($method === 'POST' && preg_match('#^/v1/invoices/([^/]+)/quote$#', $path, $m)) {
    if (($f = marker('fail_quote')) !== null && $f !== '') {
        fail_with((int)$f, 'MOCK_QUOTE_FAIL');
    }
    $inv = json_decode((string)@file_get_contents($dir . '/invoices/' . $m[1] . '.json'), true);
    if (!is_array($inv)) {
        fail_with(404, 'NOT_FOUND');
    }
    $btc = marker('quote_btc_override');
    if ($btc === null || $btc === '') {
        $btc = (string)($inv['amount']['amount'] ?? '0');
    }
    http_response_code(201);
    echo json_encode([
        'quoteId' => 'q-' . $m[1],
        'lnInvoice' => 'lnbcmock' . $m[1],
        'expiration' => gmdate('c', time() + 300),
        'expirationInSec' => 300,
        'sourceAmount' => ['amount' => $btc, 'currency' => 'BTC'],
        'targetAmount' => ['amount' => $btc, 'currency' => 'BTC'],
    ]);
    exit;
}

// GET /v1/invoices/{id} — read-back
if ($method === 'GET' && preg_match('#^/v1/invoices/([^/]+)$#', $path, $m)) {
    if (($f = marker('fail_read')) !== null && $f !== '') {
        fail_with((int)$f, 'MOCK_READ_FAIL');
    }
    $inv = json_decode((string)@file_get_contents($dir . '/invoices/' . $m[1] . '.json'), true);
    if (!is_array($inv)) {
        fail_with(404, 'NOT_FOUND');
    }
    echo json_encode([
        'invoiceId' => $m[1],
        'state' => marker('state') ?: 'UNPAID',
        'amount' => $inv['amount'] ?? null,
    ]);
    exit;
}

// GET /v1/invoices — list (used by ad-hoc key checks)
if ($method === 'GET' && $path === '/v1/invoices') {
    echo json_encode(['items' => [], 'count' => 0]);
    exit;
}

fail_with(404, 'NOT_FOUND');
PHP;
    file_put_contents($dir . '/router.php', $router);

    $base = 27500 + (getmypid() % 900);
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $port = $base + $attempt;
        $pid = (int) shell_exec(sprintf(
            '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), $port,
            escapeshellarg($dir), escapeshellarg($dir . '/router.php')
        ));
        // A failing assertion exits the test without running its finally
        // block, so the kill must not depend on the test reaching
        // stop_strike_mock(). Shutdown functions DO run on exit().
        register_shutdown_function(static function () use ($pid) {
            @posix_kill($pid, 9);
        });
        for ($i = 0; $i < 40; $i++) {
            // Health-check that THIS mock answered — a plain port probe could
            // hit an orphaned server from an earlier run.
            $resp = @file_get_contents("http://127.0.0.1:{$port}/__strike_mock");
            if ($resp !== false) {
                $health = json_decode($resp, true);
                if (is_array($health) && ($health['dir'] ?? '') === $dir) {
                    return [$pid, $port, $dir];
                }
                break; // some other server owns this port — try the next one
            }
            usleep(50000);
        }
        @posix_kill($pid, 9);
    }
    fail('mock strike api failed to start on any port');
}

function stop_strike_mock(int $pid): void {
    @posix_kill($pid, 9);
}

/** The single invoice-request body the mock captured, or all of them. */
function strike_mock_invoices(string $dir): array {
    $out = [];
    foreach (glob($dir . '/invoices/*.json') ?: [] as $f) {
        $out[basename($f, '.json')] = json_decode((string)file_get_contents($f), true);
    }
    return $out;
}

/** Captured receive requests: id => ['body' => ..., 'address' => ...]. */
function strike_mock_receive_requests(string $dir): array {
    $out = [];
    foreach (glob($dir . '/receive_requests/*.json') ?: [] as $f) {
        $out[basename($f, '.json')] = json_decode((string)file_get_contents($f), true);
    }
    return $out;
}
