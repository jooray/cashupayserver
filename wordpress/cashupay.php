<?php
/**
 * Plugin Name: CashuPay - Lightning Payments via Cashu
 * Plugin URI: https://github.com/ArcadeLabsInc/cashupayserver
 * Description: Accept Lightning payments through a Cashu mint. BTCPay Server API compatible.
 * Version: 1.0.0
 * Requires PHP: 8.0
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

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'cashupay_activate');
register_deactivation_hook(__FILE__, 'cashupay_deactivate');

// Schedule WP cron for polling quotes
add_action('cashupay_poll_quotes', 'cashupay_cron_poll');

function cashupay_cron_poll(): void {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/invoice.php';

    if (Database::isInitialized() && Config::isSetupComplete()) {
        Invoice::pollPendingQuotes();
    }
}
