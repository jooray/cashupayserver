<?php
/**
 * Swap claims follow the store's Esplora host chain (EsploraProvider::
 * chainForStore) for both fee estimation and the broadcast fallback:
 *
 *   - custom URL, fallback off: only the custom host is ever contacted —
 *     when it is down the fee comes from the cache / default and the
 *     broadcast fails rather than touching a public explorer;
 *   - custom URL, fallback on: the public chain is tried after the custom
 *     host, and only then;
 *   - "already known" broadcast answers count as success;
 *   - CASHUPAY_ESPLORA_DEFAULT_URLS replaces the public chain for rigs, but
 *     only while private endpoints are allowed.
 */
declare(strict_types=1);
require __DIR__ . '/../php/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/swap/claimer.php';

function start_stub(string $tag, float $rate3, string $txid): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
    $pname = stream_socket_get_name($probe, false);
    $port = (int)substr($pname, strrpos($pname, ':') + 1);
    fclose($probe);
    $dir = sys_get_temp_dir() . "/esplora_claim_{$tag}_" . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    file_put_contents($dir . '/mode.txt', 'ok');
    file_put_contents($dir . '/rate.txt', (string)$rate3);
    file_put_contents($dir . '/txid.txt', $txid);
    $router = <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
file_put_contents(__DIR__ . '/hits.log', $_SERVER['REQUEST_METHOD'] . ' ' . $uri . "\n", FILE_APPEND);
$mode = trim(@file_get_contents(__DIR__ . '/mode.txt')) ?: 'ok';
if ($mode === 'http500') { http_response_code(500); echo 'stub-down'; return; }
if ($uri === '/fee-estimates') {
    header('Content-Type: application/json');
    $r = (float)trim(@file_get_contents(__DIR__ . '/rate.txt'));
    echo json_encode(['1' => $r + 2, '2' => $r + 1, '3' => $r]);
    return;
}
if ($uri === '/tx' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'alreadyknown') { http_response_code(400); echo 'sendrawtransaction RPC error: {"code":-27,"message":"Transaction already in block chain"}'; return; }
    file_put_contents(__DIR__ . '/last_tx.txt', file_get_contents('php://input'));
    echo trim(@file_get_contents(__DIR__ . '/txid.txt'));
    return;
}
http_response_code(404); echo '[]';
PHP;
    file_put_contents($dir . '/router.php', $router);
    $pid = (int) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY), $port, escapeshellarg($dir), escapeshellarg($dir . '/router.php')
    ));
    register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });
    $up = false;
    for ($i = 0; $i < 120; $i++) {
        $h = @fopen("http://127.0.0.1:$port/fee-estimates", 'r');
        if ($h) { fclose($h); $up = true; break; }
        usleep(50000);
    }
    if (!$up) { fail("stub {$tag} failed to start on port {$port}"); }
    file_put_contents($dir . '/hits.log', '');
    return ['port' => $port, 'dir' => $dir, 'base' => "http://127.0.0.1:$port"];
}
function hits(array $stub): int {
    $log = @file_get_contents($stub['dir'] . '/hits.log');
    return $log === false || $log === '' ? 0 : substr_count($log, "\n");
}
function set_mode(array $stub, string $mode): void { file_put_contents($stub['dir'] . '/mode.txt', $mode); }

$custom = start_stub('custom', 7.0, str_repeat('c', 64));
$public = start_stub('public', 30.0, str_repeat('p', 64));
$VSIZE = 150;
$RAW = 'deadbeef';
$EXPECTED = str_repeat('e', 64);

// ---- chainForStore drives which hosts are consulted ------------------------
$storeExclusive = ['onchain_provider_url' => $custom['base'], 'onchain_provider_fallback' => 0, 'onchain_network' => 'mainnet'];
$storeFallback  = ['onchain_provider_url' => $custom['base'], 'onchain_provider_fallback' => 1, 'onchain_network' => 'mainnet'];

// Pretend the public chain is our second stub (gated env seam; the harness
// allows private endpoints).
putenv('CASHUPAY_ESPLORA_DEFAULT_URLS=' . $public['base']);
assert_eq([$public['base']], EsploraProvider::defaultUrlsForNetwork('mainnet'), 'env override replaces the public chain');
assert_eq([$public['base']], EsploraProvider::defaultUrlsForNetwork('regtest'), 'override applies to every network');
assert_eq([$custom['base']], EsploraProvider::chainForStore($storeExclusive), 'exclusive chain');
assert_eq([$custom['base'], $public['base']], EsploraProvider::chainForStore($storeFallback), 'fallback chain');

// --- 1. Fee estimate: custom host healthy -> only it is contacted ----------
$fee = SwapClaimer::estimateClaimFeeSats('mainnet', $VSIZE, EsploraProvider::chainForStore($storeExclusive));
assert_eq((int)ceil(7.0 * $VSIZE), $fee, 'fee from the custom explorer feerate');
assert_eq(1, hits($custom), 'custom host asked once');
assert_eq(0, hits($public), 'public host untouched while custom is healthy');

