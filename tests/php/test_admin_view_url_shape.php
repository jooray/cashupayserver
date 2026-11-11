<?php
/**
 * Regression test for the WordPress-alongside dashboard 404 on
 * PATH_INFO-hostile hosts (Local WP's stock nginx, most managed nginx).
 *
 * admin.php used to 302 every bare request to <base>/admin.php/dashboard —
 * a path-style URL such hosts cannot serve: it falls through the web server
 * into the surrounding site's themed 404, so the operator could never reach
 * the admin at all (the wp-admin dashboard iframe, the SSO handoff, and
 * every direct visit all funnel through that redirect).
 *
 * Urls::adminUsesPathUrls() is the pure decision admin.php now routes on:
 * path-style view URLs only where the host PROVABLY routes them — clean mode
 * (the wizard's probe verified the front controller) or a request that
 * itself arrived carrying a PATH_INFO tail — and query-style
 * (admin.php?view=…) everywhere else.
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

// Clean mode: the front controller was probe-proven — path URLs regardless
// of how this particular request arrived.
assert_true(Urls::adminUsesPathUrls(null, 'clean'), 'clean mode, bare request');
assert_true(Urls::adminUsesPathUrls('', 'clean'), 'clean mode, empty PATH_INFO');
assert_true(Urls::adminUsesPathUrls('/dashboard', 'clean'), 'clean mode, view tail');

// A request that ARRIVED with PATH_INFO proves the host forwards path tails
// (Apache AcceptPathInfo, router.php, nginx fastcgi_split_path_info) — keep
// the pretty URLs the operator is already on.
assert_true(Urls::adminUsesPathUrls('/dashboard', 'direct'), 'direct mode, PATH_INFO-served');
assert_true(Urls::adminUsesPathUrls('/', 'router'), 'router mode, bare /admin front-controller form');

// The regression case: a bare admin.php request in a non-clean mode proves
// nothing about path routing — the host may execute only real *.php URLs
// (Local WP nginx). Query URLs are the only safe shape.
assert_true(!Urls::adminUsesPathUrls(null, 'direct'), 'direct mode, bare request must use query URLs');
assert_true(!Urls::adminUsesPathUrls('', 'direct'), 'direct mode, empty PATH_INFO must use query URLs');
assert_true(!Urls::adminUsesPathUrls(null, 'router'), 'router mode, bare request must use query URLs');

echo "ok\n";
