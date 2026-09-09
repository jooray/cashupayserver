<?php
/**
 * CashuPayServer - Webhook Sender Module
 *
 * Send webhook notifications with HMAC signatures.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/urls.php';

class WebhookSender {
    /**
     * Retry for about three days.
     *
     * The old value of 4 attempts over ~3.5 minutes meant a shop that was down for a
     * five-minute deploy never learned its invoice settled, with nothing in the UI to
     * say so. With the backoff below (30s doubling to a 1h ceiling) 80 attempts span
     * roughly 72 hours.
     */
    private const MAX_ATTEMPTS = 80;
    private const MAX_BACKOFF = 3600;
    private const TIMEOUT = 10;
    private const LEASE_SECONDS = 30;

    /**
     * Enqueue an event for durable delivery. If called inside a transaction, the
     * invoice transition and all matching webhook rows commit atomically.
     */
    public static function fireEvent(string $storeId, string $eventType, array $invoiceData): void {
        // Get all enabled webhooks for this store that subscribe to this event
        $webhooks = Database::fetchAll(
            "SELECT * FROM webhooks WHERE store_id = ? AND enabled = 1 AND deleted_at IS NULL",
            [$storeId]
        );

        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook['events'], true) ?? [];

            // If events is empty, it means "everything"
            if (!empty($events) && !in_array($eventType, $events)) {
                continue;
            }

            self::enqueueWebhook($webhook, $eventType, $invoiceData);
        }
    }

    /**
     * Add one subscribed webhook delivery to the outbox.
     */
    private static function enqueueWebhook(array $webhook, string $eventType, array $invoiceData): void {
        $deliveryId = Database::generateId('del');
        $now = Database::timestamp();

        // Build payload (BTCPay format)
        $payload = [
            'deliveryId' => $deliveryId,
            'webhookId' => $webhook['id'],
            'originalDeliveryId' => $deliveryId,
            'isRedelivery' => false,
            'type' => $eventType,
            'timestamp' => $now,
            'storeId' => $webhook['store_id'],
            'invoiceId' => $invoiceData['id'],
        ];

        // M5: Add full invoice data (BTCPay compatible)
        $payload['invoice'] = [
            'id' => $invoiceData['id'],
            'storeId' => $invoiceData['store_id'],
            'status' => $invoiceData['status'],
            'additionalStatus' => $invoiceData['additional_status'] ?? 'None',
            'amount' => $invoiceData['amount'],
            'currency' => $invoiceData['currency'],
            'amountSats' => $invoiceData['amount_sats'] ?? null,
            'createdTime' => $invoiceData['created_at'],
            'expirationTime' => $invoiceData['expiration_time'],
        ];

        // Add invoice metadata for certain events
        if (in_array($eventType, ['InvoiceSettled', 'InvoiceReceivedPayment', 'InvoiceCreated'])) {
            if (isset($invoiceData['metadata'])) {
                $metadata = is_string($invoiceData['metadata'])
                    ? json_decode($invoiceData['metadata'], true)
                    : $invoiceData['metadata'];
                $payload['metadata'] = $metadata;
                // Also include in the invoice object
                $payload['invoice']['metadata'] = $metadata;
            }
        }

        $payloadJson = json_encode($payload);

        $idempotencyKey = $webhook['id'] . '|' . $invoiceData['id'] . '|' . $eventType;
        Database::query(
            "INSERT OR IGNORE INTO webhook_deliveries
             (id, webhook_id, invoice_id, event_type, payload, created_at, attempts,
              next_attempt_at, idempotency_key, target_url, signing_secret)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)",
            [
                $deliveryId, $webhook['id'], $invoiceData['id'], $eventType, $payloadJson,
                $now, $now, $idempotencyKey, $webhook['url'], $webhook['secret']
            ]
        );
    }

    /**
     * Whether a webhook may be delivered to this URL.
     *
     * Public HTTP(S) targets are always allowed. In addition, the application's *own*
     * origin is allowed even when it resolves to a private address: in WordPress plugin
     * mode the WooCommerce webhook is `site_url('/?wc-api=btcpaygf_default')`, and on a
     * LAN, in Docker, or behind split-horizon DNS that is exactly the case the plain
     * anti-SSRF rule refused — payments settled and orders stayed unpaid, silently.
     *
     * Operators with other internal receivers can opt in with the
     * `webhook_allow_private_targets` config flag.
     */
    public static function isAllowedTarget(string $url): bool {
        if (Security::isSafePublicHttpUrl($url)) {
            return true;
        }
        if (Config::get('webhook_allow_private_targets')) {
            return Security::isSafeAppBaseUrl($url);
        }
        return self::isOwnOrigin($url);
    }

    /** True when the URL points at this installation's own host. */
    private static function isOwnOrigin(string $url): bool {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || !Security::isSafeAppBaseUrl($url)) {
            return false;
        }

        $ownHost = strtolower((string)parse_url(Urls::siteBase(), PHP_URL_HOST));
        return $ownHost !== '' && $host === $ownHost;
    }

    /**
     * Calculate HMAC signature (BTCPay format)
     */
    private static function calculateSignature(string $payload, string $secret): string {
        $hmac = hash_hmac('sha256', $payload, $secret);
        return 'sha256=' . $hmac;
    }

    /**
     * Send HTTP request
     */
    private static function sendRequest(string $url, string $payload, string $signature): array {
        // Anti-SSRF: refuse non-public / non-http(s) targets at delivery time too
        // (defence in depth; the create/update handlers validate as well).
        if (!self::isAllowedTarget($url)) {
            return [
                'status_code' => 0,
                'response' => 'blocked: webhook URL is not an allowed target',
            ];
        }

        $ch = curl_init($url);

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Restrict protocols and forbid redirects so a target cannot bounce us to
            // file:// / gopher:// / an internal host. See FABLE-SECURITY-AUDIT (CRIT-4).
            CURLOPT_PROTOCOLS_STR => 'https,http',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https,http',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'BTCPay-Sig: ' . $signature,
                'User-Agent: CashuPayServer/1.0',
            ],
        ];

        // Connect to the address the safety check approved. Validating DNS and then
        // letting cURL resolve again leaves a rebinding window.
        $resolve = Security::resolveOptionFor($url);
        if (!empty($resolve)) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            return [
                'status_code' => 0,
                'response' => 'cURL error: ' . $error,
            ];
        }

        // Do not persist the full response body (it could contain data fetched from an
        // internal target if the URL check were ever bypassed). Store only a short,
        // length-capped marker for debugging.
        return [
            'status_code' => $statusCode,
            'response' => 'delivered (HTTP ' . (int)$statusCode . ', ' . strlen((string)$response) . ' bytes)',
        ];
    }

    /**
     * Deliver due outbox rows with short leases so overlapping cron runs do not
     * send the same row concurrently. Failed rows use bounded exponential retry.
     *
     * @return int Number of rows attempted
     */
    public static function deliverPending(int $limit = 20): int {
        $attempted = 0;
        for ($i = 0; $i < $limit; $i++) {
            $delivery = self::claimNextDelivery();
            if ($delivery === null) {
                break;
            }

            $signature = self::calculateSignature($delivery['payload'], $delivery['signing_secret']);
            $result = self::sendRequest($delivery['target_url'], $delivery['payload'], $signature);
            $now = time();
            $success = $result['status_code'] >= 200 && $result['status_code'] < 300;
            $attempts = (int)$delivery['attempts'] + 1;
            $nextAttempt = $success || $attempts >= self::MAX_ATTEMPTS
                ? 0
                : $now + (int)min(self::MAX_BACKOFF, 30 * (2 ** min($attempts - 1, 20)));

            Database::query(
                "UPDATE webhook_deliveries
                 SET attempts = ?, status_code = ?, response = ?, last_attempt_at = ?,
                     delivered_at = ?, next_attempt_at = ?, leased_until = NULL, lease_token = NULL
                 WHERE id = ? AND lease_token = ?",
                [$attempts, $result['status_code'], $result['response'], $now,
                 $success ? $now : null, $nextAttempt, $delivery['id'], $delivery['lease_token']]
            );
            $attempted++;
        }
        return $attempted;
    }

    private static function claimNextDelivery(): ?array {
        $now = time();
        $leaseToken = bin2hex(random_bytes(16));
        Database::query(
            "UPDATE webhook_deliveries SET leased_until = ?, lease_token = ?
             WHERE id = (
                 SELECT wd.id FROM webhook_deliveries wd
                  WHERE wd.delivered_at IS NULL AND wd.attempts < ? AND wd.next_attempt_at <= ?
                    AND (wd.leased_until IS NULL OR wd.leased_until < ?)
                    AND wd.target_url IS NOT NULL AND wd.signing_secret IS NOT NULL
                 ORDER BY wd.next_attempt_at ASC, wd.created_at ASC LIMIT 1
             )",
            [$now + self::LEASE_SECONDS, $leaseToken, self::MAX_ATTEMPTS, $now, $now]
        );
        return Database::fetchOne(
            "SELECT wd.* FROM webhook_deliveries wd WHERE wd.lease_token = ?",
            [$leaseToken]
        );
    }

    /**
     * Get delivery history for a webhook
     */
    public static function getDeliveries(string $webhookId, int $limit = 20): array {
        return Database::fetchAll(
            "SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY created_at DESC LIMIT ?",
            [$webhookId, $limit]
        );
    }

    /**
     * Put a delivery back in the outbox for another attempt.
     *
     * Attempts are reset so a redelivery gets the full retry budget again, and the
     * delivered marker is cleared so the outbox picks the row up.
     */
    public static function requeueDelivery(string $deliveryId): void {
        Database::query(
            "UPDATE webhook_deliveries
             SET delivered_at = NULL, attempts = 0, next_attempt_at = 0,
                 leased_until = NULL, lease_token = NULL
             WHERE id = ?",
            [$deliveryId]
        );
    }

    /**
     * Verify webhook signature (for testing)
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool {
        $expected = self::calculateSignature($payload, $secret);
        return hash_equals($expected, $signature);
    }
}
