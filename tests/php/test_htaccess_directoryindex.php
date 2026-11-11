<?php
/**
 * Regression: the shipped .htaccess must declare a DirectoryIndex that serves
 * index.php.
 *
 * Why this matters: on a subdirectory install (e.g. https://mysite.com/cashupay)
 * loading the bare directory URL must run index.php, which redirects a
 * not-yet-configured install into the setup wizard. Apache only runs index.php
 * for a directory request if it is listed in DirectoryIndex; bare Apache's
 * built-in default is "index.html" only, so relying on the host default silently
 * breaks setup on many shared hosts (the directory 403s / lists files instead).
 *
 * This asserts the directive at the source level. It cannot exercise real Apache
 * (the test stack runs `php -S`, which ignores .htaccess entirely and serves the
 * root via router.php), so this guards against the directive being dropped —
 * end-to-end HTTP verification requires a real Apache + mod_dir/mod_php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$htaccess = dirname(__DIR__, 2) . '/app/.htaccess';
assert_true(is_file($htaccess), '.htaccess exists at repo root');

$contents = file_get_contents($htaccess);
assert_true($contents !== false && $contents !== '', '.htaccess is readable and non-empty');

// A DirectoryIndex directive that lists index.php (ignoring leading whitespace,
// tolerating additional index files after it, case-insensitive per Apache).
$matched = preg_match(
    '/^\s*DirectoryIndex\s+([^\r\n]*)$/mi',
    $contents,
    $m
);
assert_eq(1, $matched, '.htaccess contains a DirectoryIndex directive');
assert_true(
    preg_match('/(^|\s)index\.php(\s|$)/', $m[1]) === 1,
    'DirectoryIndex lists index.php (got: ' . trim($m[1]) . ')'
);

echo "ok\n";
