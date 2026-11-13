<?php
/**
 * Operator-entered Esplora URL validation (admin save_onchain path).
 *
 *   normalizeCustomUrl(): static checks — http(s) only, plain host, no
 *     credentials (a bitcoind user:pass@ URL is the likely paste and gets a
 *     hint), no query/fragment/whitespace; trailing slash stripped.
 *   probeCustomUrl(): live GET /blocks/tip/height — returns the tip on
 *     success; on failure throws an operator-readable reason (HTTP status,
 *     non-API page with a "/api" suggestion, unreachable, DNS, private host
 *     refused by the SafeHttp policy with the opt-in named).
 *   validateCustomUrl(): both, returning the normalised URL.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

/**
 * Stub explorer. mode.txt: 'ok' | 'http500' | 'webroot' (HTML everywhere
 * except under /api/, which behaves like 'ok' — the "pasted the website,
 * not the API" case).
 */
function start_stub(string $tag, int $tip): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
    $pname = stream_socket_get_name($probe, false);
    $port = (int)substr($pname, strrpos($pname, ':') + 1);
    fclose($probe);

    $dir = sys_get_temp_dir() . "/esplora_val_{$tag}_" . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    file_put_contents($dir . '/mode.txt', 'ok');
    file_put_contents($dir . '/tip.txt', (string)$tip);
    $router = <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
file_put_contents(__DIR__ . '/hits.log', $uri . "\n", FILE_APPEND);
$mode = trim(@file_get_contents(__DIR__ . '/mode.txt')) ?: 'ok';
$tip = (int)trim(@file_get_contents(__DIR__ . '/tip.txt'));
if ($mode === 'http500') { http_response_code(500); echo 'stub-down'; return; }
if ($mode === 'webroot') {
    if (str_starts_with($uri, '/api/')) { $uri = substr($uri, 4); }
    else { header('Content-Type: text/html'); echo '<!doctype html><html><body>mempool web ui</body></html>'; return; }
}
if ($uri === '/blocks/tip/height') { echo (string)$tip; return; }
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
        $h = @fopen("http://127.0.0.1:$port/blocks/tip/height", 'r');
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

function expect_invalid(string $raw, string $needle, string $msg): void {
    try {
        EsploraProvider::normalizeCustomUrl($raw);
        fail("{$msg}: '{$raw}' was accepted");
    } catch (InvalidArgumentException $e) {
        assert_true(stripos($e->getMessage(), $needle) !== false, "{$msg}: message '{$e->getMessage()}' mentions '{$needle}'");
    }
}

function expect_probe_failure(string $url, string $needle, string $msg): string {
    try {
        EsploraProvider::probeCustomUrl($url, 3);
        fail("{$msg}: probe of {$url} succeeded");
    } catch (RuntimeException $e) {
        assert_true(stripos($e->getMessage(), $needle) !== false, "{$msg}: message '{$e->getMessage()}' mentions '{$needle}'");
        assert_true(str_contains($e->getMessage(), 'Nothing was saved'), "{$msg}: message says nothing was saved");
        return $e->getMessage();
    }
    return '';
}

// --- 1. Static checks -------------------------------------------------------
assert_eq('https://mempool.space/api', EsploraProvider::normalizeCustomUrl('https://mempool.space/api/'), 'trailing slash stripped');
assert_eq('https://mempool.space/api', EsploraProvider::normalizeCustomUrl("  https://mempool.space/api  "), 'surrounding whitespace trimmed');
assert_eq('http://umbrel.local:3006/api', EsploraProvider::normalizeCustomUrl('http://umbrel.local:3006/api'), 'plain http with a port is fine (LAN reachability is decided at probe time)');
assert_eq('http://192.168.1.10:3006/api', EsploraProvider::normalizeCustomUrl('http://192.168.1.10:3006/api'), 'IPv4 literal host');
assert_eq('http://[::1]:3006/api', EsploraProvider::normalizeCustomUrl('http://[::1]:3006/api'), 'IPv6 literal host');
assert_eq('https://mempool.space', EsploraProvider::normalizeCustomUrl('https://mempool.space'), 'a bare origin passes static checks (the probe decides)');

