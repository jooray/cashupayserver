<?php
/**
 * SwapClaimer claim-fee estimation: live mempool feerate with a cached-then-
 * default fallback, clamped to a sane band.
 *
 *   - fetchFeerateSatPerVb() reads an Esplora /fee-estimates endpoint (mock
 *     server here) and picks a moderate confirmation target.
 *   - estimateClaimFeeSats() prefers the live feerate, falls back to the last
 *     cached feerate when Esplora is unavailable (regtest has no public
 *     endpoint), then a conservative default, and clamps before applying.
 */
declare(strict_types=1);
require __DIR__ . '/../php/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/swap/claimer.php';

// ---- live fetch against a mock Esplora /fee-estimates -----------------------
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$name = stream_socket_get_name($probe, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($probe);
$serverDir = sys_get_temp_dir() . '/esplora_stub_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);
$router = <<<'PHP'
<?php
$path = $_SERVER['REQUEST_URI'] ?? '/';
header('Content-Type: application/json');
if (str_contains($path, 'fee-estimates')) {
    echo json_encode(['1' => 50.0, '2' => 40.0, '3' => 30.0, '6' => 12.0]);
} else { http_response_code(404); echo '{}'; }
PHP;
file_put_contents($serverDir . '/router.php', $router);
$pid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
));
register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });
$base = "http://127.0.0.1:$port";
$up = false;
for ($i = 0; $i < 120; $i++) {
    $h = @fopen("$base/fee-estimates", 'r');
    if ($h) { fclose($h); $up = true; break; }
    usleep(50000);
}
if (!$up) { fail("esplora stub failed to start on port $port"); }

$rate = SwapClaimer::fetchFeerateSatPerVb($base);
assert_eq(30.0, $rate, 'fetch picks the ~3-block target feerate');

// Unreachable endpoint -> null (no crash).
assert_null(SwapClaimer::fetchFeerateSatPerVb('http://127.0.0.1:1'), 'unreachable Esplora yields null');

// ---- cache fallback (regtest has no public Esplora endpoint) ---------------
$VSIZE = 150;

// No cache yet -> conservative default (2 sat/vB) * vsize = 300.
assert_eq(300, SwapClaimer::estimateClaimFeeSats('regtest', $VSIZE), 'default feerate when no Esplora and no cache');

// A cached feerate is used when Esplora is unavailable.
Config::set('swap_claim_feerate_regtest', ['rate' => 5.0, 'timestamp' => time()]);
assert_eq(750, SwapClaimer::estimateClaimFeeSats('regtest', $VSIZE), 'cached feerate used when Esplora unavailable');

// Clamp high: a runaway cached feerate is capped at the band ceiling (100).
Config::set('swap_claim_feerate_regtest', ['rate' => 100000.0, 'timestamp' => time()]);
assert_eq(15000, SwapClaimer::estimateClaimFeeSats('regtest', $VSIZE), 'feerate clamped to ceiling');

// Clamp low: a near-zero cached feerate is floored to the band minimum (1).
Config::set('swap_claim_feerate_regtest', ['rate' => 0.0001, 'timestamp' => time()]);
assert_eq(150, SwapClaimer::estimateClaimFeeSats('regtest', $VSIZE), 'feerate floored to minimum');

fwrite(STDERR, "test_claim_fee_estimate: all assertions passed\n");
