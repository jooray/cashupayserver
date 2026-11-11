<?php
/**
 * NotificationSender gating: nothing is enqueued unless both the global
 * master switch AND the per-event toggle are on. The per-store opt-in is
 * also required — global cannot bypass an off-state at the store level.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';

function pending_count(): int {
    return (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM notification_queue")['c'] ?? 0);
}

$store = 'store_master';
make_store($store, 'https://m.example.com');
Database::update('stores', [
    'notifications_enabled' => 1,
    'notification_email' => 'ops@example.com',
], 'id = ?', [$store]);

$invoice = [
    'id' => 'inv_1',
    'store_id' => $store,
    'amount' => '12.50',
    'currency' => 'USD',
    'amount_sats' => 25000,
];

// All switches off → no enqueue.
NotificationSender::queueInvoicePaid($invoice);
assert_eq(0, pending_count(), 'fully-off gate enqueues nothing');

// Master on, per-type off → still nothing.
Config::set('notifications_enabled', true);
Config::set('notifications_invoice_paid_enabled', false);
NotificationSender::queueInvoicePaid($invoice);
assert_eq(0, pending_count(), 'master-on but per-type-off enqueues nothing');

// Per-type on, master off → still nothing.
Config::set('notifications_enabled', false);
Config::set('notifications_invoice_paid_enabled', true);
NotificationSender::queueInvoicePaid($invoice);
assert_eq(0, pending_count(), 'per-type-on but master-off enqueues nothing');

// Both on → enqueued.
Config::set('notifications_enabled', true);
Config::set('notifications_invoice_paid_enabled', true);
NotificationSender::queueInvoicePaid($invoice);
assert_eq(1, pending_count(), 'both-on enqueues one row');

// Store disabled → no enqueue even with both global switches on.
Database::update('stores', ['notifications_enabled' => 0], 'id = ?', [$store]);
NotificationSender::queueInvoicePaid($invoice);
assert_eq(1, pending_count(), 'store-disabled cannot be overridden by global on');

// Re-enable store + queue auto-cashout events behind their own switch.
Database::update('stores', ['notifications_enabled' => 1], 'id = ?', [$store]);
Config::set('notifications_auto_cashout_enabled', false);
NotificationSender::queueAutoCashoutSuccess($store, 50000, 'user@dest.com');
assert_eq(1, pending_count(), 'auto-cashout success gated by its own switch');

Config::set('notifications_auto_cashout_enabled', true);
NotificationSender::queueAutoCashoutSuccess($store, 50000, 'user@dest.com');
assert_eq(2, pending_count(), 'auto-cashout success enqueues when both switches on');

echo "test_notification_master_switch: ok\n";
