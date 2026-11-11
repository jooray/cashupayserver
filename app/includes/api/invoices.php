<?php
/**
 * CashuPayServer - Invoice API Handlers
 */

require_once __DIR__ . '/../invoice.php';
require_once __DIR__ . '/../security.php';

/**
 * Normalize an API-supplied invoice amount to a plain decimal string, or
 * return null when it isn't a positive number in an accepted format.
 *
 * The Greenfield body is untrusted JSON: `amount` can arrive as an int,
 * float, string — or anything else. Invoice::create coerces blindly
 * ((int)"-5" is -5, (int)"abc" is 0) and the fiat path feeds bcmath, which
 * throws ValueError on non-numeric input, so every shape must be rejected
 * here. Mirrors SelfServe::validateAmount semantics: whole numbers for
 * sat/msat, up to 8 decimals for BTC, 2 for fiat; no signs, exponents,
 * or thousands separators.
 */
function normalizeApiInvoiceAmount($amount, string $currency): ?string {
    if (is_int($amount)) {
        $raw = (string)$amount;
    } elseif (is_float($amount)) {
        if (!is_finite($amount)) {
            return null;
        }
        // Fixed-point render so floats never reach the regex in exponent
        // notation ("1.0E-9"); trim the trailing zeros it introduces.
        $raw = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
    } elseif (is_string($amount)) {
        $raw = trim($amount);
    } else {
        return null; // bool, array, object — never a valid amount
    }

    $cur = strtoupper($currency);
    if (in_array($cur, ['SAT', 'SATS', 'MSAT'], true)) {
        if (!preg_match('/^\d{1,15}$/', $raw) || (int)$raw < 1) {
            return null;
        }
        return (string)(int)$raw; // strip leading zeros
    }

    $maxDecimals = ($cur === 'BTC') ? 8 : 2;
    if (!preg_match('/^\d{1,15}(\.\d{1,' . $maxDecimals . '})?$/', $raw)) {
        return null;
    }
    if ((float)$raw <= 0) {
        return null;
    }
    return $raw;
}

/**
 * Parse checkout.expirationMinutes from untrusted JSON: a whole number as
 * int, integral float, or digit string. Null for anything else.
 */
function apiExpirationMinutes($value): ?int {
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (is_finite($value) && floor($value) === $value) ? (int)$value : null;
    }
    if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value))) {
        return (int)trim($value);
    }
    return null;
}

/**
 * Create a new invoice
 */
function handleCreateInvoice(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    requireStoreAccess($auth, $storeId);

    // Verify store exists
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
    if (!is_string($currency)) {
        errorResponse('validation-error', 'Currency must be a string');
    }
    $amount = normalizeApiInvoiceAmount($amount, $currency);
    if ($amount === null) {
        errorResponse(
            'validation-error',
            'Amount must be a positive number (whole sats for sat/msat; up to 8 decimals for BTC, 2 for fiat)'
        );
    }

    // Reject non-http(s) redirectURL: payment.php renders it into an <a href>
    // and (when redirectAutomatically is set) assigns it to window.location.
    // Allowing javascript: / data: would be a stored-XSS sink in the customer's
    // browser at the merchant's origin.
    $checkout = $body['checkout'] ?? null;
    if (is_array($checkout) && isset($checkout['redirectURL']) && $checkout['redirectURL'] !== '' && $checkout['redirectURL'] !== null) {
        if (Security::sanitizeUrl((string)$checkout['redirectURL']) === null) {
            errorResponse('validation-error', 'checkout.redirectURL must be an http or https URL');
        }
    }

    // checkout.expirationMinutes (BTCPay Greenfield field): how long the
    // invoice stays payable, 1 minute up to the shared-invoice lifetime. An
    // invoice created with it — and not tied to a shop order (metadata.orderId)
    // — is a long-lived payment request: its Lightning bolt11 is re-sourced by
    // the payment page as it lapses and a fiat amount is re-quoted on load.
    // E-commerce invoices keep the plain lifetime and the existing expiry
    // behaviour; without the field nothing changes at all.
    $createOptions = [
        'amount' => $amount,
        'currency' => strtoupper($currency),
        'metadata' => $body['metadata'] ?? null,
        'checkout' => $checkout,
    ];
    if (is_array($checkout) && array_key_exists('expirationMinutes', $checkout)
            && $checkout['expirationMinutes'] !== null && $checkout['expirationMinutes'] !== '') {
        $minutes = apiExpirationMinutes($checkout['expirationMinutes']);
        $maxMinutes = intdiv(Config::getSharedInvoiceLifetime(), 60);
        if ($minutes === null || $minutes < 1 || $minutes > $maxMinutes) {
            errorResponse(
                'validation-error',
                "checkout.expirationMinutes must be a whole number between 1 and {$maxMinutes}"
            );
        }
        $metadata = $body['metadata'] ?? null;
        $isOrder = is_array($metadata) && isset($metadata['orderId'])
            && is_scalar($metadata['orderId']) && trim((string)$metadata['orderId']) !== '';
        $createOptions['lifetime_sec'] = $minutes * 60;
        $createOptions['long_lived'] = !$isOrder;
    }

    // Create invoice
    try {
        $invoice = Invoice::create($storeId, $createOptions);

        jsonResponse(Invoice::formatForApi($invoice), 200);
    } catch (Exception $e) {
        errorResponse('invoice-error', $e->getMessage());
    } catch (Throwable $t) {
        // Engine errors (TypeError, bcmath ValueError, …) must not escape as
        // a raw PHP fatal: log the detail, answer with a clean opaque 500.
        error_log('[api] invoice creation failed for store ' . $storeId . ': '
            . get_class($t) . ': ' . $t->getMessage());
        errorResponse('internal-error', 'Invoice creation failed', 500);
    }
}

/**
 * Get invoices for a store
 */
function handleGetInvoices(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    requireStoreAccess($auth, $storeId);

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
    requireStoreAccess($auth, $storeId);

    // Confirm the invoice belongs to this store BEFORE polling — otherwise
    // anyone with any API key could force-poll arbitrary invoice IDs.
    $invoice = Invoice::getById($invoiceId);
    if ($invoice === null || $invoice['store_id'] !== $storeId) {
        errorResponse('not-found', 'Invoice not found', 404);
    }

    // Poll only this specific invoice's quote status, then re-fetch to
    // pick up any status transition the poll triggered.
    Invoice::pollSingleQuote($invoiceId);
    $invoice = Invoice::getById($invoiceId);

    jsonResponse(Invoice::formatForApi($invoice));
}

/**
 * Update invoice status (mark as invalid, etc.)
 */
function handleUpdateInvoiceStatus(array $auth, array $params, array $body): void {
    $storeId = $params['storeId'];
    $invoiceId = $params['invoiceId'];
    requireStoreAccess($auth, $storeId);

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
