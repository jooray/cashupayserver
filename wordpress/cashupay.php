<?php
/**
 * Plugin Name: CashuPay - Lightning Payments via Cashu
 * Plugin URI: https://github.com/jooray/cashupayserver
 * Update URI: https://github.com/jooray/cashupayserver/releases
 * Description: Accept Lightning payments through a Cashu mint. BTCPay Server API compatible.
 * Version: 0.5.7-alpha
 * Requires PHP: 8.1
 * Author: CashuPayServer
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load bootstrap (constants, data dir logic)
require_once __DIR__ . '/bootstrap.php';

// Load plugin components
require_once __DIR__ . '/activation.php';
require_once __DIR__ . '/rewrite-rules.php';
require_once __DIR__ . '/admin-menu.php';
require_once __DIR__ . '/btcpay-dependency.php';

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'cashupay_activate');
register_deactivation_hook(__FILE__, 'cashupay_deactivate');

// Schedule WP cron for polling quotes
add_action('cashupay_poll_quotes', 'cashupay_cron_poll');

function cashupay_cron_poll(): void {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/background_runner.php';

    if (!empty($GLOBALS['cashupay_startup_error'])) {
        return; // The admin notice explains; polling a broken database helps nobody.
    }

    if (Database::isInitialized() && Config::isSetupComplete()) {
        // The same task list as the HTTP cron endpoint. Running only a subset here left
        // WordPress installs without auto-withdrawal, expired-payment recovery or keyset
        // rotation, and those gaps are invisible until funds are already stuck.
        BackgroundRunner::run();
    }
}

// Plugin updates do not run activation hooks, so normal WordPress requests must
// also perform the cheap version check.
add_action('plugins_loaded', static function (): void {
    // This runs on every request of the whole site: storefront, wp-admin, cron. An
    // exception escaping it would take the shop down and lock the merchant out of
    // wp-admin, so CashuPay switches itself off instead and says why to admins only.
    if (!empty($GLOBALS['cashupay_refuse_site'])) {
        return; // bootstrap.php switched CashuPay off on this site; leave the wallet alone.
    }
    try {
        cashupay_maybe_upgrade_data_protection();
        require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
        Database::ensureCurrentSchema();
    } catch (Throwable $e) {
        cashupay_record_startup_error('startup', $e);
    }
});
