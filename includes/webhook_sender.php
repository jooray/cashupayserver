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

    /**
     * How long past expiry a payment is still watched for, in the sense BTCPay's
     * `monitoringExpiration` means. Mirrors Invoice::EXPIRY_RECOVERY_GRACE; kept local
     * because invoice.php requires this file, so the dependency cannot go the other way.
     */
    private const MONITORING_GRACE = 259200;
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
            if (!self::subscribesTo($webhook, $eventType)) {
                continue;
            }

            self::enqueueWebhook($webhook, $eventType, $invoiceData);
        }
    }

    /**
     * Event types a webhook may subscribe to: BTCPay's full set, so a client that asks
     * for events this server never emits (payouts, payment requests) still pairs.
     */
    public const KNOWN_EVENTS = [
        'InvoiceCreated', 'InvoiceReceivedPayment', 'InvoiceProcessing', 'InvoiceExpired',
        'InvoiceSettled', 'InvoiceInvalid', 'InvoicePaymentSettled',
        'PayoutCreated', 'PayoutApproved', 'PayoutUpdated',
        'PaymentRequestCreated', 'PaymentRequestUpdated', 'PaymentRequestArchived',
        'PaymentRequestStatusChanged',
    ];

    /**
     * Whether a stored webhook row wants this event.
     *
     * This runs inside the settlement transaction, so it must never throw: a stored
     * events value that is not a list of strings (written before the API validated it)
     * used to raise a TypeError there, roll the settlement back on every poll and leave
     * the invoice in Processing for good (DS41-05). Such a row is treated as "every
     * event" — delivering too much is recoverable, a vetoed payment is not.
     */
    public static function subscribesTo(array $webhook, string $eventType): bool {
        $events = json_decode((string)($webhook['events'] ?? ''), true);
        if (!is_array($events)) {
            if ($events !== null && $events !== '') {
                error_log("CashuPayServer: webhook {$webhook['id']} has a malformed event list; sending it every event");
            }
            return true;
        }
        // An empty list means "everything" (BTCPay's authorizedEvents.everything).
        if (empty($events)) {
            return true;
        }
        foreach ($events as $event) {
            if (is_string($event) && $event === $eventType) {
                return true;
            }
        }
        return false;
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

        // Flags the official event schemas define. The WooCommerce gateway reads
        // afterExpiration/partiallyPaid/manuallyMarked/overPaid directly and emits PHP
        // warnings (and, for InvoiceInvalid, a wrong "manually marked" note) when they
        // are missing. CashuPayServer never partially pays, never overpays a Lightning
        // invoice, and only marks Invalid manually.
        $expiration = (int)($invoiceData['expiration_time'] ?? 0);
        $payload['afterExpiration'] = $expiration > 0 && $now > $expiration;
        $payload['partiallyPaid'] = false;
        $payload['overPaid'] = false;
        $payload['manuallyMarked'] = $eventType === 'InvoiceInvalid';

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
            // Fields BTCPay clients read off the invoice object.
            'monitoringExpiration' => $expiration + self::MONITORING_GRACE,
            'archived' => false,
            'availableStatusesForManualMarking' => in_array($invoiceData['status'], ['New', 'Processing'], true)
                ? ['Invalid']
                : [],
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
     * Public HTTP(S) targets on the standard ports (80/443) are always allowed. In
     * addition, the application's *own* origin is allowed even when it resolves to a
     * private address: in WordPress plugin mode the WooCommerce webhook is
     * `site_url('/?wc-api=btcpaygf_default')`, and on a LAN, in Docker, or behind
     * split-horizon DNS that is exactly the case the plain anti-SSRF rule refused —
     * payments settled and orders stayed unpaid, silently.
     *
     * Operators with other internal receivers, or a shop on another port, can opt in
     * with the `webhook_allow_private_targets` config flag.
     */
    public static function isAllowedTarget(string $url): bool {
        return self::planDelivery($url) !== null;
    }

    /**
     * Decide whether and how to connect to a webhook URL, resolving DNS exactly once.
     *
     * Returns the CURLOPT_RESOLVE entries that pin the connection to the addresses that
     * were checked (empty for a literal IP), or null when the target is refused. The old
     * flow validated one lookup and pinned a second, unvalidated one (DS41-03).
     *
     * @return string[]|null
     */
    public static function planDelivery(string $url): ?array {
        if (!self::isPlainHttpUrl($url)) {
            return null;
        }
        $isOwn = self::isOwnOrigin($url);
        $allowPrivate = (bool)Config::get('webhook_allow_private_targets');

        if (!$isOwn && !$allowPrivate) {
            // Other ports on a public host are other services, not a shop (SPB-SEC-004).
            $port = parse_url($url, PHP_URL_PORT);
            if ($port !== null && $port !== false && !in_array((int)$port, [80, 443], true)) {
                return null;
            }
            return Security::resolvePublicTarget($url)['resolve'] ?? null;
        }

        // Own origin or explicit opt-in: private addresses are fine, but the connection
        // still goes to the addresses resolved here and nowhere else.
        $target = Security::resolveTarget($url);
        return $target === null ? null : $target['resolve'];
    }

    /** An http(s) URL with a host and no credentials (a query string is fine). */
    private static function isPlainHttpUrl(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /**
     * True when the URL points at this installation's own origin: same host and the
     * same effective port as Urls::siteBase() (DS41-19). Comparing the host alone made
     * every port on the server — Redis, Elasticsearch, a metrics endpoint — "our own".
     * The http/https twin on the default ports is accepted too, for TLS terminated by a
     * proxy in front.
     */
    public static function isOwnOrigin(string $url): bool {
        // Without a configured base_url, siteBase() falls back to the request's Host
        // header, which the API caller chooses: "own origin" would be whatever they say.
        if (!Urls::isWordPress() && !Config::get('base_url')) {
            return false;
        }
        $target = parse_url($url);
        $own = parse_url(Urls::siteBase());
        if (!$target || !$own || empty($target['host']) || empty($own['host'])) {
            return false;
        }
        if (strtolower($target['host']) !== strtolower($own['host'])) {
            return false;
        }
        $targetScheme = strtolower($target['scheme'] ?? '');
        $ownScheme = strtolower($own['scheme'] ?? '');
        if (!in_array($targetScheme, ['http', 'https'], true) || !in_array($ownScheme, ['http', 'https'], true)) {
            return false;
        }
        $defaultPort = static fn(string $scheme) => $scheme === 'http' ? 80 : 443;
        $targetPort = (int)($target['port'] ?? $defaultPort($targetScheme));
        $ownPort = (int)($own['port'] ?? $defaultPort($ownScheme));

        if ($targetScheme === $ownScheme) {
            return $targetPort === $ownPort;
        }
        // Scheme differs: only the two standard origins of the same host.
        return $targetPort === $defaultPort($targetScheme) && $ownPort === $defaultPort($ownScheme);
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
     * The stored result is deliberately coarse: the deliveries API hands it to anyone
     * holding the webhook permission, and raw cURL errors ("Connection refused" vs.
     * "timed out" per port) turned it into a port scanner (SPB-SEC-004). The detail
     * goes to the server log instead.
     */
    private static function sendRequest(string $url, string $payload, string $signature): array {
        // Anti-SSRF: refuse non-public / non-http(s) targets at delivery time too
        // (defence in depth; the create/update handlers validate as well). The same
        // lookup decides and pins, so DNS cannot change the answer in between.
        $resolve = self::planDelivery($url);
        if ($resolve === null) {
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
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'BTCPay-Sig: ' . $signature,
                'User-Agent: CashuPayServer/1.0',
            ],
        ] + cashupay_curl_protocol_options();
        if (!empty($resolve)) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }

        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        if ($errno !== 0 || $error !== '') {
            error_log('CashuPayServer: webhook delivery to ' . (string)parse_url($url, PHP_URL_HOST)
                . ' failed: ' . $error);
            return [
                'status_code' => 0,
                'response' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timed out' : 'connection failed',
            ];
        }

        // Do not persist the response body (it could contain data fetched from an
        // internal target if the URL check were ever bypassed), nor its size.
        return [
            'status_code' => $statusCode,
            'response' => 'delivered (HTTP ' . (int)$statusCode . ')',
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

    /**
     * Lease the next due row.
     *
     * Events for one invoice reach one webhook strictly in the order they happened: a
     * row waits while an older row for the same webhook and invoice is still
     * deliverable, even when its own retry is due first. Per-row backoff used to let a
     * fresh InvoiceSettled overtake a failed InvoiceExpired, which then arrived last and
     * could move a paid order back to "expired" (OPUS N7). Rows that are given up on
     * (out of attempts, or cancelled) no longer hold anything back.
     */
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
                    AND NOT EXISTS (
                        SELECT 1 FROM webhook_deliveries older
                         WHERE older.webhook_id = wd.webhook_id
                           AND older.invoice_id = wd.invoice_id
                           AND older.delivered_at IS NULL AND older.attempts < ?
                           AND older.target_url IS NOT NULL AND older.signing_secret IS NOT NULL
                           AND (older.created_at < wd.created_at
                                OR (older.created_at = wd.created_at AND older.rowid < wd.rowid))
                    )
                 ORDER BY wd.next_attempt_at ASC, wd.created_at ASC, wd.rowid ASC LIMIT 1
             )",
            [$now + self::LEASE_SECONDS, $leaseToken, self::MAX_ATTEMPTS, $now, $now, self::MAX_ATTEMPTS]
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
        // Sent to where the webhook points now, signed with its current secret, as
        // BTCPay does: the snapshot may hold a URL and secret the shop has since replaced.
        Database::query(
            "UPDATE webhook_deliveries
             SET delivered_at = NULL, attempts = 0, next_attempt_at = 0,
                 leased_until = NULL, lease_token = NULL,
                 target_url = (SELECT w.url FROM webhooks w WHERE w.id = webhook_deliveries.webhook_id),
                 signing_secret = (SELECT w.secret FROM webhooks w WHERE w.id = webhook_deliveries.webhook_id)
             WHERE id = ?",
            [$deliveryId]
        );
    }

    /**
     * Give up on every undelivered row of a webhook.
     *
     * For a deleted webhook, or one whose URL, secret or enabled flag changed: queued
     * rows carry a snapshot of the old URL and secret and would otherwise keep going
     * there for days (DS41 L-9). Rows are kept as history, out of attempts and without
     * a signing secret, so the outbox never picks them up again.
     *
     * @return int Number of rows cancelled
     */
    public static function cancelPending(string $webhookId): int {
        return Database::query(
            "UPDATE webhook_deliveries
             SET attempts = ?, next_attempt_at = 0, signing_secret = NULL,
                 leased_until = NULL, lease_token = NULL,
                 response = 'cancelled: the webhook was deleted or changed'
             WHERE webhook_id = ? AND delivered_at IS NULL",
            [self::MAX_ATTEMPTS, $webhookId]
        )->rowCount();
    }

    /**
     * Verify webhook signature (for testing)
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool {
        $expected = self::calculateSignature($payload, $secret);
        return hash_equals($expected, $signature);
    }
}
