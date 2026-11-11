<?php
/**
 * End-to-end routing test for Invoice::create with LNURL direct-receive.
 *
 * Driven by a single mock LNURL host (the same well-known/callback shape used
 * elsewhere) plus an in-memory store fixture. Verifies:
 *
 *   1. With auto_melt_enabled=1, valid LN address, and LUD-21 callback,
 *      an invoice gets payment_rail='lnaddress' and the verify URL saved.
 *
 *   2. With auto_melt_enabled=0, LNURL direct-receive STILL engages — a
 *      configured destination is used at invoice creation regardless of the
 *      auto-cashout toggle (which only gates the cron threshold-melt).
 *
 *   3. When pre-existing settled invoices accumulate a fee owed >= the next
 *      invoice amount, the fee-redirect path engages: the whole invoice is
 *      pointed at the fee (here the dev fee LNURL, resolved through the mock
 *      host) and the resulting invoice records fee_redirect_note + the
 *      LUD-21 verify URL. (This is the ONLY invoice-time fee mechanism; the
 *      old fees-due mint-override gate was removed.)
 *
 *   4. When the mock host returns no verify URL, the LNURL probe fails
 *      and routing falls back transparently.
 *
 * The mint and on-chain paths are not exercised here — we sidestep them
 * by NOT configuring a mint or xpub on the store. Invoice::create still
 * raises an error if neither rail is configured, so for scenarios 2-4
 * we set a mint_url so the mint-side requestMintQuote IS attempted.
 * To avoid a network call we point the mint at a non-routable host;
 * the mint quote will fail and we'll get an exception, but the test
 * verifies the routing-decision artifacts written to the invoices table
 * BEFORE that failure. (For scenarios 2-4 we accept that Invoice::create
 * throws; we just need to confirm the LNURL probe was or wasn't called.)
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lnurl_receive.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

// Anchor fee tracking to the start so test-inserted "paid" invoices count.
Config::set('fee_tracking_start_at', 0);

$port = random_int(40000, 49999);
$serverDir = sys_get_temp_dir() . '/lnurl_routing_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

/**
 * Mock LNURL host. $mode controls whether the callback advertises a verify
 * URL ('lud21') or omits it ('no_verify'). The router logs every callback
 * request to $serverDir/calls.log so the test can assert whether the probe
 * was attempted.
 */
function start_routing_mock(int $port, string $serverDir, string $mode): int {
    $router = <<<PHP
<?php
\$path = \$_SERVER['REQUEST_URI'] ?? '/';
\$qpos = strpos(\$path, '?');
\$rawPath = \$qpos === false ? \$path : substr(\$path, 0, \$qpos);
if (strpos(\$rawPath, '/.well-known/lnurlp/') === 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'callback' => "http://127.0.0.1:$port/cb",
        'minSendable' => 1000, 'maxSendable' => 100000000,
        'metadata' => '[["text/plain","routing test"]]', 'tag' => 'payRequest',
    ]);
    return;
}
if (\$rawPath === '/cb') {
    file_put_contents('$serverDir/calls.log', date('c') . "\n", FILE_APPEND);
    header('Content-Type: application/json');
    \$resp = ['pr' => 'lnbc1pretendinvoice'];
    if ('$mode' === 'lud21') {
        \$resp['verify'] = "http://127.0.0.1:$port/verify/" . bin2hex(random_bytes(6));
    }
    echo json_encode(\$resp);
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
    fail("routing-test lnurl server failed on port $port");
}

function callback_was_hit(string $serverDir): bool {
    return is_file($serverDir . '/calls.log') && filesize($serverDir . '/calls.log') > 0;
}

function reset_callbacks(string $serverDir): void {
    @unlink($serverDir . '/calls.log');
}

/**
 * Insert a 'Settled' invoice so DevFee::computeOwed sees it as revenue.
 * Stays under the fee-tracking-start cutoff because we set start_at=0 above.
 */
function paid_invoice(string $storeId, int $sats): void {
    Database::insert('invoices', [
        'id' => 'inv_' . bin2hex(random_bytes(4)),
        'store_id' => $storeId,
        'status' => 'Settled',
        'amount' => (string) $sats,
        'currency' => 'sat',
        'amount_sats' => $sats,
        'created_at' => time(),
        'expiration_time' => time() + 3600,
    ]);
}

// One mock host shared across scenarios; per-scenario routing differences
// come from store config and pre-existing paid invoices.
$pid = start_routing_mock($port, $serverDir, 'lud21');
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$port/.well-known/lnurlp/{user}");

// Stores need a mint_url and seed_phrase for Invoice::create's
// isStoreConfigured() check to return true. Use a non-routable host so
// any actual mint network call fails fast — but the LNURL path should win
// first in the happy scenarios so the mint is never contacted.
$mintStub = 'http://127.0.0.1:1';  // refused immediately

