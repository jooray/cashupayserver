<?php
/**
 * LnUrlReceive::pollVerifyUrl: handle the three LUD-21 response states
 * (settled-with-preimage, pending, malformed/unreachable).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lnurl_receive.php';

$port = random_int(40000, 49999);
$serverDir = sys_get_temp_dir() . '/lnurl_verify_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

/**
 * Mock LUD-21 verify host. The /verify endpoint reads a marker file from
 * $serverDir/state to decide what to return, so the test can flip state
 * without restarting the server.
 */
function start_verify_host(int $port, string $serverDir): int {
    $router = <<<'PHP'
<?php
$path = $_SERVER['REQUEST_URI'] ?? '/';
$qpos = strpos($path, '?');
$rawPath = $qpos === false ? $path : substr($path, 0, $qpos);
if (strpos($rawPath, '/verify') === 0) {
    header('Content-Type: application/json');
    $state = @file_get_contents(__DIR__ . '/state');
    $state = $state !== false ? trim($state) : 'pending';
    switch ($state) {
        case 'paid':
            echo json_encode([
                'status' => 'OK', 'settled' => true,
                'preimage' => str_repeat('aa', 32),
                'pr' => 'lnbc1pretend',
            ]);
            return;
        case 'pending':
            echo json_encode([
                'status' => 'OK', 'settled' => false,
                'preimage' => null, 'pr' => 'lnbc1pretend',
            ]);
            return;
        case 'malformed':
            echo 'this is not json{{';
            return;
    }
    return;
}
http_response_code(404);
echo 'nope';
PHP;
    file_put_contents($serverDir . '/router.php', $router);
    file_put_contents($serverDir . '/state', 'pending');

    $pid = (int) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY), $port,
        escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
    ));
    for ($i = 0; $i < 40; $i++) {
        $h = @fopen("http://127.0.0.1:$port/verify/x", 'r');
        if ($h) { fclose($h); return $pid; }
        usleep(50000);
    }
    fail("verify host failed to start on port $port");
}

$pid = start_verify_host($port, $serverDir);
$verifyUrl = "http://127.0.0.1:$port/verify/abc";

try {
    // Initial state is 'pending'.
    $r = LnUrlReceive::pollVerifyUrl($verifyUrl, 5);
    assert_eq('pending', $r['state'], 'pending state');
    assert_null($r['preimage'], 'no preimage when pending');

    // Flip to paid.
    file_put_contents($serverDir . '/state', 'paid');
    $r = LnUrlReceive::pollVerifyUrl($verifyUrl, 5);
    assert_eq('paid', $r['state'], 'paid state recognized');
    assert_eq(str_repeat('aa', 32), $r['preimage'], 'preimage returned');

    // Malformed response → error.
    file_put_contents($serverDir . '/state', 'malformed');
    $r = LnUrlReceive::pollVerifyUrl($verifyUrl, 5);
    assert_eq('error', $r['state'], 'malformed response → error');

    // Unreachable URL → error.
    $r = LnUrlReceive::pollVerifyUrl("http://127.0.0.1:1/verify/x", 1);
    assert_eq('error', $r['state'], 'unreachable → error');
} finally {
    @posix_kill($pid, 9);
}

echo "test_lnurl_verify_poll: ok\n";
