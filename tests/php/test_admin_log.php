<?php
/**
 * AdminLog: the admin_event_log writer and the unified recent-issues feed.
 *
 *   1. log() persists rows; poll-context writes are throttled (identical
 *      category+invoice+message within POLL_THROTTLE_SEC is skipped) while
 *      checkout-context writes never are; the table is pruned to ROW_CAP.
 *   2. recent() merges admin_event_log with the existing error/event tables
 *      (mint events, failed webhook deliveries, failed notification emails,
 *      swap/sweep errors) newest-first, with working category/store filters
 *      and a correct total for pagination.
 *   3. suppressOnInvoice() reads the site-wide config flag, default off.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/admin_log.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('store_log_a');
make_store('store_log_b');

// ---------- 1. writer: basic persist, poll throttle, prune ----------
AdminLog::log('nwc', 'checkout', 'store_log_a', null, 'NWC wallet abcd1234… via relay.test', 'No response from NWC wallet within 5s');
$row = Database::fetchOne("SELECT * FROM admin_event_log ORDER BY id DESC LIMIT 1");
assert_eq('nwc', $row['category'], 'category persisted');
assert_eq('checkout', $row['context'], 'context persisted');
assert_eq('store_log_a', $row['store_id'], 'store persisted');

// Identical checkout-context rows are NOT throttled (each checkout matters).
AdminLog::log('nwc', 'checkout', 'store_log_a', null, 'NWC wallet abcd1234… via relay.test', 'No response from NWC wallet within 5s');
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM admin_event_log WHERE context = 'checkout'");
assert_eq(2, (int)$n['n'], 'checkout rows are never deduped');

// Identical poll-context rows within the throttle window collapse to one.
AdminLog::log('lnurl', 'poll', 'store_log_a', 'inv_x', 'merchant@host.test', 'verify poll failed: timeout');
AdminLog::log('lnurl', 'poll', 'store_log_a', 'inv_x', 'merchant@host.test', 'verify poll failed: timeout');
AdminLog::log('lnurl', 'poll', 'store_log_a', 'inv_y', 'merchant@host.test', 'verify poll failed: timeout');
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM admin_event_log WHERE context = 'poll'");
assert_eq(2, (int)$n['n'], 'poll rows throttle per invoice+message; distinct invoice still logs');

// Prune: overfill directly, then one log() call must trim to ROW_CAP.
$stmt = Database::getInstance()->prepare(
    "INSERT INTO admin_event_log (timestamp, category, context, store_id, invoice_id, label, message)
     VALUES (?, 'nwc', 'checkout', 'store_log_a', NULL, NULL, ?)"
);
for ($i = 0; $i < AdminLog::ROW_CAP + 20; $i++) {
    $stmt->execute([1000000 + $i, "filler {$i}"]);
}
AdminLog::log('noffer', 'checkout', 'store_log_a', null, 'noffer1xyz', 'relay unreachable');
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM admin_event_log");
assert_eq(AdminLog::ROW_CAP, (int)$n['n'], 'log() prunes the table to ROW_CAP');
$newest = Database::fetchOne("SELECT category FROM admin_event_log ORDER BY timestamp DESC, id DESC LIMIT 1");
assert_eq('noffer', $newest['category'], 'newest row survives the prune');

// ---------- 2. recent(): merged feed across all sources ----------
Database::query("DELETE FROM admin_event_log");
$t = 2000000; // fixed base timestamp so ordering is deterministic

AdminLog::log('nwc', 'checkout', 'store_log_a', null, 'NWC wallet ab… via relay.test', 'timed out');
Database::query("UPDATE admin_event_log SET timestamp = ?", [$t + 60]);

Database::insert('mint_event_log', [
    'mint_url' => 'https://mint.test',
    'timestamp' => $t + 50,
    'event_type' => 'QUOTE_FAILURE',
    'failure_type' => 'TIMEOUT',
    'store_id' => 'store_log_b',
    'address' => null,
    'details' => 'quote timed out',
]);

Database::insert('webhooks', [
    'id' => 'wh_log', 'store_id' => 'store_log_a', 'url' => 'https://hooks.test/x',
    'secret' => 's', 'events' => 'InvoiceSettled', 'created_at' => $t,
]);
Database::insert('webhook_deliveries', [
    'id' => 'whd_log', 'webhook_id' => 'wh_log', 'invoice_id' => null,
    'event_type' => 'InvoiceSettled', 'payload' => '{}', 'status' => 'failed',
    'attempts' => 5, 'status_code' => 500, 'created_at' => $t + 40,
]);
// Pending/delivered rows must NOT appear in the feed.
Database::insert('webhook_deliveries', [
    'id' => 'whd_ok', 'webhook_id' => 'wh_log', 'invoice_id' => null,
    'event_type' => 'InvoiceSettled', 'payload' => '{}', 'status' => 'delivered',
    'attempts' => 1, 'created_at' => $t + 45,
]);

Database::insert('notification_queue', [
    'store_id' => 'store_log_b', 'event_type' => 'invoice_settled',
    'to_email' => 'x@y.test', 'subject' => 's', 'body' => 'b',
    'created_at' => $t, 'attempts' => 3, 'last_error' => 'SMTP down',
    'failed_at' => $t + 30,
]);
// A still-live queued email must not appear.
Database::insert('notification_queue', [
    'store_id' => 'store_log_b', 'event_type' => 'invoice_settled',
    'to_email' => 'x@y.test', 'subject' => 's', 'body' => 'b',
    'created_at' => $t, 'attempts' => 0,
]);

Database::insert('invoices', [
    'id' => 'inv_log_swap', 'store_id' => 'store_log_a', 'status' => 'New',
    'amount' => '1000', 'currency' => 'sat', 'amount_sats' => 1000,
    'payment_rail' => 'swap', 'created_at' => $t, 'expiration_time' => $t + 3600,
]);
Database::insert('swap_attempts', [
    'invoice_id' => 'inv_log_swap', 'store_id' => 'store_log_a',
    'provider' => 'boltz', 'network' => 'mainnet', 'swap_id_external' => 'sw1',
    'status' => 'failed', 'preimage_hash_hex' => 'aa', 'claim_pubkey_hex' => 'bb',
    'claim_privkey_hex' => 'cc', 'refund_pubkey_hex' => 'dd',
    'lockup_address' => 'bc1q...', 'timeout_block_height' => 1,
    'claim_leaf_script_hex' => 'ee', 'refund_leaf_script_hex' => 'ff',
    'lightning_invoice' => 'lnbc1...', 'target_onchain_amount_sats' => 1000,
    'invoice_amount_sats' => 1000, 'merchant_address' => 'bc1q...',
    'merchant_address_index' => 0, 'error_message' => 'lockup never confirmed',
    'created_at' => $t, 'updated_at' => $t + 20,
]);

Database::insert('sweep_attempts', [
    'store_id' => 'store_log_b',
    'provider' => 'boltz', 'network' => 'mainnet', 'swap_id_external' => 'sw2',
    'status' => 'failed', 'preimage_hash_hex' => 'aa', 'claim_pubkey_hex' => 'bb',
    'claim_privkey_hex' => 'cc', 'refund_pubkey_hex' => 'dd',
    'lockup_address' => 'bc1q...', 'timeout_block_height' => 1,
    'claim_leaf_script_hex' => 'ee', 'refund_leaf_script_hex' => 'ff',
    'lightning_invoice' => 'lnbc1...', 'target_onchain_amount_sats' => 1000,
    'invoice_amount_sats' => 1000, 'merchant_address' => 'bc1q...',
    'merchant_address_index' => 0, 'balance_sats_at_create' => 5000,
    'quote_total_cost_sats' => 100, 'error_message' => 'quote expired',
    'created_at' => $t, 'updated_at' => $t + 10,
]);

$feed = AdminLog::recent(50, 0);
assert_eq(6, $feed['total'], 'one entry per failing source, none from healthy rows');
$cats = array_map(fn($e) => $e['category'], $feed['entries']);
assert_eq(['nwc', 'mint', 'webhook', 'email', 'swap', 'sweep'], $cats,
    'feed is newest-first across all sources');
assert_true(strpos($feed['entries'][2]['message'], 'HTTP 500') !== false, 'webhook message carries the status code');
assert_true(strpos($feed['entries'][3]['message'], 'SMTP down') !== false, 'email message carries last_error');
assert_true(strpos($feed['entries'][4]['message'], 'lockup never confirmed') !== false, 'swap message carries error_message');

// Category filter.
$feed = AdminLog::recent(50, 0, 'webhook');
assert_eq(1, $feed['total'], 'category filter narrows the feed');
assert_eq('webhook', $feed['entries'][0]['category'], 'only the requested category returned');

// Store filter.
$feed = AdminLog::recent(50, 0, null, 'store_log_b');
assert_eq(3, $feed['total'], 'store filter keeps only that store\'s rows');
foreach ($feed['entries'] as $e) {
    assert_eq('store_log_b', $e['storeId'], 'no cross-store leakage');
}

// Pagination: limit/offset walk the same ordering.
$page1 = AdminLog::recent(2, 0);
$page2 = AdminLog::recent(2, 2);
assert_eq(2, count($page1['entries']), 'page 1 honors limit');
assert_eq(['nwc', 'mint'], array_map(fn($e) => $e['category'], $page1['entries']), 'page 1 content');
assert_eq(['webhook', 'email'], array_map(fn($e) => $e['category'], $page2['entries']), 'page 2 content');

// Unknown category is ignored rather than erroring.
$feed = AdminLog::recent(50, 0, 'nonsense');
assert_eq(6, $feed['total'], 'unknown category filter falls back to unfiltered');

// ---------- 3. suppress flag ----------
assert_false(AdminLog::suppressOnInvoice(), 'suppression is off by default');
Config::set(AdminLog::SUPPRESS_CONFIG_KEY, true);
assert_true(AdminLog::suppressOnInvoice(), 'config flag turns suppression on');
Config::set(AdminLog::SUPPRESS_CONFIG_KEY, false);
assert_false(AdminLog::suppressOnInvoice(), 'and back off');

echo "test_admin_log: ok\n";
