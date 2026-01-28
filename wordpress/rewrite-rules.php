<?php
/**
 * CashuPay WordPress Rewrite Rules
 *
 * Maps URL paths to CashuPayServer PHP files.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register rewrite rules on init
add_action('init', 'cashupay_add_rewrite_rules');
add_filter('query_vars', 'cashupay_query_vars');
add_action('template_redirect', 'cashupay_handle_request');
add_filter('redirect_canonical', 'cashupay_disable_trailing_slash_redirect', 10, 2);

/**
 * Disable WordPress canonical redirect (trailing slash) for CashuPay API paths.
 * BTCPay clients don't follow redirects, so 301 breaks API calls.
 */
function cashupay_disable_trailing_slash_redirect($redirect_url, $requested_url) {
    if (strpos($requested_url, '/cashupay/api/') !== false) {
        return false;
    }
    return $redirect_url;
}

function cashupay_add_rewrite_rules(): void {
    add_rewrite_rule('^cashupay/api/v1/(.*)$', 'index.php?cashupay_api=1&cashupay_path=$matches[1]', 'top');
    add_rewrite_rule('^cashupay/payment/(.*)$', 'index.php?cashupay_payment=$matches[1]', 'top');
    add_rewrite_rule('^cashupay-admin/?$', 'index.php?cashupay_admin=1', 'top');
    add_rewrite_rule('^cashupay-setup/?$', 'index.php?cashupay_setup=1', 'top');
    add_rewrite_rule('^cashupay/cron/?$', 'index.php?cashupay_cron=1', 'top');
    add_rewrite_rule('^cashupay/receive/?$', 'index.php?cashupay_receive=1', 'top');
    add_rewrite_rule('^cashupay/api-keys/authorize/?$', 'index.php?cashupay_authorize=1', 'top');
}

function cashupay_query_vars(array $vars): array {
    $vars[] = 'cashupay_api';
    $vars[] = 'cashupay_path';
    $vars[] = 'cashupay_payment';
    $vars[] = 'cashupay_admin';
    $vars[] = 'cashupay_setup';
    $vars[] = 'cashupay_cron';
    $vars[] = 'cashupay_receive';
    $vars[] = 'cashupay_authorize';
    return $vars;
}

function cashupay_handle_request(): void {
    $api = get_query_var('cashupay_api');
    $path = get_query_var('cashupay_path');
    $payment = get_query_var('cashupay_payment');
    $admin = get_query_var('cashupay_admin');
    $setup = get_query_var('cashupay_setup');
    $cron = get_query_var('cashupay_cron');
    $authorize = get_query_var('cashupay_authorize');

    if ($api) {
        // API requests: set PATH_INFO for the API router
        // Must include /api/v1/ prefix since api.php expects it
        $_SERVER['PATH_INFO'] = '/api/v1/' . $path;
        require CASHUPAY_PLUGIN_DIR . '/api.php';
        exit;
    }

    if ($payment) {
        $_GET['id'] = $payment;
        require CASHUPAY_PLUGIN_DIR . '/payment.php';
        exit;
    }

    if ($admin) {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        require CASHUPAY_PLUGIN_DIR . '/admin.php';
        exit;
    }

    if ($setup) {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        require CASHUPAY_PLUGIN_DIR . '/setup.php';
        exit;
    }

    if ($cron) {
        require CASHUPAY_PLUGIN_DIR . '/cron.php';
        exit;
    }

    $receive = get_query_var('cashupay_receive');
    if ($receive) {
        require CASHUPAY_PLUGIN_DIR . '/receive.php';
        exit;
    }

    if ($authorize) {
        require CASHUPAY_PLUGIN_DIR . '/api-keys/authorize.php';
        exit;
    }
}
