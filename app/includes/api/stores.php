<?php
/**
 * CashuPayServer - Store API Handlers
 */

require_once __DIR__ . '/../http_status.php';

/**
 * Get all stores
 *
 * Scoped to the caller's own store unless the key has wildcard permission
 * (the internal admin-dashboard key). Without this scoping any merchant
 * could enumerate every other store on the instance.
 */
function handleGetStores(array $auth, array $params, array $body): void {
    if (Auth::hasPermission($auth, '*')) {
        $stores = Database::fetchAll(
            "SELECT id, name, created_at FROM stores ORDER BY created_at DESC"
        );
    } else {
        $stores = Database::fetchAll(
            "SELECT id, name, created_at FROM stores WHERE id = ? ORDER BY created_at DESC",
            [$auth['store_id'] ?? '']
        );
    }

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
 *
 * Requires wildcard permission. A standard per-store API key has no
 * business spawning new stores on the instance.
 */
function handleCreateStore(array $auth, array $params, array $body): void {
    if (!Auth::hasPermission($auth, '*')) {
        errorResponse('unauthorized', 'Insufficient permissions', 403);
    }

    $name = $body['name'] ?? '';

    if (empty($name)) {
        errorResponse('validation-error', 'Store name is required');
    }

    $storeId = Database::generateId('store');
    $now = Database::timestamp();

    Database::insert('stores', [
        'id' => $storeId,
        'name' => $name,
        'primary_mint_source' => 'setup',
        'created_at' => $now,
    ]);

    require_once __DIR__ . '/../trusted_mints.php';
    try {
        TrustedMints::applyToNewStore($storeId);
    } catch (Exception $e) {
        error_log("TrustedMints::applyToNewStore failed in API createStore: " . $e->getMessage());
    }

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
    requireStoreAccess($auth, $storeId);

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
    requireStoreAccess($auth, $storeId);

    $store = Database::fetchOne(
        "SELECT id FROM stores WHERE id = ?",
        [$storeId]
    );

    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    // Delete will cascade to api_keys, invoices, webhooks
    Database::delete('stores', 'id = ?', [$storeId]);

    cashupay_status(200);
    exit;
}
