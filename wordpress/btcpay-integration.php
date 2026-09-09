<?php
/**
 * CashuPay BTCPay WooCommerce Auto-Configuration
 *
 * Safely configures the BTCPay WooCommerce plugin to point to CashuPay.
 * Includes safety checks to avoid overwriting a real BTCPay Server config.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if a real BTCPay Server (not CashuPay) is configured
 */
function cashupay_is_real_btcpay_configured(): bool {
    $url = get_option('btcpay_gf_url', '');
    if (empty($url)) {
        return false;
    }

    // Check if URL points to CashuPay (not a real BTCPay Server)
    $cashupay_url = site_url('/cashupay');
    if (strpos($url, $cashupay_url) === 0) {
        return false; // Already ours
    }

    return true; // Real BTCPay Server is configured
}

/**
 * Configure the BTCPay WooCommerce plugin to use CashuPay
 */
function cashupay_configure_btcpay_plugin(string $store_id, string $api_key): array {
    if (cashupay_is_real_btcpay_configured()) {
        return [
            'success' => false,
            'error' => 'existing_btcpay',
            'current_url' => get_option('btcpay_gf_url', ''),
            'message' => 'A real BTCPay Server is already configured. '
                       . 'Disconnect it first via WooCommerce > Settings > Payments > BTCPay.'
        ];
    }

    update_option('btcpay_gf_url', site_url('/cashupay'));
    update_option('btcpay_gf_api_key', $api_key);
    update_option('btcpay_gf_store_id', $store_id);

    // Register webhook with CashuPayServer for invoice events
    $webhookResult = cashupay_register_webhook($store_id);

    return [
        'success' => true,
        'webhook' => $webhookResult
    ];
}

/**
 * Register a webhook with CashuPayServer for WooCommerce BTCPay plugin
 *
 * The BTCPay WooCommerce plugin expects webhooks at: /?wc-api=btcpaygf_default
 */
function cashupay_register_webhook(string $store_id): array {
    // Build the webhook callback URL (same as WC()->api_request_url('btcpaygf_default'))
    $webhookUrl = site_url('/?wc-api=btcpaygf_default');

    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/webhook_sender.php';

    try {
        // Go through Database, not a private PDO handle: that bypassed WAL mode, the busy
        // timeout and the schema check, and wrote rows the delivery code would then refuse.
        Database::ensureCurrentSchema();

        if (!WebhookSender::isAllowedTarget($webhookUrl)) {
            return [
                'success' => false,
                'error' => 'This site\'s own URL (' . $webhookUrl . ') is not an allowed webhook target.',
            ];
        }

        $existing = Database::fetchOne(
            "SELECT id, secret FROM webhooks WHERE store_id = ? AND url = ? AND deleted_at IS NULL",
            [$store_id, $webhookUrl]
        );

        if ($existing) {
            update_option('btcpay_gf_webhook', [
                'id' => $existing['id'],
                'url' => $webhookUrl,
                'secret' => $existing['secret'],
            ]);

            return [
                'success' => true,
                'webhook_id' => $existing['id'],
                'existing' => true,
            ];
        }

        $webhookId = Database::generateId('wh');
        $secret = bin2hex(random_bytes(32));

        // Events the BTCPay WooCommerce plugin subscribes to.
        $events = json_encode([
            'InvoiceCreated',
            'InvoiceReceivedPayment',
            'InvoiceProcessing',
            'InvoiceSettled',
            'InvoiceExpired',
            'InvoiceInvalid',
        ]);

        Database::insert('webhooks', [
            'id' => $webhookId,
            'store_id' => $store_id,
            'url' => $webhookUrl,
            'secret' => $secret,
            'events' => $events,
            'enabled' => 1,
            'created_at' => time(),
        ]);

        update_option('btcpay_gf_webhook', [
            'id' => $webhookId,
            'url' => $webhookUrl,
            'secret' => $secret,
        ]);

        return [
            'success' => true,
            'webhook_id' => $webhookId,
            'existing' => false,
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage(),
        ];
    }
}
