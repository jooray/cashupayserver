<?php
/**
 * Recipient resolution: per-store notification_email wins; otherwise fall
 * back to the site-wide config. If neither is set, no email is queued.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';

Config::set('notifications_enabled', true);
Config::set('notifications_invoice_paid_enabled', true);

$store = 'store_addr';
make_store($store, 'https://m.example.com');
Database::update('stores', ['notifications_enabled' => 1], 'id = ?', [$store]);

$invoice = [
    'id' => 'inv_r',
    'store_id' => $store,
    'amount' => '5',
    'currency' => 'USD',
    'amount_sats' => 10000,
];

// 1. Neither per-store nor site-wide set → nothing queued.
NotificationSender::queueInvoicePaid($invoice);
$row = Database::fetchOne("SELECT COUNT(*) AS c FROM notification_queue");
assert_eq(0, (int)$row['c'], 'no recipient anywhere → no enqueue');

// 2. Site-wide only → uses site-wide.
Config::set('notifications_to_email', 'site@example.com');
NotificationSender::queueInvoicePaid($invoice);
$row = Database::fetchOne("SELECT to_email FROM notification_queue ORDER BY id DESC LIMIT 1");
assert_eq('site@example.com', $row['to_email'], 'site-wide fallback used');

// 3. Per-store set → per-store wins.
Database::update('stores', ['notification_email' => 'store@example.com'], 'id = ?', [$store]);
NotificationSender::queueInvoicePaid($invoice);
$row = Database::fetchOne("SELECT to_email FROM notification_queue ORDER BY id DESC LIMIT 1");
assert_eq('store@example.com', $row['to_email'], 'per-store override wins');

// 4. Per-store blank string → falls back to site-wide.
Database::update('stores', ['notification_email' => ''], 'id = ?', [$store]);
NotificationSender::queueInvoicePaid($invoice);
$row = Database::fetchOne("SELECT to_email FROM notification_queue ORDER BY id DESC LIMIT 1");
assert_eq('site@example.com', $row['to_email'], 'blank per-store falls back to site-wide');

echo "test_notification_recipient_resolution: ok\n";
