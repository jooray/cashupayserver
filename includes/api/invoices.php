<?php
/**
 * CashuPayServer - Invoice API Handlers
 */

require_once __DIR__ . '/../invoice.php';

/**
 * Create a new invoice
 */
function handleCreateInvoice(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];

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

    // Create invoice
    try {
        $invoice = Invoice::create($storeId, [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'metadata' => $body['metadata'] ?? null,
            'checkout' => $body['checkout'] ?? null,
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
    $storeId = $params['storeId'];

    // Parse query parameters
    $status = $_GET['status'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $invoices = Invoice::getByStore($storeId, $status, $limit, $offset);

    $result = array_map([Invoice::class, 'formatForApi'], $invoices);
    jsonResponse($result);
}

/**
 * Get a single invoice
 */
function handleGetInvoice(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    $invoiceId = $params['invoiceId'];

    // Poll only this specific invoice's quote status
    Invoice::pollSingleQuote($invoiceId);

    $invoice = Invoice::getById($invoiceId);

    if ($invoice === null || $invoice['store_id'] !== $storeId) {
        errorResponse('not-found', 'Invoice not found', 404);
    }

    jsonResponse(Invoice::formatForApi($invoice));
}

/**
 * Update invoice status (mark as invalid, etc.)
 */
function handleUpdateInvoiceStatus(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    $invoiceId = $params['invoiceId'];

    $invoice = Invoice::getById($invoiceId);

    if ($invoice === null || $invoice['store_id'] !== $storeId) {
        errorResponse('not-found', 'Invoice not found', 404);
    }

    $status = $body['status'] ?? null;

    if (!in_array($status, ['Invalid', 'Settled'])) {
        errorResponse('validation-error', 'Invalid status. Allowed: Invalid, Settled');
    }

    // Only allow marking as Invalid if currently New or Processing
    if ($status === 'Invalid' && !in_array($invoice['status'], ['New', 'Processing'])) {
        errorResponse('validation-error', 'Can only invalidate New or Processing invoices');
    }

    Invoice::updateStatus($invoiceId, $status);

    $invoice = Invoice::getById($invoiceId);
    jsonResponse(Invoice::formatForApi($invoice));
}
