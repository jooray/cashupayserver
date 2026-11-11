<?php
/**
 * StoreLnAddresses with the 'strike' destination type:
 *
 *   1. chainFromLists orders Strike keys FIRST, ahead of lnaddress -> nwc ->
 *      noffer, and validates/dedups them without ever echoing a key.
 *   2. displayValue masks a key; typed round trip through replaceForStore /
 *      destinationsForStore preserves type + position.
 *   3. resolveKeepRefs is type-scoped: a strike keep-ref resolves only
 *      against strike rows, and an nwc ref can't smuggle a strike row (or
 *      vice versa).
 *   4. probeAndGateChain: a NEW key must pass the live probe (create + quote
 *      + read against the mock) — a failing probe throws with the masked
 *      label and no key material; an already-stored key is grandfathered
 *      without a probe; results carry the masked label.
 *   5. The LUD-21 gate's @strike.me special case: a Strike lightning address
 *      whose host reports no verify support is refused with the
 *      "use the Strike API option" hint.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$KEY = 'CHAINKEY' . str_repeat('C', 32);
$KEY2 = 'CHAINKEY' . str_repeat('D', 32);

// ---------- 1. ordering + validation + dedup ----------
$chain = StoreLnAddresses::chainFromLists(
    ['merchant@wallet.com'],
    [],
    [],
    [$KEY]
);
assert_eq(2, count($chain), 'both entries in the chain');
assert_eq(StoreLnAddresses::TYPE_STRIKE, $chain[0]['type'], 'strike leads the chain');
assert_eq($KEY, $chain[0]['value'], 'key preserved');
assert_eq('lnaddress', $chain[1]['type'], 'address after strike');

try {
    StoreLnAddresses::chainFromLists([], [], [], ['not a key!']);
    fail('malformed key must throw');
} catch (InvalidArgumentException $e) {
    assert_true(strpos($e->getMessage(), 'Strike API key') !== false, 'error names the type');
    assert_true(strpos($e->getMessage(), '(value hidden)') !== false, 'malformed key is hidden');
    assert_false(strpos($e->getMessage(), 'not a key!') !== false, 'paste never echoed');
}

try {
    StoreLnAddresses::chainFromLists([], [], [], [$KEY, $KEY]);
    fail('duplicate key must throw');
} catch (InvalidArgumentException $e) {
    assert_true(strpos($e->getMessage(), 'Strike API (…') !== false, 'duplicate shown masked');
    assert_false(strpos($e->getMessage(), $KEY) !== false, 'raw key never echoed');
}

// Same key differing only in case is a DIFFERENT credential — both survive.
$chain = StoreLnAddresses::chainFromLists([], [], [], [$KEY, strtolower($KEY)]);
assert_eq(2, count($chain), 'case-different keys are distinct credentials');

// ---------- 2. masking + persistence round trip ----------
assert_eq('Strike API (…' . substr($KEY, -4) . ')',
    StoreLnAddresses::displayValue(StoreLnAddresses::TYPE_STRIKE, $KEY),
    'displayValue masks the key');

$store = 'store_strike_chain';
make_store($store);
$nwcUri = 'nostr+walletconnect://' . str_repeat('ab', 32)
    . '?relay=ws%3A%2F%2F127.0.0.1%3A4869&secret=' . str_repeat('cd', 32);
StoreLnAddresses::replaceForStore($store, [
    ['type' => 'strike', 'address' => $KEY],
    ['type' => 'lnaddress', 'address' => 'merchant@wallet.com'],
    ['type' => 'nwc', 'address' => $nwcUri],
]);
$dests = StoreLnAddresses::destinationsForStore($store);
assert_eq(3, count($dests), 'three rows persisted');
assert_eq('strike', $dests[0]['type'], 'strike stays at position 0');
assert_eq($KEY, $dests[0]['value'], 'stored key intact');

try {
    StoreLnAddresses::replaceForStore($store, [['type' => 'strike', 'address' => 'nope nope']]);
    fail('replaceForStore must reject malformed keys');
} catch (InvalidArgumentException $e) {
    assert_true(strpos($e->getMessage(), '(value hidden)') !== false, 'last-line defence hides the value');
}

// ---------- 3. type-scoped keep refs ----------
$rows = StoreLnAddresses::listForStore($store);
$strikeRowId = null;
foreach ($rows as $r) {
    if ($r['type'] === 'strike') { $strikeRowId = $r['id']; }
}
assert_not_null($strikeRowId, 'strike row exists');
[$resolved, $kept] = StoreLnAddresses::resolveKeepRefs(
    $store, ['keep:' . $strikeRowId], StoreLnAddresses::TYPE_STRIKE
);
assert_eq([$KEY], $resolved, 'strike keep-ref resolves to the stored key');
assert_true(isset($kept['strike:' . $KEY]), 'kept dedup key recorded');

try {
    StoreLnAddresses::resolveKeepRefs($store, ['keep:' . $strikeRowId], StoreLnAddresses::TYPE_NWC);
    fail('a strike row must not resolve as an nwc ref');
} catch (InvalidArgumentException $e) {
    assert_true(strpos($e->getMessage(), 'NWC connection reference') !== false, 'type-scoped error');
}

// ---------- 4. probe gate against the mock ----------
[$pid, $port, $dir] = start_strike_mock($KEY2);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");
try {
    $storeB = 'store_strike_gate';
    make_store($storeB);

    // New key, probe passes -> saved; result carries the masked label only.
    $gate = StoreLnAddresses::probeAndGateChain($storeB, [
        ['type' => 'strike', 'value' => $KEY2],
    ]);
    assert_eq('strike', $gate['entries'][0]['type'], 'entry typed strike');
    assert_eq($KEY2, $gate['entries'][0]['address'], 'entry carries the key for storage');
    assert_eq('Strike API (…' . substr($KEY2, -4) . ')', $gate['results'][0]['address'],
        'result is the masked label');
    // The probe created exactly one 1-sat invoice on the mock.
    $captured = strike_mock_invoices($dir);
    assert_eq(1, count($captured), 'probe minted one test invoice');
    assert_eq('0.00000001', array_values($captured)[0]['amount']['amount'], 'probe invoice is 1 sat');
    StoreLnAddresses::replaceForStore($storeB, $gate['entries']);

    // New key that fails the probe (401 on the mock) -> refused, masked.
    $badKey = 'BADKEY00' . str_repeat('E', 32);
    try {
        StoreLnAddresses::probeAndGateChain($storeB, [
            ['type' => 'strike', 'value' => $badKey],
        ]);
        fail('failing probe must block the save');
    } catch (RuntimeException $e) {
        assert_true(strpos($e->getMessage(), 'Strike API (…') !== false, 'refusal names the masked label');
        assert_true(strpos($e->getMessage(), 'rejected the key') !== false, 'refusal carries the probe reason');
        assert_false(strpos($e->getMessage(), $badKey) !== false, 'refusal never contains the key');
    }

    // Already-stored key is grandfathered: no probe runs even when the API
    // would now refuse it (create forced to 500).
    file_put_contents($dir . '/fail_create', '500');
    $gate = StoreLnAddresses::probeAndGateChain($storeB, [
        ['type' => 'strike', 'value' => $KEY2],
    ]);
    assert_eq($KEY2, $gate['entries'][0]['address'], 'stored key passes without re-probe');
    unlink($dir . '/fail_create');
} finally {
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
}

// ---------- 5. @strike.me LUD-21 refusal hint ----------
// Mock LNURL host that answers the metadata + callback but WITHOUT a LUD-21
// verify URL — probeLud21Support() returns 0 and the gate must refuse with
// the Strike-specific hint.
$lnurlDir = sys_get_temp_dir() . '/strike_lnurl_' . bin2hex(random_bytes(4));
mkdir($lnurlDir, 0750, true);
$router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (strpos($path, '/.well-known/lnurlp/') === 0) {
    echo json_encode([
        'callback' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/callback',
        'minSendable' => 1000,
        'maxSendable' => 100000000000,
        'tag' => 'payRequest',
    ]);
    return;
}
if (strpos($path, '/callback') === 0) {
    echo json_encode(['pr' => 'lnbc1mocknoverify']); // no `verify` field
    return;
}
http_response_code(404);
PHP;
file_put_contents($lnurlDir . '/router.php', $router);
$lnurlPort = 27100 + (getmypid() % 800);
$lnurlPid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $lnurlPort,
    escapeshellarg($lnurlDir), escapeshellarg($lnurlDir . '/router.php')
));
register_shutdown_function(static function () use ($lnurlPid) {
    @posix_kill($lnurlPid, 9);
});
for ($i = 0; $i < 40; $i++) {
    $c = @fsockopen('127.0.0.1', $lnurlPort, $e, $s, 0.2);
    if ($c) { fclose($c); break; }
    usleep(50000);
}
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:{$lnurlPort}/.well-known/lnurlp/{user}");
try {
    $storeC = 'store_strike_hint';
    make_store($storeC);
    try {
        StoreLnAddresses::probeAndGateChain($storeC, [
            ['type' => 'lnaddress', 'value' => 'merchant@strike.me'],
        ]);
        fail('no-LUD-21 @strike.me address must be refused');
    } catch (RuntimeException $e) {
        assert_true(strpos($e->getMessage(), 'use the Strike API option instead') !== false,
            'refusal points at the Strike API option');
        assert_true(strpos($e->getMessage(), 'dashboard.strike.me') !== false,
            'refusal names the dashboard');
    }
    // A non-Strike address gets the generic refusal, no Strike hint.
    try {
        StoreLnAddresses::probeAndGateChain($storeC, [
            ['type' => 'lnaddress', 'value' => 'merchant@other-wallet.com'],
        ]);
        fail('no-LUD-21 address must be refused');
    } catch (RuntimeException $e) {
        assert_false(strpos($e->getMessage(), 'Strike API option') !== false,
            'generic refusal has no Strike hint');
    }
} finally {
    putenv('CASHU_LNURL_URL_TEMPLATE');
    @posix_kill($lnurlPid, 9);
}

echo "test_strike_destinations: ok\n";
