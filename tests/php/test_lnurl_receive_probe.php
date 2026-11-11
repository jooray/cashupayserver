<?php
/**
 * LnUrlReceive::probeAndFetchInvoice + probeLud21Support against a mock LNURL
 * host driven by PHP's built-in HTTP server.
 *
 * Scenarios:
 *   - LUD-21 happy path: callback returns {pr,verify} → probe returns the pair
 *   - LUD-21 missing:    callback returns {pr} only   → probe returns null
 *   - Amount below minSendable / above maxSendable    → probe returns null
 *   - Host down (port closed)                         → probe returns null
 *
 * Uses CASHU_LNURL_URL_TEMPLATE to rewrite the user@127.0.0.1:PORT address
 * to a port-bearing URL, matching the LightningAddress::resolve convention.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lnurl_receive.php';

$port = random_int(40000, 49999);
$serverDir = sys_get_temp_dir() . '/lnurl_probe_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

/**
 * Router script for the mock host. `$mode` is baked in at file-write time
 * because the built-in server can't pass per-request flags into the router.
 */
function start_mock_lnurl(int $port, string $serverDir, string $mode, int $minMsat = 1000, int $maxMsat = 100000000): int {
    $router = <<<PHP
<?php
\$path = \$_SERVER['REQUEST_URI'] ?? '/';
\$qpos = strpos(\$path, '?');
\$rawPath = \$qpos === false ? \$path : substr(\$path, 0, \$qpos);
if (strpos(\$rawPath, '/.well-known/lnurlp/') === 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'callback' => "http://127.0.0.1:$port/cb",
        'minSendable' => $minMsat,
        'maxSendable' => $maxMsat,
        'metadata' => '[["text/plain","probe test"]]',
        'tag' => 'payRequest',
    ]);
    return;
}
if (\$rawPath === '/cb') {
    header('Content-Type: application/json');
    parse_str(\$_SERVER['QUERY_STRING'] ?? '', \$q);
    \$amt = (int)(\$q['amount'] ?? 0);
    \$resp = ['pr' => 'lnbc' . dechex(\$amt) . 'pretend'];
    if ('$mode' === 'lud21') {
        \$resp['verify'] = "http://127.0.0.1:$port/verify/" . bin2hex(random_bytes(8));
    }
    echo json_encode(\$resp);
    return;
}
http_response_code(404);
echo 'nope';
PHP;
    file_put_contents($serverDir . '/router.php', $router);

    $pid = (int) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY), $port,
        escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
    ));
    for ($i = 0; $i < 40; $i++) {
        $h = @fopen("http://127.0.0.1:$port/.well-known/lnurlp/x", 'r');
        if ($h) { fclose($h); return $pid; }
        usleep(50000);
    }
    fail("mock lnurl server failed to start on port $port");
}

function kill_pid(int $pid): void { @posix_kill($pid, 9); }

// Rewrite the canonical user@domain → URL to point at our 127.0.0.1:PORT host.
// The current PORT changes per scenario, so reset the env var each time.
function set_template(int $port): void {
    putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$port/.well-known/lnurlp/{user}");
}

// --- Scenario 1: LUD-21 happy path ------------------------------------------
$p1 = $port;
$pid = start_mock_lnurl($p1, $serverDir, 'lud21');
set_template($p1);
try {
    $res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 5000);
    assert_not_null($res, 'LUD-21 happy path: probe returns result');
    assert_true(isset($res['bolt11']) && str_starts_with($res['bolt11'], 'lnbc'), 'bolt11 returned');
    assert_true(isset($res['verify_url']) && str_starts_with($res['verify_url'], 'http://127.0.0.1:'), 'verify_url returned');

    // Out-of-range amount → probe returns null (the host rejects amounts <
    // minSendable). Probe declines BEFORE the callback when bounds fail.
    $res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 0, null, $reason);
    assert_null($res, 'amount=0 outside range: null');
    assert_eq('the amount is outside the range the Lightning address accepts', $reason,
        'out-of-range failure reason set');

    // Above max:
    $res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 200000);
    assert_null($res, 'amount above max: null');

    // Lud21 support probe returns 1.
    assert_eq(1, LnUrlReceive::probeLud21Support('alice@example.test'),
              'probeLud21Support detects verify field');
} finally { kill_pid($pid); }

// --- Scenario 2: LUD-21 missing — callback returns no verify field -----------
$p2 = $port + 1;
$pid = start_mock_lnurl($p2, $serverDir, 'no_verify');
set_template($p2);
try {
    $res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 5000, null, $reason);
    assert_null($res, 'no verify URL: probe returns null');
    assert_eq('the Lightning address service does not support payment verification (LUD-21)',
        $reason, 'missing LUD-21 failure reason set');
    // Save-time LUD-21 probe explicitly reports 0 (vs. null for unreachable),
    // so the admin can surface a warning instead of silent fallback.
    assert_eq(0, LnUrlReceive::probeLud21Support('alice@example.test'),
              'probeLud21Support reports 0 for missing verify');
} finally { kill_pid($pid); }

// --- Scenario 3: Host down — port not listening ------------------------------
// Pick a port that's almost certainly not in use; don't start a server.
$p3 = $port + 2;
set_template($p3);
$res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 5000, 1, $reason);
assert_null($res, 'host down: probe returns null');
assert_eq('the Lightning address service could not be reached', $reason,
    'unreachable host failure reason set');
$supp = LnUrlReceive::probeLud21Support('alice@example.test', 1);
assert_null($supp, 'host down: support probe returns null (unreachable)');

// --- Scenario 4: Malformed LN address (bad format) ---------------------------
$res = LnUrlReceive::probeAndFetchInvoice('not-an-address', 5000, null, $reason);
assert_null($res, 'bad address format: null without network call');
assert_eq('the configured Lightning address is malformed', $reason,
    'malformed address failure reason set');

echo "test_lnurl_receive_probe: ok\n";
