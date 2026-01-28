<?php
/**
 * CashuPayServer - Webhook API Handlers
 */

require_once __DIR__ . '/../webhook_sender.php';

/**
 * Create a new webhook
 */
function handleCreateWebhook(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];

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

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        errorResponse('validation-error', 'Invalid webhook URL');
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
    $storeId = $params['storeId'];

    $webhooks = Database::fetchAll(
        "SELECT * FROM webhooks WHERE store_id = ? ORDER BY created_at DESC",
        [$storeId]
    );

    $result = array_map(fn($w) => formatWebhookForApi($w, false), $webhooks);
    jsonResponse($result);
}

/**
 * Get a single webhook
 */
function handleGetWebhook(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    $webhookId = $params['webhookId'];

    $webhook = Database::fetchOne(
        "SELECT * FROM webhooks WHERE id = ? AND store_id = ?",
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
    $storeId = $params['storeId'];
    $webhookId = $params['webhookId'];

    $webhook = Database::fetchOne(
        "SELECT * FROM webhooks WHERE id = ? AND store_id = ?",
        [$webhookId, $storeId]
    );

    if ($webhook === null) {
        errorResponse('not-found', 'Webhook not found', 404);
    }

    $updates = [];

    if (isset($body['url'])) {
        if (!filter_var($body['url'], FILTER_VALIDATE_URL)) {
            errorResponse('validation-error', 'Invalid webhook URL');
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

    $webhook = Database::fetchOne("SELECT * FROM webhooks WHERE id = ?", [$webhookId]);
    jsonResponse(formatWebhookForApi($webhook, false));
}

/**
 * Delete a webhook
 */
function handleDeleteWebhook(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    $webhookId = $params['webhookId'];

    $webhook = Database::fetchOne(
        "SELECT id FROM webhooks WHERE id = ? AND store_id = ?",
        [$webhookId, $storeId]
    );

    if ($webhook === null) {
        errorResponse('not-found', 'Webhook not found', 404);
    }

    Database::delete('webhooks', 'id = ?', [$webhookId]);

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
