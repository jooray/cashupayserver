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

if (!function_exists('cashupay_uncaught_exception')) {
    /**
     * Last-resort handler for a standalone page that throws.
     *
     * Without it PHP's own fatal-error page answered (with HTTP 200 and, where the host
     * leaves display_errors on, the server path and a stack trace): a database written
     * by a newer version, or one that cannot be opened, showed the operator a white
     * screen or a dump she cannot act on. This logs the detail and answers 503 with a
     * page that says what happened and what to send to the host. Entry points with
     * their own handler (receive.php, health.php) replace this one.
     */
    function cashupay_uncaught_exception(\Throwable $e): void {
        error_log('CashuPayServer: uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' at ' . $e->getFile() . ':' . $e->getLine());

        $message = $e->getMessage();
        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
            ? preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST']) : 'my website';
        // Never echo a server path: the detail is for the host, who has the log anyway.
        $detail = get_class($e) . ': ' . preg_replace('#(?:[A-Za-z]:)?[\\/][^\s\'"]*[\\/][^\s\'"]*#', '[path]', $message);

        if (strpos($message, 'is newer than this code') !== false) {
            $title = 'The database is from a newer version of CashuPayServer';
            $explanation = 'The program files were replaced with an older version of CashuPayServer, but its '
                . 'database had already been upgraded by a newer one. Nothing is lost. Upload the newer '
                . 'version again (the one you had before, or a later one) and leave the data folder as it is.';
            $support = "Hello,\n\nthe application CashuPayServer on my website " . $host . " stopped working "
                . "after its files were replaced with an older version; its database was already upgraded by a "
                . "newer version. Could you restore the newer program files from a backup, without changing "
                . "its data folder?\n\nThank you.";
        } elseif ($e instanceof \PDOException || stripos($message, 'database') !== false
            || stripos($message, 'SQLSTATE') !== false) {
            $title = 'CashuPayServer cannot open its database';
            $explanation = 'The website could not read its database. This can happen when the server\'s disk is '
                . 'full, or the files\' permissions were changed. Nothing has been deleted. Try again in a few '
                . 'minutes; if it stays like this, send the message below to your hosting provider.';
            $support = "Hello,\n\nthe PHP application CashuPayServer on my website " . $host . " cannot open its "
                . "SQLite database in its data folder. Could you check that the disk is not full and that PHP can "
                . "read and write that folder and the files in it? The website's error log has the details."
                . "\n\nThank you.";
        } else {
            $title = 'CashuPayServer ran into a problem';
            $explanation = 'Something went wrong while loading this page. Try again in a minute. If it keeps '
                . 'happening, send the message below to your hosting provider.';
            $support = "Hello,\n\nthe PHP application CashuPayServer on my website " . $host . " shows an error "
                . "page. Could you look at the website's error log for lines starting with \"CashuPayServer:\" "
                . "and tell me what they say?\n\nThank you.";
        }

        $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $wantsJson = in_array($script, ['api.php', 'cron.php', 'receive.php', 'health.php'], true)
            || preg_match('#/(api|cron|receive|health)(\.php)?(/|$)#', $path)
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        if ($wantsJson) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json');
                header('Cache-Control: no-store');
                header('Retry-After: 60');
            }
            echo json_encode(['error' => 'Service temporarily unavailable']);
            exit(1);
        }
        if ($script === 'payment.php' || preg_match('#/i/[^/]+$#', $path)) {
            // A customer, not the operator: no host message, and never "pay again".
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
            }
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>Payment page unavailable</title></head><body style="font-family:sans-serif;'
                . 'max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5">'
                . '<h1 style="font-size:1.3rem">This payment page is temporarily unavailable</h1>'
                . '<p>Please try again in a few minutes. If you already paid, do not pay again: '
                . 'your payment is not lost. Contact the shop if this does not go away.</p></body></html>';
            exit(1);
        }
        require_once __DIR__ . '/preflight_page.php';
        $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        cashupay_preflight_page($title, $h($explanation), $support, $detail);
    }
}

// Standalone pages: no PHP warning, path or stack trace reaches a visitor, and anything
// uncaught becomes a plain 503 (above). WordPress requests keep WordPress's own settings
// and fatal-error handling.
if (PHP_SAPI !== 'cli' && !defined('ABSPATH') && !defined('CASHUPAY_WORDPRESS')) {
    ini_set('display_errors', '0');
    set_exception_handler('cashupay_uncaught_exception');
}

// Baseline security headers for every entry point (SPB-SEC-011): setup, receive, index
// and cron sent none. Pages with other needs (the admin's relay connections) call
// Security::setSecurityHeaders() again with their own policy; header() replaces the
// earlier value, so the later, specific call wins.
// The setup wizard discovers mints from the browser (Nostr relays over wss, mints over
// https), which the strict default connect-src would block, so it gets the admin policy.
require_once __DIR__ . '/security.php';
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $cashupayIncluder = basename((string)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? ''));
    Security::setSecurityHeaders($cashupayIncluder === 'setup.php' ? Security::adminCsp() : null);
    unset($cashupayIncluder);
}

// A signed emergency notice shut this install down: nothing past this point runs,
// including the admin (see includes/safe_mode.php). One small file read when not.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safe_mode.php';
SafeMode::haltIfShutDown();
