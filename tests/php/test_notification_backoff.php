<?php
/**
 * NotificationSender::drainQueue() failure handling must:
 *   - back off a failed send (next_attempt_at into the future) instead of
 *     releasing it as immediately-due, so a transient SMTP outage isn't
 *     hammered every cron tick,
 *   - give up after MAX_ATTEMPTS by stamping failed_at, after which the row is
 *     excluded from the due-set and from pendingCount,
 *   - cleanup() removes terminal rows but keeps still-pending ones.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';

fresh_db();
make_store('s1');

// Transport always throws -> every send fails.
EmailSender::$transportOverride = function ($to, $subject, $body) {
    throw new RuntimeException('smtp down');
};

function enqueue(string $to): void {
    Database::insert('notification_queue', [
        'store_id' => 's1', 'event_type' => 'InvoicePaid', 'to_email' => $to,
        'subject' => 'hi', 'body' => 'b', 'created_at' => time(),
    ]);
}

// ---- A failed send backs off, does not retry immediately --------------------
enqueue('a@example.com');
$now = time();
$res = NotificationSender::drainQueue();
assert_eq(0, $res['sent'], 'nothing delivered');
assert_eq(1, $res['failed'], 'one failure recorded');

$row = Database::fetchOne("SELECT * FROM notification_queue WHERE to_email='a@example.com'");
assert_eq(1, (int)$row['attempts'], 'attempts bumped to 1');
assert_null($row['failed_at'], 'not given up yet');
assert_true((int)$row['next_attempt_at'] > $now, 'next_attempt_at pushed into the future (backoff), not NULL/immediate');

// Immediate re-drain finds nothing due (backoff in effect).
$res = NotificationSender::drainQueue();
assert_eq(0, $res['sent'], 're-drain: nothing sent');
assert_eq(0, $res['failed'], 're-drain: row not due, untouched');

// ---- Reaching MAX_ATTEMPTS stamps failed_at (terminal) ----------------------
// Simulate a row already at the edge: attempts=5, due now. The next failed send
// makes it 6 (== MAX_ATTEMPTS) -> failed_at set.
Database::query(
    "UPDATE notification_queue SET attempts = 5, next_attempt_at = NULL WHERE to_email = 'a@example.com'"
);
$res = NotificationSender::drainQueue();
assert_eq(1, $res['failed'], 'final attempt fails');
$row = Database::fetchOne("SELECT * FROM notification_queue WHERE to_email='a@example.com'");
assert_eq(6, (int)$row['attempts'], 'attempts == MAX_ATTEMPTS');
assert_not_null($row['failed_at'], 'failed_at stamped at the cap');

// Terminal row is excluded from the due-set and from pendingCount.
$res = NotificationSender::drainQueue();
assert_eq(0, $res['failed'], 'dead row no longer retried');
assert_eq(0, NotificationSender::pendingCount(), 'dead row not counted as pending');

// ---- cleanup() removes terminal rows, keeps pending -------------------------
// Make the dead row old, add a fresh pending row; only the dead one is removed.
Database::query("UPDATE notification_queue SET created_at = ? WHERE to_email='a@example.com'", [time() - 40 * 24 * 3600]);
enqueue('b@example.com'); // fresh, pending
$deleted = NotificationSender::cleanup(30 * 24 * 3600);
assert_eq(1, $deleted, 'one terminal row cleaned');
assert_null(Database::fetchOne("SELECT id FROM notification_queue WHERE to_email='a@example.com'"), 'dead row gone');
assert_not_null(Database::fetchOne("SELECT id FROM notification_queue WHERE to_email='b@example.com'"), 'pending row kept');

EmailSender::$transportOverride = null;
echo "PASS test_notification_backoff\n";
exit(0);
