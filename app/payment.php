<?php
/**
 * CashuPayServer - Payment Page
 *
 * Customer-facing payment page with Lightning QR code.
 */

require_once __DIR__ . '/includes/http_status.php';

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/urls.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/managed.php';
require_once __DIR__ . '/includes/payment_path_debug.php';
require_once __DIR__ . '/includes/offline_cashu.php';
require_once __DIR__ . '/includes/wallet_tabs.php';

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    cashupay_status(503);
    echo 'Service unavailable';
    exit;
}

// Get invoice ID
$invoiceId = $_GET['id'] ?? '';
if (empty($invoiceId)) {
    cashupay_status(400);
    echo 'Invoice ID required';
    exit;
}

/**
 * Public mempool.space URL for the invoice's first-seen on-chain tx, or null
 * when none has been observed. First-seen matches the admin invoices view
 * when several txs pay the same address. The store's network picks the
 * explorer path; regtest has no public explorer so it falls back to the
 * mainnet URL (same behavior as the admin UI's mempoolUrl helper). Always
 * links the public explorer even when detection runs against a custom
 * Esplora backend — the configured URL is an API base, not a browsable site.
 */
function cashupay_onchain_tx_url(array $invoice): ?string {
    // Rows exist only once a tx is seen; skip the lookup for states that
    // cannot have one (New/Expired) to keep the 2 s poll cheap.
    if ($invoice['status'] !== 'Settled'
            && !($invoice['status'] === 'Processing' && !empty($invoice['onchain_first_seen_at']))) {
        return null;
    }
    $oc = Database::fetchOne(
        "SELECT txid FROM onchain_payments
          WHERE invoice_id = ?
          ORDER BY first_seen_at ASC, id ASC
          LIMIT 1",
        [$invoice['id']]
    );
    if (!$oc || empty($oc['txid'])) {
        return null;
    }
    $store = Database::fetchOne(
        "SELECT onchain_network FROM stores WHERE id = ?",
        [$invoice['store_id']]
    );
    $network = $store['onchain_network'] ?? 'mainnet';
    $base = $network === 'testnet' ? 'https://mempool.space/testnet'
          : ($network === 'signet' ? 'https://mempool.space/signet'
          : 'https://mempool.space');
    return $base . '/tx/' . rawurlencode($oc['txid']);
}

/**
 * Relay-subscription parameters the page needs to watch for the merchant's
 * kind-21001 payment receipt; null unless this invoice currently rides the
 * noffer rail. Shared by the HTML bootstrap, the JSON poll and the Lightning
 * refresh endpoint (a refresh can move the invoice onto / off the rail).
 */
function cashupay_noffer_sub(array $invoice): ?array {
    if (($invoice['payment_rail'] ?? '') !== 'noffer' || empty($invoice['noffer_relay'])) {
        return null;
    }
    return [
        'relay' => (string)$invoice['noffer_relay'],
        'pubkey' => (string)$invoice['noffer_ephemeral_pubkey'],
        'requestId' => (string)$invoice['noffer_request_event_id'],
        'since' => (int)($invoice['noffer_created_at'] ?? 0),
    ];
}

/**
 * Lightning-side fields the page keeps in sync while an invoice is New: the
 * current bolt11, when it lapses, when it was last (re)sourced, and the noffer
 * subscription for it. Empty for any other status (nothing left to pay).
 */
function cashupay_lightning_state(array $invoice): array {
    if ($invoice['status'] !== 'New') {
        return [];
    }
    return [
        'bolt11' => $invoice['bolt11'] ?? null,
        'lnExpiresAt' => (int)($invoice['ln_expires_at'] ?? 0),
        'lnRefreshedAt' => (int)(($invoice['ln_refreshed_at'] ?? null) ?: ($invoice['created_at'] ?? 0)),
        'paymentRail' => $invoice['payment_rail'] ?? null,
        'nofferSub' => cashupay_noffer_sub($invoice),
        'amountSats' => (int)($invoice['amount_sats'] ?? 0),
    ];
}

// Lightning refresh endpoint for long-lived invoices: the page calls this when
// its bolt11 lapses (or learns from the JSON poll that another tab refreshed).
// Rate-limited and status-guarded server-side (Invoice::refreshLightning), so
// hammering it costs nothing beyond one re-source per window. No CSRF token —
// unauthenticated page, nothing to abuse: a refresh never changes what is owed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_lightning') {
    header('Content-Type: application/json');
    $res = Invoice::refreshLightning($invoiceId);
    $inv = $res['invoice'] ?? Invoice::getById($invoiceId);
    if ($inv === null) {
        cashupay_status(404);
        echo json_encode(['ok' => false, 'error' => 'Invoice not found']);
        exit;
    }
    if (($res['reason'] ?? '') === 'not_refreshable') {
        cashupay_status(400);
    }
    $payload = [
        'ok' => !empty($res['ok']),
        'reason' => $res['reason'] ?? null,
        'status' => $inv['status'],
    ] + cashupay_lightning_state($inv);
    if (!empty($res['receive_errors']) && !AdminLog::suppressOnInvoice()) {
        $payload['receiveErrors'] = $res['receive_errors'];
    }
    echo json_encode($payload);
    exit;
}

// CLINK noffer receipt endpoint: the payment page holds a live Nostr
// subscription (native WebSocket) for the merchant's kind-21001 payment
// receipt and forwards the raw signed event here. We verify the merchant's
// Schnorr signature + decrypt server-side before settling, so the browser can
// relay the receipt but cannot forge a payment. No CSRF token — like the
// receipt-email endpoint below, there's no auth context to abuse, and trust
// comes entirely from the merchant's signature.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'noffer_receipt') {
    header('Content-Type: application/json');
    $event = json_decode((string)($_POST['event'] ?? ''), true);
    if (!is_array($event)) {
        cashupay_status(400);
        echo json_encode(['error' => 'Invalid event']);
        exit;
    }
    $settled = Invoice::settleNofferFromReceiptEvent($invoiceId, $event);
    echo json_encode(['settled' => $settled]);
    exit;
}

// JSON polling branch runs first so we can keep its work tight. The
// customer's browser polls this every 2 s; firing the full Background
// fan-out on every poll burns mint API budget and fights cron for the
// same rows (concurrency race in mintAndStoreTokens). pollSingleQuote
// stays — it's the only thing that drives settlement for THIS invoice
// when no external cron is running, and skipping it stalls customers
// on the "Waiting for payment" screen until the next cron tick (which
// is never, for shops that haven't wired cron up).
if (isset($_GET['json'])) {
    Invoice::pollSingleQuote($invoiceId);
    header('Content-Type: application/json');
    $invoice = Invoice::getById($invoiceId);
    if ($invoice === null) {
        cashupay_status(404);
        echo json_encode(['error' => 'Invoice not found']);
        exit;
    }
    // If the payer left their email while the on-chain tx was confirming and
    // the invoice just settled, queue the receipt now — this poll is the
    // fastest observer of the settlement while the tab is still open (cron's
    // sweep covers payers who closed it).
    if ($invoice['status'] === 'Settled' && !empty($invoice['payer_receipt_requested'])) {
        require_once __DIR__ . '/includes/notification_sender.php';
        NotificationSender::flushRequestedPayerReceipts($invoiceId);
    }
    echo json_encode([
        'status' => $invoice['status'],
        'additionalStatus' => $invoice['additional_status'],
        // True while an on-chain payment is detected in the mempool but not
        // yet confirmed — drives the "waiting for on-chain confirmation" copy.
        'onchainPending' => $invoice['status'] === 'Processing'
            && !empty($invoice['onchain_first_seen_at']),
        // Explorer link for the detected on-chain tx; null for non-on-chain
        // payments. Sent while confirming AND after settlement so the link
        // survives the pending -> settled transition.
        'onchainTxUrl' => cashupay_onchain_tx_url($invoice),
        // Sanitized create-time direct-receive failures ([{type, reason}]);
        // static for the invoice's lifetime — the page renders them
        // server-side, this mirrors them for JSON consumers. Empty when the
        // operator suppresses payer-facing errors site-wide.
        'receiveErrors' => (!AdminLog::suppressOnInvoice() && !empty($invoice['receive_errors']))
            ? (json_decode((string)$invoice['receive_errors'], true) ?: [])
            : [],
        // Long-lived invoice: lets a tab pick up a bolt11 another tab (or a
        // reload) refreshed, and drives its "refreshes in" timer.
        'longLived' => !empty($invoice['long_lived']),
    ] + (!empty($invoice['long_lived']) ? cashupay_lightning_state($invoice) : []));
    exit;
}

// HTML render: poll this one quote so the initial page reflects current
// state, then kick background work for everything else.
Invoice::pollSingleQuote($invoiceId);
// Long-lived (shareable) invoice: re-quote a fiat amount at today's rate and
// re-source a lapsed Lightning invoice BEFORE rendering, so the first paint
// already carries a payable QR. No-op for ordinary checkout invoices.
Invoice::prepareForView($invoiceId);
Background::trigger();

// Get invoice
$invoice = Invoice::getById($invoiceId);
if ($invoice === null) {
    cashupay_status(404);
    echo 'Invoice not found';
    exit;
}

// Get store name for display. Also pull the invoice-privacy defaults so the
// cashu (NUT-18) memo honours the same store-name/note hiding the noffer rail
// applies via Invoice::buildInvoiceMemo, the "Payment order" preference
// that picks the default method tab (Invoice::firstPaymentMethod), and the
// "Popular wallet tabs" opt-in (WalletTabs::offered).
$store = Database::fetchOne(
    "SELECT name, hide_store_name_on_invoice, hide_note_on_invoice, invoice_first_method, wallet_tabs
     FROM stores WHERE id = ?",
    [$invoice['store_id']]
);
$storeName = $store['name'] ?? 'Payment';

