<?php
/**
 * Updater::isPreserved — decides which paths the overlay must NOT touch.
 * Gets it wrong and we either wipe user data on update or fail to ship
 * security fixes. Private; tested via Reflection.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

$ref = new ReflectionMethod(Updater::class, 'isPreserved');
$ref->setAccessible(true);
$preserved = static fn(string $rel): bool => $ref->invoke(null, $rel);

// Preserved: data/ tree (DB lives here), user_config.php (deployment config).
assert_true($preserved('data'), 'data dir');
assert_true($preserved('data/cashupay.sqlite'), 'data/cashupay.sqlite');
assert_true($preserved('data/updates/backup/foo/admin.php'), 'nested data');
assert_true($preserved('user_config.php'), 'user_config.php');

// NOT preserved: everything else in the codebase.
assert_true(!$preserved('admin.php'), 'admin.php');
assert_true(!$preserved('includes/updater.php'), 'includes/');
assert_true(!$preserved('api-keys/authorize.php'), 'api-keys/');
assert_true(!$preserved('vendor/autoload.php'), 'vendor/');
assert_true(!$preserved('router.php'), 'router.php');
assert_true(!$preserved('recover.php'), 'recover.php');
assert_true(!$preserved('.htaccess'), '.htaccess (handled separately)');
assert_true(!$preserved('BUILD_INFO'), 'BUILD_INFO');

// Trickier: substring match must not match prefix-without-separator.
// 'data_something' must NOT be preserved (only 'data' and 'data/...').
assert_true(!$preserved('data_export.php'), 'data_export.php (data-prefixed but not data/)');
assert_true(!$preserved('user_config.example.php'), 'user_config.example.php');

echo "ok\n";
