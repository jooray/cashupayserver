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
    $hook = add_submenu_page(
        'tools.php',
        'CashuPay',
        'CashuPay',
        'manage_options',
        'cashupay',
        'cashupay_admin_page'
    );
    if ($hook) {
        // load-{hook} runs before any admin output, so a real HTTP redirect is possible.
        add_action('load-' . $hook, 'cashupay_admin_redirect');
    }
}

/**
 * Tools → CashuPay normally forwards straight to the setup wizard or the CashuPay
 * dashboard. The one exception: while the BTCPay for WooCommerce notice has something
 * to say, stay on this wp-admin page so the admin actually sees it (the CashuPay
 * dashboard is outside wp-admin and shows no WordPress notices).
 */
function cashupay_admin_redirect(): void {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/safe_mode.php';

    // Everything CashuPay serves answers 503 while shut down, so this notice is the one
    // place a WordPress operator is sure to see why and what to do.
    $shutdown = SafeMode::shutdown();
    if ($shutdown !== null) {
        echo '<div class="notice notice-error"><p><strong>CashuPay has shut itself down for safety.</strong> ';
        echo esc_html__('The CashuPayServer developers found a serious security problem in this version and sent a signed warning. Your money is still there. Update the CashuPay plugin to', 'cashupay') . ' ';
        echo esc_html($shutdown['fixed'] !== null ? 'version ' . $shutdown['fixed'] . ' or later' : 'the latest version');
        echo esc_html__('; payments start again by themselves.', 'cashupay');
        if ($shutdown['reason'] !== '') {
            echo ' ' . esc_html($shutdown['reason']);
        }
        echo ' <a href="' . esc_url($shutdown['url'] ?? 'https://github.com/jooray/cashupayserver/releases') . '" target="_blank" rel="noopener">'
            . esc_html__('How to upgrade', 'cashupay') . '</a></p></div>';
        return;
    }

    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        wp_redirect(Urls::setup()); // Our own URL, and site_url() may be on a different host than home_url().
        exit;
    }
    if (function_exists('cashupay_btcpay_notice_step') && cashupay_btcpay_notice_step() !== null) {
        return; // cashupay_admin_page() renders; the notice prints above it.
    }
    wp_redirect(Urls::admin());
    exit;
}

function cashupay_admin_page(): void {
    echo '<div class="wrap"><h1>CashuPay</h1>';
    echo '<p>' . esc_html__('Your CashuPay wallet and payment settings open on their own page.', 'cashupay') . '</p>';
    echo '<p><a class="button button-primary button-hero" href="' . esc_url(Urls::admin()) . '">'
        . esc_html__('Open CashuPay', 'cashupay') . '</a></p>';
    echo '</div>';
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
