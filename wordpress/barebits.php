<?php
/**
 * Plugin Name: BareBits - Lightning Payments via Bitcoin
 * Plugin URI: https://github.com/BareBits/cashupayserver
 * Description: Accept Bitcoin payments (on-chain and lightning) in WooCommerce through a BareBits server — connect an existing one or install one alongside WordPress. No approval process, no middlemen.
 * Version: 1.5
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: BareBits
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: barebits-lightning-payments-via-bitcoin
 *
 * This plugin is deliberately thin: it contains only WordPress-specific glue
 * (onboarding UI, installing/configuring the BTCPay for WooCommerce gateway,
 * the checkout discount, a WP-cron pinger). The BareBits payment server it
 * talks to is a separate application with its own license, reached purely
 * over HTTP — no BareBits code ships inside this plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BAREBITS_PLUGIN_DIR', __DIR__);
define('BAREBITS_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/api-bridge.php';
// The wordpress.org distribution ships WITHOUT installer.php (the directory's
// guidelines forbid fetching executable code, which the install-alongside
// flow exists to do); onboarding degrades to connect-by-URL only. The GitHub
// distribution ships the full flow.
if (file_exists(__DIR__ . '/installer.php')) {
    require_once __DIR__ . '/installer.php';
}
require_once __DIR__ . '/btcpay-integration.php';
require_once __DIR__ . '/payment-discount.php';
require_once __DIR__ . '/onboarding.php';
require_once __DIR__ . '/admin-menu.php';
// Named cron-integration (not cron.php) so nothing can ever shadow the
// BareBits server's own cron.php endpoint in any layout.
require_once __DIR__ . '/cron-integration.php';
require_once __DIR__ . '/gateway-guard.php';

register_activation_hook(__FILE__, 'barebits_activate');
register_deactivation_hook(__FILE__, 'barebits_deactivate');

/**
 * Activation: register the every-minute interval and, when an alongside
 * install is already wired (re-activation), restart its cron pinger.
 */
function barebits_activate(): void {
    barebits_cron_reschedule();
}

/**
 * Deactivation: stop the WP-cron pinger. The BareBits server itself (and its
 * data) is deliberately untouched — deactivating the WordPress glue must
 * never take the payment server down.
 */
function barebits_deactivate(): void {
    barebits_cron_unschedule();
}
