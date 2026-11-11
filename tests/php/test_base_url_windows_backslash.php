<?php
/**
 * Auto-detected base URLs must never contain a backslash.
 *
 * On Windows, dirname() returns '\'-separated paths — dirname('/router.php')
 * is '\', so the desktop package's auto-detected base URL became
 * "http://127.0.0.1:8737\" and index.php's pre-setup redirect emitted
 * Location: http://127.0.0.1:8737\/router.php/setup.php. Browsers quietly
 * normalize the backslash, but strict HTTP clients (.NET / PowerShell's
 * Invoke-WebRequest) refuse to follow it — which is exactly how the
 * windows-smoke release job caught it (raw 302 surfaced as an error on the
 * second visit to /, after the first visit had created the schema).
 *
 * Linux dirname() never emits '\' for the single-segment case, so these
 * tests exercise the normalization via subdirectory SCRIPT_NAMEs whose
 * backslashes survive dirname() on any platform. The exact desktop scenario
 * stays covered end-to-end by the windows-smoke job on every testing release.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

$_SERVER['HTTP_HOST'] = '127.0.0.1:8737';
unset($_SERVER['HTTPS']);

// No base_url configured -> auto-detect path. A Windows-style dirname()
// result must come out '/'-separated.
$_SERVER['SCRIPT_NAME'] = '/sub\\dir/router.php';
assert_eq(
    'http://127.0.0.1:8737/sub/dir',
    Config::getBaseUrl(),
    'backslash separators are normalized in the auto-detected base URL'
);

// Root install (the desktop package layout): no trailing separator of
// either kind. On Windows dirname('/router.php') is '\'; normalization
// turns it into '/' and the rtrim strips it.
$_SERVER['SCRIPT_NAME'] = '/router.php';
assert_eq(
    'http://127.0.0.1:8737',
    Config::getBaseUrl(),
    'root-level SCRIPT_NAME yields a bare origin'
);

// The redirect target index.php builds on a schema-initialized but
// unconfigured install (url_mode defaults to 'router') must be clean.
assert_eq(
    'http://127.0.0.1:8737/router.php/setup.php',
    Urls::setup(),
    'pre-setup redirect target has no backslash'
);

// upd_base_url() mirrors Config::getBaseUrl() and must normalize the same
// way — the updater health probe self-request uses it.
define('CASHUPAY_UPDATE_PHP_NO_RUN', true);
require dirname(__DIR__, 2) . '/app/update.php';

$_SERVER['SCRIPT_NAME'] = '/sub\\dir/update.php';
assert_eq(
    'http://127.0.0.1:8737/sub/dir',
    upd_base_url(),
    'upd_base_url normalizes backslash separators'
);

echo "PASS test_base_url_windows_backslash\n";
exit(0);
