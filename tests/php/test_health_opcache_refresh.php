<?php
/**
 * health.php's per-build opcache reset (cashupay_health_refresh_opcache).
 *
 * The post-update health probe is the one request every updater — including
 * releases that predate Updater::resetOpcache — makes right after an overlay,
 * on the new code. It must flush the compiled-code cache exactly once per
 * on-disk build, tracked in a plain marker file under data/updates so it works
 * before (and independently of) the possibly-stale bootstrap:
 *
 *   1. first probe of a build: resets (or reports opcache unavailable — the
 *      CLI has none) AND writes the marker with that build's COMMIT_SHA
 *   2. next probe of the same build: 'current', marker untouched, no reset
 *   3. BUILD_INFO advances (an update landed): resets again, marker follows
 *   4. rolled back to the previous build: resets again (the marker compares
 *      equality, not recency)
 *   5. the SAME build re-applied (channel switched away and back: BUILD_INFO
 *      rewritten, same SHA): resets again — the marker tracks the file's
 *      identity (mtime + inode), because the overlay that rewrote it may have
 *      been performed by a release that does not invalidate anything itself
 *   6. no BUILD_INFO (dev checkout): inert, no marker
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('CASHUPAY_HEALTH_NO_RUN', true);
require dirname(__DIR__, 2) . '/app/health.php';

$root = sys_get_temp_dir() . '/cashupay_health_' . bin2hex(random_bytes(6));
mkdir($root . '/data', 0755, true);
$marker = $root . '/data/updates/opcache_reset_sha';
register_shutdown_function(static function () use ($root) { @cleanup_db($root); });

// 6. Dev checkout: no BUILD_INFO -> inert.
assert_eq('no_build_info', cashupay_health_refresh_opcache($root, $root . '/data'), 'no BUILD_INFO is inert');
assert_false(is_file($marker), 'no marker without BUILD_INFO');

// 1. First probe of build A.
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=aaaa1111\nCHANNEL=main\nVERSION=1.0\n");
$r = cashupay_health_refresh_opcache($root, $root . '/data');
assert_true(in_array($r, ['reset', 'unavailable'], true), "first probe acts ($r)");
assert_true(str_starts_with(trim(file_get_contents($marker)), 'aaaa1111 '), 'marker records build A');

// 2. Same build again: nothing to do.
$before = trim(file_get_contents($marker));
assert_eq('current', cashupay_health_refresh_opcache($root, $root . '/data'), 'same build is current');
assert_eq($before, trim(file_get_contents($marker)), 'marker unchanged');

// 3. An update landed: BUILD_INFO names build B.
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=bbbb2222\nVERSION=1.1\n");
$r = cashupay_health_refresh_opcache($root, $root . '/data');
assert_true(in_array($r, ['reset', 'unavailable'], true), "new build acts ($r)");
assert_true(str_starts_with(trim(file_get_contents($marker)), 'bbbb2222 '), 'marker follows to build B');

// 4. Rolled back to A: acts again.
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=aaaa1111\nVERSION=1.0\n");
$r = cashupay_health_refresh_opcache($root, $root . '/data');
assert_true(in_array($r, ['reset', 'unavailable'], true), "rollback acts ($r)");
assert_true(str_starts_with(trim(file_get_contents($marker)), 'aaaa1111 '), 'marker back to build A');
assert_eq('current', cashupay_health_refresh_opcache($root, $root . '/data'), 'and is then current');

// 5. The same build A re-applied: BUILD_INFO rewritten (an overlay gives it a
//    fresh mtime), same SHA -> must act again, not answer 'current'.
touch($root . '/BUILD_INFO', time() + 7);
$r = cashupay_health_refresh_opcache($root, $root . '/data');
assert_true(in_array($r, ['reset', 'unavailable'], true), "rewritten BUILD_INFO with the same SHA acts again ($r)");
assert_eq('current', cashupay_health_refresh_opcache($root, $root . '/data'), 'then current again');

// CRLF BUILD_INFO (zip extracted on Windows) still parses.
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=cccc3333\r\nVERSION=1.2\r\n");
cashupay_health_refresh_opcache($root, $root . '/data');
assert_true(str_starts_with(trim(file_get_contents($marker)), 'cccc3333 '), 'CRLF BUILD_INFO parsed');

echo "ok\n";
