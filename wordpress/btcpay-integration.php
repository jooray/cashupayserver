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

    // CashuPayServer database path
    $dataDir = defined('CASHUPAY_DATA_DIR') ? CASHUPAY_DATA_DIR : ABSPATH . 'cashupay/data';
    $dbPath = rtrim($dataDir, '/') . '/cashupay.sqlite';

    if (!file_exists($dbPath)) {
        return [
            'success' => false,
            'error' => 'Database not found at: ' . $dbPath
        ];
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if webhook already exists for this store and URL
        $stmt = $pdo->prepare("SELECT id, secret FROM webhooks WHERE store_id = ? AND url = ?");
        $stmt->execute([$store_id, $webhookUrl]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Webhook already exists, store secret in WooCommerce options
            update_option('btcpay_gf_webhook', [
                'id' => $existing['id'],
                'url' => $webhookUrl,
                'secret' => $existing['secret']
            ]);

            return [
                'success' => true,
                'webhook_id' => $existing['id'],
                'existing' => true
            ];
        }

        // Generate webhook ID and secret
        $webhookId = 'wh_' . bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));

        // Events that BTCPay WooCommerce plugin expects
        $events = json_encode([
            'InvoiceCreated',
            'InvoiceReceivedPayment',
            'InvoiceProcessing',
            'InvoiceSettled',
            'InvoiceExpired',
            'InvoiceInvalid'
        ]);

        // Insert webhook into CashuPayServer database
        $stmt = $pdo->prepare("
            INSERT INTO webhooks (id, store_id, url, secret, events, enabled, created_at)
            VALUES (?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([
            $webhookId,
            $store_id,
            $webhookUrl,
            $secret,
            $events,
            time()
        ]);

        // Store webhook info in WooCommerce options (BTCPay plugin expects this)
        update_option('btcpay_gf_webhook', [
            'id' => $webhookId,
            'url' => $webhookUrl,
            'secret' => $secret
        ]);

        return [
            'success' => true,
            'webhook_id' => $webhookId,
            'existing' => false
        ];

    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    }
}
