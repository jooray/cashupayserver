<?php
/**
 * CashuPay WordPress Admin Menu
 *
 * Adds CashuPay to the WordPress admin sidebar.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'cashupay_admin_menu');
add_action('admin_notices', 'cashupay_admin_notice');

function cashupay_admin_menu(): void {
    add_submenu_page(
        'tools.php',
        'CashuPay',
        'CashuPay',
        'manage_options',
        'cashupay',
        'cashupay_admin_redirect'
    );
}

function cashupay_admin_redirect(): void {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';

    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        $url = Urls::setup();
    } else {
        $url = Urls::admin();
    }
    echo '<script>window.location.href = ' . wp_json_encode($url) . ';</script>';
    exit;
}

function cashupay_admin_notice(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';

    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        echo '<div class="notice notice-info"><p>';
        echo '<strong>CashuPay:</strong> Plugin not configured yet, please ';
        echo '<a href="' . esc_url(Urls::setup()) . '">configure the plugin here</a>.';
        echo '</p></div>';
    }
}
