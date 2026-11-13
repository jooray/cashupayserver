<?php
/**
 * "Popular wallet" tabs on the customer payment page (WalletTabs).
 *
 * A store that opted into Cash App / Venmo / PayPal tabs gets one extra tab
 * per wallet after the native methods, each repeating the right native QR
 * payload (Cash App: Lightning when offered, else on-chain; Venmo/PayPal:
 * on-chain only) under the wallet's note, with the same Pay/Copy buttons.
 * Tabs whose source the invoice lacks are not rendered; a store that never
 * opted in renders exactly as before. The brand logos of wallets with a
 * tab switch to it (data-tab) instead of opening a wallet URI.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/wallet_tabs.php';

make_store('store_plain');                       // never answered (NULL)
make_store('store_all');
Database::update('stores', ['wallet_tabs' => 'cashapp,venmo,paypal'], 'id = ?', ['store_all']);
make_store('store_venmo');
Database::update('stores', ['wallet_tabs' => 'venmo'], 'id = ?', ['store_venmo']);
make_store('store_none');
Database::update('stores', ['wallet_tabs' => ''], 'id = ?', ['store_none']);

$bolt11 = 'lnbc210u1pexamplebolt11stringforwallettabs';
$addr = 'bc1qexamplewallettabsaddr000000000000000';
function mk_invoice(string $id, string $storeId, bool $ln, bool $oc): void {
    global $bolt11, $addr;
    $row = [
        'id' => $id,
        'store_id' => $storeId,
        'status' => 'New',
        'amount' => '21000',
        'currency' => 'sat',
        'amount_sats' => 21000,
        'payment_rail' => $ln ? 'mint' : 'onchain',
        'created_at' => Database::timestamp(),
        'expiration_time' => Database::timestamp() + 3600,
    ];
    if ($ln) { $row['bolt11'] = $bolt11; }
    if ($oc) { $row['onchain_address'] = $addr; $row['onchain_amount_sat'] = 21000; }
    Database::insert('invoices', $row);
}
mk_invoice('plain_both', 'store_plain', true, true);
mk_invoice('all_both', 'store_all', true, true);
mk_invoice('all_ln', 'store_all', true, false);
mk_invoice('all_oc', 'store_all', false, true);
mk_invoice('venmo_both', 'store_venmo', true, true);
mk_invoice('venmo_oc', 'store_venmo', false, true);
mk_invoice('venmo_ln', 'store_venmo', true, false);
mk_invoice('none_both', 'store_none', true, true);

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

/** data-method values of the tab buttons, in document order. */
function tab_order(string $html): array {
    preg_match_all('/class="method-tab[^"]*" data-method="([a-z]+)"/', $html, $m);
    return $m[1];
}
/** data-method-block values, in document order. */
function block_order(string $html): array {
    preg_match_all('/data-method-block="([a-z]+)"/', $html, $m);
    return $m[1];
}
function wallet_tab(string $html, string $key): ?string {
    return preg_match('/<button type="button" class="method-tab method-tab-wallet" data-method="' . $key . '" data-source="([a-z]+)" role="tab">(.*?)<\/button>/s', $html, $m)
        ? $m[1] . '|' . $m[2] : null;
}
function block(string $html, string $key): string {
    $attr = strpos($html, 'data-method-block="' . $key . '"');
    assert_true($attr !== false, "block $key present");
    // Back up to the opening tag so the class attribute is included; the
    // block runs to the next block's attribute (or the invoice details).
    $start = strrpos(substr($html, 0, $attr), '<div class="method-block');
    $end = strpos($html, 'data-method-block=', $attr + 10);
    if ($end === false) { $end = strpos($html, '<div class="invoice-details">', $attr); }
    return substr($html, $start, $end - $start);
}

// --- store that never opted in: unchanged page -------------------------------
$html = run_payment_page('plain_both');
assert_eq(['onchain', 'lightning'], tab_order($html), 'no wallet tabs for a store that never answered');
assert_false(str_contains($html, 'class="method-tab method-tab-wallet"'), 'no wallet tab markup at all');
assert_false(str_contains($html, 'data-tab="'), 'brand logos stay plain wallet links');
assert_false(str_contains($html, 'class="method-tabs method-tabs-wallets"'), 'no second strip');
assert_true(str_contains($html, 'class="pm-item pm-no-lightning"'), 'Venmo/PayPal logos keep hiding on the Lightning view');

