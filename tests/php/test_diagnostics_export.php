<?php
/**
 * Diagnostic report export: anonymization + secret-exclusion guarantees.
 *
 * Seeds the DB with distinctive PII/secret marker strings, then asserts:
 *   - anonymized report contains NONE of the customer/PII markers, uses
 *     surrogate invoice ids, keeps product_id but drops product titles, and
 *     scrubs payment tokens out of free-text fields;
 *   - de-anonymized report DOES contain the customer/PII markers and real ids;
 *   - server secrets (cron_key) are absent in BOTH modes.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/diagnostics.php';

$store = 's_diag';
make_store($store, 'https://mint.example.com');

// Distinctive markers we can grep for in the rendered JSON.
$INV_ID   = 'real-invoice-id-ABCDEF';
$BOLT11   = 'lnbc1secretbolt11payment';
$QUOTE    = 'quoteSECRET123';
$NOTE     = 'SECRET_INVOICE_NOTE';
$PRODNAME = 'SECRET_PRODUCT_NAME';
$DEST     = 'bc1qsecretdestaddrxxxxxxxxxxxxxxxxxxxxxx';
$PREIMAGE = str_repeat('a1', 32); // 64 hex chars
$EMAIL    = 'victim@example.com';
$CRONKEY  = 'SECRET-CRON-KEY-123';
$EVT_LN   = 'lnbc1eventdetailinvoice';
$REL_ADDR = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7k'; // valid bech32 charset

$now = time();

Database::insert('invoices', [
    'id' => $INV_ID, 'store_id' => $store, 'status' => 'Settled',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 12345,
    'quote_id' => $QUOTE, 'bolt11' => $BOLT11, 'mint_url' => 'https://mint.example.com',
    'metadata' => json_encode(['note' => $NOTE]), 'checkout_config' => json_encode(['x' => $NOTE]),
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);

Database::insert('products', [
    'id' => 'p_1', 'store_id' => $store, 'title' => $PRODNAME,
    'price' => '10', 'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now,
]);

Database::insert('invoice_items', [
    'id' => 'it_1', 'invoice_id' => $INV_ID, 'store_id' => $store, 'product_id' => 'p_1',
    'title' => $PRODNAME, 'unit_price' => '10', 'unit_currency' => 'USD',
    'quantity' => 1, 'amount_sats' => 12345, 'created_at' => $now,
]);

Database::insert('melts', [
    'store_id' => $store, 'amount_sats' => 500, 'network_fee_sats' => 2,
    'destination' => $DEST, 'preimage' => $PREIMAGE, 'note' => FEE_NOTE_DEV,
    'invoice_id' => $INV_ID, 'created_at' => $now,
]);

Database::insert('mint_event_log', [
    'mint_url' => 'https://mint.example.com', 'timestamp' => $now,
    'event_type' => 'WITHDRAW_FAILURE', 'failure_type' => 'MINT_PROTOCOL_ERROR',
    'store_id' => $store, 'address' => $DEST,
    'details' => "could not pay {$EVT_LN} hash {$PREIMAGE}",
]);

Database::insert('mint_reliability', [
    'mint_url' => 'https://mint.example.com',
    'last_failure_message' => "route to {$REL_ADDR} failed",
    'last_failure_kind' => 'MINT_PROTOCOL_ERROR', 'updated_at' => $now,
]);

Database::insert('notification_queue', [
    'store_id' => $store, 'event_type' => 'invoice_paid', 'to_email' => $EMAIL,
    'subject' => "receipt for {$NOTE}", 'body' => "hello {$EMAIL}",
    'created_at' => $now, 'attempts' => 3, 'last_error' => "smtp rejected {$EMAIL}",
]);

Config::set('cron_key', $CRONKEY);
Config::set('update_channel', 'main');

// ---- anonymized report ----------------------------------------------------
$anon = (new Diagnostics(true, null))->toArray();
$anonJson = json_encode($anon);

foreach ([$BOLT11, $QUOTE, $NOTE, $PRODNAME, $DEST, $PREIMAGE, $EMAIL, $INV_ID, $EVT_LN, $REL_ADDR, $CRONKEY] as $marker) {
    assert_true(strpos($anonJson, $marker) === false, "anonymized report must not contain marker: {$marker}");
}

assert_eq(true, $anon['meta']['anonymized'], 'meta.anonymized true');
assert_eq('inv_1', $anon['invoices'][0]['id'], 'invoice id replaced with surrogate');
assert_eq('inv_1', $anon['invoice_items'][0]['invoice_id'], 'item invoice_id mapped to same surrogate');
assert_eq('p_1', $anon['invoice_items'][0]['product_id'], 'product_id retained');
assert_true(!array_key_exists('title', $anon['invoice_items'][0]), 'product title dropped from items');
assert_true(!array_key_exists('bolt11', $anon['invoices'][0]), 'bolt11 dropped from invoices');
assert_eq('inv_1', $anon['melts'][0]['invoice_id'], 'melt invoice_id mapped to surrogate');
assert_eq('fee_dev', $anon['melts'][0]['type'], 'melt note classified as fee_dev');
assert_true(!array_key_exists('destination', $anon['melts'][0]), 'melt destination dropped');

// Free-text scrub leaves a redaction marker behind.
assert_true(strpos($anon['mint_event_log'][0]['details'], '[REDACTED') !== false, 'event details scrubbed');
assert_true(!array_key_exists('address', $anon['mint_event_log'][0]), 'event address dropped');
assert_true(strpos($anon['mint_reliability'][0]['last_failure_message'], '[REDACTED') !== false, 'reliability message scrubbed');
assert_true(!array_key_exists('to_email', $anon['notification_failures'][0]), 'notification email dropped');

// Safe config present, secret absent.
assert_eq('main', $anon['system']['config']['update_channel'] ?? null, 'safe config key present');
assert_true(!array_key_exists('cron_key', $anon['system']['config']), 'cron_key never in config');

// ---- de-anonymized report -------------------------------------------------
$full = (new Diagnostics(false, null))->toArray();
$fullJson = json_encode($full);

foreach ([$BOLT11, $NOTE, $PRODNAME, $DEST, $EMAIL, $INV_ID] as $marker) {
    assert_true(strpos($fullJson, $marker) !== false, "de-anonymized report must contain marker: {$marker}");
}
assert_eq(false, $full['meta']['anonymized'], 'meta.anonymized false');
assert_eq($INV_ID, $full['invoices'][0]['id'], 'real invoice id kept in full mode');
assert_eq($PRODNAME, $full['invoice_items'][0]['title'], 'product title kept in full mode');
assert_eq($DEST, $full['melts'][0]['destination'], 'destination kept in full mode');

// Secret STILL excluded even in full mode.
assert_true(strpos($fullJson, $CRONKEY) === false, 'cron_key never exported, even in full mode');
assert_true(!array_key_exists('cron_key', $full['system']['config']), 'cron_key absent from full config');

echo "diagnostics_export: ok\n";
