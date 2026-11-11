<?php
/**
 * EsploraProvider must not trust a provider's block_height / value verbatim.
 * A hostile or MITM'd endpoint could report an out-of-range block_height to
 * inflate the confirmation count past minConfs / REORG_SAFETY_DEPTH and force
 * a premature settle, or a negative output value to corrupt the received-amount
 * math. addressTransactions() must:
 *   - treat a confirmed tx whose block_height is <=0 or > tip as UNCONFIRMED
 *     (confirmations = 0, blockHeight = null), and
 *   - floor a negative vout value to 0.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
// The provider records host failures via EndpointHealth (config table), so it
// needs an isolated DB even though the test itself is network-only.
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

$ADDR = 'bc1qexampleexampleexampleexampleexampleexx';

// Collision-free port.
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$pname = stream_socket_get_name($probe, false);
$port = (int)substr($pname, strrpos($pname, ':') + 1);
fclose($probe);

$serverDir = sys_get_temp_dir() . '/esplora_bounds_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

// Mock Esplora: tip = 800100. One page (< 25 confirmed -> no pagination) with
// three txs paying OUR address:
//   future  : confirmed, block_height 900000 (> tip)  value 1000  -> unconfirmed
//   negval  : confirmed, block_height 800000 (valid)  value -5    -> confs 101, amount 0
//   good    : confirmed, block_height 800099 (valid)  value 1234  -> confs 2
$router = <<<'PHP'
<?php
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$addr = 'bc1qexampleexampleexampleexampleexampleexx';

if (str_contains($uri, '/blocks/tip/height')) {
    echo '800100';
    return;
}
if (str_contains($uri, '/address/') && str_ends_with($uri, '/txs')) {
    echo json_encode([
        [
            'txid' => str_repeat('f', 64),
            'status' => ['confirmed' => true, 'block_height' => 900000],
            'vout' => [['scriptpubkey_address' => $addr, 'value' => 1000]],
        ],
        [
            'txid' => str_repeat('e', 64),
            'status' => ['confirmed' => true, 'block_height' => 800000],
            'vout' => [['scriptpubkey_address' => $addr, 'value' => -5]],
        ],
        [
            'txid' => str_repeat('a', 64),
            'status' => ['confirmed' => true, 'block_height' => 800099],
            'vout' => [['scriptpubkey_address' => $addr, 'value' => 1234]],
        ],
    ]);
    return;
}
http_response_code(404);
echo '[]';
PHP;
file_put_contents($serverDir . '/router.php', $router);

$pid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
));
register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });

$up = false;
for ($i = 0; $i < 120; $i++) {
    $h = @fopen("http://127.0.0.1:$port/blocks/tip/height", 'r');
    if ($h) { fclose($h); $up = true; break; }
    usleep(50000);
}
if (!$up) { fail("esplora stub failed to start on port $port"); }

$provider = new EsploraProvider("http://127.0.0.1:$port");
$obs = $provider->addressTransactions($ADDR);

// Index by txid.
$byTxid = [];
foreach ($obs as $o) {
    $byTxid[$o->txid] = $o;
}

$future = str_repeat('f', 64);
$negval = str_repeat('e', 64);
$good   = str_repeat('a', 64);

assert_true(isset($byTxid[$future]), 'future-height tx observed');
assert_eq(0, $byTxid[$future]->confirmations, 'future-height tx forced to 0 confs');
assert_null($byTxid[$future]->blockHeight, 'future-height tx blockHeight nulled');

assert_true(isset($byTxid[$negval]), 'negative-value tx observed');
assert_eq(0, $byTxid[$negval]->amountSat, 'negative value floored to 0');
assert_eq(101, $byTxid[$negval]->confirmations, 'valid-height confs = tip - h + 1');

assert_true(isset($byTxid[$good]), 'good tx observed');
assert_eq(1234, $byTxid[$good]->amountSat, 'good value preserved');
assert_eq(2, $byTxid[$good]->confirmations, 'good confs = 800100 - 800099 + 1');

fwrite(STDERR, "ok\n");
