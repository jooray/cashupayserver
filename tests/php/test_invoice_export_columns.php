<?php
/**
 * The invoice CSV exports must never emit secret-bearing columns. The
 * invoices table carries per-invoice credentials — strike_api_key (Strike
 * account credential), nwc_uri (embeds the wallet secret), noffer_ephemeral_sk
 * (a Nostr secret key), cashu_offline_token (a bearer ecash token) — and the
 * exports used to stream SELECT * straight into the CSV.
 *
 * Stats::streamInvoices now projects through Stats::invoiceExportColumns().
 * This test pins both directions:
 *   1. streamed rows carry no secret column and no secret value;
 *   2. the allowlist stays complete — every real invoices column is either
 *      exported or a documented secret, so adding a column forces a
 *      deliberate decision here.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/stats.php';

const SECRET_COLUMNS = ['strike_api_key', 'nwc_uri', 'noffer_ephemeral_sk', 'cashu_offline_token'];

$store = 'store_export';
make_store($store, 'https://m.example.com');

$SECRETS = [
    'strike_api_key' => 'SECRETSTRIKEKEY' . str_repeat('S', 20),
    'nwc_uri' => 'nostr+walletconnect://pub?secret=SECRETNWC',
    'noffer_ephemeral_sk' => str_repeat('ab', 32),
    'cashu_offline_token' => 'cashuBSECRETTOKEN',
];
Database::insert('invoices', array_merge([
    'id' => 'inv_export_1',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '100',
    'currency' => 'sat',
    'payment_rail' => 'strike',
    'settled_rail' => 'strike',
    'strike_invoice_id' => 'strk_export_1',
    'ln_destination' => 'Strike API (…SSSS)',
    'created_at' => time(),
    'expiration_time' => time() + 900,
], $SECRETS));

$rows = iterator_to_array(Stats::streamInvoices(Stats::ALL_STORES, null, true));
assert_eq(1, count($rows), 'one settled invoice streamed');
$row = $rows[0];

// 1. No secret column, no secret value.
foreach (SECRET_COLUMNS as $col) {
    assert_false(array_key_exists($col, $row), "streamed row has no {$col} column");
}
$flat = json_encode($row, JSON_UNESCAPED_UNICODE);
foreach ($SECRETS as $col => $value) {
    assert_false(strpos($flat, $value) !== false, "streamed row carries no {$col} value");
}

// Reconciliation + rail columns still ride along.
assert_eq('strk_export_1', $row['strike_invoice_id'], 'strike_invoice_id exported');
assert_eq('strike', $row['payment_rail'], 'payment_rail exported');
assert_eq('Strike API (…SSSS)', $row['ln_destination'], 'masked destination exported');

// 2. Allowlist completeness: every actual column is exported or a documented
// secret, and the allowlist names only real columns.
$actual = array_map(
    fn($r) => $r['name'],
    Database::fetchAll("SELECT name FROM pragma_table_info('invoices')")
);
$missing = array_diff($actual, Stats::invoiceExportColumns(), SECRET_COLUMNS);
assert_eq([], array_values($missing),
    'every non-secret invoices column is exported (new columns must be added to invoiceExportColumns deliberately)');
$phantom = array_diff(Stats::invoiceExportColumns(), $actual);
assert_eq([], array_values($phantom), 'the allowlist names only real columns');

echo "test_invoice_export_columns: ok\n";
