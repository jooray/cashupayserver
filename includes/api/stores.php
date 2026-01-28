<?php
/**
 * CashuPayServer - Store API Handlers
 */

/**
 * Get all stores
 */
function handleGetStores(array $auth, array $params, array $body): void {
    $stores = Database::fetchAll(
        "SELECT id, name, created_at FROM stores ORDER BY created_at DESC"
    );

    $result = array_map(function ($store) {
        return [
            'id' => $store['id'],
            'name' => $store['name'],
            'createdTime' => $store['created_at'],
        ];
    }, $stores);

    jsonResponse($result);
}

/**
 * Create a new store
 */
function handleCreateStore(array $auth, array $params, array $body): void {
    $name = $body['name'] ?? '';

    if (empty($name)) {
        errorResponse('validation-error', 'Store name is required');
    }

    $storeId = Database::generateId('store');
    $now = Database::timestamp();

    Database::insert('stores', [
        'id' => $storeId,
        'name' => $name,
        'created_at' => $now,
    ]);

    jsonResponse([
        'id' => $storeId,
        'name' => $name,
        'createdTime' => $now,
    ], 200);
}

/**
 * Get a single store
 */
function handleGetStore(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];

    $store = Database::fetchOne(
        "SELECT id, name, created_at FROM stores WHERE id = ?",
        [$storeId]
    );

    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    jsonResponse([
        'id' => $store['id'],
        'name' => $store['name'],
        'createdTime' => $store['created_at'],
    ]);
}

/**
 * Delete a store
 */
function handleDeleteStore(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];

    $store = Database::fetchOne(
        "SELECT id FROM stores WHERE id = ?",
        [$storeId]
    );

    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    // Delete will cascade to api_keys, invoices, webhooks
    Database::delete('stores', 'id = ?', [$storeId]);

    http_response_code(200);
    exit;
}
