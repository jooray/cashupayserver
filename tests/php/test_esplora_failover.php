<?php
/**
 * EsploraProvider host failover + cooldown. With two configured hosts:
 *   - healthy primary: fallback never contacted;
 *   - primary HTTP 500 or 200-with-garbage: request served by the fallback,
 *     primary put on an EndpointHealth cooldown;
 *   - while cooling down, the primary is not contacted at all;
 *   - both hosts failing: the call throws (poller retries next cron);
 *   - both hosts cooling down: they are still TRIED (all-cooled fallback) so
 *     a recovered host resumes service before its cooldown expires.
 * Also: OnchainProviderFactory gives default stores the multi-host chain, but
 * an operator-configured URL stays exclusive (no silent fallback to public
 * explorers when a private instance is down).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

$ADDR = 'bc1qexampleexampleexampleexampleexampleexx';

/**
 * Start a stub Esplora on a free port. Behavior is driven by mode.txt in its
 * docroot ('ok' | 'http500' | 'garbage'); every request appends a line to
 * hits.log so the test can count contacts.
 * @return array{port:int, dir:string, base:string}
 */
function start_stub(string $tag, int $tip): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
    $pname = stream_socket_get_name($probe, false);
    $port = (int)substr($pname, strrpos($pname, ':') + 1);
    fclose($probe);

    $dir = sys_get_temp_dir() . "/esplora_fo_{$tag}_" . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    file_put_contents($dir . '/mode.txt', 'ok');
    file_put_contents($dir . '/tip.txt', (string)$tip);

    $router = <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
file_put_contents(__DIR__ . '/hits.log', $uri . "\n", FILE_APPEND);
$mode = trim(@file_get_contents(__DIR__ . '/mode.txt')) ?: 'ok';
if ($mode === 'http500') {
    http_response_code(500);
    echo 'stub-down';
    return;
}
if ($mode === 'garbage') {
    // Valid HTTP 200, useless body: exercises the malformed-data failover.
    echo '<html>totally not json</html>';
    return;
}
header('Content-Type: application/json');
$addr = 'bc1qexampleexampleexampleexampleexampleexx';
$tip = (int)trim(@file_get_contents(__DIR__ . '/tip.txt'));
if (str_contains($uri, '/blocks/tip/height')) {
    echo (string)$tip;
    return;
}
if (str_contains($uri, '/address/') && str_ends_with($uri, '/txs')) {
    echo json_encode([[
        'txid' => str_repeat('b', 64),
        'status' => ['confirmed' => true, 'block_height' => $tip - 3],
        'vout' => [['scriptpubkey_address' => $addr, 'value' => 4321]],
    ]]);
    return;
}
http_response_code(404);
echo '[]';
PHP;
    file_put_contents($dir . '/router.php', $router);

    $pid = (int) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY), $port, escapeshellarg($dir), escapeshellarg($dir . '/router.php')
    ));
    register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });

    $up = false;
    for ($i = 0; $i < 120; $i++) {
        $h = @fopen("http://127.0.0.1:$port/blocks/tip/height", 'r');
        if ($h) { fclose($h); $up = true; break; }
        usleep(50000);
    }
    if (!$up) { fail("esplora stub {$tag} failed to start on port {$port}"); }
    file_put_contents($dir . '/hits.log', ''); // discard startup probes

    return ['port' => $port, 'dir' => $dir, 'base' => "http://127.0.0.1:$port"];
}

function hits(array $stub): int {
    $log = @file_get_contents($stub['dir'] . '/hits.log');
    return $log === false || $log === '' ? 0 : substr_count($log, "\n");
}

$a = start_stub('a', 800100);
$b = start_stub('b', 800200); // distinct tips identify which host answered

$provider = new EsploraProvider([$a['base'], $b['base']]);

// --- 1. Healthy primary: fallback never contacted ---------------------------
assert_eq(800100, $provider->currentTipHeight(), 'healthy primary answers');
assert_eq(0, hits($b), 'fallback not contacted while primary is healthy');