$fee = SwapClaimer::estimateClaimFeeSats('mainnet', $VSIZE, EsploraProvider::chainForStore($storeFallback));
assert_eq((int)ceil(7.0 * $VSIZE), $fee, 'fallback chain also answers from the custom host first');
assert_eq(0, hits($public), 'fallback host not contacted while custom is healthy');

// --- 2. Fee estimate: custom down, fallback OFF -> cache, never public -----
set_mode($custom, 'http500');
$fee = SwapClaimer::estimateClaimFeeSats('mainnet', $VSIZE, EsploraProvider::chainForStore($storeExclusive));
assert_eq((int)ceil(7.0 * $VSIZE), $fee, 'last good (custom) feerate served from cache');
assert_eq(0, hits($public), 'custom outage without opt-in never reaches the public explorer');

// --- 3. Fee estimate: custom down, fallback ON -> public answers ----------
$fee = SwapClaimer::estimateClaimFeeSats('mainnet', $VSIZE, EsploraProvider::chainForStore($storeFallback));
assert_eq((int)ceil(30.0 * $VSIZE), $fee, 'public explorer feerate used when the custom host is down and fallback is on');
assert_eq(1, hits($public), 'public explorer contacted exactly once');

// Default (null chain) keeps the historical behaviour: public chain.
file_put_contents($public['dir'] . '/hits.log', '');
$fee = SwapClaimer::estimateClaimFeeSats('mainnet', $VSIZE);
assert_eq((int)ceil(30.0 * $VSIZE), $fee, 'no chain given -> public defaults');
assert_eq(1, hits($public), 'public contacted for the default chain');

// --- 4. Broadcast: exclusive chain ----------------------------------------
set_mode($custom, 'ok');
file_put_contents($custom['dir'] . '/hits.log', '');
file_put_contents($public['dir'] . '/hits.log', '');
$txid = SwapClaimer::broadcastViaEsplora(EsploraProvider::chainForStore($storeExclusive), $RAW, $EXPECTED);
assert_eq(str_repeat('c', 64), $txid, 'custom host accepted the broadcast');
assert_eq($RAW, trim(file_get_contents($custom['dir'] . '/last_tx.txt')), 'raw tx hex posted verbatim');
assert_eq(0, hits($public), 'public not contacted for a successful custom broadcast');

set_mode($custom, 'http500');
$threw = false;
try {
    SwapClaimer::broadcastViaEsplora(EsploraProvider::chainForStore($storeExclusive), $RAW, $EXPECTED);
} catch (RuntimeException $e) {
    $threw = true;
    assert_true(str_contains($e->getMessage(), 'All broadcast paths failed'), 'broadcast failure message');
}
assert_true($threw, 'custom down + fallback off -> broadcast fails (does not leak to public)');
assert_eq(0, hits($public), 'public never contacted without the opt-in');

// --- 5. Broadcast: fallback chain -----------------------------------------
$txid = SwapClaimer::broadcastViaEsplora(EsploraProvider::chainForStore($storeFallback), $RAW, $EXPECTED);
assert_eq(str_repeat('p', 64), $txid, 'public host accepted the broadcast after the custom host failed');
assert_eq(1, hits($public), 'public contacted once');

// --- 6. "already known" counts as success ----------------------------------
set_mode($custom, 'alreadyknown');
file_put_contents($public['dir'] . '/hits.log', '');
$txid = SwapClaimer::broadcastViaEsplora(EsploraProvider::chainForStore($storeFallback), $RAW, $EXPECTED);
assert_eq($EXPECTED, $txid, 'already-in-chain answer returns the expected txid');
assert_eq(0, hits($public), 'no fallback attempt after an already-known answer');

// --- 7. Empty chain (regtest, nothing configured) ----------------------------
putenv('CASHUPAY_ESPLORA_DEFAULT_URLS');
assert_null(EsploraProvider::publicDefaultsOverride(), 'override gone once the env is unset');
assert_eq([], EsploraProvider::chainForStore(['onchain_network' => 'regtest']), 'regtest has no chain');
$threw = false;
try { SwapClaimer::broadcastViaEsplora([], $RAW, $EXPECTED); } catch (RuntimeException $e) { $threw = true; }
assert_true($threw, 'empty chain -> broadcast fails cleanly');

// --- 8. The env seam is gated on the private-endpoints opt-in --------------
putenv('CASHUPAY_ESPLORA_DEFAULT_URLS=' . $public['base']);
Config::set('allow_private_endpoints', false);
assert_null(EsploraProvider::publicDefaultsOverride(), 'override ignored while private endpoints are refused');
assert_eq(
    ['https://mempool.space/api', 'https://blockstream.info/api'],
    EsploraProvider::defaultUrlsForNetwork('mainnet'),
    'stock deployment keeps the real public chain despite a stray env var'
);
Config::set('allow_private_endpoints', true);
putenv('CASHUPAY_ESPLORA_DEFAULT_URLS');

fwrite(STDERR, "test_claim_esplora_chain: all assertions passed\n");
