<?php
/**
 * listBackups / pruneBackups / rollbackTo against a fixture install root.
 * Uses Updater::$installRootOverride so the real install isn't touched.
 *
 * What we verify:
 *   - listBackups scans data/updates/backup/ and returns subdirs newest-first
 *     by name (Ymd-His prefix makes lexicographic == chronological)
 *   - pruneBackups keeps the N newest and rm-rf's the rest
 *   - rollbackTo copies a backup over the install, preserving data/
 *     and user_config.php
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// Set up a fake install root in a tempdir.
$root = sys_get_temp_dir() . '/cashupay_updater_test_' . bin2hex(random_bytes(6));
mkdir($root, 0755, true);
Updater::$installRootOverride = $root;
register_shutdown_function(function () use ($root) {
    // best-effort cleanup
    $rec = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rec as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
});

// Helper to create a backup dir with a known marker file inside.
function make_backup(string $root, string $name, array $files): void {
    $dir = $root . '/data/updates/backup/' . $name;
    mkdir($dir, 0755, true);
    foreach ($files as $rel => $content) {
        $full = $dir . '/' . $rel;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $content);
    }
}

// --- listBackups ---

// 1. Empty: returns [].
assert_eq([], Updater::listBackups(), 'empty install');

// 2. Three backups: returned in newest-first order.
make_backup($root, '20260101-000000-aaa', ['admin.php' => 'A']);
make_backup($root, '20260601-120000-bbb', ['admin.php' => 'B']);
make_backup($root, '20260301-090000-ccc', ['admin.php' => 'C']);
$list = Updater::listBackups();
assert_eq(3, count($list));
assert_eq('20260601-120000-bbb', $list[0], 'newest first');
assert_eq('20260301-090000-ccc', $list[1]);
assert_eq('20260101-000000-aaa', $list[2]);

// --- pruneBackups (via rollback path: triggered after each update; we
//     exercise it directly through Reflection). ---
$pruneMethod = new ReflectionMethod(Updater::class, 'pruneBackups');
$pruneMethod->setAccessible(true);

// Keep 2 → drop the oldest, keep the two newest.
$pruneMethod->invoke(null, $root . '/data/updates/backup', 2);
$list = Updater::listBackups();
assert_eq(2, count($list), 'pruned to 2');
assert_eq('20260601-120000-bbb', $list[0]);
assert_eq('20260301-090000-ccc', $list[1]);

// --- rollbackTo ---

// Plant a "current install" that rollback will overwrite, plus user data
// that rollback must NOT touch.
file_put_contents($root . '/admin.php', 'CURRENT');
file_put_contents($root . '/BUILD_INFO', "COMMIT_SHA=current\n");
mkdir($root . '/data', 0755, true);
file_put_contents($root . '/data/MARKER', 'preserve');
file_put_contents($root . '/user_config.php', '<?php // user');

// Rolling back to the newest remaining backup should overlay admin.php
// from that backup, leave data/ and user_config.php alone.
$ok = Updater::rollbackTo('20260601-120000-bbb');
assert_true($ok, 'rollbackTo returned true');
assert_eq('B', (string)file_get_contents($root . '/admin.php'), 'admin.php overlaid from backup');
assert_eq('preserve', (string)file_get_contents($root . '/data/MARKER'), 'data/MARKER preserved');
assert_eq('<?php // user', (string)file_get_contents($root . '/user_config.php'), 'user_config.php preserved');

// Rolling back to a non-existent backup returns false.
$ok = Updater::rollbackTo('nope');
assert_true(!$ok, 'rollbackTo of missing returns false');

Updater::$installRootOverride = null;
echo "ok\n";
