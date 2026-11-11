<?php
/**
 * Regression test for the blank-stats-charts bug.
 *
 * With path-based admin routing the SPA is served at sub-paths like
 * /admin/dashboard and /admin/stats. A page-relative 'assets/...' URL then
 * resolves to /admin/assets/... and 404s, so chart.min.js (and mint-discovery,
 * animated-qr, flag images) silently fail to load and the stats charts render
 * blank. Urls::assets() must therefore return a base-rooted ABSOLUTE URL that
 * loads correctly from any sub-path.
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

// Pin a known base so the assertion is deterministic (no auto-detect from
// $_SERVER, which is empty under the CLI test runner).
Config::set('base_url', 'https://pay.example.com');

$asset = Urls::assets('js/chart.min.js');

// Must be absolute (scheme://host/...), not a page-relative 'assets/...'.
assert_true(
    strpos($asset, 'https://pay.example.com/') === 0,
    "assets() must be base-rooted absolute, got: {$asset}"
);
assert_eq('https://pay.example.com/assets/js/chart.min.js', $asset, 'chart.min.js asset url');

// No-arg form points at the assets root.
assert_eq('https://pay.example.com/assets/', Urls::assets(), 'assets root url');

// Crucially it must NOT start with a bare 'assets/' (the broken relative form
// that 404s on /admin/* sub-paths).
assert_true(strpos($asset, 'assets/') !== 0, 'assets() must not be page-relative');

echo "ok\n";
