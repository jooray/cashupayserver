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
    $events = $body['authorizedEvents']['specificEvents'] ?? $body['authorizedEvents']['specific'] ?? $body['events'] ?? [];
    $enabled = $body['enabled'] ?? true;

    if (empty($url)) {
        errorResponse('validation-error', 'Webhook URL is required');
    }

    // Anti-SSRF: only allow public http(s) targets (blocks file://, internal IPs, metadata).
    if (!WebhookSender::isAllowedTarget($url)) {
        errorResponse('validation-error', 'Webhook URL must be a public http(s) URL');
    }

    // Generate secret for HMAC signing
    $secret = bin2hex(random_bytes(32));
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

    if (isset($body['url'])) {
        if (!WebhookSender::isAllowedTarget($body['url'])) {
            errorResponse('validation-error', 'Webhook URL must be a public http(s) URL');
        }
        $updates['url'] = $body['url'];
    }

    if (isset($body['authorizedEvents']['specificEvents']) || isset($body['authorizedEvents']['specific']) || isset($body['events'])) {
        $events = $body['authorizedEvents']['specificEvents'] ?? $body['authorizedEvents']['specific'] ?? $body['events'];
        $updates['events'] = json_encode($events);
    }

    if (isset($body['enabled'])) {
        $updates['enabled'] = $body['enabled'] ? 1 : 0;
    }

    if (!empty($updates)) {
        Database::update('webhooks', $updates, 'id = ?', [$webhookId]);
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

    // Keep the row as a tombstone so its foreign-key-linked committed outbox events
    // remain deliverable using their snapshotted URL and signing secret.
    Database::update(
        'webhooks',
        ['enabled' => 0, 'deleted_at' => Database::timestamp()],
        'id = ?',
        [$webhookId]
    );

    http_response_code(200);
    exit;
}

/**
 * Format webhook for API response
 */
function formatWebhookForApi(array $webhook, bool $includeSecret = false): array {
    $events = json_decode($webhook['events'], true) ?? [];

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
         WHERE d.id = ? AND d.webhook_id = ? AND w.store_id = ?",
        [$params['deliveryId'], $params['webhookId'], $storeId]
    );
    if ($delivery === null) {
        errorResponse('not-found', 'Delivery not found', 404);
    }

    WebhookSender::requeueDelivery($params['deliveryId']);

    jsonResponse(['id' => $params['deliveryId'], 'status' => 'Pending'], 200);
}
