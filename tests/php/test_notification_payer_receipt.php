<?php
/**
 * Payer receipt: gating, per-invoice rate limit, and body construction.
 *
 * Unlike the operator-facing InvoicePaid path, payer receipts are gated on
 * the master switch + per-type toggle + SMTP, but NOT on the store's
 * notifications_enabled flag — the payer is the one opting in. The 3-per-
 * invoice cap is the only thing protecting the public endpoint from abuse.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';

$store = 'store_receipt';
make_store($store, 'https://m.example.com');
// Intentionally leave stores.notifications_enabled = 0: payer receipts must
// not depend on that flag.

// Pretend SMTP is configured so isPayerReceiptOffered() can pass when the
// other gates are on. Defining the constant overrides EmailSender::settingValue.
if (!defined('CASHUPAY_SMTP_HOST')) {
    define('CASHUPAY_SMTP_HOST', 'smtp.test');
}
if (!defined('CASHUPAY_SMTP_FROM_ADDRESS')) {
    define('CASHUPAY_SMTP_FROM_ADDRESS', 'noreply@test');
}

$invoice = [
    'id' => 'inv_receipt_1',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '12.50',
    'currency' => 'USD',
    'amount_sats' => 25000,
    'paid_at' => 1700000000,
    'settled_rail' => 'mint',
    'payment_rail' => 'mint',
    'mint_url' => 'https://m.example.com',
];

// ---- Gate: all three required ----------------------------------------------

assert_eq(false, NotificationSender::isPayerReceiptOffered(), 'all gates off');

Config::set('notifications_enabled', true);
assert_eq(false, NotificationSender::isPayerReceiptOffered(), 'master only');

Config::set('notifications_payer_receipt_enabled', true);
assert_eq(true, NotificationSender::isPayerReceiptOffered(), 'master + per-type + SMTP');

Config::set('notifications_enabled', false);
assert_eq(false, NotificationSender::isPayerReceiptOffered(), 'per-type only');
Config::set('notifications_enabled', true);

// ---- Enqueue: row carries invoice_id, recipient is the payer ---------------

assert_eq(0, NotificationSender::payerReceiptCountForInvoice('inv_receipt_1'));
$ok = NotificationSender::queuePayerReceipt($invoice, 'buyer@example.com');
assert_eq(true, $ok, 'first send queues');
assert_eq(1, NotificationSender::payerReceiptCountForInvoice('inv_receipt_1'));

$row = Database::fetchOne(
    "SELECT to_email, invoice_id, event_type, subject, body
     FROM notification_queue WHERE invoice_id = ?",
    ['inv_receipt_1']
);
assert_eq('buyer@example.com', $row['to_email']);
assert_eq('inv_receipt_1', $row['invoice_id']);
assert_eq('PayerReceipt', $row['event_type']);
assert_true(str_contains($row['subject'], 'inv_receipt_1'), 'subject has invoice id');
assert_true(str_contains($row['body'], 'Thank you for shopping at'), 'thank-you line');
assert_true(str_contains($row['body'], 'test ' . $store), 'store name swapped in');
assert_true(str_contains($row['body'], '25,000 sats'), 'sats with thousands separator');
assert_true(str_contains($row['body'], '12.50 USD'), 'fiat equivalent');
assert_true(str_contains($row['body'], 'Lightning (cashu)(m.example.com)'),
    'mint rail labelled Lightning (cashu)(mint host)');

// ---- Per-invoice cap: 3 sends, then the 4th is rejected --------------------

assert_eq(true, NotificationSender::queuePayerReceipt($invoice, 'buyer2@example.com'));
assert_eq(true, NotificationSender::queuePayerReceipt($invoice, 'buyer3@example.com'));
assert_eq(3, NotificationSender::payerReceiptCountForInvoice('inv_receipt_1'));

$ok = NotificationSender::queuePayerReceipt($invoice, 'buyer4@example.com');
assert_eq(false, $ok, 'fourth send rejected by cap');
assert_eq(3, NotificationSender::payerReceiptCountForInvoice('inv_receipt_1'));

// Cap is per-invoice, not global.
$invoice2 = $invoice; $invoice2['id'] = 'inv_receipt_2';
assert_eq(true, NotificationSender::queuePayerReceipt($invoice2, 'buyer@example.com'));

// ---- Rail-specific lines: on-chain surfaces txids + address ---------------

$onchainInvoice = [
    'id' => 'inv_onchain',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '0.0001',
    'currency' => 'BTC',
    'amount_sats' => 10000,
    'paid_at' => 1700000000,
    'settled_rail' => 'onchain',
    'payment_rail' => 'onchain',
    'onchain_address' => 'bc1qexampleaddress',
];
// Seed a settled on-chain payment so buildPayerReceiptBody's lookup hits.
Database::insert('invoices', [
    'id' => 'inv_onchain', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '0.0001', 'currency' => 'BTC', 'amount_sats' => 10000,
    'onchain_address' => 'bc1qexampleaddress', 'payment_rail' => 'onchain',
    'settled_rail' => 'onchain', 'paid_at' => 1700000000,
    'created_at' => 1700000000, 'expiration_time' => 1700003600,
]);
Database::insert('onchain_payments', [
    'id' => 'op_1', 'invoice_id' => 'inv_onchain',
    'txid' => 'deadbeef' . str_repeat('00', 28), 'vout' => 0,
    'amount_sat' => 10000, 'confirmations' => 1,
    'first_seen_at' => 1700000000, 'last_seen_at' => 1700000000,
]);
NotificationSender::queuePayerReceipt($onchainInvoice, 'onchain@example.com');
$row = Database::fetchOne(
    "SELECT body FROM notification_queue WHERE invoice_id = ?",
    ['inv_onchain']
);
assert_true(str_contains($row['body'], 'bc1qexampleaddress'), 'on-chain address in body');
assert_true(str_contains($row['body'], 'deadbeef'), 'on-chain txid in body');
assert_true(str_contains($row['body'], 'On-chain Bitcoin'), 'on-chain rail label');

// ---- Cashu-token rail: labelled Cashu, never Lightning ---------------------
// Legacy rows settled as 'mint' with payment_rail 'cashu' (token receipts
// before the settled_rail fix) must resolve to the Cashu label too.
$cashuInvoice = $invoice;
$cashuInvoice['id'] = 'inv_cashu_token';
$cashuInvoice['payment_rail'] = 'cashu';
$cashuInvoice['settled_rail'] = 'mint'; // legacy fingerprint
NotificationSender::queuePayerReceipt($cashuInvoice, 'token@example.com');
$row = Database::fetchOne(
    "SELECT body FROM notification_queue WHERE invoice_id = ?",
    ['inv_cashu_token']
);
assert_true(str_contains($row['body'], 'Cashu(m.example.com)'), 'cashu rail label with mint host');
assert_true(!str_contains($row['body'], 'Lightning'), 'cashu token receipt not labelled Lightning');

echo "test_notification_payer_receipt: ok\n";
