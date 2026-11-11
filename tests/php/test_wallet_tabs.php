<?php
/**
 * WalletTabs: the per-store "Popular wallet tabs" setting (Cash App / Venmo /
 * PayPal tabs on the customer payment page).
 *
 * Covers the stored-value codec (stores.wallet_tabs is a comma-separated
 * list in canonical order; NULL = never answered, '' = explicitly none), the
 * source rule (Cash App prefers Lightning and falls back to on-chain; Venmo
 * and PayPal are on-chain only) and the per-invoice offer (a wallet whose
 * source the invoice lacks is not rendered).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/wallet_tabs.php';

// --- Keys ------------------------------------------------------------------
assert_eq(['cashapp', 'venmo', 'paypal'], WalletTabs::ALL, 'three wallets, in tab order');
foreach (WalletTabs::ALL as $k) {
    assert_true(WalletTabs::isKnown($k), "$k is a known wallet");
    assert_true(isset(WalletTabs::BRANDS[$k]), "$k has brand metadata");
    foreach (['label', 'file', 'class', 'sources', 'note', 'button'] as $field) {
        assert_true(array_key_exists($field, WalletTabs::BRANDS[$k]), "$k brand carries $field");
    }
    assert_true(is_file(dirname(__DIR__, 2) . '/app/images/payment-methods/' . WalletTabs::BRANDS[$k]['file']),
        "$k logo exists under images/payment-methods/ (the brand row's own artwork)");
}
assert_false(WalletTabs::isKnown('strike'), 'brands without a tab are not wallets');
assert_false(WalletTabs::isKnown('CashApp'), 'keys are matched exactly');
assert_false(WalletTabs::isKnown(''), 'blank is not a wallet');

// The notes are the operator-approved copy.
assert_eq('Go to the QR code icon in Cash App or tap "Pay with Cash App".', WalletTabs::BRANDS['cashapp']['note'], 'Cash App note');
assert_eq('Pay with Cash App', WalletTabs::BRANDS['cashapp']['button'], 'Cash App names its open button');
assert_eq('Pay with BTC wallet', WalletTabs::BRANDS['venmo']['button'], 'Venmo keeps the generic button');
assert_eq('Pay with BTC wallet', WalletTabs::BRANDS['paypal']['button'], 'PayPal keeps the generic button');
assert_true(str_starts_with(WalletTabs::BRANDS['venmo']['note'], 'In Venmo, go to Crypto → Bitcoin → Transfer (arrow icons) → Send.'), 'Venmo note path');
assert_true(str_contains(WalletTabs::BRANDS['venmo']['note'], 'Venmo charges additional fees'), 'Venmo note fee warning');
assert_eq('In PayPal app, go to Finances → Crypto → Transfer arrows.', WalletTabs::BRANDS['paypal']['note'], 'PayPal note (no fee sentence)');

// --- parse / serialize ------------------------------------------------------
assert_eq([], WalletTabs::parse(null), 'NULL parses to none');
assert_eq([], WalletTabs::parse(''), 'empty string parses to none');
assert_eq([], WalletTabs::parse(' , '), 'blank tokens parse to none');
assert_eq(['cashapp'], WalletTabs::parse('cashapp'), 'single key');
assert_eq(['cashapp', 'venmo', 'paypal'], WalletTabs::parse('paypal,cashapp,venmo'), 'parse returns canonical order regardless of stored order');
assert_eq(['venmo', 'paypal'], WalletTabs::parse(' Venmo , PAYPAL '), 'parse trims and lower-cases');
assert_eq(['cashapp'], WalletTabs::parse('cashapp,cashapp'), 'duplicates collapse');
assert_eq(['venmo'], WalletTabs::parse('venmo,strike,bogus'), 'unknown tokens are dropped, never rendered');

assert_eq('', WalletTabs::serialize([]), 'serialize none -> empty string (explicit "none")');
assert_eq('cashapp,venmo,paypal', WalletTabs::serialize(['paypal', 'venmo', 'cashapp']), 'serialize writes canonical order');
assert_eq('cashapp,paypal', WalletTabs::serialize(['paypal', 'CashApp ', 'cashapp', 'nope']), 'serialize dedupes, normalises and drops unknowns');
foreach (['', 'cashapp', 'venmo,paypal', 'cashapp,venmo,paypal'] as $v) {
    assert_eq($v, WalletTabs::serialize(WalletTabs::parse($v)), "round trip: '$v'");
}

// --- enabled / answered on a store row --------------------------------------
assert_eq([], WalletTabs::enabled([]), 'a row without the column has no tabs');
assert_eq([], WalletTabs::enabled(['wallet_tabs' => null]), 'NULL = no tabs (pre-setting rows unchanged)');
assert_eq([], WalletTabs::enabled(['wallet_tabs' => '']), "'' = no tabs");
assert_eq(['cashapp', 'paypal'], WalletTabs::enabled(['wallet_tabs' => 'paypal,cashapp']), 'enabled reads the column');
assert_false(WalletTabs::answered([]), 'no column = not answered');
assert_false(WalletTabs::answered(['wallet_tabs' => null]), 'NULL = not answered (wizard pre-ticks all three)');
assert_true(WalletTabs::answered(['wallet_tabs' => '']), "'' = answered: explicitly none");
assert_true(WalletTabs::answered(['wallet_tabs' => 'venmo']), 'a value = answered');

// --- sourceFor: which native QR a tab repeats ------------------------------
assert_eq('lightning', WalletTabs::sourceFor('cashapp', true, true), 'Cash App prefers Lightning when both are offered');
assert_eq('lightning', WalletTabs::sourceFor('cashapp', false, true), 'Cash App: Lightning only');
assert_eq('onchain', WalletTabs::sourceFor('cashapp', true, false), 'Cash App falls back to on-chain without a bolt11');
assert_null(WalletTabs::sourceFor('cashapp', false, false), 'Cash App: nothing to scan -> no tab');
foreach (['venmo', 'paypal'] as $k) {
    assert_eq('onchain', WalletTabs::sourceFor($k, true, true), "$k is on-chain even when Lightning is offered");
    assert_eq('onchain', WalletTabs::sourceFor($k, true, false), "$k: on-chain only invoice");
    assert_null(WalletTabs::sourceFor($k, false, true), "$k cannot pay a Lightning-only invoice -> no tab");
    assert_null(WalletTabs::sourceFor($k, false, false), "$k: nothing offered -> no tab");
}
assert_null(WalletTabs::sourceFor('strike', true, true), 'unknown wallet has no source');

// --- offered: the tabs for one invoice ---------------------------------------
$all = ['wallet_tabs' => 'cashapp,venmo,paypal'];
assert_eq(
    ['cashapp' => 'lightning', 'venmo' => 'onchain', 'paypal' => 'onchain'],
    WalletTabs::offered($all, true, true),
    'both rails: all three tabs, Cash App on Lightning'
);
assert_eq(
    ['cashapp' => 'onchain', 'venmo' => 'onchain', 'paypal' => 'onchain'],
    WalletTabs::offered($all, true, false),
    'on-chain only: all three on the on-chain QR'
);
assert_eq(
    ['cashapp' => 'lightning'],
    WalletTabs::offered($all, false, true),
    'Lightning only: Venmo and PayPal drop out'
);
assert_eq([], WalletTabs::offered($all, false, false), 'nothing offered: no wallet tabs');
assert_eq(
    ['venmo' => 'onchain'],
    WalletTabs::offered(['wallet_tabs' => 'venmo'], true, true),
    'only the enabled wallets are offered'
);
assert_eq([], WalletTabs::offered(['wallet_tabs' => null], true, true), 'a store that never opted in shows no wallet tabs');
assert_eq([], WalletTabs::offered([], true, true), 'a row without the column shows no wallet tabs');

echo "test_wallet_tabs: ok\n";
