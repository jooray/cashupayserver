<?php
/**
 * Regression: Auth::authorizationHeader() must find the Authorization header
 * wherever the SAPI put it. Stock Apache/mod_php never populates
 * $_SERVER['HTTP_AUTHORIZATION'] for non-Basic schemes (verified live against
 * php:8.3-apache), so before the fallback existed every
 * `Authorization: token …` API call 401'd on Apache deployments.
 *
 * The apache_request_headers() branch can't run under CLI (the function only
 * exists under server SAPIs); it is exercised end-to-end by the apache-backend
 * run of tests/e2e/test_desktop_smoke_driver.py and by the webserver smoke.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

// Direct CGI-style variable wins.
$_SERVER['HTTP_AUTHORIZATION'] = 'token direct-key';
assert_eq('token direct-key', Auth::authorizationHeader(), 'HTTP_AUTHORIZATION read directly');

// mod_rewrite internal redirects prefix env vars with REDIRECT_.
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'token redirected-key';
assert_eq('token redirected-key', Auth::authorizationHeader(), 'REDIRECT_ variant honoured');

// Nothing anywhere -> empty string, never null/notice.
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
assert_eq('', Auth::authorizationHeader(), 'absent header yields empty string');

// And validateApiRequest treats an unknown token as unauthenticated, not error.
$_SERVER['HTTP_AUTHORIZATION'] = 'token not-a-real-key';
assert_null(Auth::validateApiRequest(), 'unknown key rejects cleanly through the fallback path');

echo "ok\n";
