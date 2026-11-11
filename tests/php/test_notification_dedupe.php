<?php
/**
 * 48-hour dedupe on auto-cashout failures, keyed on (store, destination).
 * Per the spec, identical = same store + same destination — error text is
 * intentionally NOT part of the dedupe key. Successful withdrawals never
 * dedupe (operators want every confirmation).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';

// All switches on so the gate doesn't interfere.
Config::set('notifications_enabled', true);
Config::set('notifications_auto_cashout_enabled', true);
Config::set('notifications_to_email', 'ops@example.com');

$store = 'store_dedupe';
make_store($store, 'https://m.example.com');
Database::update('stores', ['notifications_enabled' => 1], 'id = ?', [$store]);

// Drain transport: count sends without touching SMTP.
$sent = 0;
EmailSender::$transportOverride = function($to, $subject, $body) use (&$sent) {
    $sent++;
};

function drain_now(): void {
    NotificationSender::drainQueue();
}

// First failure → enqueued + sent + logged.
NotificationSender::queueAutoCashoutFailure($store, 'user@dest.com', 'Mint unreachable', 50000);
drain_now();
assert_eq(1, $sent, 'first failure sends');

// Same store + same destination + different error text within 48h → suppressed.
NotificationSender::queueAutoCashoutFailure($store, 'user@dest.com', 'Insufficient balance', 50000);
drain_now();
assert_eq(1, $sent, 'identical (store+dest) suppressed even with different error text');

// Different destination → not suppressed.
NotificationSender::queueAutoCashoutFailure($store, 'other@dest.com', 'Mint unreachable', 50000);
drain_now();
assert_eq(2, $sent, 'different destination sends');

// Successful withdrawal is never deduped, even back-to-back to the same destination.
NotificationSender::queueAutoCashoutSuccess($store, 12345, 'user@dest.com');
NotificationSender::queueAutoCashoutSuccess($store, 12345, 'user@dest.com');
drain_now();
assert_eq(4, $sent, 'success notifications never dedupe');

// Walk the log row backwards by 48h+1s — next failure should send again.
Database::query(
    "UPDATE notification_log SET sent_at = sent_at - ? WHERE store_id = ? AND dedupe_key = ?",
    [48 * 3600 + 1, $store, hash('sha256', strtolower('user@dest.com'))]
);
NotificationSender::queueAutoCashoutFailure($store, 'user@dest.com', 'Mint unreachable', 50000);
drain_now();
assert_eq(5, $sent, 'window expires after 48h');

echo "test_notification_dedupe: ok\n";