// --- 2. Primary HTTP 500: fallback answers, primary earns a cooldown --------
file_put_contents($a['dir'] . '/mode.txt', 'http500');
assert_eq(800200, $provider->currentTipHeight(), 'fallback answers on primary HTTP error');
assert_true(EndpointHealth::isCoolingDown('esplora:' . $a['base']), 'failed primary is cooling down');
assert_false(EndpointHealth::isCoolingDown('esplora:' . $b['base']), 'healthy fallback is not cooling down');

// --- 3. While cooling down, the primary is skipped entirely -----------------
$aHits = hits($a);
assert_eq(800200, $provider->currentTipHeight(), 'fallback keeps answering');
assert_eq($aHits, hits($a), 'cooling-down primary is not contacted');

// --- 4. 200-with-garbage counts as a host failure and fails over ------------
EndpointHealth::recordSuccess('esplora:' . $a['base']); // clear the cooldown
file_put_contents($a['dir'] . '/mode.txt', 'garbage');
assert_eq(800200, $provider->currentTipHeight(), 'malformed tip body fails over');
assert_true(EndpointHealth::isCoolingDown('esplora:' . $a['base']), 'garbage body earns a cooldown');

// Same for the JSON endpoint: observations come from the fallback.
EndpointHealth::recordSuccess('esplora:' . $a['base']);
$obs = $provider->addressTransactions($ADDR);
assert_eq(1, count($obs), 'observations served despite garbage primary');
assert_eq(4321, $obs[0]->amountSat, 'observation came from the fallback host');

// --- 5. Both hosts failing: throws (poller retries next cron) ---------------
EndpointHealth::recordSuccess('esplora:' . $a['base']);
EndpointHealth::recordSuccess('esplora:' . $b['base']);
file_put_contents($b['dir'] . '/mode.txt', 'http500');
$threw = false;
try { $provider->currentTipHeight(); } catch (\Throwable $e) { $threw = true; }
assert_true($threw, 'all hosts failing throws');
assert_true(EndpointHealth::isCoolingDown('esplora:' . $a['base']), 'primary cooled after total outage');
assert_true(EndpointHealth::isCoolingDown('esplora:' . $b['base']), 'fallback cooled after total outage');

// --- 6. All hosts cooling down are still tried (all-cooled fallback) --------
// Primary recovers while both cooldowns are active; the request must succeed.
file_put_contents($a['dir'] . '/mode.txt', 'ok');
assert_eq(800100, $provider->currentTipHeight(), 'recovered host answers despite active cooldowns');
assert_false(EndpointHealth::isCoolingDown('esplora:' . $a['base']), 'success clears the recovered host cooldown');

// --- 7. Factory: defaults get the chain, custom URLs stay exclusive ---------
$prop = new ReflectionProperty(EsploraProvider::class, 'baseUrls');

$default = OnchainProviderFactory::forStore(['onchain_provider_url' => '']);
assert_eq(
    ['https://mempool.space/api', 'https://blockstream.info/api'],
    $prop->getValue($default),
    'default mainnet store gets the mempool.space -> blockstream.info chain'
);

$custom = OnchainProviderFactory::forStore(['onchain_provider_url' => $a['base']]);
assert_eq([$a['base']], $prop->getValue($custom), 'operator-configured URL is used exclusively');

assert_eq(
    ['https://mempool.space/signet/api'],
    EsploraProvider::defaultUrlsForNetwork('signet'),
    'signet has no blockstream.info fallback'
);
assert_null(EsploraProvider::defaultUrlsForNetwork('regtest'), 'regtest has no default');
assert_eq('https://mempool.space/api', EsploraProvider::defaultUrlForNetwork('mainnet'), 'singular default is the chain head');

fwrite(STDERR, "test_esplora_failover: all assertions passed\n");
