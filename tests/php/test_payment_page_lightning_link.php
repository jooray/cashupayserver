<?php
/**
 * Payment page: every open-in-wallet link carries the same lightning: URI the
 * QR encodes (upper-case bolt11), with no lower-case lightning: href left.
 *
 * Cash App users reported "transaction declined" when tapping the button
 * while scanning the QR for the same invoice paid fine: the QR encoded the
 * upper-case invoice, the button the lower-case one. Covers the native
 * Lightning block, the brand-row data-href-* attributes (Lightning and
 * Cashu views) and the initial brand logo hrefs. The copy targets and the
 * invoice text keep the invoice as issued.
 *
 * Cash App itself is the exception: its tab button and its brand-row logo
 * open the app's universal link (Urls::cashAppLightningUri) with the same
 * upper-case invoice, because a tapped lightning: link is routed by the OS
 * and did not reliably reach Cash App even once the case matched. Its QR
 * keeps the lightning: URI the scanner is known to accept.
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
$cashApp = 'https://cash.app/launch/lightning/' . $upper;
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
    assert_true(str_contains($html, 'data-href-cashapp="' . $cashApp . '"'), "$id: brand row carries Cash App's universal link");
    assert_false(str_contains($html, 'https://cash.app/launch/lightning/' . $bolt11), "$id: the universal link never carries the lower-case invoice");

    // The native button is tagged so the long-lived refresh rewrites it.
    assert_true(str_contains($html, 'href="lightning:' . $upper . '" class="btn" data-ln-link="lightning"'), "$id: native Lightning button is a lightning:-kind link");
    // The JS twin builds the universal link from the same base.
    assert_true(str_contains($html, "return \"https:\\/\\/cash.app\\/launch\\/lightning\\/\" + String(bolt11).toUpperCase();"), "$id: JS helper builds the Cash App link from the shared base");

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

// The Cash App tab's own button opens the universal link; its QR keeps the
// lightning: URI (renderWalletQRs encodes lightningUri(invoice) for every
// lightning-sourced tab).
$html = run_payment_page('inv_tabs');
assert_true(preg_match('/data-method-block="cashapp"[\s\S]*?<a href="([^"]*)" class="btn"( data-ln-link="[a-z]+")?>/', $html, $m) === 1, 'Cash App tab has an open button');
assert_eq($cashApp, $m[1], 'Cash App tab button opens the universal link with the upper-case invoice');
assert_eq(' data-ln-link="cashapp"', $m[2] ?? '', 'Cash App tab button is a cashapp-kind link (rewritten on refresh)');
assert_true(str_contains($html, "renderQR('qr-' + method, lightningUri(invoice));"), 'wallet-tab QRs still encode the lightning: URI');
// With the tab shown the Cash App logo switches to the tab and has no href.
assert_true(str_contains($html, 'class="pm-item pm-has-tab" data-tab="cashapp"'), 'Cash App logo switches to its tab');
assert_false(str_contains($html, '<a class="pm-item" data-wallet="cashapp"'), 'no plain Cash App logo link alongside the tab');

// Without a Cash App tab the brand-row logo is a link straight into the app.
$html = run_payment_page('inv_plain');
assert_true(preg_match('/<a class="pm-item" data-wallet="cashapp" href="([^"]*)" aria-label="Pay with Cash App">/', $html, $m) === 1, 'Cash App logo is a wallet link');
assert_eq($cashApp, $m[1], 'Cash App logo opens the universal link on the Lightning view');
// Every other brand keeps the generic lightning: link.
preg_match_all('/<a class="pm-item[^"]*"(?! data-wallet) href="([^"]*)" aria-label="Pay with BTC wallet \(([^)]*)\)">/', $html, $mm, PREG_SET_ORDER);
assert_true(count($mm) >= 3, 'other brand logos rendered as generic links');
foreach ($mm as $row) {
    assert_eq('lightning:' . $upper, $row[1], "{$row[2]} logo keeps the lightning: URI");
}

echo "test_payment_page_lightning_link: all assertions passed\n";
