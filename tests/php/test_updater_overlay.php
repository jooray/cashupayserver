<?php
/**
 * Updater::overlayInstall — copies a freshly-extracted release directory
 * onto the live install, with two safety rules:
 *   1. PRESERVE_PATHS (data/, user_config.php) are never touched
 *   2. .htaccess: overwrite ONLY if the live file hash matches the
 *      previously-shipped hash (= operator hasn't edited it). Otherwise
 *      drop the new version as .htaccess.new and leave .htaccess alone.
 *
 * Returns true if .htaccess was left untouched.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

$base = sys_get_temp_dir() . '/cashupay_overlay_test_' . bin2hex(random_bytes(6));
$src = $base . '/source';
$dst = $base . '/dest';
mkdir($src . '/includes', 0755, true);
mkdir($src . '/data', 0755, true);
mkdir($dst . '/includes', 0755, true);
mkdir($dst . '/data', 0755, true);
register_shutdown_function(function () use ($base) {
    $rec = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rec as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($base);
});

// Build a fake "shipped" source tree.
file_put_contents($src . '/admin.php', 'NEW_ADMIN');
file_put_contents($src . '/includes/updater.php', 'NEW_UPDATER');
file_put_contents($src . '/BUILD_INFO', "COMMIT_SHA=new\n");
file_put_contents($src . '/.htaccess', 'NEW_HTACCESS');
// Source also includes data/ and user_config.php (the build script writes
// stub files into data/) — they should be ignored by the overlay.
file_put_contents($src . '/data/.htaccess', 'deny from all');
file_put_contents($src . '/user_config.php', 'SHOULD_NEVER_OVERWRITE');

// Build a fake "live install" with user data.
file_put_contents($dst . '/admin.php', 'OLD_ADMIN');
file_put_contents($dst . '/includes/updater.php', 'OLD_UPDATER');
file_put_contents($dst . '/BUILD_INFO', "COMMIT_SHA=old\n");
file_put_contents($dst . '/data/MARKER', 'preserve_me');
file_put_contents($dst . '/user_config.php', 'USER_CONFIG');

$overlay = new ReflectionMethod(Updater::class, 'overlayInstall');
$overlay->setAccessible(true);

// --- Case 1: .htaccess pristine (matches previously-shipped hash) ---
$prevShippedHtaccess = 'PRISTINE_HTACCESS';
file_put_contents($dst . '/.htaccess', $prevShippedHtaccess);
$prevShippedSha = hash('sha256', $prevShippedHtaccess);

$untouched = $overlay->invoke(null, $src, $dst, $prevShippedSha, []);

assert_eq('NEW_ADMIN', file_get_contents($dst . '/admin.php'), 'admin.php overlaid');
assert_eq('NEW_UPDATER', file_get_contents($dst . '/includes/updater.php'), 'includes overlaid');
assert_eq("COMMIT_SHA=new\n", file_get_contents($dst . '/BUILD_INFO'), 'BUILD_INFO overlaid');
assert_eq('NEW_HTACCESS', file_get_contents($dst . '/.htaccess'), '.htaccess overwritten (pristine)');
assert_true(!is_file($dst . '/.htaccess.new'), 'no .htaccess.new when pristine');
assert_eq('preserve_me', file_get_contents($dst . '/data/MARKER'), 'data/MARKER preserved');
assert_eq('USER_CONFIG', file_get_contents($dst . '/user_config.php'), 'user_config.php preserved');
assert_true($untouched === true, 'overlayInstall returns true when htaccess pristine');

// --- Case 2: .htaccess hand-edited (hash mismatch) ---
// Reset destination to a state where user edited .htaccess.
file_put_contents($dst . '/admin.php', 'OLD_ADMIN_2');
file_put_contents($dst . '/.htaccess', 'OPERATOR_EDITED');
@unlink($dst . '/.htaccess.new');

$untouched = $overlay->invoke(null, $src, $dst, $prevShippedSha, []);

assert_eq('NEW_ADMIN', file_get_contents($dst . '/admin.php'), 'admin.php still overlaid');
assert_eq('OPERATOR_EDITED', file_get_contents($dst . '/.htaccess'), '.htaccess preserved (edited)');
assert_eq('NEW_HTACCESS', file_get_contents($dst . '/.htaccess.new'), 'new version dropped as .htaccess.new');
assert_true($untouched === false, 'overlayInstall returns false when htaccess held back');

// --- Case 3: no previous hash (fresh install / missing BUILD_INFO).
//     Conservative: overwrite. Operator may not be running an upgrade path
//     where they had a chance to edit yet, so the shipped version wins. ---
file_put_contents($dst . '/.htaccess', 'WHATEVER');
@unlink($dst . '/.htaccess.new');

$untouched = $overlay->invoke(null, $src, $dst, '', []);
assert_eq('NEW_HTACCESS', file_get_contents($dst . '/.htaccess'), '.htaccess overwritten when no prev hash');
assert_true(!is_file($dst . '/.htaccess.new'), 'no .htaccess.new when no prev hash');
assert_true($untouched === true, 'returns true when no prev hash and overwrite happened');

echo "ok\n";
