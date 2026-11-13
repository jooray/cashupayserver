<?php
/**
 * Payment page: every open-in-wallet link carries the same lightning: URI the
 * QR encodes (upper-case bolt11), with no lower-case lightning: href left.
 *
 * Cash App users reported "transaction declined" when tapping the button
 * while scanning the QR for the same invoice paid fine: the QR encoded the
 * upper-case invoice, the button the lower-case one. Covers the native
 * Lightning block, the brand-row data-href-* attributes (Lightning and
 * Cashu views), the initial brand logo hrefs, and the Cash App wallet tab.
 * The copy targets and the invoice text keep the invoice as issued.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

make_store('store_tabs');
Database::update('stores', ['wallet_tabs' => 'cashapp'], 'id = ?', ['store_tabs']);
make_store('store_plain');

$bolt11 = 'lnbc210u1pexamplebolt11stringqpzry9x8gf2tvdw0s3jn54khce6mua7l';
$upper = strtoupper($bolt11);
foreach (['inv_tabs' => 'store_tabs', 'inv_plain' => 'store_plain'] as $id => $storeId) {
    Database::insert('invoices', [
        'id' => $id,
        'store_id' => $storeId,
        'status' => 'New',
        'amount' => '21000',
        'currency' => 'sat',
        'amount_sats' => 21000,
        'payment_rail' => 'mint',
        'bolt11' => $bolt11,
        'created_at' => Database::timestamp(),
        'expiration_time' => Database::timestamp() + 3600,
    ]);
}

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/payment_runner.php';
file_put_contents($runner, sprintf(<<<'PHP2'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = getenv('T_INVOICE');
require %s;
PHP2, var_export($root . '/payment.php', true)));

function run_payment_page(string $invoiceId): string {
    global $dataDir, $runner;
    $env = sprintf('T_DATA_DIR=%s T_INVOICE=%s', escapeshellarg($dataDir), escapeshellarg($invoiceId));
    return (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');
}

/** Every href="lightning:..." value in the markup. */
function lightning_hrefs(string $html): array {
    preg_match_all('/href="(lightning:[^"]*)"/', $html, $m);
    return $m[1];
}

foreach (['inv_plain', 'inv_tabs'] as $id) {
    $html = run_payment_page($id);
    assert_true(str_contains($html, 'id="qr-lightning"'), "$id: page rendered the Lightning block");

    $hrefs = lightning_hrefs($html);
    assert_true(count($hrefs) >= 1, "$id: at least one lightning: href");
    foreach ($hrefs as $h) {
        assert_eq('lightning:' . $upper, $h, "$id: every lightning: href is the upper-case URI");
    }
    assert_false(str_contains($html, 'lightning:' . $bolt11), "$id: the lower-case lightning: URI appears nowhere in the markup");

    // The brand row's per-view links (Lightning and Cashu views reuse it).
    assert_true(str_contains($html, 'data-href-lightning="lightning:' . $upper . '"'), "$id: brand row Lightning href");
    assert_true(str_contains($html, 'data-href-cashu="lightning:' . $upper . '"'), "$id: brand row Cashu href");

    // The QR is rendered client-side from the same upper-cased value; the
    // page ships the helper both QR and hrefs go through.
    assert_true(str_contains($html, "renderQR('qr-lightning', lightningUri(invoice));"), "$id: Lightning QR encodes lightningUri(invoice)");
    assert_true(str_contains($html, "return 'lightning:' + String(bolt11).toUpperCase();"), "$id: JS helper upper-cases");
    assert_false(str_contains($html, "'lightning:' + invoice"), "$id: no ad-hoc lower-case lightning: concatenation left in the JS");

    // Copy targets and the shown invoice keep the invoice as issued.
    assert_true(str_contains($html, 'data-copy="' . $bolt11 . '"'), "$id: copy button hands over the bolt11 as issued");
    assert_false(str_contains($html, 'data-copy="' . $upper . '"'), "$id: copy target is not upper-cased");
    assert_true(str_contains($html, htmlspecialchars(substr($bolt11, 0, 40) . '...' . substr($bolt11, -10))), "$id: the shortened invoice text is shown as issued");
}

// The Cash App tab's own button carries the same URI as the native one.
$html = run_payment_page('inv_tabs');
assert_true(preg_match('/data-method-block="cashapp"[\s\S]*?<a href="(lightning:[^"]*)" class="btn">/', $html, $m) === 1, 'Cash App tab has an open button');
assert_eq('lightning:' . $upper, $m[1], 'Cash App tab button opens the upper-case lightning: URI');

echo "test_payment_page_lightning_link: all assertions passed\n";
