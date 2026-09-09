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

        // Is this file inside a WordPress plugin tree? If wp-load.php sits at one of the
        // usual ancestors, we were installed as a plugin and this is a direct hit.
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $dir = dirname($dir);
            if ($dir === '/' || $dir === '.') {
                break;
            }
            if (is_file($dir . '/wp-load.php')) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                exit(
                    "This file must be reached through WordPress.\n"
                    . "Use the CashuPay menu in wp-admin, or the /cashupay/... routes.\n"
                );
            }
        }
    }
}

cashupay_guard_direct_entry();
