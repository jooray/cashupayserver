<?php
/**
 * NotificationSender::drainQueue() must claim each row with a delivery lease
 * BEFORE sending, so concurrent drainers can't double-send one email. The claim
 * pushes next_attempt_at into the future (gated on the row being due), exactly
 * like the webhook outbox. We assert:
 *   - the lease is taken before the email is sent (probed from inside the send),
 *   - a delivered row is not re-sent on the next drain,
 *   - a row already leased by a "concurrent" drainer is skipped.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';

fresh_db();
make_store('s1');

$dbPath = Database::getDbPath();
$sendCount = 0;
$leasedDuringSend = null;   // [attempts, next_attempt_at] observed mid-send

EmailSender::$transportOverride = function ($to, $subject, $body) use (&$sendCount, &$leasedDuringSend, $dbPath) {
    $sendCount++;
    // A second connection simulates a concurrent drainer peeking while we send.
    $other = new PDO('sqlite:' . $dbPath);
    $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $other->exec('PRAGMA busy_timeout = 2000');
    $r = $other->query(
        "SELECT attempts, next_attempt_at FROM notification_queue WHERE sent_at IS NULL ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $leasedDuringSend = $r ?: null;
};

function enqueue_row(string $to): void {
    Database::insert('notification_queue', [
        'store_id' => 's1', 'event_type' => 'InvoicePaid', 'to_email' => $to,
        'subject' => 'hi', 'body' => 'body', 'created_at' => time(),
    ]);
}

// --- one pending row, single drain ----------------------------------------
enqueue_row('a@example.com');
$now = time();
$res = NotificationSender::drainQueue();
assert_eq(1, $res['sent'], 'one row sent');
assert_eq(1, $sendCount, 'transport invoked exactly once');

// The mid-send probe must have seen the row already leased: attempts bumped to
// 1 and next_attempt_at pushed into the future. That is what makes a concurrent
// drainer's "due" SELECT skip it.
assert_not_null($leasedDuringSend, 'mid-send probe saw the in-flight row');
assert_eq(1, (int)$leasedDuringSend['attempts'], 'attempts leased to 1 before send');
assert_true((int)$leasedDuringSend['next_attempt_at'] >= $now, 'lease pushed next_attempt_at to the future before send');

// Row is now delivered.
$row = Database::fetchOne("SELECT * FROM notification_queue WHERE to_email='a@example.com'");
assert_not_null($row['sent_at'], 'sent_at recorded after delivery');

// --- a delivered row is not re-sent ---------------------------------------
$res2 = NotificationSender::drainQueue();
assert_eq(0, $res2['sent'], 'delivered row not re-sent');
assert_eq(1, $sendCount, 'transport not invoked again for the delivered row');

// --- a row already leased by a concurrent drainer is skipped --------------
enqueue_row('b@example.com');
$leasedId = (int)Database::fetchOne("SELECT id FROM notification_queue WHERE to_email='b@example.com'")['id'];
// Simulate the winning drainer's claim: lease it into the future.
Database::update('notification_queue',
    ['next_attempt_at' => time() + 120, 'attempts' => 1],
    'id = ? AND sent_at IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= ?)',
    [$leasedId, time()]
);
$res3 = NotificationSender::drainQueue();
assert_eq(0, $res3['sent'], 'leased row is skipped by the other drainer');
assert_eq(1, $sendCount, 'transport not invoked for the leased row');
$stillUnsent = Database::fetchOne("SELECT sent_at FROM notification_queue WHERE id = ?", [$leasedId]);
assert_null($stillUnsent['sent_at'], 'leased row stays pending for its lease owner');

echo "PASS test_notification_drain_lease\n";
exit(0);
