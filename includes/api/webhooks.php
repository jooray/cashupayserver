<?php
/**
 * CashuPayServer - Webhook API Handlers
 */

require_once __DIR__ . '/../webhook_sender.php';

/**
 * Create a new webhook
 */
function handleCreateWebhook(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');

    // Verify store exists
    $store = Database::fetchOne("SELECT id FROM stores WHERE id = ?", [$storeId]);
    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    $url = $body['url'] ?? '';
    if (!is_string($url) || $url === '') {
        errorResponse('validation-error', 'Webhook URL is required');
    }
    validateWebhookUrl($url);
    $events = parseWebhookEvents($body, true);
    $enabled = parseWebhookEnabled($body) ?? true;

    // BTCPay uses the client's secret when it sends one and only generates one
    // otherwise. Ignoring it made every signature check on the shop side fail
    // (OPUS-022).
    $secret = parseWebhookSecret($body) ?? bin2hex(random_bytes(32));
    $webhookId = Database::generateId('wh');

    Database::insert('webhooks', [
        'id' => $webhookId,
        'store_id' => $storeId,
        'url' => $url,
        'secret' => $secret,
        'events' => json_encode($events),
        'enabled' => $enabled ? 1 : 0,
        'created_at' => Database::timestamp(),
    ]);

    $webhook = Database::fetchOne("SELECT * FROM webhooks WHERE id = ?", [$webhookId]);

    jsonResponse(formatWebhookForApi($webhook, true), 200);
}

/**
 * Get webhooks for a store
 */
function handleGetWebhooks(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');

    $webhooks = Database::fetchAll(
        "SELECT * FROM webhooks WHERE store_id = ? AND deleted_at IS NULL ORDER BY created_at DESC",
        [$storeId]
    );

    $result = array_map(fn($w) => formatWebhookForApi($w, false), $webhooks);
    jsonResponse($result);
}

/**
 * Get a single webhook
 */
function handleGetWebhook(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');
    $webhookId = $params['webhookId'];

    $webhook = Database::fetchOne(
        "SELECT * FROM webhooks WHERE id = ? AND store_id = ? AND deleted_at IS NULL",
        [$webhookId, $storeId]
    );

    if ($webhook === null) {
        errorResponse('not-found', 'Webhook not found', 404);
    }

    jsonResponse(formatWebhookForApi($webhook, false));
}

/**
 * Update a webhook
 */
function handleUpdateWebhook(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');
    $webhookId = $params['webhookId'];

    $webhook = Database::fetchOne(
        "SELECT * FROM webhooks WHERE id = ? AND store_id = ? AND deleted_at IS NULL",
        [$webhookId, $storeId]
    );

    if ($webhook === null) {
        errorResponse('not-found', 'Webhook not found', 404);
    }

    $updates = [];

    if (array_key_exists('url', $body)) {
        if (!is_string($body['url']) || $body['url'] === '') {
            errorResponse('validation-error', 'Webhook URL must be a string');
        }
        validateWebhookUrl($body['url']);
        $updates['url'] = $body['url'];
    }

    $events = parseWebhookEvents($body, false);
    if ($events !== null) {
        $updates['events'] = json_encode($events);
    }

    $enabled = parseWebhookEnabled($body);
    if ($enabled !== null) {
        $updates['enabled'] = $enabled ? 1 : 0;
    }

    // BTCPay lets PUT rotate the secret.
    $secret = parseWebhookSecret($body);
    if ($secret !== null) {
        $updates['secret'] = $secret;
    }

    if (!empty($updates)) {
        Database::update('webhooks', $updates, 'id = ?', [$webhookId]);
    }

    // Queued deliveries carry the old URL and secret: a repointed, re-keyed or
    // switched-off webhook must not keep receiving them (DS41 L-9).
    $changed = false;
    foreach (['url', 'secret', 'enabled'] as $field) {
        if (array_key_exists($field, $updates) && (string)$updates[$field] !== (string)$webhook[$field]) {
            $changed = true;
        }
    }
    if ($changed) {
        WebhookSender::cancelPending($webhookId);
    }

    $webhook = Database::fetchOne("SELECT * FROM webhooks WHERE id = ? AND deleted_at IS NULL", [$webhookId]);
    jsonResponse(formatWebhookForApi($webhook, false));
}

