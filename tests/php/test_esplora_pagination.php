<?php
/**
 * EsploraProvider must (a) walk /address/{a}/txs/chain pagination so payments
 * beyond the first 25 confirmed txs on a re-used address are still observed,
 * and (b) reject a non-numeric /blocks/tip/height body instead of collapsing
 * to (int)0 — a 0 tip poisons the historical-UTXO filter at allocation time.
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

$serverDir = sys_get_temp_dir() . '/esplora_stub_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);
file_put_contents($serverDir . '/tip.txt', "800100");

// Mock Esplora:
//  - /blocks/tip/height       -> contents of tip.txt (test rewrites it)
//  - /address/{a}/txs         -> page 0: 25 confirmed txs paying a DECOY addr
//  - /address/{a}/txs/chain/* -> page 1: 1 confirmed tx paying OUR addr (1234 sat)
// If pagination is broken, the page-1 payment is never seen.
$router = <<<'PHP'
<?php
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$addr = 'bc1qexampleexampleexampleexampleexampleexx';
$decoy = 'bc1qdecoydecoydecoydecoydecoydecoydecoyzz';

if (str_contains($uri, '/blocks/tip/height')) {
    echo trim(@file_get_contents(__DIR__ . '/tip.txt'));
    return;
}
if (str_contains($uri, '/txs/chain/')) {
    // Page 1: a single confirmed tx that pays our address.
    echo json_encode([[
        'txid' => str_repeat('a', 64),
        'status' => ['confirmed' => true, 'block_height' => 800050],
        'vout' => [['scriptpubkey_address' => $addr, 'value' => 1234]],
    ]]);
    return;
}
if (str_contains($uri, '/address/') && str_ends_with($uri, '/txs')) {
    // Page 0: 25 confirmed txs paying the decoy (full page -> forces paging).
    $txs = [];
    for ($i = 0; $i < 25; $i++) {
        $txs[] = [
            'txid' => str_pad((string)$i, 64, '0', STR_PAD_LEFT),
            'status' => ['confirmed' => true, 'block_height' => 800099 - $i],
            'vout' => [['scriptpubkey_address' => $decoy, 'value' => 5000]],
        ];
    }
    echo json_encode($txs);
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

// 1. Pagination: the page-1 payment to our address is observed.
$obs = $provider->addressTransactions($ADDR);
$ours = array_values(array_filter($obs, fn($o) => $o->amountSat === 1234));
assert_eq(1, count($ours), 'page-1 payment observed via chain pagination');
assert_eq(str_repeat('a', 64), $ours[0]->txid, 'paged tx txid');

// 2. Valid tip parses.
assert_eq(800100, $provider->currentTipHeight(), 'valid tip parses');

// 3. Empty tip body must throw (not return 0).
file_put_contents($serverDir . '/tip.txt', "");
$threw = false;
try { $provider->currentTipHeight(); } catch (\Throwable $e) { $threw = true; }
assert_true($threw, 'empty tip body throws');

// 4. Non-numeric (HTML error page) tip must throw.
file_put_contents($serverDir . '/tip.txt', "<html>502 Bad Gateway</html>");
$threw = false;
try { $provider->currentTipHeight(); } catch (\Throwable $e) { $threw = true; }
assert_true($threw, 'html tip body throws');

echo "PASS test_esplora_pagination\n";
exit(0);
