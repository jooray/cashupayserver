<?php
/**
 * LnurlPay::fetchInvoice prefers LUD-12 comment when commentAllowed is large
 * enough, falls back to LUD-18 payerData.name when commentAllowed is too
 * small but payerData supports name, and pays without a memo (after logging
 * a warning) when neither channel works. Uses a local PHP-built-in HTTP
 * server as the stand-in LNURL endpoint.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$port = random_int(40000, 49999);
$serverDir = sys_get_temp_dir() . '/lnurl_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

// Mode controls which capabilities the fake LNURL endpoint advertises.
// We launch one router per scenario.
function start_server(int $port, string $serverDir, string $mode): int {
    // Router PHP file: reads ?mode from its own filename so the harness can
    // start multiple ports.
    $router = <<<PHP
<?php
\$path = \$_SERVER['REQUEST_URI'] ?? '/';
\$qpos = strpos(\$path, '?');
\$rawPath = \$qpos === false ? \$path : substr(\$path, 0, \$qpos);
\$mode = '$mode';
// payRequest metadata
if (strpos(\$rawPath, '/.well-known/lnurlp/') === 0 || \$rawPath === '/lnurlp') {
    header('Content-Type: application/json');
    \$payRequest = [
        'callback' => "http://127.0.0.1:$port/cb",
        'minSendable' => 1000,
        'maxSendable' => 1000000000,
        'metadata' => '[["text/plain","CashuPayServer test"]]',
        'tag' => 'payRequest',
    ];
    if (\$mode === 'lud12') {
        \$payRequest['commentAllowed'] = 255;
    } elseif (\$mode === 'lud18') {
        \$payRequest['commentAllowed'] = 0;
        \$payRequest['payerData'] = ['name' => ['mandatory' => false]];
    }
    echo json_encode(\$payRequest);
    return;
}
if (\$rawPath === '/cb') {
    header('Content-Type: application/json');
    parse_str(\$_SERVER['QUERY_STRING'] ?? '', \$q);
    // Record what the payer sent (so the test can read it back).
    \$record = [
        'amount' => \$q['amount'] ?? null,
        'comment' => \$q['comment'] ?? null,
        'payerdata' => \$q['payerdata'] ?? null,
    ];
    file_put_contents('$serverDir/last_request.json', json_encode(\$record));
    // Return a fake (invalid) BOLT-11 so the caller gets to the melt step.
    echo json_encode(['pr' => 'lnbc1pretend']);
    return;
}
http_response_code(404);
echo 'nope';
PHP;
    file_put_contents($serverDir . '/router.php', $router);

    $pid = (int) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($serverDir),
        escapeshellarg($serverDir . '/router.php')
    ));

    // Wait for the server to come up.
    for ($i = 0; $i < 40; $i++) {
        $h = @fopen("http://127.0.0.1:$port/.well-known/lnurlp/test", 'r');
        if ($h) { fclose($h); return $pid; }
        usleep(50000);
    }
    fail("lnurl test server failed to start on port $port");
}

function kill_server(int $pid): void {
    @posix_kill($pid, 9);
}

// --- Scenario 1: LUD-12 — comment is delivered as ?comment=...
$pid = start_server($port, $serverDir, 'lud12');
try {
    $params = LnurlPay::resolve("test@127.0.0.1:$port");
    // resolve() expects port-less hostnames; we sneak the port in by using
    // the raw URL path instead.
    $params = LnurlPay::resolve("http://127.0.0.1:$port/.well-known/lnurlp/test");
    assert_not_null($params, 'resolve via raw URL returned params');
    assert_eq(255, $params['commentAllowed']);
    LnurlPay::fetchInvoice($params, 1000, 'Deployment: TEST_DEPLOY');
    $rec = json_decode(file_get_contents($serverDir . '/last_request.json'), true);
    assert_eq('Deployment: TEST_DEPLOY', $rec['comment'], 'LUD-12 comment delivered');
    assert_eq(null, $rec['payerdata'], 'LUD-18 path not used when LUD-12 sufficed');
} finally {
    kill_server($pid);
}

// --- Scenario 2: LUD-18 — commentAllowed=0, payerData.name supported.
$pid = start_server($port + 1, $serverDir, 'lud18');
try {
    $params = LnurlPay::resolve("http://127.0.0.1:" . ($port + 1) . "/.well-known/lnurlp/test");
    assert_not_null($params);
    assert_eq(0, $params['commentAllowed']);
    LnurlPay::fetchInvoice($params, 1000, 'Deployment: TEST_DEPLOY');
    $rec = json_decode(file_get_contents($serverDir . '/last_request.json'), true);
    assert_eq(null, $rec['comment'], 'LUD-12 not used (commentAllowed=0)');
    assert_not_null($rec['payerdata'], 'LUD-18 payerdata sent as fallback');
    $payerdata = json_decode($rec['payerdata'], true);
    assert_eq('Deployment: TEST_DEPLOY', $payerdata['name']);
} finally {
    kill_server($pid + 1);
}

// --- Scenario 3: Neither LUD-12 nor LUD-18 — pay without memo (no error).
$pid = start_server($port + 2, $serverDir, 'none');
try {
    $params = LnurlPay::resolve("http://127.0.0.1:" . ($port + 2) . "/.well-known/lnurlp/test");
    assert_not_null($params);
    LnurlPay::fetchInvoice($params, 1000, 'Deployment: TEST_DEPLOY');
    $rec = json_decode(file_get_contents($serverDir . '/last_request.json'), true);
    assert_eq(null, $rec['comment']);
    assert_eq(null, $rec['payerdata']);
} finally {
    kill_server($pid + 2);
}

echo "test_lnurl_memo_fallback: ok\n";