expect_invalid('', 'empty', 'empty URL');
expect_invalid('mempool.space/api', 'http://', 'missing scheme');
expect_invalid('ftp://mempool.space/api', 'http://', 'non-http scheme');
expect_invalid('http://user:pw@127.0.0.1:18443/wallet/cashupay', 'username or password', 'bitcoind RPC URL with credentials');
expect_invalid('http://user:pw@127.0.0.1:18443/wallet/cashupay', 'Bitcoin Core', 'credentials hint names Bitcoin Core RPC');
expect_invalid('https://mempool.space/api?x=1', 'query', 'query string');
expect_invalid('https://mempool.space/api#frag', 'fragment', 'fragment');
expect_invalid("https://mempool .space/api", 'spaces', 'embedded space');
expect_invalid("https://mempool.space/api\x01x", 'control', 'embedded control character');
assert_eq('https://mempool.space/api', EsploraProvider::normalizeCustomUrl("https://mempool.space/api\n"), 'a trailing newline is just trimmed');
expect_invalid('https://mem%pool/api', 'host', 'invalid host syntax');
expect_invalid('https://mempool.space./api', 'host', 'trailing-dot host (would defeat the resolve pin)');

// --- 2. Live probe ---------------------------------------------------------
$ok = start_stub('ok', 870001);
assert_eq(870001, EsploraProvider::probeCustomUrl($ok['base']), 'healthy stub: probe returns the tip');
assert_eq(1, hits($ok), 'probe makes exactly one request');
assert_eq($ok['base'], EsploraProvider::validateCustomUrl($ok['base'] . '/'), 'validateCustomUrl returns the normalised URL');

// HTTP error
file_put_contents($ok['dir'] . '/mode.txt', 'http500');
EndpointHealth::recordSuccess('esplora:' . $ok['base']);
expect_probe_failure($ok['base'], 'HTTP 500', 'HTTP error is named');

// Web root instead of API base: HTML body, and /api works -> suggestion.
file_put_contents($ok['dir'] . '/mode.txt', 'webroot');
EndpointHealth::recordSuccess('esplora:' . $ok['base']);
$msg = expect_probe_failure($ok['base'], 'did not return a block height', 'HTML page is not a block height');
assert_true(str_contains($msg, "Did you mean {$ok['base']}/api ?"), "web-root paste suggests the /api URL: {$msg}");
// ...and the suggested URL itself validates.
EndpointHealth::recordSuccess('esplora:' . $ok['base'] . '/api');
assert_eq(870001, EsploraProvider::probeCustomUrl($ok['base'] . '/api'), 'suggested /api URL probes fine');
// A URL already ending in /api gets no suggestion (nothing to try).
EndpointHealth::recordSuccess('esplora:' . $ok['base']);
file_put_contents($ok['dir'] . '/mode.txt', 'http500');
$msg = expect_probe_failure($ok['base'] . '/api', 'HTTP 500', 'failing /api URL');
assert_false(str_contains($msg, 'Did you mean'), 'no /api suggestion when the URL already ends in /api');
file_put_contents($ok['dir'] . '/mode.txt', 'ok');

// Connection refused (nothing listens on port 1).
expect_probe_failure('http://127.0.0.1:1', 'could not be reached', 'closed port');

// DNS failure (resolver stubbed so the test never touches the network).
SafeHttp::$resolverForTesting = fn(string $host) => [];
expect_probe_failure('https://explorer.example.invalid/api', 'could not be resolved', 'DNS failure');
SafeHttp::$resolverForTesting = null;

// Private / loopback host refused while the opt-in is off: the message names
// the opt-in, and no /api suggestion probe is attempted (it would be refused
// the same way).
Config::set('allow_private_endpoints', false);
EndpointHealth::recordSuccess('esplora:' . $ok['base']);
$before = hits($ok);
$msg = expect_probe_failure($ok['base'], 'private / local network address', 'private host refused by policy');
assert_true(str_contains($msg, 'CASHUPAY_ALLOW_PRIVATE_ENDPOINTS'), "refusal names the opt-in: {$msg}");
assert_false(str_contains($msg, 'Did you mean'), 'no /api suggestion on a policy refusal');
assert_eq($before, hits($ok), 'policy refusal never contacts the host');
Config::set('allow_private_endpoints', true);
assert_eq(870001, EsploraProvider::probeCustomUrl($ok['base']), 'same host probes fine once the opt-in is on');

fwrite(STDERR, "test_esplora_custom_url_validation: all assertions passed\n");