/**
 * Delete a webhook
 */
function handleDeleteWebhook(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');
    $webhookId = $params['webhookId'];

    $webhook = Database::fetchOne(
        "SELECT id FROM webhooks WHERE id = ? AND store_id = ? AND deleted_at IS NULL",
        [$webhookId, $storeId]
    );

    if ($webhook === null) {
        errorResponse('not-found', 'Webhook not found', 404);
    }

    // Keep the row as a tombstone so the delivery history stays linked to it, but
    // nothing queued for it is sent any more: deleting a webhook means "stop" (DS41 L-9).
    Database::update(
        'webhooks',
        ['enabled' => 0, 'deleted_at' => Database::timestamp()],
        'id = ?',
        [$webhookId]
    );
    WebhookSender::cancelPending($webhookId);

    http_response_code(200);
    exit;
}

/**
 * Refuse a webhook URL the server will not deliver to (anti-SSRF).
 */
function validateWebhookUrl(string $url): void {
    if (strlen($url) > 2048) {
        errorResponse('validation-error', 'Webhook URL is too long');
    }
    // Only public http(s) targets on the standard ports, or this installation's own
    // origin (blocks file://, internal IPs, cloud metadata, other services' ports).
    if (!WebhookSender::isAllowedTarget($url)) {
        errorResponse('validation-error', 'Webhook URL must be a public http(s) URL on the standard port (80 or 443)');
    }
}

/**
 * The event list a create/update body asks for, validated (DS41-05).
 *
 * Returns [] for "every event", a list of known event names, or null when an update
 * leaves the events alone. A value that is not a list of known names is a 400: stored
 * as-is it used to break settlement for the whole store.
 *
 * @return string[]|null
 */
function parseWebhookEvents(array $body, bool $creating): ?array {
    $authorized = $body['authorizedEvents'] ?? null;
    if ($authorized !== null && !is_array($authorized)) {
        errorResponse('validation-error', 'authorizedEvents must be an object');
    }
    $authorized = $authorized ?? [];

    $everything = $authorized['everything'] ?? null;
    if ($everything !== null && !is_bool($everything)) {
        errorResponse('validation-error', 'authorizedEvents.everything must be true or false');
    }
    if ($everything === true) {
        return [];
    }

    $specific = $authorized['specificEvents'] ?? $authorized['specific'] ?? $body['events'] ?? null;
    if ($specific === null) {
        if ($everything === false) {
            errorResponse('validation-error', 'Choose at least one event in authorizedEvents.specificEvents');
        }
        return $creating ? [] : null;
    }

    if (!is_array($specific) || !array_is_list($specific)) {
        errorResponse('validation-error', 'specificEvents must be a list of event names');
    }
    foreach ($specific as $event) {
        if (!is_string($event) || !in_array($event, WebhookSender::KNOWN_EVENTS, true)) {
            errorResponse('validation-error', 'Unknown webhook event: '
                . (is_string($event) ? $event : gettype($event))
                . '. Known events: ' . implode(', ', WebhookSender::KNOWN_EVENTS));
        }
    }
    if (empty($specific) && $everything === false) {
        // Stored as [] this would mean "everything", the opposite of what was asked.
        errorResponse('validation-error', 'Choose at least one event in authorizedEvents.specificEvents');
    }
    return array_values(array_unique($specific));
}

/** `enabled` from a body as a bool, or null when absent. */
function parseWebhookEnabled(array $body): ?bool {
    if (!array_key_exists('enabled', $body) || $body['enabled'] === null) {
        return null;
    }
    $enabled = $body['enabled'];
    if (is_bool($enabled)) {
        return $enabled;
    }
    if ($enabled === 0 || $enabled === 1) {
        return (bool)$enabled;
    }
    errorResponse('validation-error', 'enabled must be true or false');
    return null;
}

