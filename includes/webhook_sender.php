<?php
/**
 * CashuPayServer - Webhook Sender Module
 *
 * Send webhook notifications with HMAC signatures.
 */

require_once __DIR__ . '/database.php';

class WebhookSender {
    private const MAX_RETRIES = 3;
    private const TIMEOUT = 10;

    /**
     * Fire webhook event
     */
    public static function fireEvent(string $storeId, string $eventType, array $invoiceData): void {
        // Get all enabled webhooks for this store that subscribe to this event
        $webhooks = Database::fetchAll(
            "SELECT * FROM webhooks WHERE store_id = ? AND enabled = 1",
            [$storeId]
        );

        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook['events'], true) ?? [];

            // If events is empty, it means "everything"
            if (!empty($events) && !in_array($eventType, $events)) {
                continue;
            }

            self::deliverWebhook($webhook, $eventType, $invoiceData);
        }
    }

    /**
     * Deliver webhook to endpoint
     */
    private static function deliverWebhook(array $webhook, string $eventType, array $invoiceData): void {
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

        // Calculate HMAC signature
        $signature = self::calculateSignature($payloadJson, $webhook['secret']);

        // Send webhook
        $result = self::sendRequest($webhook['url'], $payloadJson, $signature);

        // Log delivery
        Database::insert('webhook_deliveries', [
            'id' => $deliveryId,
            'webhook_id' => $webhook['id'],
            'invoice_id' => $invoiceData['id'],
            'event_type' => $eventType,
            'payload' => $payloadJson,
            'status_code' => $result['status_code'],
            'response' => $result['response'],
            'created_at' => $now,
        ]);
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
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'BTCPay-Sig: ' . $signature,
                'User-Agent: CashuPayServer/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            return [
                'status_code' => 0,
                'response' => 'cURL error: ' . $error,
            ];
        }

        return [
            'status_code' => $statusCode,
            'response' => substr($response, 0, 1000), // Limit stored response
        ];
    }

    /**
     * Redeliver a webhook
     */
    public static function redeliver(string $deliveryId): bool {
        $delivery = Database::fetchOne(
            "SELECT wd.*, w.url, w.secret FROM webhook_deliveries wd
             JOIN webhooks w ON w.id = wd.webhook_id
             WHERE wd.id = ?",
            [$deliveryId]
        );

        if ($delivery === null) {
            return false;
        }

        // Parse original payload and update for redelivery
        $payload = json_decode($delivery['payload'], true);
        $newDeliveryId = Database::generateId('del');
        $payload['deliveryId'] = $newDeliveryId;
        $payload['originalDeliveryId'] = $delivery['id'];
        $payload['isRedelivery'] = true;
        $payload['timestamp'] = Database::timestamp();

        $payloadJson = json_encode($payload);
        $signature = self::calculateSignature($payloadJson, $delivery['secret']);

        $result = self::sendRequest($delivery['url'], $payloadJson, $signature);

        // Log redelivery
        Database::insert('webhook_deliveries', [
            'id' => $newDeliveryId,
            'webhook_id' => $delivery['webhook_id'],
            'invoice_id' => $delivery['invoice_id'],
            'event_type' => $delivery['event_type'],
            'payload' => $payloadJson,
            'status_code' => $result['status_code'],
            'response' => $result['response'],
            'created_at' => Database::timestamp(),
        ]);

        return $result['status_code'] >= 200 && $result['status_code'] < 300;
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
     * Verify webhook signature (for testing)
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool {
        $expected = self::calculateSignature($payload, $secret);
        return hash_equals($expected, $signature);
    }
}
