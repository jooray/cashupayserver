<?php
/**
 * Payment-page "Share" button visibility rule (Urls::paymentLinkShareable).
 *
 * The button copies the invoice's public URL, so it must disappear whenever
 * that URL could not possibly open on another device:
 *   - on the Windows desktop package (loopback-only built-in server) — the
 *     desktop flag wins over any configured base URL;
 *   - whenever the base URL host is loopback: the localhost name (including
 *     RFC 6761 *.localhost), anything in 127.0.0.0/8, IPv6 ::1, and the
 *     0.0.0.0 listen-shorthand.
 *
 * The method's parameters are explicit test seams (desktop flag, base URL),
 * so the whole decision matrix is testable without env juggling; the live
 * Desktop::isWindowsDesktop() detection has its own coverage in
 * test_setup_flow_desktop.php and tests/e2e/test_setup_desktop_mode.py.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

// --- Shareable: public hosts, any scheme/port/path ---------------------------
assert_true(Urls::paymentLinkShareable(false, 'https://pay.example.com'),
    'public https host is shareable');
assert_true(Urls::paymentLinkShareable(false, 'http://pay.example.com:8080/sub/dir'),
    'port and subdirectory do not matter');
assert_true(Urls::paymentLinkShareable(false, 'http://192.168.1.50'),
    'a LAN IP is not loopback — the operator may serve a local network');
assert_true(Urls::paymentLinkShareable(false, 'http://10.0.0.7:8081'),
    'private-range IPs stay shareable');

// --- Hidden: Windows desktop package wins over everything --------------------
assert_false(Urls::paymentLinkShareable(true, 'https://pay.example.com'),
    'desktop package hides Share even with a public base URL configured');
assert_false(Urls::paymentLinkShareable(true, 'http://127.0.0.1:8080'),
    'desktop package with the usual loopback URL');

// --- Hidden: loopback base URLs ----------------------------------------------
assert_false(Urls::paymentLinkShareable(false, 'http://localhost'),
    'localhost');
assert_false(Urls::paymentLinkShareable(false, 'http://localhost:8080/pay'),
    'localhost with port and path');
assert_false(Urls::paymentLinkShareable(false, 'http://LOCALHOST:8080'),
    'host matching is case-insensitive');
assert_false(Urls::paymentLinkShareable(false, 'http://shop.localhost'),
    'RFC 6761 *.localhost names resolve to loopback');
assert_false(Urls::paymentLinkShareable(false, 'http://127.0.0.1:8080'),
    '127.0.0.1');
assert_false(Urls::paymentLinkShareable(false, 'http://127.5.4.3'),
    'the whole 127.0.0.0/8 block is loopback');
assert_false(Urls::paymentLinkShareable(false, 'http://[::1]:8080'),
    'IPv6 loopback (parse_url keeps the brackets)');
assert_false(Urls::paymentLinkShareable(false, 'http://0.0.0.0:8080'),
    '0.0.0.0 is listen-shorthand, not a reachable host');

// --- Hidden: no usable host ---------------------------------------------------
assert_false(Urls::paymentLinkShareable(false, ''),
    'empty base URL');
assert_false(Urls::paymentLinkShareable(false, '/just/a/path'),
    'relative base URL has no host to share');

// --- Default args read live state: loopback autodetection hides ---------------
// fresh_db() leaves no stored base_url, so getBaseUrl() autodetects from the
// request environment. A loopback Host must hide; a public one must show.
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
Config::clearCache();
assert_false(Urls::paymentLinkShareable(false),
    'autodetected loopback base URL hides Share');

$_SERVER['HTTP_HOST'] = 'pay.merchant.example';
Config::clearCache();
assert_true(Urls::paymentLinkShareable(false),
    'autodetected public base URL shows Share');

// A stored base_url beats autodetection — the decision must follow it.
Config::set('base_url', 'http://localhost:8080');
Config::clearCache();
assert_false(Urls::paymentLinkShareable(false),
    'stored loopback base_url hides Share even when the Host header is public');

echo "OK test_share_link_visibility\n";
