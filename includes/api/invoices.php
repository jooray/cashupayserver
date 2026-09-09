<?php
/**
 * CashuPayServer - Invoice API Handlers
 */

require_once __DIR__ . '/../invoice.php';

/**
 * Create a new invoice
 */
function handleCreateInvoice(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.cancreateinvoice');

    // Verify store exists and user has access
    $store = Database::fetchOne("SELECT id FROM stores WHERE id = ?", [$storeId]);
    if ($store === null) {
        errorResponse('not-found', 'Store not found', 404);
    }

    // Validate required fields
    $amount = $body['amount'] ?? null;
    $currency = $body['currency'] ?? 'sat';

    if ($amount === null || $amount === '') {
        errorResponse('validation-error', 'Amount is required');
    }

    // `is_numeric()` accepts "1e308", hex-ish forms and negatives, all of which reach
    // the BCMath converter and fatal there. Require a plain, bounded decimal instead.
    if (!is_scalar($amount) || !preg_match('/^\d{1,15}(\.\d{1,8})?$/', (string)$amount)
        || (float)$amount <= 0) {
        errorResponse('validation-error', 'Amount must be a positive decimal number');
    }

    if (!is_string($currency) || !preg_match('/^[A-Za-z]{3,10}$/', $currency)) {
        errorResponse('validation-error', 'Currency must be a 3-10 letter code');
    }

    $metadata = $body['metadata'] ?? null;
    if ($metadata !== null) {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (!is_array($decoded)) {
                errorResponse('validation-error', 'Metadata must be an object');
            }
            $metadata = $decoded;
        }
        if (!is_array($metadata) || strlen((string)json_encode($metadata)) > 16384) {
            errorResponse('validation-error', 'Metadata must be an object of at most 16 KiB');
        }
    }

    $checkout = $body['checkout'] ?? null;
    if ($checkout !== null && !is_array($checkout)) {
        errorResponse('validation-error', 'Checkout must be an object');
    }

    // `redirectURL` becomes an anchor href and a `window.location` assignment after
    // settlement. HTML-escaping does not neutralize `javascript:`, so an integration with
    // only invoice-create permission could plant script that runs on this origin.
    if (isset($checkout['redirectURL'])) {
        if (!is_string($checkout['redirectURL']) || !Security::isSafeBrowserRedirect($checkout['redirectURL'])) {
            errorResponse('validation-error', 'redirectURL must be an http(s) or site-relative URL');
        }
    }

    // Create invoice
    try {
        $invoice = Invoice::create($storeId, [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'metadata' => $metadata,
            'checkout' => $checkout,
        ]);

        jsonResponse(Invoice::formatForApi($invoice), 200);
    } catch (Exception $e) {
        errorResponse('invoice-error', $e->getMessage());
    }
}

/**
 * Get invoices for a store
 */
function handleGetInvoices(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canviewinvoices');

    // Parse query parameters. `limit` needs a lower bound too: a negative value passed
    // through the upper clamp and became SQLite's "no limit".
    $status = $_GET['status'] ?? null;
    if ($status !== null && !is_string($status)) {
        errorResponse('validation-error', 'Invalid status filter');
    }
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 100));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $invoices = Invoice::getByStore($storeId, $status, $limit, $offset);

    $result = array_map([Invoice::class, 'formatForApi'], $invoices);
    jsonResponse($result);
}

/**
 * Get a single invoice
 */
function handleGetInvoice(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canviewinvoices');
    $invoiceId = $params['invoiceId'];

    // Authorize before touching the mint: polling first let a caller drive network work
    // on invoices belonging to another store.
    $invoice = Invoice::getById($invoiceId);
    if ($invoice === null || $invoice['store_id'] !== $storeId) {
        errorResponse('not-found', 'Invoice not found', 404);
    }

    // Poll only this specific invoice's quote status
    Invoice::pollSingleQuote($invoiceId);

    $invoice = Invoice::getById($invoiceId) ?? $invoice;

    jsonResponse(Invoice::formatForApi($invoice));
}

/**
 * Update invoice status (mark as invalid, etc.)
 */
function handleUpdateInvoiceStatus(array $auth, array $params, array $body): void {
    $storeId = requireStore($auth, $params);
    requirePermission($auth, 'btcpay.store.canmodifyinvoices');
    $invoiceId = $params['invoiceId'];

    $invoice = Invoice::getById($invoiceId);

    if ($invoice === null || $invoice['store_id'] !== $storeId) {
        errorResponse('not-found', 'Invoice not found', 404);
    }

    $status = $body['status'] ?? null;

    if ($status !== 'Invalid') {
        errorResponse('validation-error', 'Only Invalid status may be set manually');
    }

    // Only allow marking as Invalid if currently New or Processing
    if ($status === 'Invalid' && !in_array($invoice['status'], ['New', 'Processing'])) {
        errorResponse('validation-error', 'Can only invalidate New or Processing invoices');
    }

    // Conditional: another worker may have settled this invoice between the read above
    // and here. Reporting success then would tell the shop an order was cancelled while
    // its payment was in fact received.
    if (!Invoice::updateStatus($invoiceId, 'Invalid', null, ['New', 'Processing'])) {
        $current = Invoice::getById($invoiceId);
        errorResponse(
            'validation-error',
            'Invoice is now ' . ($current['status'] ?? 'unknown') . ' and can no longer be invalidated'
        );
    }

    $invoice = Invoice::getById($invoiceId);
    jsonResponse(Invoice::formatForApi($invoice));
}
