<?php
/**
 * CashuPayServer - Direct-execution guard for WordPress installs
 *
 * In WordPress mode the plugin's PHP files sit inside wp-content/plugins/cashupay/,
 * which the web server happily serves. Normal traffic reaches them through rewrite
 * rules that bootstrap WordPress first and enforce `manage_options`; a request straight
 * to /wp-content/plugins/cashupay/admin.php does neither, and the file runs in
 * standalone mode instead — a second, anonymously claimable installation on the
 * merchant's own origin, and (if both modes point at the same data directory) a way
 * around the WordPress capability check entirely.
 *
 * Included at the top of every shared entry point. It is a no-op for standalone
 * deployments, where these files are the intended front door.
 */

if (!function_exists('cashupay_guard_direct_entry')) {
    /**
     * Refuse a request that reached a plugin file directly instead of through WordPress.
     */
    function cashupay_guard_direct_entry(): void {
        // Bootstrapped by WordPress (the rewrite handler defines this), so it is fine.
        if (defined('CASHUPAY_WORDPRESS') || defined('ABSPATH')) {
            return;
        }

        // Is this the plugin build? Only the WordPress plugin ships its loader files at
        // the application root. Looking for wp-load.php in parent directories instead
        // locked out every standalone install that happens to live below a WordPress
        // site (public_html/cashupayserver/ next to WordPress in public_html/), which is
        // the most common shared-hosting layout there is.
        $appRoot = dirname(__DIR__);
        if (is_file($appRoot . '/cashupay.php') && is_file($appRoot . '/bootstrap.php')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit(
                "This file must be reached through WordPress.\n"
                . "Use the CashuPay menu in wp-admin, or the /cashupay/... routes.\n"
            );
        }
    }
}

cashupay_guard_direct_entry();

// A signed emergency notice shut this install down: nothing past this point runs,
// including the admin (see includes/safe_mode.php). One small file read when not.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safe_mode.php';
SafeMode::haltIfShutDown();
