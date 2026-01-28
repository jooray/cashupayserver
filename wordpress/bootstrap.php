<?php
/**
 * CashuPay WordPress Bootstrap
 *
 * Sets up constants and data directory for WordPress integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Mark that we're running in WordPress mode
define('CASHUPAY_WORDPRESS', true);

// Plugin directory (contains includes/, assets/, etc.)
define('CASHUPAY_PLUGIN_DIR', __DIR__);

// Determine data directory with priority:
// 1. User-defined constant in wp-config.php
// 2. Above ABSPATH (outside web root)
// 3. Fallback: wp-content/cashupay-data
if (!defined('CASHUPAY_DATA_DIR')) {
    $above_abspath = dirname(ABSPATH) . '/cashupay-data';
    if (is_dir($above_abspath) || (!is_dir(WP_CONTENT_DIR . '/cashupay-data') && is_writable(dirname(ABSPATH)))) {
        define('CASHUPAY_DATA_DIR', $above_abspath);
    } else {
        define('CASHUPAY_DATA_DIR', WP_CONTENT_DIR . '/cashupay-data');
    }
}

// Initialize centralized URL helper
// In deployed plugin, __DIR__ is the plugin root containing includes/
require_once __DIR__ . '/includes/urls.php';
Urls::init(__DIR__ . '/admin.php');
