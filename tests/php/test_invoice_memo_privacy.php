<?php
/**
 * Tests for the per-store / per-invoice "hide store name / note on invoice"
 * privacy controls that gate the memo embedded in the invoices a payer's
 * wallet records (the noffer NIP-69 description and the cashu NUT-18 memo).
 *
 * Layers covered:
 *   1. Resolution — Invoice::showStoreNameOnInvoice / showNoteOnInvoice resolve
 *      per-invoice metadata override > per-store column > default-show, and
 *      coerce the various truthy encodings (bool, int, "1"/"0", "true").
 *   2. Builder — Invoice::buildInvoiceMemo (and the nofferMemo wrapper) drops
 *      the hidden pieces, joins the rest with " - ", trims, and caps mb-safely.
 *   3. Persistence — the stores.hide_* columns round-trip and feed the builder
 *      when read back through Config::getStore.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

// ---------------------------------------------------------------------------
// 1. Resolution — showStoreNameOnInvoice / showNoteOnInvoice
// ---------------------------------------------------------------------------

// Default: nothing set anywhere -> both shown.
assert_true(Invoice::showStoreNameOnInvoice(['name' => 'Acme'], null),
    'store name shown by default');
assert_true(Invoice::showNoteOnInvoice(['name' => 'Acme'], ['itemDesc' => 'x']),
    'note shown by default');

// Per-store column hides (1 = hide); NULL/0/'' = show.
assert_false(Invoice::showStoreNameOnInvoice(['hide_store_name_on_invoice' => 1], null),
    'store column =1 hides store name');
assert_true(Invoice::showStoreNameOnInvoice(['hide_store_name_on_invoice' => 0], null),
    'store column =0 shows store name');
assert_true(Invoice::showStoreNameOnInvoice(['hide_store_name_on_invoice' => null], null),
    'store column NULL inherits default-show');
assert_true(Invoice::showStoreNameOnInvoice(['hide_store_name_on_invoice' => ''], null),
    'store column empty-string inherits default-show');
assert_false(Invoice::showNoteOnInvoice(['hide_note_on_invoice' => 1], ['itemDesc' => 'x']),
    'store column =1 hides note');

// Per-invoice metadata override WINS over the store column, both directions.
assert_false(Invoice::showStoreNameOnInvoice(
    ['hide_store_name_on_invoice' => 0], ['hideStoreName' => true]),
    'invoice hideStoreName=true overrides a store set to show');
assert_true(Invoice::showStoreNameOnInvoice(
    ['hide_store_name_on_invoice' => 1], ['hideStoreName' => false]),
    'invoice hideStoreName=false overrides a store set to hide (force-show)');
assert_false(Invoice::showNoteOnInvoice(
    ['hide_note_on_invoice' => 0], ['hideNote' => true]),
    'invoice hideNote=true overrides a store set to show');
assert_true(Invoice::showNoteOnInvoice(
    ['hide_note_on_invoice' => 1], ['hideNote' => false]),
    'invoice hideNote=false overrides a store set to hide (force-show)');

// Truthy coercions for the metadata flag (JSON may decode as bool/int/string).
foreach ([true, 1, '1', 'true'] as $truthy) {
    assert_false(Invoice::showStoreNameOnInvoice(['name' => 'Acme'], ['hideStoreName' => $truthy]),
        'metadata hideStoreName=' . var_export($truthy, true) . ' hides');
}
foreach ([false, 0, '0', '', 'false'] as $falsy) {
    assert_true(Invoice::showStoreNameOnInvoice(['name' => 'Acme'], ['hideStoreName' => $falsy]),
        'metadata hideStoreName=' . var_export($falsy, true) . ' shows');
}

// ---------------------------------------------------------------------------
// 2. Builder — buildInvoiceMemo + nofferMemo wrapper
// ---------------------------------------------------------------------------
$store = ['name' => 'Acme Coffee'];

// Baseline (nothing hidden) is unchanged from the pre-privacy behaviour.
assert_eq('Acme Coffee - 2x Latte',
    Invoice::buildInvoiceMemo($store, ['itemDesc' => '2x Latte']),
    'name + note joined when neither hidden');
assert_eq('Acme Coffee - 2x Latte',
    Invoice::nofferMemo($store, ['itemDesc' => '2x Latte']),
    'nofferMemo mirrors buildInvoiceMemo');

// Hide store name -> note only.
assert_eq('2x Latte',
    Invoice::buildInvoiceMemo($store, ['itemDesc' => '2x Latte', 'hideStoreName' => true]),
    'note only when store name hidden');

// Hide note -> store name only.
assert_eq('Acme Coffee',
    Invoice::buildInvoiceMemo($store, ['itemDesc' => '2x Latte', 'hideNote' => true]),
    'store name only when note hidden');

// Hide both -> empty (caller omits the memo entirely).
assert_eq('',
    Invoice::buildInvoiceMemo($store, ['itemDesc' => '2x Latte', 'hideStoreName' => true, 'hideNote' => true]),
    'empty memo when both hidden');

// Per-store hide with no per-invoice override.
assert_eq('2x Latte',
    Invoice::buildInvoiceMemo(
        ['name' => 'Acme Coffee', 'hide_store_name_on_invoice' => 1],
        ['itemDesc' => '2x Latte']),
    'store-level hide store name -> note only');

// Cap: noffer caps at 100 (mb-safe); buildInvoiceMemo with maxLen=0 is uncapped.
$longName = str_repeat('é', 120);
assert_true(mb_strlen(Invoice::nofferMemo(['name' => $longName], null)) <= 100,
    'noffer memo capped at 100 chars');
assert_eq($longName, Invoice::buildInvoiceMemo(['name' => $longName], null, 0),
    'maxLen=0 leaves the memo uncapped (for the cashu rail)');

// ---------------------------------------------------------------------------
// 3. Persistence — columns round-trip and feed the builder via Config::getStore
// ---------------------------------------------------------------------------
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
$sid = 'store_privacy';
make_store($sid, 'http://127.0.0.1:1');
Database::update('stores', ['name' => 'Acme Coffee'], 'id = ?', [$sid]);

// Fresh store: columns default to NULL -> both shown.
$row = Config::getStore($sid);
assert_true(array_key_exists('hide_store_name_on_invoice', $row),
    'hide_store_name_on_invoice column exists on stores');
assert_true(array_key_exists('hide_note_on_invoice', $row),
    'hide_note_on_invoice column exists on stores');
assert_eq('Acme Coffee - 2x Latte',
    Invoice::buildInvoiceMemo($row, ['itemDesc' => '2x Latte']),
    'default store row shows both');

// Flip the per-store defaults and confirm the builder honours them.
Database::update('stores',
    ['hide_store_name_on_invoice' => 1, 'hide_note_on_invoice' => 0],
    'id = ?', [$sid]);
$row = Config::getStore($sid);
assert_eq('2x Latte',
    Invoice::buildInvoiceMemo($row, ['itemDesc' => '2x Latte']),
    'store hides name after save -> note only');
// ...but a per-invoice force-show still wins over the saved store default.
assert_eq('Acme Coffee - 2x Latte',
    Invoice::buildInvoiceMemo($row, ['itemDesc' => '2x Latte', 'hideStoreName' => false]),
    'per-invoice force-show overrides saved store hide');

echo "test_invoice_memo_privacy: OK\n";
