<?php
/**
 * Takeover decision matrix for the existing-BTCPay override flow.
 *
 * barebits_btcpay_takeover_decision is the single place that decides whether
 * the WooCommerce auto-wiring may run, must stop for consent, or may replace
 * a real BTCPay Server connection the merchant approved replacing. The two
 * invariants that must never break:
 *
 *   1. a real BTCPay Server is untouchable without a consent recorded for
 *      exactly that server's URL, and
 *   2. consent for one server never authorizes clobbering a different one.
 *
 * The function is pure (no WordPress calls) precisely so this file can pin
 * the matrix without a WordPress install; the ABSPATH define below only
 * satisfies the helper file's direct-access guard.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/');
// The helper file registers its retry endpoint on template_redirect at
// include time; the decision function under test stays pure.
function add_action($hook, $cb) {}
require_once dirname(__DIR__, 2) . '/wordpress/btcpay-integration.php';

const OURS = 'https://shop.example/cashupay';
const REAL = 'https://btcpay.example.com';

// --- No real BTCPay Server configured → 'none' -----------------------------

assert_eq('none', barebits_btcpay_takeover_decision('', OURS, ''),
    'no configured URL means nothing to take over');
assert_eq('none', barebits_btcpay_takeover_decision('   ', OURS, ''),
    'a whitespace-only URL is treated as unconfigured');
assert_eq('none', barebits_btcpay_takeover_decision(OURS, OURS, ''),
    'our own URL is not a real BTCPay Server');
assert_eq('none', barebits_btcpay_takeover_decision(OURS . '/', OURS, ''),
    'prefix match: a trailing slash on our URL is still ours');
assert_eq('none', barebits_btcpay_takeover_decision('  ' . OURS . '  ', OURS, ''),
    'the configured URL is trimmed before the prefix check');

// --- Real server, no consent → 'needs_consent' ------------------------------

assert_eq('needs_consent', barebits_btcpay_takeover_decision(REAL, OURS, ''),
    'a real BTCPay Server without consent must block the wiring');
// An unconfigured plugin has no URL of its own; the empty string must not act
// as a match-everything prefix that would call a real server "ours".
assert_eq('needs_consent', barebits_btcpay_takeover_decision(REAL, '', ''),
    'an unconfigured plugin must never consider a real server ours');
assert_eq('needs_consent', barebits_btcpay_takeover_decision(REAL . '/cashupay', OURS, ''),
    'a foreign host is real even when its path ends in /cashupay — only a prefix of OUR url is ours');

// --- Consent must match the configured URL exactly ---------------------------

assert_eq('consented', barebits_btcpay_takeover_decision(REAL, OURS, REAL),
    'consent recorded for exactly this server authorizes the takeover');
assert_eq('consented', barebits_btcpay_takeover_decision(' ' . REAL . ' ', OURS, REAL),
    'whitespace around the configured URL does not defeat a matching consent');
assert_eq('needs_consent', barebits_btcpay_takeover_decision(REAL, OURS, 'https://other.example.com'),
    'consent for a different server must NOT authorize replacing this one');
assert_eq('needs_consent', barebits_btcpay_takeover_decision(REAL, OURS, REAL . '/store'),
    'consent matching is exact, not prefix — a related-looking URL is not the same server config');

// A stale consent left behind after the merchant reconnected some real server
// again is only honoured if it names the same URL — the single-use delete in
// barebits_ensure_woocommerce_integration plus this exact match is what makes
// "approve once, clobber forever" impossible.
assert_eq('needs_consent', barebits_btcpay_takeover_decision('https://btcpay2.example.com', OURS, REAL),
    'a consent consumed for one server does not carry over to the next');

echo "ok\n";
