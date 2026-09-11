<?php
/**
 * Order-storage pin decision matrix (wordpress/btcpay-integration.php).
 *
 * On the SQLite database drop-in, HPOS order totals are destroyed on
 * hydration (REAL -> "1.5E-5" -> wc_format_decimal's character strip ->
 * 1.50000000), so the WooCommerce wiring pins order storage to the immune
 * posts table on such hosts. The two invariants that must never break:
 *
 *   1. a MySQL host is never touched — its DECIMAL columns are exact and
 *      HPOS is WooCommerce's default there for a reason, and
 *   2. an HPOS table that already HOLDS orders is never abandoned — the
 *      flip would make those orders invisible, which is worse than the
 *      degraded totals it cures.
 *
 * The function is pure precisely so this file can pin the matrix without a
 * WordPress install; the stubs below only satisfy the helper file's
 * include-time registrations. The end-to-end behavior (a wiring run pinning
 * a freshly auto-enabled HPOS shop back to the posts table, then a checkout
 * settling at the correct amount) is tests/wordpress/test_wp_hpos_checkout.py.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/');
// The helper file registers its retry endpoint on template_redirect at
// include time; the decision function under test stays pure.
function add_action($hook, $cb) {}
require_once dirname(__DIR__, 2) . '/wordpress/btcpay-integration.php';

// --- MySQL hosts: hands off, whatever the HPOS state ------------------------

assert_eq('leave', barebits_order_storage_pin_decision(false, false, 0),
    'MySQL, HPOS off: nothing to do');
assert_eq('leave', barebits_order_storage_pin_decision(false, true, 0),
    'MySQL, HPOS on: HPOS is healthy there — never touched');
assert_eq('leave', barebits_order_storage_pin_decision(false, true, 250),
    'MySQL with HPOS orders: never touched');

// --- SQLite hosts: pin before the corruption can reach an order -------------

assert_eq('pin', barebits_order_storage_pin_decision(true, false, 0),
    'SQLite, HPOS off: pin explicitly and stop the deferred auto-enable job');
assert_eq('pin', barebits_order_storage_pin_decision(true, true, 0),
    'SQLite, HPOS freshly enabled but no orders yet: safe to flip back');

// --- SQLite with orders already in the HPOS table: the one protected state --

assert_eq('leave', barebits_order_storage_pin_decision(true, true, 1),
    'a single HPOS order is enough — flipping storage would orphan it');
assert_eq('leave', barebits_order_storage_pin_decision(true, true, 8000),
    'an established HPOS shop is left alone');

// HPOS off cannot have made rows invisible, so a stale count (rows left in
// wc_orders from an earlier HPOS stint the merchant already migrated away
// from) must not block the pin.
assert_eq('pin', barebits_order_storage_pin_decision(true, false, 8000),
    'HPOS off pins regardless of leftover rows in the unused table');

echo "ok\n";
