<?php
/**
 * Background housekeeping and update links (2026-10 audit).
 *
 * - N3: session files in <data>/sessions are removed after 25 h (logins end after
 *   24 h at the latest), since PHP's own session GC is off on many hosts.
 * - L-5: the update notice links only to this project's GitHub releases. The manifest
 *   is not signed, so a link on the manifest's own host is no longer accepted.
 *
 * Run: php tests/housekeeping_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-housekeeping-' . bin2hex(random_bytes(8));
mkdir($dataDir . '/sessions', 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(function () use ($dataDir) { exec('rm -rf ' . escapeshellarg($dataDir)); });

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/background_runner.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();

echo "Old session files are removed\n";
$now = time();
$sessions = "$dataDir/sessions";
foreach (['sess_old' => $now - 26 * 3600, 'sess_day' => $now - 23 * 3600, 'sess_new' => $now, 'other_old' => $now - 90 * 86400] as $name => $mtime) {
    file_put_contents("$sessions/$name", 'x');
    touch("$sessions/$name", $mtime);
}
check(isset(BackgroundRunner::tasks()['cleanup_sessions']), 'the cleanup runs as a background task');
$result = BackgroundRunner::tasks()['cleanup_sessions']();
check($result === 'removed 1 old sessions', "one stale session is removed ($result)");
check(!file_exists("$sessions/sess_old"), 'the one older than 25 h');
check(file_exists("$sessions/sess_day") && file_exists("$sessions/sess_new"), 'sessions that may still be logged in stay');
check(file_exists("$sessions/other_old"), 'files that are not sessions are left alone');
check(BackgroundRunner::failureOf($result) === null, 'and the task reports success');

echo "Update links lead only to GitHub releases\n";
$accept = new ReflectionMethod(UpdateCheck::class, 'acceptableLink');
check($accept->invoke(null, 'https://github.com/jooray/cashupayserver/releases/tag/v0.9.9') === true, 'a release tag is accepted');
check($accept->invoke(null, 'https://github.com/jooray/cashupayserver/releases') === true, 'the releases page is accepted');
check($accept->invoke(null, 'https://cashupayserver.org/download') === false, "a link on the manifest's own host is dropped");
check($accept->invoke(null, 'https://cashupayserver.org/') === false, 'even its front page');

echo "housekeeping_2026_10: OK\n";
