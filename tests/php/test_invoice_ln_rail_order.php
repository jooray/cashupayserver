<?php
/**
 * The configurable Lightning type order through Invoice::create:
 *
 *   1. Baseline: a store with a Strike key + an LNURL address and NO explicit
 *      order uses the default chain — the Strike rail wins.
 *   2. With ln_rail_order = lnaddress-first, the SAME store serves the next
 *      invoice from the LNURL destination, and the Strike API is never
 *      contacted for it.
 *   3. Restoring strike-first puts the next invoice back on the Strike rail.
 *   4. The order still only picks among WORKING destinations: lnaddress-first
 *      with the LNURL host down falls through to Strike, recording the lnurl
 *      failure in receive_errors.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rail_order.php';

$KEY = 'LNORDERKEY0' . str_repeat('E', 29);

// Mock LNURL host WITH LUD-21 verify (same shape as test_invoice_strike_receive).
$lnurlDir = sys_get_temp_dir() . '/rail_order_lnurl_' . bin2hex(random_bytes(4));
mkdir($lnurlDir, 0750, true);
$router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
if (strpos($path, '/.well-known/lnurlp/') === 0) {
    echo json_encode([
        'callback' => $base . '/callback',
        'minSendable' => 1000,
        'maxSendable' => 100000000000,
        'tag' => 'payRequest',
    ]);
    return;
}
if (strpos($path, '/callback') === 0) {
    echo json_encode(['pr' => 'lnbc1mockorderlnurl', 'verify' => $base . '/verify/x']);
    return;
}
if (strpos($path, '/verify') === 0) {
    echo json_encode(['status' => 'OK', 'settled' => false, 'preimage' => null]);
    return;
}
http_response_code(404);
PHP;
file_put_contents($lnurlDir . '/router.php', $router);
$lnurlPort = 27900 + (getmypid() % 700);
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

[$pid, $port, $dir] = start_strike_mock($KEY);
putenv("CASHUPAY_STRIKE_API_BASE=http://127.0.0.1:{$port}/v1");

try {
    $store = 'store_ln_order';
    make_store($store, 'http://127.0.0.1:1'); // dead mint — must never be needed
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'strike', 'address' => $KEY],
        ['type' => 'lnaddress', 'address' => 'merchant@order-wallet.test', 'supports_verify' => 1],
    ]);

    // ---------- 1. default order: strike leads ----------
    $inv = Invoice::create($store, ['amount' => 100, 'currency' => 'sat']);
    assert_eq('strike', $inv['payment_rail'], 'default order picks the Strike rail');
    assert_true(str_starts_with((string)$inv['bolt11'], 'lnbcmock'), 'bolt11 from the Strike quote');

    // ---------- 2. lnaddress-first order flips the rail ----------
    RailOrder::setLnOrder($store, 'lnaddress,nwc,noffer,strike');
    $createsBefore = count(strike_mock_invoices($dir));
    $inv2 = Invoice::create($store, ['amount' => 100, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv2['payment_rail'], 'lnaddress-first order picks the LNURL rail');
    assert_eq('lnbc1mockorderlnurl', $inv2['bolt11'], 'bolt11 from the LNURL host');
    assert_eq($createsBefore, count(strike_mock_invoices($dir)),
        'Strike was never contacted when a higher-priority rail worked');
    $row2 = Database::fetchOne("SELECT receive_errors, ln_destination FROM invoices WHERE id = ?", [$inv2['id']]);
    assert_null($row2['receive_errors'], 'first-try success on the reordered chain');
    assert_eq('merchant@order-wallet.test', $row2['ln_destination'], 'destination is the address');

    // ---------- 3. restoring strike-first flips it back ----------
    RailOrder::setLnOrder($store, 'strike,lnaddress,nwc,noffer');
    $inv3 = Invoice::create($store, ['amount' => 100, 'currency' => 'sat']);
    assert_eq('strike', $inv3['payment_rail'], 'restored order picks Strike again');

    // ---------- 4. order picks among WORKING rails only ----------
    RailOrder::setLnOrder($store, 'lnaddress,nwc,noffer,strike');
    // Dead LNURL host: point the template at a closed port.
    putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:1/.well-known/lnurlp/{user}');
    $inv4 = Invoice::create($store, ['amount' => 100, 'currency' => 'sat']);
    assert_eq('strike', $inv4['payment_rail'], 'lnaddress failure falls through to Strike');
    $row4 = Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv4['id']]);
    $errors = json_decode((string)$row4['receive_errors'], true) ?: [];
    assert_eq(1, count($errors), 'one failure recorded');
    assert_eq('lnurl', $errors[0]['type'], 'the failed higher-priority rail is named');
} finally {
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
    putenv('CASHU_LNURL_URL_TEMPLATE');
    @posix_kill($lnurlPid, 9);
}

echo "test_invoice_ln_rail_order: ok\n";