try {
    // ---------- Scenario 1: LNURL wins on a fresh, LUD-21-capable host ----------
    $store1 = 'store_lnurl_happy';
    make_store($store1, $mintStub);
    Database::update('stores', [
        'auto_melt_enabled' => 1,
    ], 'id = ?', [$store1]);
    StoreLnAddresses::replaceForStore($store1, ['merchant@example.test']);

    reset_callbacks($serverDir);
    $inv = Invoice::create($store1, ['amount' => 5000, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv['payment_rail'], 'rail = lnaddress on happy path');
    assert_true(!empty($inv['lnurl_verify_url']), 'verify URL recorded');
    assert_eq(null, $inv['lnurl_override_reason'], 'no override on happy path');
    assert_true(str_starts_with((string)$inv['bolt11'], 'lnbc'), 'lnurl-issued bolt11 stored');
    assert_true(callback_was_hit($serverDir), 'mock callback was probed');

    // ---------- Scenario 2: auto_melt_enabled=0 → LNURL direct-receive STILL
    // engages. Configuring a destination is enough to direct-receive to it; the
    // auto-cashout toggle only gates the cron threshold-melt of mint balance,
    // not invoice creation. (Regression guard for that decoupling.) ----------
    $store2 = 'store_no_automelt';
    make_store($store2, $mintStub);
    Database::update('stores', [
        'auto_melt_enabled' => 0,
    ], 'id = ?', [$store2]);
    StoreLnAddresses::replaceForStore($store2, ['merchant@example.test']);

    reset_callbacks($serverDir);
    $inv2 = Invoice::create($store2, ['amount' => 5000, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv2['payment_rail'],
              'rail = lnaddress even with auto_melt_enabled=0');
    assert_true(!empty($inv2['lnurl_verify_url']), 'verify URL recorded with toggle off');
    assert_true(str_starts_with((string)$inv2['bolt11'], 'lnbc'), 'lnurl-issued bolt11 stored');
    assert_true(callback_was_hit($serverDir),
                'LNURL probed even when auto_melt_enabled=0');

    // ---------- Scenario 3: fee owed >= invoice → payment redirected to fee ----------
    // Inflate revenue so a dev fee is owed in an amount larger than the next
    // invoice. Because a fee rail is reachable (the dev LNURL, resolved
    // through the mock host), the whole 5_000-sat invoice is pointed straight
    // at the dev fee instead of round-tripping through the mint. With
    // 1_000_000 sats revenue the dev fee owes 20_000 sats (2%), comfortably
    // above the invoice.
    $store3 = 'store_override_force';
    make_store($store3, $mintStub);
    Database::update('stores', [
        'auto_melt_enabled' => 1,
    ], 'id = ?', [$store3]);
    StoreLnAddresses::replaceForStore($store3, ['merchant@example.test']);
    paid_invoice($store3, 1_000_000);

    $owed = DevFee::computeOwed($store3);
    assert_true((int)$owed['dev_owed'] > 5000,
                "dev fee owed ({$owed['dev_owed']}) must exceed the upcoming invoice amount");

    reset_callbacks($serverDir);
    $inv3 = Invoice::create($store3, ['amount' => 5000, 'currency' => 'sat']);
    // The whole invoice is now a dev-fee payment (largest owed fee). It rides
    // the lnaddress rail with the fee LNURL's bolt11 + verify URL, and carries
    // the redirect tag that drives settlement accounting + the admin badge.
    assert_eq('lnaddress', $inv3['payment_rail'], 'redirect uses the lnaddress rail');
    assert_eq(FEE_NOTE_DEV, $inv3['fee_redirect_note'], 'redirected to the dev fee (largest owed)');
    assert_true(!empty($inv3['fee_redirect_destination']), 'fee destination recorded');
    assert_true(!empty($inv3['lnurl_verify_url']), 'verify URL recorded for settlement detection');
    assert_true(str_starts_with((string)$inv3['bolt11'], 'lnbc'), 'fee LNURL bolt11 stored');
    assert_eq(5000, (int)$inv3['amount_sats'], 'invoice amount unchanged');
    assert_true(callback_was_hit($serverDir), 'fee LNURL was probed for the redirect');

    // ---------- Scenario 4: LUD-21 missing on the host → silent fallback ----------
    // Restart mock in no_verify mode.
    @posix_kill($pid, 9);
    $pid = start_routing_mock($port, $serverDir, 'no_verify');
    putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$port/.well-known/lnurlp/{user}");

    $store4 = 'store_no_lud21';
    make_store($store4, $mintStub);
    Database::update('stores', [
        'auto_melt_enabled' => 1,
    ], 'id = ?', [$store4]);
    StoreLnAddresses::replaceForStore($store4, ['merchant@example.test']);

    reset_callbacks($serverDir);
    $threw = false;
    try {
        Invoice::create($store4, ['amount' => 5000, 'currency' => 'sat']);
    } catch (Throwable $e) {
        $threw = true;  // expected: probe rejects (no verify URL), mint stub fails
    }
    assert_true($threw, 'expected mint failure when LUD-21 missing and fallback also fails');
    // Probe DID hit /cb to inspect for the verify field; only after seeing
    // none does it return null. So callback hit count > 0.
    assert_true(callback_was_hit($serverDir),
                'no-LUD21: probe attempted callback, found no verify, fell back');
} finally {
    @posix_kill($pid, 9);
}

echo "test_lnurl_receive_routing: ok\n";
