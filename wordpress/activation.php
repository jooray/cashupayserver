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
 * Plugin activation
 */
function cashupay_activate(): void {
    // Create data directory with protection files
    $dataDir = CASHUPAY_DATA_DIR;

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0750, true);
    }

    // Apache protection
    $htaccess = $dataDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "deny from all\n");
    }

    // Directory index protection
    $indexFile = $dataDir . '/index.php';
    if (!file_exists($indexFile)) {
        file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
    }

    // IIS protection
    $webConfig = $dataDir . '/web.config';
    if (!file_exists($webConfig)) {
        file_put_contents($webConfig, '<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <authorization>
            <deny users="*" />
        </authorization>
    </system.webServer>
</configuration>
');
    }

    // Initialize database
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    Database::initialize();

    // Set base_url config
    Config::set('base_url', site_url('/cashupay'));

    // Schedule WP cron (every minute)
    if (!wp_next_scheduled('cashupay_poll_quotes')) {
        wp_schedule_event(time(), 'every_minute', 'cashupay_poll_quotes');
    }

    // Register the custom cron interval
    add_filter('cron_schedules', 'cashupay_cron_schedules');

    // Flush rewrite rules
    cashupay_add_rewrite_rules();
    flush_rewrite_rules();
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