// Public POST endpoint: payer enters their email on the payment-complete
// modal. We persist the email + newsletter opt-in on the invoice (always, for
// the merchant's customer list) and, when payer receipts are enabled, also
// queue a confirmation email. No CSRF token because there's no auth context to
// abuse — the page itself is unauthenticated. The per-invoice send cap inside
// NotificationSender bounds receipt-email abuse; the email column is a single
// overwrite, so capture itself is harmless to spam.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_receipt') {
    require_once __DIR__ . '/includes/notification_sender.php';
    header('Content-Type: application/json');

    // Deployments with payer email capture off (managed single-shop installs
    // by default — the shop owns customer emails) don't render the form, so
    // reject the endpoint outright rather than trusting the client not to
    // POST.
    if (!Config::isPayerEmailCaptureEnabled()) {
        cashupay_status(404);
        echo json_encode(['error' => 'Not available.']);
        exit;
    }

    // Accepted for a settled invoice (receipt queued immediately) or while a
    // detected on-chain payment is still confirming (email saved now, receipt
    // queued at settlement — see NotificationSender::flushRequestedPayerReceipts).
    $onchainConfirming = $invoice['status'] === 'Processing'
        && !empty($invoice['onchain_first_seen_at']);
    if ($invoice['status'] !== 'Settled' && !$onchainConfirming) {
        cashupay_status(400);
        echo json_encode(['error' => 'Invoice is not paid yet.']);
        exit;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cashupay_status(400);
        echo json_encode(['error' => 'Please enter a valid email address.']);
        exit;
    }
    $newsletterOptIn = ($_POST['newsletter'] ?? '0') === '1' ? 1 : 0;

    // Persist the customer's email + newsletter choice on the invoice. Last
    // submission wins — the payer may resubmit with a changed choice. This is
    // decoupled from receipt sending so it works even without SMTP/receipts.
    Database::update(
        'invoices',
        ['customer_email' => $email, 'newsletter_opt_in' => $newsletterOptIn],
        'id = ?',
        [$invoice['id']]
    );

    // Still confirming: don't queue anything yet — a receipt sent before
    // confirmation would confirm a payment that could still time out. Flag the
    // request so the settlement sweep queues exactly one receipt (to the
    // latest saved email) once the invoice settles.
    if ($onchainConfirming) {
        $receiptPending = false;
        if (NotificationSender::isPayerReceiptOffered()) {
            Database::update(
                'invoices',
                ['payer_receipt_requested' => 1],
                'id = ?',
                [$invoice['id']]
            );
            $receiptPending = true;
        }
        echo json_encode(['success' => true, 'receiptQueued' => false, 'receiptPending' => $receiptPending]);
        exit;
    }

    // Queue a payment-confirmation email only when receipts are offered. The
    // per-invoice cap still bounds how many receipts can be sent.
    $receiptQueued = false;
    if (NotificationSender::isPayerReceiptOffered()) {
        if (NotificationSender::payerReceiptCountForInvoice($invoice['id'])
                >= NotificationSender::PAYER_RECEIPT_MAX_PER_INVOICE) {
            cashupay_status(429);
            echo json_encode(['error' => 'Receipt limit reached for this invoice.']);
            exit;
        }
        $receiptQueued = NotificationSender::queuePayerReceipt($invoice, $email);
        if (!$receiptQueued) {
            // Race: the cap was reached between the check above and the call.
            cashupay_status(429);
            echo json_encode(['error' => 'Receipt limit reached for this invoice.']);
            exit;
        }
    }

    echo json_encode(['success' => true, 'receiptQueued' => $receiptQueued]);
    exit;
}

// Get checkout config
$checkoutConfig = $invoice['checkout_config'] ? json_decode($invoice['checkout_config'], true) : [];
$redirectUrl = $checkoutConfig['redirectURL'] ?? null;
// Defense-in-depth: the redirect is rendered as an <a href> below, and rows
// stored before the create-time http(s) validation may carry any scheme.
// htmlspecialchars does NOT neutralize a javascript:/data: scheme in an href,
// so re-validate here and drop anything that isn't http(s).
if ($redirectUrl !== null && $redirectUrl !== '') {
    $redirectUrl = Security::sanitizeUrl((string)$redirectUrl);
}
// Managed installs: payer-facing exits prefer the shop's front page. An
// invoice with no redirect gets the provisioned shop URL, and a redirect
// pointing at the BareBits admin/setup surfaces (invoices created from the
// embedded admin's "Request payment" modal historically stored the admin
// SPA's own URL) is rewritten there too — those surfaces are login-gated
// and payer-hostile. Boundary-checked prefix match, so an unrelated page
// that merely starts with the same string survives. Render-time (not
// create-time) so invoices already carrying an admin URL are covered.
$managedShopUrl = ManagedInstall::shopUrl();
if ($managedShopUrl !== '') {
    if ($redirectUrl === null || $redirectUrl === '') {
        $redirectUrl = $managedShopUrl;
    } else {
        $adminBase = rtrim(Config::getBaseUrl(), '/');
        foreach ([$adminBase . '/admin', $adminBase . '/setup'] as $surface) {
            $next = $redirectUrl[strlen($surface)] ?? '';
            if (strpos($redirectUrl, $surface) === 0
                    && in_array($next, ['', '/', '?', '#', '.'], true)) {
                $redirectUrl = $managedShopUrl;
                break;
            }
        }
    }
}
$redirectAuto = $checkoutConfig['redirectAutomatically'] ?? true;

// Pull the payer-facing note out of the invoice's metadata. itemDesc is what
// admin.php's "Create invoice" wizard stores for the memo field; other callers
// of the API may use the same key. Anything else in metadata is internal.
$invoiceMetadata = $invoice['metadata'] ? json_decode($invoice['metadata'], true) : null;
$invoiceNote = is_array($invoiceMetadata) ? trim((string)($invoiceMetadata['itemDesc'] ?? '')) : '';

// E-commerce invoices (metadata.orderId) get a retry link on the expired
// screen when the deployment provisioned a shop-side retry endpoint
// (CASHUPAY_RETRY_URL_TEMPLATE — the WordPress plugin's endpoint bounces the
// customer to WooCommerce's order-pay page, where clicking "Pay" makes the
// gateway mint a fresh invoice for the same order). Invoices with no order
// to re-pay keep the plain Return-to-Shop exit.
$retryUrl = null;
$retryTemplate = ManagedInstall::retryUrlTemplate();
if ($retryTemplate !== '' && is_array($invoiceMetadata) && !empty($invoiceMetadata['orderId'])) {
    $retryUrl = str_replace('{invoiceId}', rawurlencode((string)$invoice['id']), $retryTemplate);
}

// Whether the payer email/newsletter capture form exists at all on this
// deployment (managed single-shop installs default it off — the shop owns
// customer emails). Gates the form, the newsletter default, and receipts.
$emailCaptureEnabled = Config::isPayerEmailCaptureEnabled();

// Decide whether to render the payer-receipt form. The check is composed of
// site-wide master switch + per-type toggle + SMTP. When false, the success
// modal shows a "screenshot this page" fallback instead.
require_once __DIR__ . '/includes/notification_sender.php';
$payerReceiptOffered = $emailCaptureEnabled && NotificationSender::isPayerReceiptOffered();

// Resolve the newsletter checkbox's initial state for this store (per-store
// override → site-wide default). The email/newsletter capture form is shown
// regardless of whether receipts are offered.
$newsletterDefaultChecked = $emailCaptureEnabled && Config::getNewsletterDefaultChecked($invoice['store_id']);

// An on-chain payment was seen in the mempool but hasn't confirmed yet. This
// drives the unified detected/complete screen: same layout as the settled
// state (amount, invoice details, email form) with a "waiting for on-chain
// confirmation" badge + copy instead of the checkmark, and without the
// Continue-to-Store link (the WooCommerce order isn't marked paid until the
// Settled webhook). The generic #payment-processing block remains for the
// transient non-on-chain Processing state (Cashu minting after a Lightning
// payment).
$onchainPendingInitial = $invoice['status'] === 'Processing'
    && !empty($invoice['onchain_first_seen_at']);

// Explorer link for the detected on-chain tx (null for non-on-chain
// payments). Rendered on the unified screen in both pending and settled
// modes; the poll also carries it so JS can inject the link when the tx is
// detected while the page is open.
$onchainTxUrl = cashupay_onchain_tx_url($invoice);

// Format amount for display - use store's mint unit
$mintUnit = Config::getStoreMintUnit($invoice['store_id']);
$displayAmount = $invoice['amount'] . ' ' . strtoupper($invoice['currency']);

// Show secondary amount info based on currency relationships
$requestCurrency = strtoupper($invoice['currency']);
$mintUnitUpper = strtoupper($mintUnit);

if ($invoice['amount_sats'] && $requestCurrency !== 'SAT' && $requestCurrency !== 'SATS') {
    if ($mintUnitUpper === 'SAT') {
        // Mint uses sats - show sat equivalent
        $displayAmount .= ' (' . number_format($invoice['amount_sats']) . ' sats)';
    } elseif ($requestCurrency !== $mintUnitUpper) {
        // Different currency than mint unit - show mint unit equivalent
        // For fiat mints (EUR, USD), amount_sats is actually in cents
        $mintAmount = $invoice['amount_sats'] / 100; // Convert cents to main unit
        $displayAmount .= ' (' . number_format($mintAmount, 2) . ' ' . $mintUnitUpper . ')';
    }
    // If request currency matches mint unit, no secondary display needed
}

$baseUrl = Config::getBaseUrl();