// --- explicit "none" renders the same ------------------------------------------
$html = run_payment_page('none_both');
assert_eq(['onchain', 'lightning'], tab_order($html), "'' = no wallet tabs");
assert_false(str_contains($html, 'class="method-tab method-tab-wallet"'), "'' renders no wallet tab markup");

// --- all three enabled, both rails: 5 tabs, wallets on their own strip -------
$html = run_payment_page('all_both');
assert_eq(['onchain', 'lightning', 'cashapp', 'venmo', 'paypal'], tab_order($html), 'wallet tabs follow the native tabs in canonical order');
assert_true(str_contains($html, 'class="method-tab active" data-method="onchain"'), 'the native default is still the active tab');
assert_true(str_contains($html, '<div class="method-tabs method-tabs-wallets" role="tablist"'), 'five tabs: wallet tabs move onto their own strip');
$ca = wallet_tab($html, 'cashapp');
assert_not_null($ca, 'Cash App tab button');
assert_true(str_starts_with($ca, 'lightning|'), 'Cash App sources the Lightning QR when both rails are offered');
assert_true(str_contains($ca, 'payment-methods/cashapp.svg'), 'Cash App tab shows the brand-row logo');
assert_true(str_contains($ca, '<span class="method-tab-label">Cash App</span>'), 'Cash App tab label');
assert_true(str_starts_with((string)wallet_tab($html, 'venmo'), 'onchain|'), 'Venmo sources on-chain');
assert_true(str_starts_with((string)wallet_tab($html, 'paypal'), 'onchain|'), 'PayPal sources on-chain');
assert_true(str_contains((string)wallet_tab($html, 'venmo'), 'class="method-tab-logo pm-card"'), 'Venmo wordmark gets the white card');

// Blocks: all hidden on load, right payload, note, buttons.
assert_eq(['lightning', 'onchain', 'cashapp', 'venmo', 'paypal'], block_order($html), 'one block per tab');
$caBlock = block($html, 'cashapp');
assert_true(str_contains($caBlock, 'class="method-block hidden" data-method-block="cashapp" data-source="lightning"'), 'Cash App block starts hidden, lightning-sourced');
assert_true(str_contains($caBlock, 'id="qr-cashapp"'), 'Cash App QR container');
assert_true(str_contains($caBlock, 'Go to the QR code icon in Cash App or tap &quot;Pay with Cash App&quot;.'), 'Cash App note');
assert_true(str_contains($caBlock, 'href="lightning:' . strtoupper($bolt11) . '"'), 'Cash App Pay button opens the lightning: URI (upper-case, as the QR encodes it)');
assert_false(str_contains($caBlock, 'href="lightning:' . $bolt11 . '"'), 'no lower-case lightning: href survives');
assert_true(str_contains($caBlock, 'data-copy="' . $bolt11 . '"'), 'Cash App copies the bolt11');
assert_true(str_contains($caBlock, 'Copy Invoice'), 'Cash App copy button wording');
assert_true(str_contains($caBlock, 'Pay with Cash App'), 'the Cash App tab names its open button after the app');
assert_false(str_contains($caBlock, 'Pay with BTC wallet'), 'and not the generic label');
$veBlock = block($html, 'venmo');
assert_true(str_contains($veBlock, 'data-method-block="venmo" data-source="onchain"'), 'Venmo block on-chain-sourced');
assert_true(str_contains($veBlock, 'In Venmo, go to Crypto → Bitcoin → Transfer (arrow icons) → Send.'), 'Venmo note path');
assert_true(str_contains($veBlock, 'Venmo charges additional fees'), 'Venmo fee note');
assert_true(str_contains($veBlock, 'href="bitcoin:' . $addr . '?amount=0.00021"'), 'Venmo Pay button opens the BIP-21 URI');
assert_true(str_contains($veBlock, 'Copy Address'), 'Venmo copy button wording');
assert_true(str_contains($veBlock, 'Pay with BTC wallet'), 'Venmo keeps the generic open button');
assert_true(str_contains($veBlock, '21,000 sat'), 'Venmo shows the on-chain amount');
$ppBlock = block($html, 'paypal');
assert_true(str_contains($ppBlock, 'In PayPal app, go to Finances → Crypto → Transfer arrows.'), 'PayPal note');
assert_false(str_contains($ppBlock, 'charges additional fees'), 'PayPal note has no fee sentence');
assert_true(str_contains($ppBlock, 'id="qr-paypal"'), 'PayPal QR container');

