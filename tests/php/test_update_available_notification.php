<?php
/**
 * "Update available" admin email (Updater::maybeNotifyUpdateAvailable +
 * NotificationSender::queueUpdateAvailable).
 *
 * With auto-update off (the default), a newly-available release must email
 * the site-wide notification address exactly once per release — gated on the
 * notifications master switch plus the per-type toggle (which, uniquely,
 * defaults ON) — and never when auto-update is enabled, since the release
 * would apply on its own.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';

$shaA = 'notifsha-' . str_repeat('a', 31);
$shaB = 'notifsha-' . str_repeat('b', 31);
$localSha = 'localsha-' . str_repeat('0', 31);

// Pin the "running build" to the SHA the seeded verdicts are computed against
// (Updater::getAvailableUpdate() invalidates a verdict from a different
// build), and keep the test independent of any BUILD_INFO lying around in
// the dev checkout.
$installRoot = sys_get_temp_dir() . '/cashupay_notif_' . bin2hex(random_bytes(6));
mkdir($installRoot, 0700, true);
file_put_contents($installRoot . '/BUILD_INFO', "COMMIT_SHA=$localSha\nVERSION=1.0-old\n");
Updater::$installRootOverride = $installRoot;
register_shutdown_function(static function () use ($installRoot): void {
    @unlink($installRoot . '/BUILD_INFO');
    @rmdir($installRoot);
});

function seed_available(string $sha, string $version): void {
    Config::set('updater_available', [
        'available' => true,
        'channel' => 'main',
        'current_version' => '1.0-old',
        'current_sha' => $GLOBALS['localSha'],
        'latest_version' => $version,
        'latest_sha' => $sha,
        'blocked' => false,
        'checked_at' => time(),
        'error' => null,
    ]);
}

function queued_update_emails(): array {
    return Database::fetchAll(
        "SELECT * FROM notification_queue WHERE event_type = ? ORDER BY id ASC",
        [NotificationSender::EVENT_UPDATE_AVAILABLE]
    );
}

// --- Closed gates: nothing may be enqueued ---------------------------------

// No cached verdict at all.
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'no cached verdict, no email');

// Verdict says current.
Config::set('updater_available', ['available' => false, 'checked_at' => time()]);
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'no update available, no email');

// Update available but the notifications master switch is off (its default).
seed_available($shaA, '2.0-new');
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'master switch off, no email');

// Master on, but no notification address configured anywhere.
Config::set('notifications_enabled', true);
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'no recipient, no email');

// Address set, but the per-type toggle was explicitly unchecked.
Config::set('notifications_to_email', 'ops@example.com');
Config::set('notifications_update_available_enabled', false);
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'per-type toggle off, no email');
Config::set('notifications_update_available_enabled', true);

// Auto-update enabled: the release applies on its own, so no nudge email.
Updater::$autoUpdateEnabledOverride = true;
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'auto-update on, no email');
Updater::$autoUpdateEnabledOverride = null;
assert_eq([], queued_update_emails(), 'closed gates enqueued nothing');

// Every closed-gate pass above must NOT have stamped the once-per-release
// marker — the email is still owed once the gates open.
assert_eq('', (string)Config::get('updater_notified_sha', ''), 'marker not stamped while gated');

// --- Open gates: exactly one email per release ------------------------------

assert_eq(true, Updater::maybeNotifyUpdateAvailable(), 'open gates enqueue the email');
$rows = queued_update_emails();
assert_eq(1, count($rows), 'exactly one row queued');
assert_eq('ops@example.com', $rows[0]['to_email'], 'sent to the site-wide notification address');
assert_true(strpos($rows[0]['subject'], '2.0-new') !== false, 'subject names the new version');
assert_true(strpos($rows[0]['body'], 'Automatic updates are disabled') !== false, 'body explains it will not self-apply');
assert_eq($shaA, (string)Config::get('updater_notified_sha', ''), 'marker stamped on enqueue');

// The next cron tick sees the same release: deduped, still one row.
assert_eq(false, Updater::maybeNotifyUpdateAvailable(), 'same release not re-notified');
assert_eq(1, count(queued_update_emails()), 'still exactly one row');

// The per-type toggle defaults ON: the key was never written on a fresh
// install, and the mail above only needed the master switch + address.
// (Explicitly proven again on a clean key.)
Database::query("DELETE FROM config WHERE key = 'notifications_update_available_enabled'", []);

// A NEW release notifies again.
seed_available($shaB, '2.1-newer');
assert_eq(true, Updater::maybeNotifyUpdateAvailable(), 'a new release notifies again');
$rows = queued_update_emails();
assert_eq(2, count($rows), 'second release, second row');
assert_true(strpos($rows[1]['subject'], '2.1-newer') !== false, 'second subject names the newer version');
assert_eq($shaB, (string)Config::get('updater_notified_sha', ''), 'marker moved to the new release');

// --- Delivery through the queue drain ---------------------------------------

$captured = [];
EmailSender::$transportOverride = function ($to, $subject, $body) use (&$captured) {
    $captured[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
};
$drain = NotificationSender::drainQueue();
EmailSender::$transportOverride = null;

assert_eq(2, $drain['sent'], 'both queued update emails delivered');
assert_eq(0, $drain['failed'], 'no delivery failures');
assert_eq('ops@example.com', $captured[0]['to'], 'delivered to the notification address');
assert_true(strpos($captured[1]['subject'], '2.1-newer') !== false, 'delivered subject intact');

echo "test_update_available_notification: ok\n";
