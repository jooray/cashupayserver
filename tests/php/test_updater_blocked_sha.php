<?php
/**
 * Crash-recovery additions to the Updater engine:
 *   - getBlockedShas() / blockSha() round-trip + idempotency
 *   - getPendingVerify() / clearPendingVerify() round-trip
 *   - checkAndApply() SKIPS a remote build whose COMMIT_SHA is blocked
 *     (the forward-failure guard that stops an apply -> crash -> rollback ->
 *     re-apply loop)
 *   - a successful apply records the updater_pending_verify marker with the
 *     applied SHA and the backup it can roll back to
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// --- Accessor round-trips ---
assert_eq([], Updater::getBlockedShas(), 'blocked list starts empty');
Updater::blockSha('aaa');
Updater::blockSha('aaa'); // idempotent
Updater::blockSha('bbb');
assert_eq(['aaa', 'bbb'], Updater::getBlockedShas(), 'blocked list dedupes + appends');

assert_null(Updater::getPendingVerify(), 'no pending marker initially');
Config::set('updater_pending_verify', ['sha' => 'x', 'backup' => 'b']);
assert_eq('x', Updater::getPendingVerify()['sha'] ?? null, 'pending marker readable');
Updater::clearPendingVerify();
assert_null(Updater::getPendingVerify(), 'pending marker cleared');

// Reset the blocked list for the apply scenarios below.
Config::set('updater_blocked_shas', []);

// --- checkAndApply skips a blocked SHA ---
$sha = 'newsha-' . str_repeat('a', 35);
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => $sha,
    'CHANNEL' => 'main',
    'VERSION' => '0.2-new',
], [
    'admin.php' => 'NEW_ADMIN',
]);

Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Updater::$autoUpdateEnabledOverride = true;
Config::set('update_channel', 'main');

// Block the very SHA the channel is offering.
Updater::blockSha($sha);
Config::set('updater_last_check', 0);
$applied = Updater::checkAndApply();
assert_true($applied === false, 'blocked SHA is not applied');
assert_eq('OLD_ADMIN', file_get_contents($fixture['installRoot'] . '/admin.php'), 'install left untouched');
assert_null(Updater::getPendingVerify(), 'no pending marker when the update was skipped');

// --- Unblock -> applies, and records the pending-verify marker ---
Config::set('updater_blocked_shas', []);
Config::set('updater_last_check', 0);
$applied = Updater::checkAndApply();
assert_true($applied === true, 'applies once the SHA is unblocked');

$pending = Updater::getPendingVerify();
assert_not_null($pending, 'pending-verify marker written after apply');
assert_eq($sha, $pending['sha'] ?? null, 'marker carries the applied SHA');
assert_true(!empty($pending['backup']), 'marker carries a backup name');
assert_eq('0.2-new', $pending['version'] ?? null, 'marker carries the new version');

$backups = Updater::listBackups();
assert_true(in_array($pending['backup'], $backups, true), 'marker backup exists on disk');

// Reset overrides for any later case in this process.
Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;
Updater::$autoUpdateEnabledOverride = null;

echo "ok\n";
