<?php
/**
 * The Strike receive rail through Invoice::create and settlement:
 *
 *   1. A store with a Strike key + an LNURL fallback: the Strike rail wins
 *      (payment_rail='strike'), the bolt11 comes from the Strike quote, the
 *      strike_invoice_id/strike_api_key columns are set, ln_destination is
 *      the masked label, and the create body carried our invoice id as the
 *      correlationId. formatForApi never leaks the key.
 *   2. Settlement: pollSingleStrike is a no-op while Strike reports UNPAID,
 *      settles the invoice (status=Settled, settled_rail='strike') once
 *      Strike reports PAID, and is idempotent after that.
 *   3. Fallthrough: with the Strike API failing, the chain walks on to the
 *      LNURL destination (payment_rail='lnaddress') and receive_errors
 *      carries a sanitized {type:'strike'} entry with no key material; the
 *      admin event log gets the masked label.
 *   4. Cron batch: pollPendingStrike settles a pending strike invoice.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_strike_api.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$KEY = 'INVKEY00' . str_repeat('F', 32);

// Mock LNURL host WITH LUD-21 verify, as the fallback destination.
$lnurlDir = sys_get_temp_dir() . '/strike_inv_lnurl_' . bin2hex(random_bytes(4));
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
    echo json_encode(['pr' => 'lnbc1mocklnurlfallback', 'verify' => $base . '/verify/x']);
    return;
}
if (strpos($path, '/verify') === 0) {
    echo json_encode(['status' => 'OK', 'settled' => false, 'preimage' => null]);
    return;
}
http_response_code(404);
PHP;
file_put_contents($lnurlDir . '/router.php', $router);
$lnurlPort = 27200 + (getmypid() % 700);
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
    $store = 'store_strike_recv';
    make_store($store, 'http://127.0.0.1:1'); // dead mint — must never be needed
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'strike', 'address' => $KEY],
        ['type' => 'lnaddress', 'address' => 'merchant@fallback-wallet.test', 'supports_verify' => 1],
    ]);

    // ---------- 1. strike rail wins ----------
    $inv = Invoice::create($store, [
        'amount' => 100, 'currency' => 'sat',
        'metadata' => ['orderId' => '42', 'itemDesc' => 'Test order'],
    ]);
    assert_eq('strike', $inv['payment_rail'], 'strike rail selected');
    assert_true(str_starts_with((string)$inv['bolt11'], 'lnbcmock'), 'bolt11 from the Strike quote');

    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$inv['id']]);
    assert_not_null($row['strike_invoice_id'], 'strike_invoice_id persisted');
    assert_eq($KEY, $row['strike_api_key'], 'key persisted for settlement polls');
    assert_eq('Strike API (…' . substr($KEY, -4) . ')', $row['ln_destination'],
        'ln_destination is the masked label');
    assert_null($row['receive_errors'], 'first-try success leaves receive_errors NULL');

    // The Strike invoice carries our invoice id for reconciliation, and the
    // memo (store name - order - note).
    $captured = strike_mock_invoices($dir);
    $req = $captured[$row['strike_invoice_id']] ?? null;
    assert_not_null($req, 'mock captured the create');
    assert_eq($inv['id'], $req['correlationId'], 'correlationId is our invoice id');
    assert_true(strpos((string)($req['description'] ?? ''), 'Order 42') !== false,
        'memo carries the order reference');
    assert_eq('0.00000100', $req['amount']['amount'], 'BTC amount is the exact sat value');

    // API shape: the key never leaves the server; the masked label does, and
    // Strike's invoice id is exposed for dashboard reconciliation.
    $api = Invoice::formatForApi($row);
    $apiJson = json_encode($api, JSON_UNESCAPED_UNICODE);
    assert_false(strpos($apiJson, $KEY) !== false, 'formatForApi never contains the key');
    assert_true(strpos($apiJson, 'Strike API (…') !== false, 'formatForApi shows the masked destination');
    assert_eq($row['strike_invoice_id'], $api['strikeInvoiceId'] ?? null,
        'formatForApi exposes the Strike invoice id');

    // ---------- 2. settlement ----------
    Invoice::pollSingleStrike((string)$inv['id']);
    $row = Database::fetchOne("SELECT status FROM invoices WHERE id = ?", [$inv['id']]);
    assert_eq('New', $row['status'], 'UNPAID leaves the invoice pending');

    file_put_contents($dir . '/state', 'PAID');
    // Clear the single-poll min-interval gate the first poll just claimed.
    Database::update('invoices', ['last_polled_at' => time() - 60], 'id = ?', [$inv['id']]);
    Invoice::pollSingleStrike((string)$inv['id']);
    $row = Database::fetchOne("SELECT status, settled_rail, paid_at FROM invoices WHERE id = ?", [$inv['id']]);
    assert_eq('Settled', $row['status'], 'PAID settles the invoice');
    assert_eq('strike', $row['settled_rail'], 'settled_rail records strike');
    assert_true((int)$row['paid_at'] > 0, 'paid_at stamped');

    // Idempotent re-poll.
    Database::update('invoices', ['last_polled_at' => time() - 60], 'id = ?', [$inv['id']]);
    Invoice::pollSingleStrike((string)$inv['id']);
    $again = Database::fetchOne("SELECT status, paid_at FROM invoices WHERE id = ?", [$inv['id']]);
    assert_eq($row['paid_at'], $again['paid_at'], 're-poll does not restamp');

    // The settle fired exactly one InvoiceSettled webhook enqueue (outbox).
    file_put_contents($dir . '/state', 'UNPAID');

    // ---------- 3. fallthrough to LNURL on Strike failure ----------
    file_put_contents($dir . '/fail_create', '500');
    $inv2 = Invoice::create($store, ['amount' => 100, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv2['payment_rail'], 'strike failure falls through to LNURL');
    assert_eq('lnbc1mocklnurlfallback', $inv2['bolt11'], 'fallback bolt11 from the LNURL host');
    $row2 = Database::fetchOne("SELECT receive_errors, strike_api_key FROM invoices WHERE id = ?", [$inv2['id']]);
    assert_null($row2['strike_api_key'], 'no strike context on a non-strike rail');
    $errors = json_decode((string)$row2['receive_errors'], true);
    assert_eq(1, count($errors), 'one strike failure recorded');
    assert_eq('strike', $errors[0]['type'], 'entry names the strike type');
    assert_eq('the Strike API reported a server error', $errors[0]['reason'], 'sanitized phrase');
    assert_false(strpos((string)$row2['receive_errors'], $KEY) !== false,
        'receive_errors never contains the key');

    $lr = Database::fetchOne(
        "SELECT * FROM admin_event_log WHERE category = 'strike' AND context = 'checkout' AND store_id = ? ORDER BY id DESC LIMIT 1",
        [$store]
    );
    assert_not_null($lr, 'strike failure logged for the admin');
    assert_true(str_starts_with((string)$lr['label'], 'Strike API (…'), 'admin log label is masked');
    assert_false(strpos($lr['label'] . ' ' . $lr['message'], $KEY) !== false,
        'admin log never contains the key');
    unlink($dir . '/fail_create');

    // ---------- 4. cron batch poll ----------
    $inv3 = Invoice::create($store, ['amount' => 100, 'currency' => 'sat']);
    assert_eq('strike', $inv3['payment_rail'], 'third invoice back on the strike rail');
    file_put_contents($dir . '/state', 'PAID');
    Invoice::pollPendingStrike(0, 10);
    $row3 = Database::fetchOne("SELECT status, settled_rail FROM invoices WHERE id = ?", [$inv3['id']]);
    assert_eq('Settled', $row3['status'], 'cron batch settles the pending strike invoice');
    assert_eq('strike', $row3['settled_rail'], 'cron settle records the rail');
} finally {
    stop_strike_mock($pid);
    putenv('CASHUPAY_STRIKE_API_BASE');
    putenv('CASHU_LNURL_URL_TEMPLATE');
    @posix_kill($lnurlPid, 9);
}

echo "test_invoice_strike_receive: ok\n";
