<?php
/**
 * CashuPayServer - Webhook Sender Module
 *
 * Send webhook notifications with HMAC signatures.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/safe_http.php';
require_once __DIR__ . '/background.php';

class WebhookSender {
    // Give up after this many delivery attempts (initial + retries).
    private const MAX_ATTEMPTS = 6;
    private const TIMEOUT = 10;
    // How long a drainer "owns" a claimed row before another drainer may retry
    // it, so an in-flight send isn't picked up twice.
    private const CLAIM_LEASE_SEC = 120;

    /**
     * Fire webhook event.
     *
     * Delivery is now an OUTBOX: we persist one `pending` delivery row per
     * subscribed webhook and return immediately, then a background drain
     * (Background::trigger here + the cron `deliver_webhooks` task) performs the
     * actual HTTP send with retries. This keeps a slow/dead merchant endpoint
     * off the customer's checkout/settlement request path (previously a 10s
     * blocking send per subscription) and means a transient outage no longer
     * silently loses the event — it's retried with backoff.
     */
    public static function fireEvent(string $storeId, string $eventType, array $invoiceData): void {
        // Get all enabled webhooks for this store that subscribe to this event
        $webhooks = Database::fetchAll(
            "SELECT * FROM webhooks WHERE store_id = ? AND enabled = 1",
            [$storeId]
        );

        $enqueued = 0;
        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook['events'], true) ?? [];

            // If events is empty, it means "everything"
            if (!empty($events) && !in_array($eventType, $events)) {
                continue;
            }

            self::enqueueDelivery($webhook, $eventType, $invoiceData);
            $enqueued++;
        }

        // Nudge a prompt background drain so delivery doesn't wait for the next
        // cron tick. Skipped when we're already inside a cron run (the current
        // run will drain), and harmless otherwise: the fire-and-forget internal
        // cron either drains now or hits the cron lock and exits, with the
        // scheduled `deliver_webhooks` task as the guaranteed backstop.
        if ($enqueued > 0 && !defined('CASHUPAY_IN_CRON')) {
            try {
                Background::trigger();
            } catch (\Throwable $e) {
                // Best-effort; cron is the backstop.
            }
        }
    }

    /**
     * Persist a single delivery as a `pending` outbox row (no network here).
     */
    private static function enqueueDelivery(array $webhook, string $eventType, array $invoiceData): void {
        $deliveryId = Database::generateId('del');
        $now = Database::timestamp();

        $payload = self::buildPayload($webhook, $eventType, $invoiceData, $deliveryId, $now);
        $payloadJson = json_encode($payload);

        Database::insert('webhook_deliveries', [
            'id' => $deliveryId,
            'webhook_id' => $webhook['id'],
            'invoice_id' => $invoiceData['id'],
            'event_type' => $eventType,
            'payload' => $payloadJson,
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => $now, // eligible immediately
            'status_code' => null,
            'response' => null,
            'created_at' => $now,
        ]);
    }

    /**
     * Build the BTCPay-format webhook payload.
     */
    private static function buildPayload(array $webhook, string $eventType, array $invoiceData, string $deliveryId, int $now): array {
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

        // The BTCPay WooCommerce plugin branches on partiallyPaid when it
        // handles InvoiceExpired. BareBits rails are all-or-nothing (no
        // partial-payment tracking), so a swept invoice is never partially
        // paid — but the field must exist to match the BTCPay payload shape.
        if ($eventType === 'InvoiceExpired') {
            $payload['partiallyPaid'] = false;
        }

        // Add invoice metadata for certain events
        if (in_array($eventType, ['InvoiceSettled', 'InvoiceReceivedPayment', 'InvoiceCreated', 'InvoiceProvisional'])) {
            if (isset($invoiceData['metadata'])) {
                $metadata = is_string($invoiceData['metadata'])
                    ? json_decode($invoiceData['metadata'], true)
                    : $invoiceData['metadata'];
                $payload['metadata'] = $metadata;
                // Also include in the invoice object
                $payload['invoice']['metadata'] = $metadata;
            }
        }

        return $payload;
    }

    /**
     * Drain due outbox rows: send each, mark delivered on 2xx, otherwise
     * schedule a backoff retry until MAX_ATTEMPTS, then give up (status=failed).
     *
     * Race-safe for concurrent drainers: each row is claimed via an atomic
     * lease UPDATE (attempts bumped + next_retry_at pushed out) and only the
     * claimer sends it. Called from cron's `deliver_webhooks` task.
     *
     * @return array{sent:int, failed:int, gave_up:int}
     */
    public static function drainPending(int $limit = 50): array {
        $now = Database::timestamp();
        $rows = Database::fetchAll(
            "SELECT wd.id, wd.payload, w.url AS hook_url, w.secret AS hook_secret
               FROM webhook_deliveries wd
               JOIN webhooks w ON w.id = wd.webhook_id
              WHERE wd.status = 'pending'
                AND (wd.next_retry_at IS NULL OR wd.next_retry_at <= ?)
              ORDER BY wd.created_at ASC
              LIMIT ?",
            [$now, $limit]
        );

        $sent = 0; $failed = 0; $gaveUp = 0;
        foreach ($rows as $row) {
            // Atomic claim: lease the row (push next_retry_at into the future)
            // so a concurrent drainer skips it while we send.
            $claimed = Database::update(
                'webhook_deliveries',
                ['next_retry_at' => $now + self::CLAIM_LEASE_SEC],
                "id = ? AND status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= ?)",
                [$row['id'], $now]
            );
            if ($claimed !== 1) {
                continue; // another drainer took it
            }
            // Count this attempt (separate statement: Database::update can't
            // express a column self-reference).
            Database::query(
                "UPDATE webhook_deliveries SET attempts = attempts + 1 WHERE id = ?",
                [$row['id']]
            );
            $attempts = (int)(Database::fetchOne(
                "SELECT attempts FROM webhook_deliveries WHERE id = ?",
                [$row['id']]
            )['attempts'] ?? 1);

            $payload = (string)$row['payload'];
            $signature = self::calculateSignature($payload, (string)$row['hook_secret']);
            $result = self::sendRequest((string)$row['hook_url'], $payload, $signature);
            $code = (int)$result['status_code'];

            if ($code >= 200 && $code < 300) {
                Database::update(
                    'webhook_deliveries',
                    [
                        'status' => 'delivered',
                        'status_code' => $code,
                        'response' => $result['response'],
                        'delivered_at' => Database::timestamp(),
                        'next_retry_at' => null,
                    ],
                    'id = ?',
                    [$row['id']]
                );
                $sent++;
                continue;
            }

            // Failure: retry with backoff, or give up after MAX_ATTEMPTS.
            if ($attempts >= self::MAX_ATTEMPTS) {
                Database::update(
                    'webhook_deliveries',
                    ['status' => 'failed', 'status_code' => $code, 'response' => $result['response'], 'next_retry_at' => null],
                    'id = ?',
                    [$row['id']]
                );
                $gaveUp++;
            } else {
                Database::update(
                    'webhook_deliveries',
                    [
                        'status' => 'pending',
                        'status_code' => $code,
                        'response' => $result['response'],
                        'next_retry_at' => Database::timestamp() + self::backoffSeconds($attempts),
                    ],
                    'id = ?',
                    [$row['id']]
                );
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'gave_up' => $gaveUp];
    }

    /**
     * Exponential-ish backoff (seconds) keyed by the number of attempts made.
     */
    private static function backoffSeconds(int $attempts): int {
        $schedule = [15, 60, 300, 1800, 3600];
        $idx = max(0, min($attempts - 1, count($schedule) - 1));
        return $schedule[$idx];
    }

    /**
     * Prune old delivery rows for audit-log hygiene, keeping the most recent
     * $keep TERMINAL rows. Only 'delivered' and 'failed' rows are eligible:
     * a 'pending' row is still scheduled for retry, so it is never counted or
     * evicted here — otherwise a burst or a sustained merchant outage that
     * piles up pending rows could push deliverable events out of the outbox,
     * silently losing them (the exact failure the outbox exists to prevent).
     *
     * @return int number of rows deleted
     */
    public static function pruneDeliveries(int $keep = 1000): int {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM webhook_deliveries WHERE status IN ('delivered', 'failed')"
        );
        $total = (int)($row['cnt'] ?? 0);
        if ($total <= $keep) {
            return 0;
        }
        $deleteCount = $total - $keep;
        Database::query(
            "DELETE FROM webhook_deliveries
              WHERE id IN (
                  SELECT id FROM webhook_deliveries
                  WHERE status IN ('delivered', 'failed')
                  ORDER BY created_at ASC LIMIT ?
              )",
            [$deleteCount]
        );
        return $deleteCount;
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
     *
     * Webhook URLs are merchant-supplied. By default we refuse private/
     * loopback destinations (defense against tenant-supplied SSRF). The
     * operator opt-in (allow_private_endpoints) lifts the restriction —
     * intended for self-hosters who want to point webhooks at a LAN
     * service or local development.
     */
    private static function sendRequest(string $url, string $payload, string $signature): array {
        $result = SafeHttp::request($url, [
            'maxBytes' => 64 * 1024,
            'method' => 'POST',
            'body' => $payload,
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type: application/json',
                'BTCPay-Sig: ' . $signature,
            ],
            'allowPrivate' => SafeHttp::privateEndpointsAllowed(),
            'followRedirects' => false,
        ]);

        if ($result['error'] !== '') {
            return [
                'status_code' => 0,
                'response' => 'cURL error: ' . $result['error'],
            ];
        }

        return [
            'status_code' => $result['status'],
            'response' => substr($result['body'], 0, 1000),
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
        $code = (int)$result['status_code'];
        $ok = $code >= 200 && $code < 300;
        $now = Database::timestamp();

        // Log redelivery. This is an admin-triggered immediate send, so it's
        // recorded in a terminal state rather than re-queued for the drainer.
        Database::insert('webhook_deliveries', [
            'id' => $newDeliveryId,
            'webhook_id' => $delivery['webhook_id'],
            'invoice_id' => $delivery['invoice_id'],
            'event_type' => $delivery['event_type'],
            'payload' => $payloadJson,
            'status' => $ok ? 'delivered' : 'failed',
            'attempts' => 1,
            'next_retry_at' => null,
            'delivered_at' => $ok ? $now : null,
            'status_code' => $code,
            'response' => $result['response'],
            'created_at' => $now,
        ]);

        return $ok;
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
