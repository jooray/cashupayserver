<?php
/**
 * CashuPayServer - Store API Handlers
 */

/**
 * Get stores.
 *
 * Single-operator: an API key only ever sees its own store (not every store).
 * This keeps BTCPay clients working (they expect a list) without leaking other
 * stores' existence. Like BTCPay, the list only shows stores whose settings the key
 * may view; any other key gets an empty list.
 */
function handleGetStores(array $auth, array $params, array $body): void {
    if (!Auth::hasPermission($auth, 'btcpay.store.canviewstoresettings')) {
        jsonResponse([]);
        return;
    }

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
    requirePermission($auth, 'btcpay.store.canviewstoresettings');

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
 * Kept for BTCPay compatibility but only for a key that is not tied to a store: an
 * admin-created `*` key or one holding server-settings permission. Every key issued by
 * pairing belongs to one store, and a key for one store has no business adding others
 * (SPB-SEC-012) — store-settings permission alone is not enough.
 */
function handleCreateStore(array $auth, array $params, array $body): void {
    if (!apiKeyIsUnscoped($auth)) {
        errorResponse('unauthorized', 'Only an admin API key can create stores; this key belongs to one store', 403);
    }

    $name = $body['name'] ?? '';
    if (!is_string($name) || trim($name) === '') {
        errorResponse('validation-error', 'Store name is required');
    }
    $name = trim($name);
    if (mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        errorResponse('validation-error', 'Store name must be plain text of at most 100 characters');
    }

    $storeId = Database::generateId('store');
    $now = Database::timestamp();

    Database::insert('stores', [
        'id' => $storeId,
        'name' => $name,
        'wallet_account_id' => Database::generateWalletAccountId(),
        'created_at' => $now,
    ]);

    jsonResponse([
        'id' => $storeId,
        'name' => $name,
        'createdTime' => $now,
    ], 200);
}

/**
 * Whether a key may act beyond its own store: the admin wildcard, or BTCPay's
 * server-settings permission (which pairing never grants).
 */
function apiKeyIsUnscoped(array $auth): bool {
    $perms = $auth['permissions'] ?? [];
    if (!is_array($perms)) {
        return false;
    }
    return in_array('*', $perms, true) || in_array('btcpay.server.canmodifyserversettings', $perms, true);
}

/**
 * Delete a store (Greenfield: DELETE /stores/{storeId}).
 *
 * Kept for BTCPay compatibility but: (a) requires the key to be scoped to this store,
 * (b) requires store-settings-modify permission, and (c) refuses, with the reasons, to
 * delete a store that still holds ecash or has unresolved payments, transfers, exports
 * or old-mint funds — deleting removes the seed, so they would be lost for good. The
 * same blockers as the dashboard (StoreSafety), not just the spendable balance
 * (OPUS-007, DS41-17, SPB-CORE-008). `{"confirmFundLoss": true}` overrides, like the
 * dashboard's explicit confirmation. See FABLE-SECURITY-AUDIT (CRIT-2).
 */
function handleDeleteStore(array $auth, array $params, array $body): void {
    require_once __DIR__ . '/../store_safety.php';

    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canmodifystoresettings');

    $store = Database::fetchOne("SELECT id FROM stores WHERE id = ?", [$storeId]);
    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    $confirmFundLoss = ($body['confirmFundLoss'] ?? false) === true;

    $blockers = [];
    try {
        $balance = Invoice::getBalance($storeId);
        if ($balance > 0) {
            $blockers[] = "{$balance} of spendable ecash; withdraw it first";
        }
        $blockers = array_merge($blockers, StoreSafety::deletionBlockers($storeId));
    } catch (Throwable $e) {
        // If the state can't be determined, err on the safe side and refuse.
        error_log("CashuPayServer: store {$storeId} deletion check failed: " . $e->getMessage());
        $blockers[] = 'the store\'s funds could not be checked';
    }

    if (!empty($blockers) && !$confirmFundLoss) {
        jsonResponse([
            'code' => 'conflict',
            'message' => 'This store still has funds or unfinished payments, which deleting it would lose: '
                . implode('; ', $blockers)
                . '. Resolve them first, or send {"confirmFundLoss": true} to delete anyway.',
            'blockers' => $blockers,
        ], 409);
    }
    if (!empty($blockers)) {
        error_log("CashuPayServer: store {$storeId} deleted over the API despite: " . implode('; ', $blockers));
    }

    // Cascade removes api_keys, invoices, webhooks.
    Database::delete('stores', 'id = ?', [$storeId]);

    http_response_code(200);
    exit;
}

/**
 * GET /api-keys/current
 *
 * The WooCommerce gateway and the Shopify/Magento apps call this after pairing to learn
 * which permissions their key actually holds. Without it they cannot tell a
 * misconfigured key from a broken server.
 */
function handleCurrentApiKey(array $auth, array $params, array $body): void {
    jsonResponse([
        'apiKey' => null, // never echo the credential back
        'label' => $auth['store_name'] ?? null,
        'permissions' => greenfieldPermissions($auth),
    ]);
}

/**
 * A key's permissions in BTCPay's wire form: every store permission scoped as
 * "perm:storeId".
 *
 * The WooCommerce gateway rejects a key on settings save unless every permission
 * names exactly one store and the set equals its required list (plus optional
 * webhook and pull-payment permissions). Bare names failed the first check, `*`
 * both, and the gateway then skipped creating its webhook.
 *
 * `*` (keys made in the admin) is reported as the set this server serves through
 * Greenfield to a shop plugin. Pull payments are left out on purpose: the gateway
 * reads that permission as "refunds work", and there are no refunds here.
 */
function greenfieldPermissions(array $auth): array {
    $storeId = (string)($auth['store_id'] ?? '');
    $held = array_values($auth['permissions'] ?? []);
    if (in_array('*', $held, true)) {
        $held = [
            'btcpay.store.canviewinvoices',
            'btcpay.store.cancreateinvoice',
            'btcpay.store.canviewstoresettings',
            'btcpay.store.canmodifyinvoices',
            'btcpay.store.webhooks.canmodifywebhooks',
        ];
    }
    $scoped = [];
    foreach ($held as $permission) {
        $permission = (string)$permission;
        if (strpos($permission, 'btcpay.store.') === 0 && $storeId !== '') {
            // Stored keys may already carry a scope; this key only ever serves its own store.
            $permission = explode(':', $permission, 2)[0] . ':' . $storeId;
        }
        $scoped[] = $permission;
    }
    return array_values(array_unique($scoped));
}

/**
 * GET /stores/{storeId}/payment-methods
 *
 * One Lightning method, always on. CashuPayServer settles Lightning invoices through a
 * Cashu mint, so there is nothing to enable or disable per store.
 */
function handleStorePaymentMethods(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canviewstoresettings');

    jsonResponse([[
        'enabled' => true,
        'paymentMethodId' => 'BTC-LN',
        'cryptoCode' => 'BTC',
        'config' => null,
    ]]);
}

/**
 * GET /stores/{storeId}/invoices/{invoiceId}/payment-methods
 *
 * BTCPay clients read this to render payment details (and, in WooCommerce's case, to
 * confirm an invoice really is payable) rather than trusting the invoice object alone.
 */
function handleInvoicePaymentMethods(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canviewinvoices');

    $invoice = Invoice::getById($params['invoiceId']);
    if ($invoice === null || $invoice['store_id'] !== $storeId) {
        errorResponse('not-found', 'Invoice not found', 404);
    }

    $paid = in_array($invoice['status'], ['Settled', 'Processing'], true);

    jsonResponse([[
        'paymentMethodId' => 'BTC-LN',
        'cryptoCode' => 'BTC',
        'currency' => 'BTC',
        'destination' => $invoice['bolt11'] ?? null,
        'paymentLink' => $invoice['bolt11'] ? 'lightning:' . $invoice['bolt11'] : null,
        'rate' => (string)($invoice['exchange_rate'] ?? '1'),
        'amount' => (string)($invoice['amount'] ?? '0'),
        'due' => $paid ? '0' : (string)($invoice['amount'] ?? '0'),
        'totalPaid' => $paid ? (string)($invoice['amount'] ?? '0') : '0',
        'activated' => true,
        'payments' => [],
        'additionalData' => [],
    ]]);
}