/** A client-supplied signing secret, or null when none was sent. */
function parseWebhookSecret(array $body): ?string {
    if (!array_key_exists('secret', $body) || $body['secret'] === null || $body['secret'] === '') {
        return null;
    }
    $secret = $body['secret'];
    if (!is_string($secret) || strlen($secret) > 256 || preg_match('/[\x00-\x1F\x7F]/', $secret)) {
        errorResponse('validation-error', 'secret must be text of at most 256 characters');
    }
    return $secret;
}

/**
 * Format webhook for API response
 */
function formatWebhookForApi(array $webhook, bool $includeSecret = false): array {
    $events = json_decode((string)$webhook['events'], true);
    if (!is_array($events)) {
        $events = []; // a malformed legacy row receives every event; say so
    }
    $events = array_values(array_filter($events, 'is_string'));

    $result = [
        'id' => $webhook['id'],
        'url' => $webhook['url'],
        'authorizedEvents' => [
            'everything' => empty($events),
            'specificEvents' => $events,
        ],
        'enabled' => (bool)$webhook['enabled'],
        'createdTime' => $webhook['created_at'],
    ];

    // Only include secret on creation
    if ($includeSecret) {
        $result['secret'] = $webhook['secret'];
    }

    return $result;
}

/**
 * GET /stores/{storeId}/webhooks/{webhookId}/deliveries
 *
 * BTCPay exposes this and its clients use it to diagnose "the order never got marked
 * paid". Without it the only record of a failed delivery was the server's error log.
 */
function handleGetWebhookDeliveries(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');

    $webhook = Database::fetchOne(
        "SELECT id FROM webhooks WHERE id = ? AND store_id = ? AND deleted_at IS NULL",
        [$params['webhookId'], $storeId]
    );
    if ($webhook === null) {
        errorResponse('not-found', 'Webhook not found', 404);
    }

    $limit = max(1, min((int)($_GET['count'] ?? 50), 200));
    $deliveries = WebhookSender::getDeliveries($params['webhookId'], $limit);

    jsonResponse(array_map(static function (array $row): array {
        return [
            'id' => $row['id'],
            'timestamp' => (int)$row['created_at'],
            'webhookId' => $row['webhook_id'],
            'status' => $row['delivered_at'] ? 'HttpSuccess' : ($row['attempts'] > 0 ? 'HttpError' : 'Pending'),
            'httpCode' => (int)($row['status_code'] ?? 0),
            'attempts' => (int)($row['attempts'] ?? 0),
            'errorMessage' => $row['delivered_at'] ? null : ($row['response'] ?? null),
            'deliveredAt' => $row['delivered_at'] ? (int)$row['delivered_at'] : null,
            'nextAttemptAt' => (int)($row['next_attempt_at'] ?? 0) ?: null,
        ];
    }, $deliveries));
}

/**
 * POST /stores/{storeId}/webhooks/{webhookId}/deliveries/{deliveryId}/redeliver
 *
 * Re-queues an existing delivery instead of creating a new event, so the receiver sees
 * the same logical event and its own idempotency still works.
 */
function handleRedeliverWebhook(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.webhooks.canmodifywebhooks');

    $delivery = Database::fetchOne(
        "SELECT d.id FROM webhook_deliveries d
         JOIN webhooks w ON w.id = d.webhook_id
         WHERE d.id = ? AND d.webhook_id = ? AND w.store_id = ? AND w.deleted_at IS NULL",
        [$params['deliveryId'], $params['webhookId'], $storeId]
    );
    if ($delivery === null) {
        errorResponse('not-found', 'Delivery not found', 404);
    }

    WebhookSender::requeueDelivery($params['deliveryId']);

    jsonResponse(['id' => $params['deliveryId'], 'status' => 'Pending'], 200);
}
