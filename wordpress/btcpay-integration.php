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

    // Ours even after a move between http and https or with a trailing slash; a
    // look-alike such as /cashupay-copy is not (UX-A11).
    return !cashupay_gateway_url_is_ours((string) $url);
}

/**
 * Configure the BTCPay WooCommerce plugin to use CashuPay
 */
/**
 * Revoke the API key a previous Configuration saved, so re-configuring does not leave
 * an old key valid for the store (MINIMAX 4.15).
 *
 * The option holds the only clear copy of the previous key; the database holds its
 * digest. Keys created by an earlier attempt that never reached the option are removed
 * too, which is what used to leave a live key nobody could see or revoke.
 */
function cashupay_revoke_previous_gateway_keys(string $store_id, string $keep_api_key): int {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/auth.php';

    $keepHash = hash('sha256', $keep_api_key);
    $removed = 0;
    try {
        Database::ensureCurrentSchema();
        $previous = (string) get_option('btcpay_gf_api_key', '');
        if ($previous !== '' && hash_equals($keepHash, hash('sha256', $previous)) === false) {
            $row = Database::fetchOne(
                'SELECT id FROM api_keys WHERE store_id = ? AND key_hash = ?',
                [$store_id, hash('sha256', $previous)]
            );
            if ($row) {
                Auth::deleteApiKey($row['id']);
                $removed++;
            }
        }
        foreach (Database::fetchAll(
            "SELECT id FROM api_keys WHERE store_id = ? AND application_identifier = 'woocommerce' AND key_hash != ?",
            [$store_id, $keepHash]
        ) as $row) {
            Auth::deleteApiKey($row['id']);
            $removed++;
        }
    } catch (Throwable $e) {
        error_log('CashuPayServer: could not revoke the previous WooCommerce key: ' . $e->getMessage());
    }
    return $removed;
}

function cashupay_configure_btcpay_plugin(string $store_id, string $api_key): array {
    if (cashupay_is_real_btcpay_configured()) {
        return [
            'success' => false,
            'error' => 'existing_btcpay',
            'current_url' => get_option('btcpay_gf_url', ''),
            'message' => 'Your shop is connected to another BTCPay Server. To use CashuPay instead, '
                       . 'open WooCommerce > Settings > BTCPay Settings, delete the address in the "BTCPay Server URL" box '
                       . 'and click "Save changes".'
        ];
    }

    cashupay_revoke_previous_gateway_keys($store_id, $api_key);
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
 * Connect the BTCPay for WooCommerce gateway to a CashuPay store: a dedicated key, the
 * gateway's settings and the webhook. Shared by the setup wizard's last step and the
 * wp-admin notice's "Connect" button, so both do exactly the same (ARGON UX-05).
 *
 * @return array{success: bool, message?: string, error?: string, webhook?: array}
 */
function cashupay_connect_woocommerce_store(string $store_id): array {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/auth.php';

    if (cashupay_is_real_btcpay_configured()) {
        // Checked before a key is minted, so a refusal leaves nothing behind.
        return cashupay_configure_btcpay_plugin($store_id, '');
    }
    // A dedicated integration key, not the least-privilege dashboard one: the
    // WooCommerce gateway refetches the invoice on InvoiceSettled and refuses to mark the
    // order paid if that lookup fails, so it needs canviewinvoices as well.
    $createdKey = Auth::createApiKey(
        $store_id,
        'WooCommerce (auto-configured)',
        [
            'btcpay.store.cancreateinvoice',
            'btcpay.store.canviewinvoices',
            'btcpay.store.canmodifyinvoices',
            // Required by the gateway's own key check on settings save.
            'btcpay.store.canviewstoresettings',
            'btcpay.store.webhooks.canmodifywebhooks',
        ],
        'woocommerce'
    );
    $result = cashupay_configure_btcpay_plugin($store_id, $createdKey['key']);
    if (!($result['success'] ?? false)) {
        // The key just minted would otherwise stay live and invisible.
        Auth::deleteApiKey((string)($createdKey['id'] ?? ''));
    }
    return $result;
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

        // Events the BTCPay WooCommerce plugin subscribes to.
        $events = json_encode([
            'InvoiceCreated',
            'InvoiceReceivedPayment',
            'InvoiceProcessing',
            'InvoiceSettled',
            'InvoiceExpired',
            'InvoiceInvalid',
        ]);

        $existing = Database::fetchOne(
            "SELECT id, secret FROM webhooks WHERE store_id = ? AND url = ? AND deleted_at IS NULL",
            [$store_id, $webhookUrl]
        );

        if ($existing) {
            // Configuring is an explicit "make WooCommerce work" action: turn the row back
            // on. uninstall.php disables it (enabled = 0, deleted_at left NULL), so a
            // reinstall used to report success while no order was ever marked paid
            // (ARGON CPS-02). Refresh the event list too, in case an older version or an
            // API edit narrowed it.
            Database::update(
                'webhooks',
                ['enabled' => 1, 'events' => $events],
                'id = ?',
                [$existing['id']]
            );
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
