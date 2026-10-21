<?php
/**
 * CashuPay WordPress Activation/Deactivation
 *
 * Handles plugin lifecycle events.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Apache 2.2 and 2.4 both understand this form. A bare `deny from all` is a 500 on
 * Apache 2.4 without mod_access_compat, which some hosts treat as "allowed". Keep it
 * identical to what Database::createDataDirectory() writes for standalone installs.
 */
const CASHUPAY_DATA_HTACCESS = <<<'HTACCESS'
# Deny all access to this directory
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;

const CASHUPAY_DATA_INDEX_PHP = "<?php http_response_code(403); exit('Forbidden');";

/** Bump when the protection files written by cashupay_protect_data_dir() change. */
const CASHUPAY_DATA_PROTECTION_VERSION = '2';

/**
 * Create the data directory if needed and write the files that stop Apache and IIS
 * from serving it. nginx ignores all of these; cashupay_data_exposure_notice() in
 * admin-menu.php checks whether the directory is actually reachable over HTTP.
 *
 * Earlier versions wrote a bare `deny from all` and a silent index.php; those are
 * replaced. Any other content is left alone, as the operator may have customised it.
 */
function cashupay_protect_data_dir(string $dataDir): void {
    if (!is_dir($dataDir)) {
        // 0700: the directory holds spendable ecash and the seed phrases that derive it.
        if (!@mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new RuntimeException(
                "CashuPay could not create its data folder at $dataDir. "
                . 'Ask your hosting provider to make that location writable by the web server, '
                . "or set a different folder with define('CASHUPAY_DATA_DIR', '/path/outside/your/site'); in wp-config.php."
            );
        }
    } elseif (function_exists('posix_geteuid') && @fileowner($dataDir) === posix_geteuid()) {
        // Ours, so PHP needs no "other" access: drop it. Group access is left as is,
        // since a host may share the folder between a deploy user and the web server.
        $mode = @fileperms($dataDir);
        if ($mode !== false && ($mode & 0007) !== 0) {
            @chmod($dataDir, $mode & 0770);
        }
    }

    $legacy = [
        '.htaccess' => ["deny from all\n"],
        'index.php' => ["<?php\n// Silence is golden.\n"],
    ];
    $wanted = [
        '.htaccess' => CASHUPAY_DATA_HTACCESS,
        'index.php' => CASHUPAY_DATA_INDEX_PHP,
        'web.config' => '<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <authorization>
            <deny users="*" />
        </authorization>
    </system.webServer>
</configuration>
',
    ];

    foreach ($wanted as $name => $content) {
        $path = $dataDir . '/' . $name;
        if (file_exists($path)) {
            $current = @file_get_contents($path);
            if ($current === $content || !in_array($current, $legacy[$name] ?? [], true)) {
                continue;
            }
        }
        @file_put_contents($path, $content);
    }

    // Records which installation (and which site of a network) the folder belongs to,
    // so another WordPress site next to this one never adopts it (bootstrap.php).
    if (!file_exists($dataDir . '/.owner')) {
        @file_put_contents($dataDir . '/.owner', cashupay_data_dir_owner(CASHUPAY_BLOG_ID));
    }
}

/**
 * Plugin updates do not run the activation hook, so an install protected by an older
 * version keeps its old files until this runs once from a normal request.
 */
function cashupay_maybe_upgrade_data_protection(): void {
    if (get_option('cashupay_data_protection_version') === CASHUPAY_DATA_PROTECTION_VERSION) {
        return;
    }
    if (!is_dir(cashupay_data_dir())) {
        return; // Activation (or setup) creates it; nothing to upgrade yet.
    }
    cashupay_protect_data_dir(cashupay_data_dir());
    update_option('cashupay_data_protection_version', CASHUPAY_DATA_PROTECTION_VERSION);
}

/**
 * Remember why CashuPay could not start, for the admin-only notice in admin-menu.php.
 * The shop itself keeps working; only CashuPay is off.
 */
function cashupay_record_startup_error(string $where, Throwable $e): void {
    $message = $e->getMessage();
    if (function_exists('error_log')) {
        error_log('CashuPay ' . $where . ' failed: ' . get_class($e) . ': ' . $message);
    }
    $GLOBALS['cashupay_startup_error'] = $message;
    if ($where === 'activation' && function_exists('update_option')) {
        // The activation request redirects before any notice prints; keep it for the next page.
        update_option('cashupay_activation_error', $message, false);
    }
}

/**
 * Plugin activation
 */
function cashupay_activate(): void {
    try {
        cashupay_activate_unguarded();
        delete_option('cashupay_activation_error');
    } catch (Throwable $e) {
        // A PHP fatal here leaves WordPress showing "Plugin could not be activated
        // because it triggered a fatal error" at best, and a blank site at worst.
        cashupay_record_startup_error('activation', $e);
    }
}

function cashupay_activate_unguarded(): void {
    if (!empty($GLOBALS['cashupay_refuse_site'])) {
        throw new RuntimeException((string) $GLOBALS['cashupay_startup_error']);
    }

    // Create data directory with protection files
    cashupay_protect_data_dir(cashupay_data_dir());
    update_option('cashupay_data_protection_version', CASHUPAY_DATA_PROTECTION_VERSION);
    delete_transient('cashupay_data_exposure');

    // Initialize database
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    Database::initialize();

    // Pin base_url to this site, unless the database already belongs to another site:
    // overwriting it broke that site's payment and webhook links. The same site after a
    // switch between http and https is updated.
    $existing = Config::get('base_url');
    if (!is_string($existing) || $existing === '' || cashupay_same_site_url($existing, site_url('/cashupay'))) {
        Config::set('base_url', site_url('/cashupay'));
    } else {
        error_log('CashuPay activation: base_url ' . $existing . ' belongs to another site; left unchanged.');
    }

    // Schedule WP cron (every minute)
    if (!wp_next_scheduled('cashupay_poll_quotes')) {
        wp_schedule_event(time(), 'every_minute', 'cashupay_poll_quotes');
    }

    // Register the custom cron interval
    add_filter('cron_schedules', 'cashupay_cron_schedules');

    // Flush rewrite rules (also marks the current rules version as flushed)
    cashupay_add_rewrite_rules();
    flush_rewrite_rules();
    delete_transient('cashupay_rewrite_flush_retry');
    cashupay_mark_rewrite_rules_flushed();
}

/**
 * Plugin deactivation
 */
function cashupay_deactivate(): void {
    // Unschedule WP cron
    $timestamp = wp_next_scheduled('cashupay_poll_quotes');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cashupay_poll_quotes');
    }

    // Flush rewrite rules
    flush_rewrite_rules();

    // Keep data directory intact (user's ecash tokens)
}

/**
 * Add custom cron interval
 */
add_filter('cron_schedules', 'cashupay_cron_schedules');

function cashupay_cron_schedules(array $schedules): array {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display' => 'Every Minute',
    ];
    return $schedules;
}
