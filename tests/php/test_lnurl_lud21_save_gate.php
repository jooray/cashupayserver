<?php
/**
 * StoreLnAddresses::probeAndGateChain — the save-time LUD-21 gate shared by
 * admin.php save_auto_melt and the setup wizard's lightning step.
 *
 * Scenarios (mock LNURL host via PHP's built-in server, same pattern as
 * test_lnurl_receive_probe.php):
 *   - Host with LUD-21:   new address saves with supports_verify=1
 *   - Host w/o verify:    NEW address throws (mentions LUD-21);
 *                         already-stored address passes with supports_verify=0
 *   - Host down:          NEW address throws (host didn't respond);
 *                         already-stored address passes with supports_verify=null
 *   - noffer entries skip the probe entirely (supports_verify stays null)
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$port = random_int(40000, 49999);
$serverDir = sys_get_temp_dir() . '/lud21_gate_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

function start_mock_lnurl(int $port, string $serverDir, string $mode): int {
    $router = <<<PHP
<?php
\$path = \$_SERVER['REQUEST_URI'] ?? '/';
\$qpos = strpos(\$path, '?');
\$rawPath = \$qpos === false ? \$path : substr(\$path, 0, \$qpos);
if (strpos(\$rawPath, '/.well-known/lnurlp/') === 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'callback' => "http://127.0.0.1:$port/cb",
        'minSendable' => 1000,
        'maxSendable' => 100000000,
        'metadata' => '[["text/plain","gate test"]]',
        'tag' => 'payRequest',
    ]);
    return;
}
if (\$rawPath === '/cb') {
    header('Content-Type: application/json');
    parse_str(\$_SERVER['QUERY_STRING'] ?? '', \$q);
    \$resp = ['pr' => 'lnbc' . dechex((int)(\$q['amount'] ?? 0)) . 'pretend'];
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

function set_template(int $port): void {
    putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$port/.well-known/lnurlp/{user}");
}

/** Expect probeAndGateChain to throw; return the exception message. */
function expect_gate_throw(string $storeId, array $chain, string $label): string {
    try {
        StoreLnAddresses::probeAndGateChain($storeId, $chain);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    fail("$label: expected RuntimeException, none thrown");
}

make_store('gate-store');
$ln = static fn(string $v): array => ['type' => StoreLnAddresses::TYPE_LNADDRESS, 'value' => $v];

// --- Scenario 1: LUD-21 host — new address saves with supports_verify=1 -----
$p1 = $port;
$pid = start_mock_lnurl($p1, $serverDir, 'lud21');
set_template($p1);
try {
    $gate = StoreLnAddresses::probeAndGateChain('gate-store', [$ln('new@wallet.test')]);
    assert_eq(1, count($gate['entries']), 'lud21 host: one entry');
    assert_eq(1, $gate['entries'][0]['supports_verify'], 'lud21 host: supports_verify=1');
    assert_eq(1, $gate['results'][0]['lud21Support'], 'lud21 host: result lud21Support=1');
    // Persist so later scenarios can exercise the grandfather branch.
    StoreLnAddresses::replaceForStore('gate-store', $gate['entries']);
} finally { kill_pid($pid); }

// --- Scenario 2: host without verify — new blocked, stored passes -----------
$p2 = $port + 1;
$pid = start_mock_lnurl($p2, $serverDir, 'no_verify');
set_template($p2);
try {
    $msg = expect_gate_throw('gate-store', [$ln('someone-else@wallet.test')], 'no-verify new address');
    assert_true(stripos($msg, 'LUD-21') !== false, 'no-verify error names LUD-21');

    // The address stored in scenario 1 is grandfathered: it passes the gate
    // and its probe result is refreshed to 0 (host reachable, no verify).
    $gate = StoreLnAddresses::probeAndGateChain('gate-store', [$ln('new@wallet.test')]);
    assert_eq(0, $gate['entries'][0]['supports_verify'], 'stored address grandfathered with supports_verify=0');

    // A whole chain is rejected if ANY new lnaddress fails, even alongside a
    // grandfathered one.
    expect_gate_throw(
        'gate-store',
        [$ln('new@wallet.test'), $ln('fresh@wallet.test')],
        'mixed chain with failing new address'
    );
} finally { kill_pid($pid); }

// --- Scenario 3: host down — new blocked, stored passes with null -----------
$p3 = $port + 2;
set_template($p3); // nothing listening on this port
$msg = expect_gate_throw('gate-store', [$ln('typo@wallet.test')], 'unreachable new address');
assert_true(stripos($msg, 'respond') !== false, 'unreachable error says host did not respond');

$gate = StoreLnAddresses::probeAndGateChain('gate-store', [$ln('new@wallet.test')]);
assert_null($gate['entries'][0]['supports_verify'], 'stored address grandfathered with supports_verify=null when host down');

// --- Scenario 4: noffers skip the probe (no host running at all) ------------
$gate = StoreLnAddresses::probeAndGateChain('gate-store', [
    ['type' => StoreLnAddresses::TYPE_NOFFER, 'value' => 'noffer1qqspretendnoffer'],
]);
assert_eq('noffer', $gate['entries'][0]['type'], 'noffer passes without probe');
assert_null($gate['entries'][0]['supports_verify'], 'noffer supports_verify stays null');

// Grandfathering is case-insensitive, matching the chain dedup rules.
$gate = StoreLnAddresses::probeAndGateChain('gate-store', [$ln('NEW@wallet.test')]);
assert_null($gate['entries'][0]['supports_verify'], 'grandfather match is case-insensitive');

echo "test_lnurl_lud21_save_gate: ok\n";