// Brand row: wallets with a tab switch to it; others unchanged.
assert_true(str_contains($html, 'class="pm-item pm-has-tab" data-tab="cashapp" role="button" tabindex="0" aria-label="Show the Cash App tab"'), 'Cash App logo switches to its tab');
assert_true(str_contains($html, 'class="pm-item pm-no-lightning pm-has-tab" data-tab="venmo"'), 'Venmo logo switches to its tab and is no longer hidden on the Lightning view');
assert_true(str_contains($html, 'class="pm-item pm-no-lightning pm-has-tab" data-tab="paypal"'), 'PayPal logo switches to its tab');
assert_true(str_contains($html, 'aria-label="Pay with BTC wallet (Strike)"'), 'Strike logo stays a wallet link');
assert_true(str_contains($html, 'aria-label="Pay with BTC wallet (Coinbase)"'), 'Coinbase logo stays a wallet link');

// --- all three enabled, Lightning only: only Cash App survives ---------------
$html = run_payment_page('all_ln');
assert_eq(['lightning', 'cashapp'], tab_order($html), 'Lightning-only: Venmo/PayPal have nothing to scan');
assert_false(str_contains($html, 'class="method-tabs method-tabs-wallets"'), 'two tabs share one strip');
assert_true(str_starts_with((string)wallet_tab($html, 'cashapp'), 'lightning|'), 'Cash App on Lightning');
assert_true(str_contains($html, 'class="pm-item pm-no-lightning"'), 'Venmo/PayPal logos without a tab still hide on the Lightning view');

// --- all three enabled, on-chain only: Cash App falls back to on-chain -------
$html = run_payment_page('all_oc');
assert_eq(['onchain', 'cashapp', 'venmo', 'paypal'], tab_order($html), 'on-chain only: all three wallet tabs');
assert_true(str_contains($html, 'class="method-tabs method-tabs-wallets"'), 'four tabs: wallets on their own strip');
assert_true(str_starts_with((string)wallet_tab($html, 'cashapp'), 'onchain|'), 'Cash App falls back to the on-chain QR');
$caBlock = block($html, 'cashapp');
assert_true(str_contains($caBlock, 'data-source="onchain"'), 'Cash App block on-chain-sourced');
assert_true(str_contains($caBlock, 'Go to the QR code icon in Cash App or tap &quot;Pay with Cash App&quot;.'), 'Cash App note shows on the on-chain fallback too');
assert_true(str_contains($caBlock, 'Pay with Cash App'), 'Cash App button label on the on-chain fallback too');
assert_true(str_contains($caBlock, 'href="bitcoin:' . $addr), 'Cash App Pay button opens the BIP-21 URI');
assert_true(str_contains($caBlock, 'Copy Address'), 'Cash App copy wording follows the source');

// --- only Venmo enabled ------------------------------------------------------
$html = run_payment_page('venmo_both');
assert_eq(['onchain', 'lightning', 'venmo'], tab_order($html), 'only the enabled wallet gets a tab');
assert_false(str_contains($html, 'class="method-tabs method-tabs-wallets"'), 'three tabs share one strip');
assert_false(str_contains($html, 'data-tab="cashapp"'), 'Cash App logo (no tab) stays a wallet link');
assert_true(str_contains($html, 'aria-label="Pay with BTC wallet (Cash App)"'), 'Cash App logo stays a wallet link');
assert_true(str_contains($html, 'data-tab="venmo"'), 'Venmo logo switches to its tab');
assert_false(str_contains($html, 'data-tab="paypal"'), 'PayPal logo stays a plain link');

// A Venmo tab on an on-chain-only invoice is what makes a tab bar appear.
$html = run_payment_page('venmo_oc');
assert_eq(['onchain', 'venmo'], tab_order($html), 'on-chain + Venmo: two tabs');
assert_true(str_contains($html, 'role="tablist"'), 'a wallet tab counts towards the tab bar');
assert_true(str_contains($html, 'class="method-tab active" data-method="onchain"'), 'on-chain is still the active tab');

// A Venmo-only store on a Lightning-only invoice: no wallet tab, no tab bar.
$html = run_payment_page('venmo_ln');
assert_eq([], tab_order($html), 'Venmo cannot pay a Lightning-only invoice');
assert_false(str_contains($html, 'role="tablist"'), 'single method renders no tab bar');
assert_false(str_contains($html, 'data-method-block="venmo"'), 'no Venmo block either');

echo "test_payment_page_wallet_tabs: ok\n";
