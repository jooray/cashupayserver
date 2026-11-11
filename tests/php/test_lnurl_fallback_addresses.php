<?php
/**
 * Ordered Lightning-address fallback for the receive (invoice-presentation)
 * path. A merchant can list several addresses; Invoice::create walks them in
 * priority order and presents the first one that yields a usable LUD-21
 * invoice, skipping hosts that are down / can't produce an invoice.
 *
 * The mock LNURL host serves a valid LUD-21 callback for any user EXCEPT one
 * named 'down', for which it returns HTTP 503 on the metadata endpoint
 * (simulating an unreachable / broken host). Scenarios:
 *
 *   1. [down@, good@]  → first fails, second wins; invoice rail=lnaddress.
 *   2. [good@, other@] → first wins; second never probed.
 *   3. [down@, down2@] → both fail; LNURL gives up, falls through to the mint
 *      stub (which refuses), so create() throws and no lnaddress invoice is made.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lnurl_receive.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$port = random_int(40000, 49999);
$serverDir = sys_get_temp_dir() . '/lnurl_fallback_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

/**
 * Mock host. Logs each probed user to calls.log (one line per metadata hit)
 * so the test can assert which addresses were tried and in what order. Users
 * whose name starts with 'down' get a 503 on the metadata endpoint.
 */
function start_fallback_mock(int $port, string $serverDir): int {
    $router = <<<PHP
<?php
\$path = \$_SERVER['REQUEST_URI'] ?? '/';
\$qpos = strpos(\$path, '?');
\$rawPath = \$qpos === false ? \$path : substr(\$path, 0, \$qpos);
\$prefix = '/.well-known/lnurlp/';
if (strpos(\$rawPath, \$prefix) === 0) {
    \$user = substr(\$rawPath, strlen(\$prefix));
    file_put_contents('$serverDir/calls.log', \$user . "\\n", FILE_APPEND);
    if (strpos(\$user, 'down') === 0) {
        http_response_code(503);
        echo 'host down';
        return;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'callback' => "http://127.0.0.1:$port/cb/" . \$user,
        'minSendable' => 1000, 'maxSendable' => 100000000,
        'metadata' => '[["text/plain","fallback test"]]', 'tag' => 'payRequest',
    ]);
    return;
}
if (strpos(\$rawPath, '/cb/') === 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'pr' => 'lnbc1pretend' . substr(\$rawPath, 4),
        'verify' => "http://127.0.0.1:$port/verify/" . bin2hex(random_bytes(6)),
    ]);
    return;
}
http_response_code(404);
PHP;
    file_put_contents($serverDir . '/router.php', $router);
    @unlink($serverDir . '/calls.log');

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
    fail("fallback-test lnurl server failed on port $port");
}

function probed_users(string $serverDir): array {
    if (!is_file($serverDir . '/calls.log')) return [];
    return array_values(array_filter(array_map('trim',
        file($serverDir . '/calls.log', FILE_IGNORE_NEW_LINES))));
}
function reset_calls(string $serverDir): void { @unlink($serverDir . '/calls.log'); }

$pid = start_fallback_mock($port, $serverDir);
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$port/.well-known/lnurlp/{user}");

$mintStub = 'http://127.0.0.1:1'; // refused immediately; LNURL should win first

try {
    // ---------- Scenario 1: first address down, second wins ----------
    $s1 = 'store_fallback_wins';
    make_store($s1, $mintStub);
    Database::update('stores', ['auto_melt_enabled' => 1], 'id = ?', [$s1]);
    StoreLnAddresses::replaceForStore($s1, ['down@example.test', 'good@example.test']);

    reset_calls($serverDir);
    $inv = Invoice::create($s1, ['amount' => 5000, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv['payment_rail'], 'rail=lnaddress after falling back to #2');
    assert_true(!empty($inv['lnurl_verify_url']), 'verify URL from the working (#2) address');
    $probed = probed_users($serverDir);
    assert_eq(['down', 'good'], $probed, 'tried #1 (down) then #2 (good) in order');

    // ---------- Scenario 2: first address wins, second never tried ----------
    $s2 = 'store_first_wins';
    make_store($s2, $mintStub);
    Database::update('stores', ['auto_melt_enabled' => 1], 'id = ?', [$s2]);
    StoreLnAddresses::replaceForStore($s2, ['good@example.test', 'other@example.test']);

    reset_calls($serverDir);
    $inv2 = Invoice::create($s2, ['amount' => 5000, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv2['payment_rail'], 'rail=lnaddress on first address');
    assert_eq(['good'], probed_users($serverDir), 'second address not probed once first wins');

    // ---------- Scenario 3: all addresses down → fall through to mint ----------
    $s3 = 'store_all_down';
    make_store($s3, $mintStub);
    Database::update('stores', ['auto_melt_enabled' => 1], 'id = ?', [$s3]);
    StoreLnAddresses::replaceForStore($s3, ['down@example.test', 'down2@example.test']);

    reset_calls($serverDir);
    $threw = false;
    try {
        Invoice::create($s3, ['amount' => 5000, 'currency' => 'sat']);
    } catch (Throwable $e) {
        $threw = true; // expected: no LNURL works, mint stub refuses
    }
    assert_true($threw, 'all addresses down + mint stub down → create throws');
    assert_eq(['down', 'down2'], probed_users($serverDir),
        'both addresses probed before giving up');
} finally {
    @posix_kill($pid, 9);
}

echo "test_lnurl_fallback_addresses: ok\n";
