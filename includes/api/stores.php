<?php
/**
 * CashuPayServer - Store API Handlers
 */

/**
 * Get stores.
 *
 * Single-operator: an API key only ever sees its own store (not every store).
 * This keeps BTCPay clients working (they expect a list) without leaking other
 * stores' existence.
 */
function handleGetStores(array $auth, array $params, array $body): void {
    $store = Database::fetchOne(
        "SELECT id, name, created_at FROM stores WHERE id = ?",
        [(string)($auth['store_id'] ?? '')]
    );

    $result = [];
    if ($store !== null) {
        $result[] = [
            'id' => $store['id'],
            'name' => $store['name'],
            'createdTime' => $store['created_at'],
        ];
    }

    jsonResponse($result);
}

/**
 * Get a single store
 */
function handleGetStore(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);

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
 * Create a new store (Greenfield: POST /stores).
 *
 * Kept for BTCPay compatibility but gated behind store-settings-modify permission, which
 * a normal e-commerce pairing key (invoice/webhook perms only) does not hold. This
 * prevents a low-privilege plugin key from creating stores while keeping the endpoint.
 */
function handleCreateStore(array $auth, array $params, array $body): void {
    requirePermission($auth, 'btcpay.store.canmodifystoresettings');

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
 * Delete a store (Greenfield: DELETE /stores/{storeId}).
 *
 * Kept for BTCPay compatibility but: (a) requires the key to be scoped to this store,
 * (b) requires store-settings-modify permission, and (c) refuses to delete a store that
 * still holds ecash (deleting removes the seed = irrecoverable funds).
 * See FABLE-SECURITY-AUDIT (CRIT-2) and FABLE-CASHUPAYSERVER-AUDIT (A3).
 */
function handleDeleteStore(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canmodifystoresettings');

    $store = Database::fetchOne("SELECT id FROM stores WHERE id = ?", [$storeId]);
    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    // Fund-safety guard: never destroy the seed of a funded store via the API.
    $balance = 0;
    try {
        $balance = Invoice::getBalance($storeId);
    } catch (Throwable $e) {
        // If balance can't be determined, err on the safe side and refuse.
        errorResponse('conflict', 'Cannot verify store balance; refusing to delete', 409);
    }
    if ($balance > 0) {
        errorResponse('conflict', 'Store still holds funds; withdraw before deleting', 409);
    }

    // Cascade removes api_keys, invoices, webhooks.
    Database::delete('stores', 'id = ?', [$storeId]);

    http_response_code(200);
    exit;
}