// Admin-only payment-path debug labels. Double-gated: the site-wide toggle
// (default OFF) AND a logged-in admin session. We only probe the session when
// the admin session cookie is already present, so anonymous payers never
// receive a Set-Cookie from this public page. When on, gather the extra
// context the labels need (store on-chain mode + swap provider) so the helper
// stays a pure string builder. $pathDebug stays false for everyone else.
$pathDebug = false;
$pathDebugOnchainMode = null;
$pathDebugSwapProvider = null;
$pathDebugCashuMintUrl = null;
$pathDebugMayBeAdmin = isset($_COOKIE['cashupay_session']);
if (PaymentPathDebug::enabled() && $pathDebugMayBeAdmin) {
    if (Auth::isAdmin()) {
        $pathDebug = true;
        if (!empty($invoice['onchain_address'])) {
            $modeRow = Database::fetchOne(
                "SELECT onchain_address_mode FROM stores WHERE id = ?",
                [$invoice['store_id']]
            );
            $pathDebugOnchainMode = $modeRow['onchain_address_mode'] ?? null;
        }
        if (($invoice['payment_rail'] ?? '') === 'swap') {
            $swapRow = Database::fetchOne(
                "SELECT provider FROM swap_attempts WHERE invoice_id = ? ORDER BY id DESC LIMIT 1",
                [$invoice['id']]
            );
            $pathDebugSwapProvider = $swapRow['provider'] ?? null;
        }
        $pathDebugCashuMintUrl = Config::getStoreMintUrl($invoice['store_id']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pay Invoice - <?= htmlspecialchars($storeName) ?></title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: rgba(255, 255, 255, 0.05);
            --text-primary: #ffffff;
            --text-secondary: #a0aec0;
            --accent: #f7931a;
            --accent-hover: #e8820a;
            --success: #48bb78;
            --error: #e53e3e;
            --warning: #ed8936;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            min-height: 100vh;
            min-height: 100dvh;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
        }

        .container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0;
            width: 100%;
        }

        .payment-card {
            background: var(--bg-card);
            padding: 2rem;
            width: 100%;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(20px);
            text-align: center;
        }

        /* Keep the checkout content readable on wide screens while the card
           itself fills the full viewport. */
        .payment-card > * {
            width: 100%;
            max-width: 480px;
        }

        /* "Store name · powered by [BareBits]" on one compact line above
           the amount. */
        .merchant-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.35rem 0.5rem;
            margin-bottom: 1rem;
        }

        .logo {
            height: 22px;
            width: auto;
            display: block;
        }

        .merchant-name {
            font-size: 0.875rem;
            color: var(--text-secondary);
            /* A long store name wraps (and breaks mid-word if it has to)
               instead of pushing the logo past a phone's right edge; the
               "powered by" pair then drops to its own line. */
            min-width: 0;
            max-width: 100%;
            overflow-wrap: anywhere;
            text-align: center;
        }

        .powered-by {
            font-size: 0.75rem;
            color: var(--text-secondary);
            opacity: 0.8;
            margin-left: 0.25rem;
        }

        .amount {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .amount-secondary {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        .qr-container {
            background: #fff;
            border-radius: 16px;
            padding: 1rem;
            margin: 0 auto 1.5rem;
            width: 100%;
            /* 380px of QR plus the white quiet-zone padding. */
            max-width: calc(380px + 2rem);
        }

        .qr-container svg,
        .qr-container canvas {
            display: block;
            width: 100%;
            height: auto;
            aspect-ratio: 1;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }

        .status-badge.new {
            background: rgba(247, 147, 26, 0.2);
            color: var(--accent);
        }

        .status-badge.processing {
            background: rgba(66, 153, 225, 0.2);
            color: #63b3ed;
        }

        .status-badge.settled {
            background: rgba(72, 187, 120, 0.2);
            color: var(--success);
        }

        .status-badge.expired {
            background: rgba(229, 62, 62, 0.2);
            color: var(--error);
        }

        .status-badge .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .status-badge .checkmark {
            width: 14px;
            height: 14px;
        }

        .invoice-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 0.75rem;
            font-family: monospace;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Lightning <-> on-chain method tabs (shown only when both methods are
           configured for the invoice). */
        .method-tabs {
            display: flex;
            gap: 0.5rem;
            margin: 0 0 1rem 0;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 12px;
            padding: 0.25rem;
        }
        .method-tab {
            flex: 1;
            background: transparent;
            color: var(--text-secondary);
            border: none;
            padding: 0.6rem 0.75rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .method-tab.active {
            background: var(--accent);
            color: var(--text-primary);
        }
        .method-tab:hover:not(.active) {
            color: var(--text-primary);
        }
        .method-block.hidden {
            display: none !important;
        }

        /* "Popular wallet" tabs (WalletTabs): a brand logo over its name.
           Up to three tabs share the native strip; from four on the wallet
           tabs sit on a second strip (.method-tabs-wallets) directly under
           it, so six tabs stay legible on a phone without scrolling. */
        .method-tabs-wrap {
            margin: 0 0 1rem 0;
        }
        .method-tabs-wrap .method-tabs {
            margin: 0;
        }
        .method-tabs-wrap .method-tabs-wallets {
            margin-top: 0.5rem;
        }
        .method-tab-wallet {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            padding: 0.5rem 0.5rem 0.45rem;
            min-width: 0;
        }
        .method-tab-logo {
            display: block;
            height: 28px;
            width: auto;
            max-width: 100%;
        }
        .method-tab-logo.pm-card {
            background: #fff;
            padding: 4px 6px;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .method-tab-label {
            font-size: 0.75rem;
            line-height: 1;
            white-space: nowrap;
        }
        .method-tab-wallet.active .method-tab-label {
            color: var(--text-primary);
        }
        /* Where-to-find-it note at the top of a wallet tab. */
        .wallet-note {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0 0 1rem 0;
            padding: 0.7rem 0.85rem;
            text-align: left;
            font-size: 0.85rem;
            line-height: 1.45;
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        .wallet-note-logo {
            flex: 0 0 auto;
            height: 36px;
            width: auto;
        }
        .wallet-note-logo.pm-card {
            background: #fff;
            padding: 5px 7px;
            border-radius: 7px;
            box-sizing: border-box;
        }

        .invoice-input:hover {
            border-color: var(--accent);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 1rem 1.5rem;
            background: var(--accent);
            color: var(--text-primary);
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            text-decoration: none;
            margin-top: 1rem;
        }

        .btn:hover {
            background: var(--accent-hover);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            margin-top: 0.5rem;
        }

        /* Open-in-wallet + copy side by side under the QR. */
        .btn-row {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn-row .btn {
            flex: 1;
            min-width: 0;
            margin-top: 0;
            padding: 1rem 0.75rem;
            white-space: nowrap;
        }

        @media (max-width: 380px) {
            .btn-row .btn {
                font-size: 0.875rem;
                gap: 0.35rem;
                padding: 0.9rem 0.5rem;
            }
            .btn-row .btn svg {
                width: 16px;
                height: 16px;
            }
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        /* Share stays compact so "Pay with BTC wallet" + "Copy ..." keep their
           room when the row holds three buttons; on narrow screens it
           collapses to just the icon (the aria-label keeps it accessible). */
        .btn-row .btn-share {
            /* .btn sets width:100%; without width:auto the auto flex-basis
               resolves to the full row and crushes the other buttons. */
            flex: 0 0 auto;
            width: auto;
        }
        @media (max-width: 519px) {
            .btn-share .btn-share-label {
                display: none;
            }
        }
        @media (max-width: 380px) {
            .btn-row .btn-share {
                padding: 0.9rem 0.6rem;
            }
        }

        /* Admin-only payment-path debug label (next to each Copy button). */
        .debug-path {
            margin-top: 0.6rem;
            padding: 0.5rem 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.45rem;
            text-align: left;
            font-size: 0.72rem;
            line-height: 1.35;
            color: var(--text-secondary);
            background: rgba(247, 147, 26, 0.08);
            border: 1px dashed rgba(247, 147, 26, 0.4);
            border-radius: 8px;
            word-break: break-all;
        }

        .debug-path-tag {
            flex-shrink: 0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.6rem;
            font-weight: 700;
            color: var(--accent);
            background: rgba(247, 147, 26, 0.16);
            padding: 0.12rem 0.4rem;
            border-radius: 5px;
        }

        .debug-path-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .timer {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 1rem;
        }

        .timer.urgent {
            color: var(--warning);
        }

        .timer.expired {
            color: var(--error);
        }

        /* Long-lived invoices: "Refreshed N seconds ago" under the Lightning
           QR; .flash is added for one second each time the bolt11 changes. */
        .ln-refreshed {
            display: inline-block;
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin: -0.5rem 0 0.75rem;
            padding: 0.15rem 0.55rem;
            border-radius: 6px;
            min-height: 1.1em;
        }
        .ln-refreshed.flash {
            animation: ln-flash 1s ease-out;
        }
        @keyframes ln-flash {
            0%   { background: var(--accent); color: #fff; }
            100% { background: transparent; color: var(--text-secondary); }
        }

        /* "Pay with [logos]" row with "or any Bitcoin wallet" on its own
           line beneath. Each SVG carries its own brand fill; the row only
           sets size. Every logo is a wallet link equivalent to the "Pay with
           BTC wallet" button of the active method (see
           applyPaymentMethodLinks); on a view with nothing to open the
           anchors carry no href and render inert. */
        .payment-methods {
            margin-top: 0.85rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.35rem 0.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        .payment-methods .pm-label {
            white-space: nowrap;
        }
        .payment-methods .pm-label-any {
            flex-basis: 100%;
            font-size: 0.95rem;
            margin-top: 0.15rem;
        }
        .payment-methods .pm-logos {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: center;
            gap: 0.5rem;
        }
        /* Each brand is a logo stacked over its name, wrapped in a wallet
           link (same target as the "Pay with BTC wallet" button). */
        .pm-item {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            text-decoration: none;
            color: var(--text-secondary);
        }
        .pm-name {
            font-size: 0.65rem;
            line-height: 1;
            white-space: nowrap;
        }
        .pm-item[href]:hover .pm-name {
            color: var(--text-primary);
        }
        .pm-item:not([href]) {
            cursor: default;
        }
        .pm-logo {
            display: inline-block;
            height: 44px;
            width: auto;
            vertical-align: middle;
        }
        .pm-logo.pm-strike { border-radius: 10px; }
        /* Venmo and PayPal use thin blue wordmarks that disappear against
           the dark card; give them white backgrounds the way each brand
           presents itself in its own marketing. */
        .pm-logo.pm-card {
            background: #fff;
            padding: 6px 8px;
            border-radius: 8px;
            box-sizing: border-box;
        }
        /* On the Lightning view, hide brands that don't natively send LN —
           unless the brand has its own wallet tab, which is where a click
           on it leads. */
        .payment-methods[data-method="lightning"] .pm-no-lightning:not(.pm-has-tab) {
            display: none;
        }
        .pm-item.pm-has-tab {
            cursor: pointer;
        }
        .pm-item.pm-has-tab:hover .pm-name,
        .pm-item.pm-has-tab:focus-visible .pm-name {
            color: var(--text-primary);
        }

        /* Sanitized direct-receive failure banner (invoices.receive_errors):
           tells the payer a store wallet (NWC / noffer / Lightning address)
           couldn't be reached at invoice creation. Informational — the page
           always still shows a payable method. */
        .receive-errors {
            margin: 0 0 1rem 0;
            padding: 0.6rem 0.8rem;
            text-align: left;
            font-size: 0.8rem;
            line-height: 1.45;
            color: var(--text-secondary);
            border: 1px solid var(--warning);
            border-radius: 10px;
        }
        .receive-errors .re-title {
            color: var(--warning);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .receive-errors ul {
            margin: 0;
            padding-left: 1.1rem;
        }

        .barebits-notice {
            margin-top: 0.65rem;
            font-size: 1.05rem;
            color: var(--text-secondary);
            opacity: 0.85;
            line-height: 1.45;
        }
        .barebits-notice a {
            color: var(--text-secondary);
            text-decoration: underline;
        }
        .barebits-notice a:hover {
            color: var(--text-primary);
        }

        .success-animation {
            display: none;
            flex-direction: column;
            align-items: center;
            padding: 2rem 0;
        }

        .success-animation.show {
            display: flex;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            animation: popIn 0.5s ease;
        }

        @keyframes popIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }

        /* Large spinner shown in place of the checkmark while a detected
           on-chain payment waits for confirmation. */
        .confirming-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 6px solid rgba(66, 153, 225, 0.25);
            border-top-color: #63b3ed;
            animation: spin 1.2s linear infinite;
            margin-bottom: 1.5rem;
        }

        .onchain-pending-copy {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0 0 0.5rem;
            text-align: center;
        }

        /* Explorer link for the detected on-chain tx. Visible in both the
           pending and settled modes of the unified screen. */
        .onchain-tx-link {
            display: inline-block;
            color: #63b3ed;
            font-size: 0.85rem;
            margin: 0 0 0.5rem;
            text-decoration: underline;
        }
        .onchain-tx-link.hidden {
            display: none;
        }

        .hidden {
            display: none;
        }

        /* Invoice ID + note block shown on both pending and success screens.
           Compact, monospace for the ID, wraps the note. */
        .invoice-details {
            margin: 1rem 0;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 10px;
            text-align: left;
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }
        .invoice-details .label {
            font-weight: 600;
            color: var(--text-primary);
            margin-right: 0.4rem;
        }
        .invoice-details .invoice-id-value {
            font-family: monospace;
            word-break: break-all;
        }
        .invoice-details .invoice-note-value {
            word-break: break-word;
        }
        .invoice-details > div + div {
            margin-top: 0.35rem;
        }

        /* Payer-receipt opt-in form on the success modal. */
        .receipt-form {
            margin: 1rem 0 0;
            text-align: left;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .receipt-form .receipt-prompt {
            margin-bottom: 0.5rem;
            text-align: center;
            color: var(--text-primary);
        }
        .receipt-form .receipt-prompt small {
            display: block;
            margin-top: 0.15rem;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }
        .receipt-form .form-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .receipt-form .form-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .receipt-form .receipt-newsletter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0 0.6rem;
            color: var(--text-secondary);
            font-size: 0.82rem;
            cursor: pointer;
        }
        .receipt-form .receipt-newsletter input {
            width: 1rem;
            height: 1rem;
            accent-color: var(--accent);
            cursor: pointer;
            flex-shrink: 0;
        }
        .receipt-form .receipt-skip {
            display: block;
            margin-top: 0.75rem;
            text-align: center;
            color: var(--text-secondary);
            text-decoration: underline;
            font-size: 0.8rem;
            cursor: pointer;
            background: none;
            border: none;
            width: 100%;
        }
        .receipt-form .receipt-status {
            margin-top: 0.5rem;
            text-align: center;
            font-size: 0.8rem;
        }
        .receipt-form .receipt-status.error {
            color: var(--error);
        }
        .receipt-form .receipt-status.success {
            color: var(--success);
        }
        .receipt-fallback {
            margin: 1rem 0 0;
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-align: center;
        }

        .footer {
            padding: 1rem;
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .footer a {
            color: var(--text-secondary);
            text-decoration: none;
        }

        .copy-toast {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .copy-toast.show {
            transform: translateX(-50%) translateY(0);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="payment-card">
            <div class="merchant-header">
                <span class="merchant-name"><?= htmlspecialchars($storeName) ?></span>
                <span class="powered-by">powered by</span>
                <img class="logo" src="<?= htmlspecialchars(Urls::assets('img/barebits-logo.svg')) ?>" alt="BareBits">
            </div>

            <?php
            $hasLightning = !empty($invoice['bolt11']);
            $hasOnchain = !empty($invoice['onchain_address']);
            $onchainSat = (int)($invoice['onchain_amount_sat'] ?? 0);
            $onchainBtc = $onchainSat > 0
                ? rtrim(rtrim(bcdiv((string)$onchainSat, '100000000', 8), '0'), '.')
                : '0';
            if ($onchainBtc === '') { $onchainBtc = '0'; }
            $bip21 = $hasOnchain
                ? 'bitcoin:' . $invoice['onchain_address'] . '?amount=' . $onchainBtc
                : '';
            $shortOnchainAddr = $hasOnchain
                ? htmlspecialchars($invoice['onchain_address'])
                : '';

            // Cashu ecash: offered only when the store has opted into offline
            // Cashu acceptance. The request is built without contacting the
            // mint, so it works even while the server is mint-offline (the
            // whole point of offline acceptance). The NUT-18 request id is the
            // invoice id so the receipt attaches back to THIS invoice.
            $hasCashu = false;
            $cashuRequest = '';
            if (Config::isStoreConfigured($invoice['store_id'])
                && OfflineCashu::isEnabled($invoice['store_id'])
                && in_array($invoice['status'], ['New', 'Provisional'], true)
                && (int)($invoice['amount_sats'] ?? 0) > 0) {
                try {
                    $cMintUrl = Config::getStoreMintUrl($invoice['store_id']);
                    $cUnit = Config::getStoreMintUnit($invoice['store_id']);
                    $cWallet = new \Cashu\Wallet($cMintUrl, $cUnit);
                    // Mirror the noffer rail: prepend the store name (unless
                    // hidden) and drop the note when the privacy settings hide
                    // it. Uncapped — NUT-18 has no description length limit and
                    // notes already cap at 200 chars at entry.
                    $cashuMemo = Invoice::buildInvoiceMemo(
                        is_array($store) ? $store : [],
                        $invoiceMetadata,
                        0
                    );
                    $cPr = $cWallet->createHttpPaymentRequest(
                        (int)$invoice['amount_sats'],
                        Urls::receive(),
                        $cashuMemo !== '' ? $cashuMemo : null
                    );
                    $cPr->id = $invoice['id'];
                    $cashuRequest = $cPr->serialize();
                    $hasCashu = true;
                } catch (\Throwable $e) {
                    $hasCashu = false;
                }
            }
            $methodCount = ($hasLightning ? 1 : 0) + ($hasOnchain ? 1 : 0) + ($hasCashu ? 1 : 0);
            // "Popular wallet" tabs (WalletTabs): Cash App / Venmo / PayPal
            // tabs the store opted into, each repeating a native QR (the
            // source method) under a note on where that app hides its
            // send-bitcoin screen. Only wallets with an offered source are
            // rendered; they count as methods so an on-chain-only invoice
            // with a Venmo tab still gets a tab bar.
            $walletTabs = WalletTabs::offered(is_array($store) ? $store : [], $hasOnchain, $hasLightning);
            $methodCount += count($walletTabs);
            // "Share" copies the canonical invoice URL (configured base URL +
            // URL mode), not location.href — the viewer may have the page open
            // via an internal hostname. Hidden when the link couldn't work on
            // another device (Windows desktop package, loopback base URL).
            $shareUrl = Urls::payment($invoice['id']);
            $showShare = Urls::paymentLinkShareable();
            ?>
            <div id="payment-pending" class="<?= $invoice['status'] !== 'New' ? 'hidden' : '' ?>">
                <div class="amount"><?= htmlspecialchars($displayAmount) ?></div>

                <?php
                // Itemized cart breakdown (when this invoice came from the cart
                // checkout). Each line shows sats with the store-currency
                // equivalent in parentheses, per the store's display currency.
                $lineItems = Cart::getItems($invoice['id']);
                if (!empty($lineItems)):
                ?>
                <div style="margin:0 0 1rem 0; text-align:left; border:1px solid rgba(0,0,0,0.08); border-radius:12px; overflow:hidden;">
                    <?php foreach ($lineItems as $li):
                        $itype = (string)($li['image_type'] ?? 'none');
                        $ival = (string)($li['image_value'] ?? '');
                        $paren = (!empty($li['display_amount']) && strtoupper((string)$li['display_currency']) !== 'SAT')
                            ? ' (' . htmlspecialchars($li['display_amount']) . ' ' . htmlspecialchars(strtoupper((string)$li['display_currency'])) . ')'
                            : '';
                    ?>
                    <div style="display:flex; align-items:center; gap:0.6rem; padding:0.55rem 0.7rem; border-bottom:1px solid rgba(0,0,0,0.06);">
                        <div style="width:38px; height:38px; border-radius:8px; background:rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:center; font-size:1.3rem; overflow:hidden; flex-shrink:0;"><?php
                            if ($itype === 'upload' && $ival !== '') {
                                echo '<img src="' . htmlspecialchars(Product::imageUrl($ival)) . '" alt="" style="width:100%;height:100%;object-fit:cover;">';
                            } elseif ($itype === 'emoji' && $ival !== '') {
                                echo htmlspecialchars($ival);
                            } else {
                                echo "\u{1F4E6}";
                            }
                        ?></div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-weight:600; font-size:0.92rem;"><?= htmlspecialchars($li['title']) ?></div>
                            <div style="font-size:0.8rem; color:#666;">&times;<?= (int)$li['quantity'] ?></div>
                        </div>
                        <div style="text-align:right; font-size:0.85rem; white-space:nowrap;"><?= number_format((int)$li['amount_sats']) ?> sats<span style="color:#666;"><?= $paren ?></span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="status-badge new">
                    <div class="spinner"></div>
                    Waiting for payment
                </div>

                <?php
                // Direct-receive failures recorded at create time (see
                // Invoice::create): tell the payer which wallet *type* had a
                // problem and why, in the fixed sanitized phrasing persisted in
                // receive_errors. No addresses, URIs, or wallet-provided text
                // ever land here, but escape everything anyway — this page is
                // public.
                // The site-wide suppress switch (admin Settings) hides the
                // banner without touching what gets recorded — failures still
                // land in receive_errors and the admin Log tab.
                $receiveErrors = [];
                if (!empty($invoice['receive_errors']) && !AdminLog::suppressOnInvoice()) {
                    $decoded = json_decode((string)$invoice['receive_errors'], true);
                    if (is_array($decoded)) {
                        $receiveErrors = $decoded;
                    }
                }
                $receiveErrorLabels = [
                    'nwc' => 'NWC wallet',
                    'noffer' => 'Noffer (Nostr offer)',
                    'lnurl' => 'Lightning address',
                    'strike' => 'Strike API',
                    'swap' => 'Lightning (submarine swap)',
                ];
                if ($receiveErrors !== []): ?>
                <div class="receive-errors">
                    <div class="re-title">Some of this store&#39;s wallet connections had a problem</div>
                    <ul>
                        <?php foreach ($receiveErrors as $re):
                            $reType = is_array($re) ? (string)($re['type'] ?? '') : '';
                            $reReason = is_array($re) ? (string)($re['reason'] ?? '') : '';
                            if ($reReason === '') { continue; }
                        ?>
                        <li><strong><?= htmlspecialchars($receiveErrorLabels[$reType] ?? $reType) ?></strong>: <?= htmlspecialchars($reReason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    You can still pay using the option<?= $methodCount >= 2 ? 's' : '' ?> below.
                </div>
                <?php endif; ?>

                <?php
                // The store's "Payment order" setting picks the default
                // method when the invoice offers it (on-chain unless the
                // store chose Lightning first); the other methods remain
                // selectable via the tabs. Tab order mirrors the preference
                // so the default tab sits first; Cashu is always last.
                $firstMethod = Invoice::firstPaymentMethod(
                    is_array($store) ? $store : [],
                    $hasOnchain,
                    $hasLightning,
                    $hasCashu
                );
                $methodTabs = [
                    'onchain'   => ['offered' => $hasOnchain,   'label' => 'On-chain'],
                    'lightning' => ['offered' => $hasLightning, 'label' => 'Lightning'],
                    'cashu'     => ['offered' => $hasCashu,     'label' => 'Cashu'],
                ];
                // Wallet tabs follow the native methods. Up to three tabs share
                // one strip; from four on, the wallet tabs move onto a strip
                // of their own below the native one so six tabs stay legible
                // on a phone (see .method-tabs-wallets).
                $nativeTabCount = $methodCount - count($walletTabs);
                $walletTabsOwnStrip = $walletTabs !== [] && $methodCount > 3;
                $renderWalletTab = function (string $walletKey, string $walletSource): void {
                    $wb = WalletTabs::BRANDS[$walletKey];
                    $logoClass = 'method-tab-logo' . ($wb['class'] !== '' ? ' ' . $wb['class'] : '');
                    ?>
                    <button type="button" class="method-tab method-tab-wallet" data-method="<?= htmlspecialchars($walletKey) ?>" data-source="<?= htmlspecialchars($walletSource) ?>" role="tab"><img class="<?= $logoClass ?>" src="<?= htmlspecialchars(Urls::images('payment-methods/' . $wb['file'])) ?>" alt=""><span class="method-tab-label"><?= htmlspecialchars($wb['label']) ?></span></button>
                    <?php
                };
                if ($methodCount >= 2): ?>
                <div class="method-tabs-wrap">
                <div class="method-tabs" role="tablist">
                    <?php foreach (Invoice::paymentMethodTabOrder(is_array($store) ? $store : []) as $tabMethod):
                        if (!$methodTabs[$tabMethod]['offered']) { continue; } ?>
                    <button type="button" class="method-tab <?= $firstMethod === $tabMethod ? 'active' : '' ?>" data-method="<?= $tabMethod ?>" role="tab"><?= $methodTabs[$tabMethod]['label'] ?></button>
                    <?php endforeach; ?>
                    <?php if (!$walletTabsOwnStrip) { foreach ($walletTabs as $walletKey => $walletSource) { $renderWalletTab($walletKey, $walletSource); } } ?>
                </div>
                <?php if ($walletTabsOwnStrip): ?>
                <div class="method-tabs method-tabs-wallets" role="tablist" aria-label="Popular wallets">
                    <?php foreach ($walletTabs as $walletKey => $walletSource) { $renderWalletTab($walletKey, $walletSource); } ?>
                </div>
                <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasLightning): ?>
                <div class="method-block <?= $firstMethod === 'lightning' ? '' : 'hidden' ?>" data-method-block="lightning" data-source="lightning">
                    <div class="qr-container" id="qr-lightning"></div>
                    <?php if (!empty($invoice['long_lived'])): ?>
                    <?php // When this bolt11 was (re)sourced; flashes for a second on each refresh. ?>
                    <div class="ln-refreshed" id="ln-refreshed" aria-live="polite"></div>
                    <?php endif; ?>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($invoice['bolt11']) ?>">
                        <?= htmlspecialchars(substr($invoice['bolt11'], 0, 40) . '...' . substr($invoice['bolt11'], -10)) ?>
                    </div>
                    <div class="btn-row">
                        <a href="lightning:<?= htmlspecialchars($invoice['bolt11']) ?>" class="btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                            </svg>
                            Pay with BTC wallet
                        </a>
                        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($invoice['bolt11']) ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            Copy Invoice
                        </button>
                        <?php if ($showShare): ?>
                        <button type="button" class="btn btn-secondary btn-share" data-share-url="<?= htmlspecialchars($shareUrl) ?>" aria-label="Copy share link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                            <span class="btn-share-label">Share</span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($pathDebug): ?>
                    <div class="debug-path" title="Admin debug: how this Lightning invoice was sourced">
                        <span class="debug-path-tag">path</span>
                        <span class="debug-path-value"><?= htmlspecialchars(PaymentPathDebug::lightningLabel($invoice, $pathDebugSwapProvider)) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasOnchain): ?>
                <div class="method-block <?= $firstMethod === 'onchain' ? '' : 'hidden' ?>" data-method-block="onchain">
                    <div class="qr-container" id="qr-onchain"></div>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($bip21) ?>">
                        <?= $shortOnchainAddr ?>
                        <div style="margin-top:.35rem;font-size:.85rem;color:var(--text-secondary)">
                            <?= number_format($onchainSat) ?> sat
                        </div>
                    </div>
                    <div class="btn-row">
                        <a href="<?= htmlspecialchars($bip21) ?>" class="btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.5 8h4a2.5 2.5 0 0 1 0 5h-4v-5zm0 5h4.5a2.5 2.5 0 0 1 0 5h-4.5v-5z"></path>
                            </svg>
                            Pay with BTC wallet
                        </a>
                        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($bip21) ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            Copy Address
                        </button>
                        <?php if ($showShare): ?>
                        <button type="button" class="btn btn-secondary btn-share" data-share-url="<?= htmlspecialchars($shareUrl) ?>" aria-label="Copy share link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                            <span class="btn-share-label">Share</span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($pathDebug): ?>
                    <div class="debug-path" title="Admin debug: on-chain address derivation mode">
                        <span class="debug-path-tag">path</span>
                        <span class="debug-path-value"><?= htmlspecialchars(PaymentPathDebug::onchainLabel($invoice, $pathDebugOnchainMode)) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasCashu): ?>
                <div class="method-block <?= $firstMethod === 'cashu' ? '' : 'hidden' ?>" data-method-block="cashu">
                    <div class="qr-container" id="qr-cashu"></div>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($cashuRequest) ?>">
                        <?= htmlspecialchars(substr($cashuRequest, 0, 40) . '…') ?>
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($cashuRequest) ?>">
                            Copy Cashu Request
                        </button>
                        <?php if ($showShare): ?>
                        <button type="button" class="btn btn-secondary btn-share" data-share-url="<?= htmlspecialchars($shareUrl) ?>" aria-label="Copy share link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                            <span class="btn-share-label">Share</span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($pathDebug): ?>
                    <div class="debug-path" title="Admin debug: Cashu ecash request target mint">
                        <span class="debug-path-tag">path</span>
                        <span class="debug-path-value"><?= htmlspecialchars(PaymentPathDebug::cashuLabel($pathDebugCashuMintUrl)) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top:0.75rem; text-align:left;">
                        <label style="font-size:0.8rem; color:var(--text-secondary);">…or paste a Cashu token to pay</label>
                        <textarea id="cashu-token-input" rows="3" placeholder="cashuB..."
                                  style="width:100%; margin-top:0.35rem; padding:0.6rem; border-radius:8px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:inherit; font-size:0.75rem; resize:vertical;"></textarea>
                        <button type="button" class="btn" id="cashu-submit" style="margin-top:0.5rem;">Pay with Cashu token</button>
                        <div id="cashu-result" style="margin-top:0.6rem; font-size:0.82rem;"></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Wallet tab blocks: the source method's QR and payload, the
                // same buttons as the native block, plus the app note. Never
                // the default view (Invoice::firstPaymentMethod only ever
                // picks a native method), so they all start hidden. The
                // lightning-sourced ones carry data-source="lightning" so a
                // long-lived refresh (applyLightning) re-renders them too.
                foreach ($walletTabs as $walletKey => $walletSource):
                    $wb = WalletTabs::BRANDS[$walletKey];
                    $wIsLn = $walletSource === 'lightning';
                    $wPayload = $wIsLn ? (string)$invoice['bolt11'] : $bip21;
                    $wUri = $wIsLn ? 'lightning:' . $invoice['bolt11'] : $bip21;
                    $wLogoClass = 'wallet-note-logo' . ($wb['class'] !== '' ? ' ' . $wb['class'] : '');
                ?>
                <div class="method-block hidden" data-method-block="<?= htmlspecialchars($walletKey) ?>" data-source="<?= htmlspecialchars($walletSource) ?>">
                    <div class="wallet-note">
                        <img class="<?= $wLogoClass ?>" src="<?= htmlspecialchars(Urls::images('payment-methods/' . $wb['file'])) ?>" alt="<?= htmlspecialchars($wb['label']) ?>">
                        <span><?= htmlspecialchars($wb['note']) ?></span>
                    </div>
                    <div class="qr-container" id="qr-<?= htmlspecialchars($walletKey) ?>"></div>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($wPayload) ?>">
                        <?php if ($wIsLn): ?>
                        <?= htmlspecialchars(substr($wPayload, 0, 40) . '...' . substr($wPayload, -10)) ?>
                        <?php else: ?>
                        <?= $shortOnchainAddr ?>
                        <div style="margin-top:.35rem;font-size:.85rem;color:var(--text-secondary)">
                            <?= number_format($onchainSat) ?> sat
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="btn-row">
                        <a href="<?= htmlspecialchars($wUri) ?>" class="btn">
                            <?php if ($wIsLn): ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                            </svg>
                            <?php else: ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.5 8h4a2.5 2.5 0 0 1 0 5h-4v-5zm0 5h4.5a2.5 2.5 0 0 1 0 5h-4.5v-5z"></path>
                            </svg>
                            <?php endif; ?>
                            <?= htmlspecialchars($wb['button']) ?>
                        </a>
                        <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($wPayload) ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <?= $wIsLn ? 'Copy Invoice' : 'Copy Address' ?>
                        </button>
                        <?php if ($showShare): ?>
                        <button type="button" class="btn btn-secondary btn-share" data-share-url="<?= htmlspecialchars($shareUrl) ?>" aria-label="Copy share link">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                            <span class="btn-share-label">Share</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="invoice-details">
                    <div><span class="label">Invoice:</span><span class="invoice-id-value"><?= htmlspecialchars($invoice['id']) ?></span></div>
                    <?php if ($invoiceNote !== ''): ?>
                    <div><span class="label">Note:</span><span class="invoice-note-value"><?= htmlspecialchars($invoiceNote) ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="timer" id="timer"></div>

                <?php
                $initialMethod = $firstMethod;
                $imgBase = Urls::images('payment-methods/');
                // Logos shown in the "Pay with … / or any Bitcoin wallet" row. Each
                // carries a small name caption and acts as the "Pay with BTC wallet"
                // button of the active method: the lightning: URI on the Lightning
                // view, the BIP-21 URI on the on-chain view, and the lightning: URI
                // again on the Cashu view (no link at all when the invoice has no
                // Lightning leg). `noLightning` brands (card apps that can't natively
                // send Lightning) are hidden on the Lightning view via the
                // pm-no-lightning class. A brand whose wallet tab is shown
                // (`tab`, see WalletTabs) instead switches to that tab when
                // clicked (data-tab, pm-has-tab) and stays visible on every
                // view — its tab is the right place for it.
                $paymentBrands = [
                    ['name' => 'Cash App', 'file' => 'cashapp.svg', 'tab' => WalletTabs::CASHAPP],
                    ['name' => 'Strike',   'file' => 'strike.png',   'class' => 'pm-strike'],
                    ['name' => 'Coinbase', 'file' => 'coinbase.svg'],
                    ['name' => 'Kraken',   'file' => 'kraken.svg'],
                    ['name' => 'Venmo',    'file' => 'venmo.svg',    'class' => 'pm-card', 'noLightning' => true, 'tab' => WalletTabs::VENMO],
                    ['name' => 'PayPal',   'file' => 'paypal.svg',   'class' => 'pm-card', 'noLightning' => true, 'tab' => WalletTabs::PAYPAL],
                ];
                $lightningUri = $hasLightning ? 'lightning:' . $invoice['bolt11'] : '';
                $pmLinks = [
                    'lightning' => $lightningUri,
                    'onchain'   => $hasOnchain ? $bip21 : '',
                    'cashu'     => $lightningUri,
                ];
                $pmInitialHref = $pmLinks[$initialMethod] ?? '';
                ?>
                <div class="payment-methods" id="payment-methods" data-method="<?= $initialMethod ?>"
                     data-href-lightning="<?= htmlspecialchars($pmLinks['lightning']) ?>"
                     data-href-onchain="<?= htmlspecialchars($pmLinks['onchain']) ?>"
                     data-href-cashu="<?= htmlspecialchars($pmLinks['cashu']) ?>">
                    <span class="pm-label">Pay with</span>
                    <span class="pm-logos">
                        <?php foreach ($paymentBrands as $brand): ?>
                            <?php
                            $brandTab = isset($brand['tab'], $walletTabs[$brand['tab']]) ? $brand['tab'] : '';
                            $itemClass = 'pm-item' . (!empty($brand['noLightning']) ? ' pm-no-lightning' : '') . ($brandTab !== '' ? ' pm-has-tab' : '');
                            $logoClass = 'pm-logo' . (!empty($brand['class']) ? ' ' . $brand['class'] : '');
                            ?>
                            <?php if ($brandTab !== ''): ?>
                            <a class="<?= $itemClass ?>" data-tab="<?= htmlspecialchars($brandTab) ?>" role="button" tabindex="0" aria-label="Show the <?= htmlspecialchars($brand['name']) ?> tab">
                            <?php else: ?>
                            <a class="<?= $itemClass ?>"<?= $pmInitialHref !== '' ? ' href="' . htmlspecialchars($pmInitialHref) . '"' : '' ?> aria-label="Pay with BTC wallet (<?= htmlspecialchars($brand['name']) ?>)">
                            <?php endif; ?>
                                <img class="<?= $logoClass ?>" src="<?= htmlspecialchars($imgBase) ?><?= htmlspecialchars($brand['file']) ?>" alt="<?= htmlspecialchars($brand['name']) ?>" title="<?= htmlspecialchars($brand['name']) ?>">
                                <span class="pm-name"><?= htmlspecialchars($brand['name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </span>
                    <span class="pm-label pm-label-any">or any Bitcoin wallet</span>
                </div>

                <div class="barebits-notice">
                    Payments by <a href="https://getbarebits.com" target="_blank" rel="noopener">BareBits</a>.
                </div>
            </div>

            <div id="payment-processing" class="<?= ($invoice['status'] !== 'Processing' || $onchainPendingInitial) ? 'hidden' : '' ?>">
                <div class="status-badge processing">
                    <div class="spinner"></div>
                    Processing payment...
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    Payment detected. Please wait...
                </p>
            </div>

            <!-- Unified detected/complete screen. Shown from the moment an
                 on-chain payment is detected (pending mode: spinner badge +
                 confirmation copy) and stays up through settlement (settled
                 mode: checkmark + Continue-to-Store). Only the mode-specific
                 elements toggle, so anything the payer typed into the email
                 form survives the transition. -->
            <div id="payment-success" class="success-animation <?= ($invoice['status'] === 'Settled' || $onchainPendingInitial) ? 'show' : '' ?>">
                <div class="success-icon <?= $onchainPendingInitial ? 'hidden' : '' ?>" id="success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div class="confirming-icon <?= $onchainPendingInitial ? '' : 'hidden' ?>" id="confirming-icon"></div>
                <div class="amount"><?= htmlspecialchars($displayAmount) ?></div>
                <div class="status-badge settled <?= $onchainPendingInitial ? 'hidden' : '' ?>" id="success-badge-settled">
                    Payment Complete
                </div>
                <div class="status-badge processing <?= $onchainPendingInitial ? '' : 'hidden' ?>" id="success-badge-pending">
                    <div class="spinner"></div>
                    Waiting for on-chain confirmation
                </div>
                <p class="onchain-pending-copy <?= $onchainPendingInitial ? '' : 'hidden' ?>" id="onchain-pending-copy">
                    Payment detected, waiting for on-chain confirmation. Please do not
                    close this window. Confirmation usually takes up to 10 minutes, but
                    may take longer if you elected to pay a low network fee.
                </p>
                <!-- Explorer link for the detected tx. Always in the DOM so the
                     poll can populate it when the tx is detected mid-session;
                     shown in both pending and settled modes once a txid is
                     known (setSuccessMode deliberately doesn't toggle it). -->
                <a class="onchain-tx-link <?= $onchainTxUrl ? '' : 'hidden' ?>" id="onchain-tx-link"
                   href="<?= htmlspecialchars($onchainTxUrl ?? '') ?>" target="_blank" rel="noopener">
                    View transaction on mempool.space
                </a>
                <div class="invoice-details">
                    <div><span class="label">Invoice:</span><span class="invoice-id-value"><?= htmlspecialchars($invoice['id']) ?></span></div>
                    <?php if ($invoiceNote !== ''): ?>
                    <div><span class="label">Note:</span><span class="invoice-note-value"><?= htmlspecialchars($invoiceNote) ?></span></div>
                    <?php endif; ?>
                </div>
                <?php if ($emailCaptureEnabled): ?>
                <form class="receipt-form" id="receipt-form" data-receipt-offered="<?= $payerReceiptOffered ? '1' : '0' ?>" novalidate>
                    <div class="receipt-prompt">
                        <?= $payerReceiptOffered ? 'Email me a payment confirmation' : 'Leave your email' ?>
                        <small>Optional</small>
                    </div>
                    <input type="email" class="form-input" id="receipt-email"
                           placeholder="you@example.com" autocomplete="email" required>
                    <label class="receipt-newsletter" for="receipt-newsletter">
                        <input type="checkbox" id="receipt-newsletter" <?= $newsletterDefaultChecked ? 'checked' : '' ?>>
                        Subscribe to our newsletter
                    </label>
                    <button type="submit" class="btn" id="receipt-submit"><?= $payerReceiptOffered ? 'Send receipt' : 'Submit' ?></button>
                    <button type="button" class="receipt-skip" id="receipt-skip">No thanks</button>
                    <div class="receipt-status hidden" id="receipt-status"></div>
                </form>
                <?php endif; ?>
                <?php if (!$payerReceiptOffered): ?>
                <div class="receipt-fallback <?= $onchainPendingInitial ? 'hidden' : '' ?>" id="receipt-fallback">
                    Screenshot this page or save your invoice ID for your records.
                </div>
                <?php endif; ?>
                <?php if ($redirectUrl): ?>
                    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn <?= $onchainPendingInitial ? 'hidden' : '' ?>" id="redirect-btn">
                        Continue to Store
                    </a>
                <?php endif; ?>
            </div>

            <div id="payment-expired" class="<?= $invoice['status'] === 'Expired' ? '' : 'hidden' ?>">
                <div class="status-badge expired">
                    Invoice Expired
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    This invoice has expired. Please request a new one.
                </p>
                <?php if ($retryUrl): ?>
                    <a href="<?= htmlspecialchars($retryUrl) ?>" class="btn <?= $invoice['status'] === 'Invalid' ? 'hidden' : '' ?>" style="margin-top: 1.5rem;" id="retry-btn">
                        Request a new invoice
                    </a>
                    <?php if ($redirectUrl): ?>
                        <p style="margin-top: 1rem;">
                            <a href="<?= htmlspecialchars($redirectUrl) ?>" style="color: var(--text-secondary);">
                                Return to Shop
                            </a>
                        </p>
                    <?php endif; ?>
                <?php elseif ($redirectUrl): ?>
                    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn" style="margin-top: 1.5rem;">
                        Return to Shop
                    </a>
                <?php endif; ?>
            </div>

            <div id="payment-provisional" class="<?= $invoice['status'] === 'Provisional' ? '' : 'hidden' ?>">
                <div class="status-badge processing">
                    Accepted offline — pending settlement
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    This Cashu payment was accepted while the mint was unreachable. It is
                    <strong>provisional</strong> and will be confirmed automatically once the mint
                    is reachable again.
                </p>
            </div>
        </div>
    </div>

    <div class="footer">
        Powered by <a href="https://getbarebits.com" target="_blank" rel="noopener">BareBits</a>
    </div>

    <div class="copy-toast" id="copy-toast">Copied to clipboard!</div>

    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>qrcode-generator.js"></script>
    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>qr-shim.js"></script>
    <script>
        // `let`: a long-lived invoice's bolt11 is replaced in place when it lapses.
        let invoice = <?= json_encode($invoice['bolt11']) ?>;
        const onchainAddress = <?= json_encode($invoice['onchain_address'] ?? null) ?>;
        const onchainBip21 = <?= json_encode($bip21 ?? '') ?>;
        const invoiceId = <?= json_encode($invoiceId) ?>;
        const expirationTime = <?= (int)$invoice['expiration_time'] ?>;
        // Long-lived (shareable) invoice: the invoice itself lives for months;
        // the timer below tracks the Lightning bolt11 instead and asks the
        // server for a fresh one when it lapses.
        const longLived = <?= json_encode(!empty($invoice['long_lived'])) ?>;
        let lnExpiresAt = <?= (int)($invoice['ln_expires_at'] ?? 0) ?>;
        let lnRefreshedAt = <?= (int)(($invoice['ln_refreshed_at'] ?? null) ?: ($invoice['created_at'] ?? 0)) ?>;
        const redirectUrl = <?= json_encode($redirectUrl) ?>;
        const payerReceiptOffered = <?= json_encode($payerReceiptOffered) ?>;
        // CLINK noffer receive: subscription params so the page can watch the
        // offer's relay for the merchant's kind-21001 payment receipt. Null
        // unless this invoice rides the noffer rail.
        let nofferSub = <?= json_encode(cashupay_noffer_sub($invoice)) ?>;
        let currentStatus = <?= json_encode($invoice['status']) ?>;
        // Whether a detected on-chain payment is still waiting for
        // confirmation — selects the unified screen's pending mode while
        // status is Processing.
        let currentOnchainPending = <?= json_encode($onchainPendingInitial) ?>;

        function renderQR(targetId, data) {
            const target = document.getElementById(targetId);
            if (!target) return;
            target.innerHTML = '';
            if (typeof QRious === 'undefined') {
                target.innerHTML = '<p style="color:#666;padding:2rem;">QR code failed to load</p>';
                return;
            }
            const canvas = document.createElement('canvas');
            target.appendChild(canvas);
            // Rendered at 760px internally (2x the 380px display cap) so the
            // CSS-scaled canvas stays crisp on hidpi screens.
            new QRious({
                element: canvas, value: data, size: 760,
                backgroundAlpha: 1, foreground: '#000000', background: '#ffffff', level: 'M',
            });
        }

        const cashuRequest = <?= json_encode($cashuRequest) ?>;
        const storeIdForCashu = <?= json_encode($invoice['store_id']) ?>;
        const invoiceIdForCashu = <?= json_encode($invoice['id']) ?>;

        // Wallet tabs (WalletTabs) repeat a native QR: render each from its
        // source method's payload. Also re-run by applyLightning when a
        // long-lived invoice's bolt11 is replaced.
        function renderWalletQRs(source) {
            document.querySelectorAll('.method-block[data-source][data-method-block]').forEach(block => {
                const method = block.dataset.methodBlock;
                if (method === 'lightning' || method === 'onchain' || method === 'cashu') return;
                if (source && block.dataset.source !== source) return;
                if (block.dataset.source === 'lightning' && invoice) {
                    renderQR('qr-' + method, 'lightning:' + invoice.toUpperCase());
                } else if (block.dataset.source === 'onchain' && onchainBip21) {
                    renderQR('qr-' + method, onchainBip21);
                }
            });
        }

        if (currentStatus === 'New') {
            if (invoice) renderQR('qr-lightning', 'lightning:' + invoice.toUpperCase());
            if (onchainBip21) renderQR('qr-onchain', onchainBip21);
            if (cashuRequest) renderQR('qr-cashu', cashuRequest);
            renderWalletQRs(null);
        }

        // Pay-with-Cashu-token: POST the pasted token to receive.php, keyed to
        // this invoice. Online -> Settled; mint-unreachable -> Provisional. The
        // normal status poller then flips the page.
        const cashuSubmitBtn = document.getElementById('cashu-submit');
        if (cashuSubmitBtn) {
            cashuSubmitBtn.addEventListener('click', async () => {
                const token = (document.getElementById('cashu-token-input').value || '').trim();
                const resultEl = document.getElementById('cashu-result');
                if (!token) { resultEl.style.color = 'var(--danger)'; resultEl.textContent = 'Paste a Cashu token first.'; return; }
                cashuSubmitBtn.disabled = true;
                resultEl.style.color = 'var(--text-secondary)';
                resultEl.textContent = 'Submitting…';
                try {
                    const r = await fetch('receive.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ store_id: storeIdForCashu, id: invoiceIdForCashu, token })
                    });
                    const data = await r.json();
                    if (r.ok && data.success) {
                        if (data.settlement === 'offline_provisional') {
                            resultEl.style.color = '#fbd38d';
                            resultEl.textContent = '⚠ Accepted offline — provisional until the mint is reachable.';
                        } else {
                            resultEl.style.color = '#9ae6b4';
                            resultEl.textContent = '✓ Payment received.';
                        }
                        pollStatus();
                    } else {
                        resultEl.style.color = 'var(--danger)';
                        resultEl.textContent = data.error || 'Payment failed.';
                        cashuSubmitBtn.disabled = false;
                    }
                } catch (e) {
                    resultEl.style.color = 'var(--danger)';
                    resultEl.textContent = 'Network error submitting token.';
                    cashuSubmitBtn.disabled = false;
                }
            });
        }

        // Point every brand logo in the "Pay with" row at the wallet URI of
        // the given method (data-href-* on #payment-methods, kept current by
        // the Lightning refresh). An empty URI drops the href so the logo is
        // inert rather than a dead link.
        // Brands with their own wallet tab (a.pm-item[data-tab]) switch tabs
        // instead and are left alone here.
        function applyPaymentMethodLinks(method) {
            const pm = document.getElementById('payment-methods');
            if (!pm) return;
            const key = 'href' + String(method || '').charAt(0).toUpperCase() + String(method || '').slice(1);
            const href = pm.dataset[key] || '';
            pm.querySelectorAll('a.pm-item:not([data-tab])').forEach(a => {
                if (href) a.setAttribute('href', href);
                else a.removeAttribute('href');
            });
        }

        // Tab switching between the payment methods. A wallet tab (Cash App,
        // Venmo, PayPal) shows its own block but keys the brand row off the
        // native method it repeats (data-source), so the remaining brand
        // logos open the right wallet URI.
        function activateMethodTab(method) {
            const tab = document.querySelector('.method-tab[data-method="' + method + '"]');
            if (!tab) return;
            document.querySelectorAll('.method-tab').forEach(t => t.classList.toggle('active', t === tab));
            document.querySelectorAll('[data-method-block]').forEach(block => {
                block.classList.toggle('hidden', block.dataset.methodBlock !== method);
            });
            const source = tab.dataset.source || method;
            const pm = document.getElementById('payment-methods');
            if (pm) pm.dataset.method = source;
            applyPaymentMethodLinks(source);
        }
        document.querySelectorAll('.method-tab').forEach(tab => {
            tab.addEventListener('click', () => activateMethodTab(tab.dataset.method));
        });
        document.querySelectorAll('a.pm-item[data-tab]').forEach(item => {
            const go = (e) => { e.preventDefault(); activateMethodTab(item.dataset.tab); };
            item.addEventListener('click', go);
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') go(e);
            });
        });

        // Copy any string to clipboard. Falls back to a hidden textarea + execCommand
        // if the page isn't a secure context (clipboard API requires HTTPS or localhost).
        function copyText(value, toastMessage) {
            if (!value) return;
            const showToast = () => {
                const toast = document.getElementById('copy-toast');
                toast.textContent = toastMessage || 'Copied to clipboard!';
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(showToast).catch(() => fallback(value));
            } else {
                fallback(value);
            }
            function fallback(text) {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); showToast(); }
                catch (e) { console.error('copy failed', e); }
                document.body.removeChild(ta);
            }
        }
        // Attach copy handlers to any element with data-copy="...".
        document.querySelectorAll('[data-copy]').forEach(el => {
            el.addEventListener('click', () => copyText(el.dataset.copy));
            el.style.cursor = 'pointer';
        });
        // Share buttons copy the invoice's public URL with their own toast.
        document.querySelectorAll('[data-share-url]').forEach(el => {
            el.addEventListener('click', () => copyText(el.dataset.shareUrl, 'Share link copied to your clipboard!'));
        });
        // Backwards-compat alias for any handlers that still reference copyInvoice.
        function copyInvoice() { copyText(invoice); }

        function formatRemaining(remaining) {
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            if (remaining < 600) {
                // Less than 10 minutes: show minutes:seconds
                return `${minutes}:${seconds.toString().padStart(2, '0')}`;
            } else if (hours > 0) {
                // 1+ hours: show Xh Ym
                return `${hours}h ${minutes}m`;
            }
            // 10-59 minutes: show just minutes
            return `${minutes} min`;
        }

        // Update timer
        function updateTimer() {
            const now = Math.floor(Date.now() / 1000);
            const timerEl = document.getElementById('timer');

            if (longLived) {
                // The invoice does not lapse for months; what lapses is the
                // Lightning bolt11, which the server re-sources on request.
                updateRefreshedLabel(false);
                if (!invoice) {
                    timerEl.textContent = '';
                    timerEl.className = 'timer';
                    return;
                }
                const lnRemaining = lnExpiresAt - now;
                if (lnRemaining <= 0) {
                    timerEl.textContent = lnRefreshState === 'failed'
                        ? 'Couldn\u2019t get a new Lightning invoice \u2014 retrying\u2026'
                        : 'Refreshing Lightning invoice\u2026';
                    timerEl.className = lnRefreshState === 'failed' ? 'timer urgent' : 'timer';
                    requestLightningRefresh();
                    return;
                }
                timerEl.textContent = `Lightning invoice refreshes in ${formatRemaining(lnRemaining)}`;
                timerEl.className = 'timer';
                return;
            }

            const remaining = expirationTime - now;
            if (remaining <= 0) {
                timerEl.textContent = 'Invoice expired';
                timerEl.className = 'timer expired';
                return;
            }
            timerEl.textContent = `Expires in ${formatRemaining(remaining)}`;
            timerEl.className = remaining < 300 ? 'timer urgent' : 'timer';
        }

        // ---- Long-lived invoices: Lightning refresh ----
        // When the bolt11 lapses the page asks the server for a fresh one
        // (same destination chain / mint failover as creation). The server
        // first checks the old bolt11 for a payment and keeps it under watch
        // afterwards, so a scan made seconds before the swap still settles.
        // A failed attempt is retried every 30 s; on-chain stays payable.
        let lnRefreshInFlight = false;
        let lnRefreshNextAt = 0;
        let lnRefreshState = 'idle';
        async function requestLightningRefresh() {
            if (!longLived || lnRefreshInFlight || currentStatus !== 'New') return;
            const now = Math.floor(Date.now() / 1000);
            if (now < lnRefreshNextAt) return;
            lnRefreshInFlight = true;
            try {
                const body = new URLSearchParams();
                body.set('action', 'refresh_lightning');
                const url = new URL(window.location.href);
                url.searchParams.set('id', invoiceId);
                const r = await fetch(url.toString(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                const data = await r.json().catch(() => ({}));
                if (data && data.ok) {
                    lnRefreshState = 'idle';
                    applyLightning(data, true);
                } else if (data && data.reason === 'settled') {
                    pollStatus();
                } else if (data && data.bolt11 && (data.reason === 'not_due' || data.reason === 'busy')) {
                    // Another tab / a reload already refreshed it: adopt that.
                    lnRefreshState = 'idle';
                    lnRefreshNextAt = now + 5;
                    applyLightning(data, true);
                } else {
                    lnRefreshState = 'failed';
                    lnRefreshNextAt = now + 30;
                    updateTimer();
                }
            } catch (e) {
                lnRefreshState = 'failed';
                lnRefreshNextAt = now + 30;
            } finally {
                lnRefreshInFlight = false;
            }
        }

        // Swap the page onto a new bolt11: QR, copy targets, wallet link,
        // noffer watch, timer, and the "Refreshed ... ago" label (flashed
        // when the invoice actually changed).
        function applyLightning(data, flash) {
            if (!data || !data.bolt11) return;
            const changed = data.bolt11 !== invoice;
            invoice = data.bolt11;
            if (data.lnExpiresAt) lnExpiresAt = data.lnExpiresAt;
            if (data.lnRefreshedAt) lnRefreshedAt = data.lnRefreshedAt;
            if (changed) {
                renderQR('qr-lightning', 'lightning:' + invoice.toUpperCase());
                renderWalletQRs('lightning');
                // The native Lightning block and every wallet tab that
                // repeats it (data-source="lightning") swap payloads together.
                document.querySelectorAll('.method-block[data-source="lightning"]').forEach(block => {
                    const input = block.querySelector('.invoice-input');
                    if (input) {
                        input.dataset.copy = invoice;
                        input.textContent = invoice.slice(0, 40) + '...' + invoice.slice(-10);
                    }
                    const open = block.querySelector('a.btn[href^="lightning:"]');
                    if (open) open.href = 'lightning:' + invoice;
                    block.querySelectorAll('button[data-copy]').forEach(b => { b.dataset.copy = invoice; });
                });
                const pm = document.getElementById('payment-methods');
                if (pm) {
                    pm.dataset.hrefLightning = 'lightning:' + invoice;
                    pm.dataset.hrefCashu = 'lightning:' + invoice;
                    applyPaymentMethodLinks(pm.dataset.method);
                }
                nofferSub = data.nofferSub || null;
                restartNofferWatch();
            }
            updateRefreshedLabel(changed && flash);
            updateTimer();
        }

        function formatAgo(seconds) {
            if (seconds < 5) return 'just now';
            if (seconds < 60) return `${seconds} seconds ago`;
            const m = Math.floor(seconds / 60);
            if (m < 60) return m === 1 ? '1 minute ago' : `${m} minutes ago`;
            const h = Math.floor(m / 60);
            if (h < 48) return h === 1 ? '1 hour ago' : `${h} hours ago`;
            return `${Math.floor(h / 24)} days ago`;
        }

        function updateRefreshedLabel(flash) {
            const el = document.getElementById('ln-refreshed');
            if (!el) return;
            const ago = Math.max(0, Math.floor(Date.now() / 1000) - lnRefreshedAt);
            el.textContent = 'Refreshed ' + formatAgo(ago);
            if (flash) {
                el.classList.remove('flash');
                void el.offsetWidth; // restart the animation
                el.classList.add('flash');
                setTimeout(() => el.classList.remove('flash'), 1000);
            }
        }

        // CLINK noffer: hold a live Nostr subscription on the offer's relay and
        // forward the merchant's kind-21001 payment receipt to the server, which
        // verifies the signature + decrypts before settling. This is the
        // reliable settlement path for the noffer rail (cron is best-effort,
        // since the receipt is an ephemeral event relays may not retain). Uses
        // the browser's native WebSocket — no library/bundle needed.
        let activeNofferWatch = null;
        function restartNofferWatch() {
            if (activeNofferWatch) { activeNofferWatch.stop(); activeNofferWatch = null; }
            startNofferReceiptWatch();
        }
        function startNofferReceiptWatch() {
            if (!nofferSub || !nofferSub.relay) return;
            let ws = null;
            let stopped = false;
            // The sub id only needs to be unique on this socket, but we draw it
            // from the platform CSPRNG (with a graceful fallback for legacy /
            // non-secure contexts) to avoid any collision between concurrent
            // tabs and to keep Math.random out of the codebase.
            const subId = 'clink-' + (function () {
                try {
                    const c = (typeof crypto !== 'undefined' && crypto)
                        || (typeof window !== 'undefined' && window.crypto);
                    if (c && c.getRandomValues) {
                        const buf = new Uint8Array(6);
                        c.getRandomValues(buf);
                        return Array.from(buf, function (b) {
                            return b.toString(16).padStart(2, '0');
                        }).join('');
                    }
                } catch (e) { /* fall through to non-CSPRNG fallback */ }
                return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
            })();

            // Warn once (not per 3s retry) when the relay socket can't be
            // opened — e.g. a ws:// relay on an https page (mixed content) or
            // a malformed relay URL. Without this the stuck "waiting for
            // payment" screen is undiagnosable; the server-side receipt poll
            // keeps covering settlement while we retry.
            let connectWarned = false;
            function warnConnectOnce(detail) {
                if (connectWarned) return;
                connectWarned = true;
                console.warn('CLINK receipt watch: cannot reach relay', nofferSub.relay, detail || '');
            }

            function connect() {
                if (stopped) return;
                try { ws = new WebSocket(nofferSub.relay); }
                catch (e) {
                    // The constructor throws synchronously (mixed content,
                    // bad URL). Keep retrying while the invoice is open
                    // instead of silently giving up for good.
                    warnConnectOnce(e);
                    if (currentStatus === 'New') {
                        setTimeout(connect, 3000);
                    }
                    return;
                }

                ws.onopen = function () {
                    ws.send(JSON.stringify(['REQ', subId, {
                        kinds: [21001],
                        '#p': [nofferSub.pubkey],
                        '#e': [nofferSub.requestId],
                        since: Math.max(0, nofferSub.since - 1),
                    }]));
                };
                ws.onmessage = async function (msg) {
                    let data;
                    try { data = JSON.parse(msg.data); } catch (e) { return; }
                    if (!Array.isArray(data) || data[0] !== 'EVENT' || data[1] !== subId || !data[2]) return;
                    try {
                        const body = new URLSearchParams();
                        body.set('action', 'noffer_receipt');
                        body.set('event', JSON.stringify(data[2]));
                        const postUrl = new URL(window.location.href);
                        postUrl.searchParams.set('id', invoiceId);
                        const r = await fetch(postUrl.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString(),
                        });
                        const res = await r.json().catch(() => ({}));
                        if (res && res.settled) {
                            stopped = true;
                            try { ws.close(); } catch (e) {}
                            pollStatus(); // flip the UI to Settled promptly
                        }
                    } catch (e) { /* ignore; cron is the fallback */ }
                };
                ws.onclose = function () {
                    // Relays drop idle connections; reconnect while still open.
                    if (!stopped && currentStatus === 'New') {
                        setTimeout(connect, 3000);
                    }
                };
                ws.onerror = function () {
                    warnConnectOnce('socket error');
                    try { ws.close(); } catch (e) {}
                };
            }
            connect();

            // Stop once the invoice leaves the New state.
            const guard = setInterval(function () {
                if (currentStatus !== 'New') {
                    stopped = true;
                    try { if (ws) ws.close(); } catch (e) {}
                    clearInterval(guard);
                }
            }, 2000);
            // A Lightning refresh moving the invoice to a new noffer request
            // (or off the rail) tears this watch down via restartNofferWatch.
            activeNofferWatch = {
                stop: function () {
                    stopped = true;
                    try { if (ws) ws.close(); } catch (e) {}
                    clearInterval(guard);
                },
            };
        }

        // Poll for status
        async function pollStatus() {
            if (currentStatus === 'Settled' || currentStatus === 'Expired' || currentStatus === 'Invalid') {
                return;
            }

            try {
                const pollUrl = new URL(window.location.href);
                pollUrl.searchParams.set('id', invoiceId);
                pollUrl.searchParams.set('json', '1');
                const response = await fetch(pollUrl.toString());
                const data = await response.json();

                // Populate the explorer link as soon as the poll knows the
                // txid; independent of the status transition below so a page
                // that already shows the pending screen still picks it up.
                if (data.onchainTxUrl) {
                    const txLink = document.getElementById('onchain-tx-link');
                    if (txLink) {
                        txLink.href = data.onchainTxUrl;
                        txLink.classList.remove('hidden');
                    }
                }

                // Long-lived invoice: another tab or a reload may have
                // refreshed the bolt11 — adopt it so this QR stays payable.
                if (longLived && data.status === 'New' && data.bolt11 && data.bolt11 !== invoice) {
                    applyLightning(data, true);
                }

                const onchainPending = !!data.onchainPending;
                if (data.status !== currentStatus || onchainPending !== currentOnchainPending) {
                    currentStatus = data.status;
                    currentOnchainPending = onchainPending;
                    updateUI(data.status);
                }
            } catch (e) {
                console.error('Poll error:', e);
            }

            setTimeout(pollStatus, 2000);
        }

        // Toggle the unified detected/complete screen between its two modes.
        // Only mode-specific elements flip; the email form and invoice details
        // are shared DOM nodes, so anything the payer typed survives the
        // pending -> settled transition.
        function setSuccessMode(pending) {
            const toggle = (id, show) => {
                const el = document.getElementById(id);
                if (el) el.classList.toggle('hidden', !show);
            };
            toggle('success-icon', !pending);
            toggle('confirming-icon', pending);
            toggle('success-badge-settled', !pending);
            toggle('success-badge-pending', pending);
            toggle('onchain-pending-copy', pending);
            toggle('receipt-fallback', !pending);
            toggle('redirect-btn', !pending);
        }

        // Update UI based on status
        function updateUI(status) {
            document.getElementById('payment-pending').classList.add('hidden');
            document.getElementById('payment-processing').classList.add('hidden');
            document.getElementById('payment-success').classList.remove('show');
            document.getElementById('payment-expired').classList.add('hidden');
            const provisionalEl = document.getElementById('payment-provisional');
            if (provisionalEl) provisionalEl.classList.add('hidden');

            switch (status) {
                case 'New':
                    document.getElementById('payment-pending').classList.remove('hidden');
                    break;
                case 'Processing':
                    if (currentOnchainPending) {
                        // On-chain payment detected, awaiting confirmation:
                        // show the unified screen in pending mode so the payer
                        // can already leave their email.
                        setSuccessMode(true);
                        document.getElementById('payment-success').classList.add('show');
                        wireReceiptForm();
                    } else {
                        document.getElementById('payment-processing').classList.remove('hidden');
                    }
                    break;
                case 'Provisional':
                    if (provisionalEl) provisionalEl.classList.remove('hidden');
                    break;
                case 'Settled':
                    setSuccessMode(false);
                    document.getElementById('payment-success').classList.add('show');
                    onSettled();
                    break;
                case 'Expired':
                case 'Invalid': {
                    document.getElementById('payment-expired').classList.remove('hidden');
                    // Re-paying only makes sense for an invoice that lapsed on
                    // its own (Expired), not one an operator voided (Invalid).
                    const retryBtn = document.getElementById('retry-btn');
                    if (retryBtn) retryBtn.classList.toggle('hidden', status !== 'Expired');
                    break;
                }
            }
        }

        // Start polling and timer
        if (currentStatus === 'New' || currentStatus === 'Processing' || currentStatus === 'Provisional') {
            pollStatus();
            if (currentStatus === 'New') {
                updateTimer();
                setInterval(updateTimer, 1000);
                startNofferReceiptWatch();
            }
        }

        // Settled-state entry point. Wires up the receipt form (if rendered)
        // so the payer can opt into a receipt. We deliberately do NOT auto-
        // redirect: the success modal stays visible until the payer clicks
        // the "Continue to Store" link (when the merchant configured one) or
        // navigates away themselves. Auto-redirect used to flash the modal
        // for ~2 seconds, which made the invoice ID + note unreadable.
        function onSettled() {
            wireReceiptForm();
        }

        // Wire up the receipt form: submit POSTs to this same URL with
        // action=send_receipt. "No thanks" just hides the form so the
        // modal isn't cluttered. Neither path navigates the user away —
        // they leave via the "Continue to Store" link (if present).
        let receiptFormWired = false;
        function wireReceiptForm() {
            if (receiptFormWired) return;
            const form = document.getElementById('receipt-form');
            if (!form) return;
            receiptFormWired = true;

            const submitBtn = document.getElementById('receipt-submit');
            const skipBtn = document.getElementById('receipt-skip');
            const emailInput = document.getElementById('receipt-email');
            const newsletterInput = document.getElementById('receipt-newsletter');
            const statusEl = document.getElementById('receipt-status');
            const receiptOffered = form.getAttribute('data-receipt-offered') === '1';

            function setStatus(msg, kind) {
                statusEl.textContent = msg;
                statusEl.classList.remove('hidden', 'error', 'success');
                if (kind) statusEl.classList.add(kind);
            }

            skipBtn.addEventListener('click', () => {
                form.style.display = 'none';
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const email = emailInput.value.trim();
                if (!email) { setStatus('Please enter an email address.', 'error'); return; }
                submitBtn.disabled = true;
                setStatus(receiptOffered ? 'Sending…' : 'Saving…', null);
                try {
                    const body = new URLSearchParams();
                    body.set('action', 'send_receipt');
                    body.set('email', email);
                    body.set('newsletter', (newsletterInput && newsletterInput.checked) ? '1' : '0');
                    const pollUrl = new URL(window.location.href);
                    pollUrl.searchParams.set('id', invoiceId);
                    const response = await fetch(pollUrl.toString(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (response.ok && data.success) {
                        setStatus(data.receiptPending
                            ? 'Saved — your receipt will be emailed once the payment confirms.'
                            : (data.receiptQueued
                                ? 'Receipt queued — check your inbox.'
                                : 'Thanks — your email has been saved.'), 'success');
                        emailInput.disabled = true;
                        if (newsletterInput) newsletterInput.disabled = true;
                        submitBtn.style.display = 'none';
                        skipBtn.style.display = 'none';
                    } else {
                        setStatus(data.error || 'Could not save your email.', 'error');
                        submitBtn.disabled = false;
                    }
                } catch (err) {
                    setStatus('Network error — please try again.', 'error');
                    submitBtn.disabled = false;
                }
            });
        }

        // Handle settled state on initial page load.
        if (currentStatus === 'Settled') {
            onSettled();
        }
        // Page loaded while an on-chain payment awaits confirmation: the
        // unified screen is already rendered in pending mode server-side;
        // just wire the email form.
        if (currentStatus === 'Processing' && currentOnchainPending) {
            wireReceiptForm();
        }
    </script>
</body>
</html>
