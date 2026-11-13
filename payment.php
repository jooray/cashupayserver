<?php
/**
 * CashuPayServer - Payment Page
 *
 * Customer-facing payment page with Lightning QR code.
 */

require_once __DIR__ . '/includes/entrypoint_guard.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/security.php';

// There are no refunds, so a customer who already paid must never be told to pay again:
// a late payment, or one on an order the shop cancelled, is still collected (C1, C2).
const CLOSED_MESSAGE_EXPIRED = 'This invoice has expired. If you already paid, do not pay again: '
    . 'your payment will still be found and this page will update. Otherwise, go back to the shop '
    . 'to get a new invoice.';
const CLOSED_MESSAGE_INVALID = 'This order was cancelled. If you already paid, do not pay again: '
    . 'contact the shop and give them the reference below.';

// Keep minting alive if the customer navigates away mid-request. See FABLE-CASHUPAYSERVER-AUDIT (C-ABORT-1).
ignore_user_abort(true);
@set_time_limit(60);

// Security headers (customer page: strict CSP, scripts from this server only).
Security::setSecurityHeaders();
// The page and its status poll show live payment state (and the Lightning invoice):
// a cached copy must never stand in for it, in the browser or in a shared proxy.
header('Cache-Control: no-store');

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

// Get invoice ID
// `?id[]=x` arrives as an array; it must be refused here, before it reaches a
// string-typed parameter and becomes an uncaught TypeError (a stack trace on a public page).
$invoiceId = $_GET['id'] ?? '';
if (!is_string($invoiceId) || $invoiceId === '' || strlen($invoiceId) > 128) {
    http_response_code(400);
    echo 'Invoice ID required';
    exit;
}

$invoice = Invoice::getById($invoiceId);
if ($invoice === null) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

// Only the page's status checks ask the mint. The first render comes from the database,
// so a slow or unreachable mint no longer leaves the customer on a blank page for up to
// half a minute; the page's first check follows immediately (C5, 2026-10-28).
if (isset($_GET['json'])) {
    if (in_array($invoice['status'], ['New', 'Processing'], true)) {
        // H3: Poll only this specific invoice (fast) instead of all pending quotes
        Invoice::pollSingleQuote($invoiceId);
    } else {
        // A payment made at the last second, or on an order the shop cancelled, still
        // arrives; check for it so the customer is not told to pay again (C1, C2).
        Invoice::recheckClosedInvoice($invoiceId);
    }
    $before = $invoice['status'];
    $invoice = Invoice::getById($invoiceId) ?? $invoice;
    $justSettled = $before !== 'Settled' && $invoice['status'] === 'Settled';
}

// Trigger background processing for other tasks (non-blocking). A payment that just
// settled queued the shop's webhook: deliver it now, not on the next visit (R3).
Background::trigger(!empty($justSettled));

// Get store name for display
$store = Database::fetchOne(
    "SELECT name FROM stores WHERE id = ?",
    [$invoice['store_id']]
);
$storeName = $store['name'] ?? 'Payment';

// Something the customer can quote to the shop if anything goes wrong: the shop's own
// order number when the integration sent one, and always this payment's id (C3).
$metadata = $invoice['metadata'] ? (json_decode($invoice['metadata'], true) ?: []) : [];
$orderId = isset($metadata['orderId']) && is_scalar($metadata['orderId']) ? (string)$metadata['orderId'] : null;

// Handle JSON requests (polling)
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $invoice['status'],
        'additionalStatus' => $invoice['additional_status'],
    ]);
    exit;
}

// Get checkout config
$checkoutConfig = $invoice['checkout_config'] ? json_decode($invoice['checkout_config'], true) : [];
$redirectUrl = $checkoutConfig['redirectURL'] ?? null;
$redirectAuto = $checkoutConfig['redirectAutomatically'] ?? true;

// Re-check the stored redirect before handing it to the browser. Creation-time
// validation does not cover invoices written by an earlier version, and this value
// becomes both an anchor href and a window.location assignment — where escaping does
// nothing against a `javascript:` scheme.
if ($redirectUrl !== null) {
    if (!is_string($redirectUrl) || !Security::isSafeBrowserRedirect($redirectUrl)) {
        error_log('CashuPayServer: dropped unsafe redirectURL on invoice ' . $invoice['id']);
        $redirectUrl = null;
    }
}

// Format amount for display in the unit the invoice was created in: amount_sats is in
// that unit's smallest amount, and the store may have switched mints (and units) since
// (ARGON UX-06.7). Invoices from before the column existed fall back to the store's.
$mintUnit = (string)($invoice['mint_unit'] ?? '') !== '' ? (string)$invoice['mint_unit'] : Config::getStoreMintUnit($invoice['store_id']);
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
        // amount_sats is in the mint's smallest unit (cents for fiat, satoshis for btc)
        $decimals = Config::unitDecimals($mintUnit);
        $mintAmount = $invoice['amount_sats'] / (10 ** $decimals);
        $displayAmount .= ' (' . number_format($mintAmount, $decimals) . ' ' . $mintUnitUpper . ')';
    }
    // If request currency matches mint unit, no secondary display needed
}

$baseUrl = Config::getBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Invoice - <?php echo htmlspecialchars($storeName); ?></title>
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
            padding: 1rem;
            max-width: 480px;
            margin: 0 auto;
            width: 100%;
        }

        .payment-card {
            background: var(--bg-card);
            border-radius: 24px;
            padding: 2rem;
            width: 100%;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .logo {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .merchant-name {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
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
            display: inline-block;
        }

        .qr-container svg,
        .qr-container canvas {
            display: block;
            width: 220px;
            height: 220px;
        }

        @media (max-width: 360px) {
            .qr-container svg,
            .qr-container canvas {
                width: 180px;
                height: 180px;
            }
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
            word-break: break-all;
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

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
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

        .reference {
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            word-break: break-all;
            user-select: all;
        }

        .hidden {
            display: none;
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
            <div class="logo">&#9889;</div>
            <div class="merchant-name"><?php echo htmlspecialchars($storeName); ?></div>

            <div id="payment-pending" class="<?php echo $invoice['status'] !== 'New' ? 'hidden' : ''; ?>">
                <div class="amount"><?php echo htmlspecialchars($displayAmount); ?></div>

                <div class="status-badge new">
                    <div class="spinner"></div>
                    Waiting for payment
                </div>

                <div class="qr-container" id="qr-code"></div>

                <div class="invoice-input" id="invoice-text" onclick="copyInvoice()">
                    <?php echo htmlspecialchars(substr($invoice['bolt11'], 0, 40) . '...' . substr($invoice['bolt11'], -10)); ?>
                </div>

                <a href="lightning:<?php echo htmlspecialchars($invoice['bolt11']); ?>" class="btn" id="open-wallet">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    Open in Wallet
                </a>

                <button class="btn btn-secondary" id="copy-invoice" onclick="copyInvoice()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                    Copy Invoice
                </button>

                <div class="timer" id="timer"></div>
            </div>

            <div id="payment-processing" class="<?php echo $invoice['status'] !== 'Processing' ? 'hidden' : ''; ?>">
                <div class="status-badge processing">
                    <div class="spinner"></div>
                    Processing payment...
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    Payment detected. Please wait...
                </p>
                <div id="processing-slow" class="hidden">
                    <p style="color: var(--text-secondary); margin-top: 1rem;">
                        Your payment was received and is still being confirmed. Do not pay again.
                        You can close this page; the shop will be told when it is done.
                    </p>
                    <?php if ($redirectUrl): ?>
                        <a href="<?php echo htmlspecialchars($redirectUrl); ?>" class="btn" style="margin-top: 1.5rem;">
                            Return to Shop
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div id="payment-success" class="success-animation <?php echo $invoice['status'] === 'Settled' ? 'show' : ''; ?>">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div class="amount"><?php echo htmlspecialchars($displayAmount); ?></div>
                <div class="status-badge settled">
                    Payment Complete
                </div>
                <?php if ($redirectUrl): ?>
                    <a href="<?php echo htmlspecialchars($redirectUrl); ?>" class="btn" id="redirect-btn">
                        Continue to Store
                    </a>
                <?php endif; ?>
            </div>

            <?php
            // An invoice loaded directly (or reloaded) in Invalid state had no card at
            // all: the pending card was hidden, polling exited, and the customer saw an
            // empty page. Invalid is a cancellation, not an expiry, so say so.
            $isClosed = in_array($invoice['status'], ['Expired', 'Invalid'], true);
            $isInvalid = $invoice['status'] === 'Invalid';
            ?>
            <div id="payment-expired" class="<?php echo $isClosed ? '' : 'hidden'; ?>">
                <div class="status-badge expired" id="closed-badge">
                    <?php echo $isInvalid ? 'Payment Cancelled' : 'Invoice Expired'; ?>
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;" id="closed-message">
                    <?php echo $isInvalid ? CLOSED_MESSAGE_INVALID : CLOSED_MESSAGE_EXPIRED; ?>
                </p>
                <?php if ($redirectUrl): ?>
                    <a href="<?php echo htmlspecialchars($redirectUrl); ?>" class="btn" style="margin-top: 1.5rem;">
                        Return to Shop
                    </a>
                <?php endif; ?>
            </div>
            <p id="poll-trouble" class="hidden" style="color: var(--text-secondary); margin-top: 1rem;">
                We can't check this payment right now. If you already paid, do not pay again &mdash; we'll keep checking.
            </p>
            <div class="reference" id="reference">
                <?php if ($orderId !== null): ?>
                    Order <?php echo htmlspecialchars($orderId); ?> &middot;
                <?php endif; ?>
                Reference <span id="reference-id"><?php echo htmlspecialchars($invoiceId); ?></span>
            </div>
        </div>
    </div>

    <div class="footer">
        Powered by <a href="https://cashupayserver.org" target="_blank" rel="noopener noreferrer">CashuPayServer</a>
    </div>

    <div class="copy-toast" id="copy-toast">Copied to clipboard!</div>

    <script src="<?php echo htmlspecialchars(Urls::asset('js/vendor/qrcode-generator.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(Urls::asset('js/qr-canvas.js')); ?>"></script>
    <script>
        const invoice = <?php echo json_encode($invoice['bolt11']); ?>;
        const invoiceId = <?php echo json_encode($invoiceId); ?>;
        const expirationTime = <?php echo (int)$invoice['expiration_time']; ?>;
        const redirectUrl = <?php echo json_encode($redirectUrl); ?>;
        const redirectAuto = <?php echo json_encode($redirectAuto); ?>;
        let currentStatus = <?php echo json_encode($invoice['status']); ?>;

        // Generate QR code with lightning: prefix. Called after polling has started: an
        // invoice too long for a QR code throws, and that must not stop the status checks.
        function drawInvoiceQr() {
            if (!invoice || currentStatus !== 'New') return;
            const box = document.getElementById('qr-code');
            const fallback = '<p style="color:#666;padding:2rem;">The QR code could not be shown. '
                + 'Use "Copy Invoice" or "Open in Wallet" below instead.</p>';
            if (typeof QRious === 'undefined') {
                console.error('QRious library not loaded');
                box.innerHTML = fallback;
                return;
            }
            try {
                const canvas = document.createElement('canvas');
                box.appendChild(canvas);
                new QRious({
                    element: canvas,
                    value: 'lightning:' + invoice.toUpperCase(),
                    size: 220,
                    backgroundAlpha: 1,
                    foreground: '#000000',
                    background: '#ffffff',
                    level: 'M'
                });
            } catch (e) {
                console.error('QR code error:', e);
                box.innerHTML = fallback;
            }
        }

        // Copy invoice to clipboard
        let invoiceExpired = false;
        function copyInvoice() {
            if (invoiceExpired) return;
            const toast = document.getElementById('copy-toast');
            const show = (text) => {
                toast.textContent = text;
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            };
            // navigator.clipboard is missing on plain-HTTP pages and in some in-app
            // browsers; fall back to a hidden textarea, and say so if both fail (C8).
            const fallback = () => {
                const area = document.createElement('textarea');
                area.value = invoice;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(area);
                show(ok ? 'Copied to clipboard!' : 'Could not copy. Use "Open in Wallet" instead.');
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(invoice).then(() => show('Copied to clipboard!'), fallback);
            } else {
                fallback();
            }
        }

        // Update timer
        function updateTimer() {
            const now = Math.floor(Date.now() / 1000);
            const remaining = expirationTime - now;

            if (remaining <= 0) {
                // Until the server confirms the expiry with the mint, a payment made at the
                // last second may still be on its way; do not invite a new one (C6).
                document.getElementById('timer').textContent = 'Invoice expired. Checking for a last-second payment…';
                document.getElementById('timer').className = 'timer expired';
                document.getElementById('qr-code').style.opacity = '0.25';
                // Nor offer it through the buttons: an expired invoice cannot be paid, and
                // a wallet would only show an error (ARGON UX-06.7).
                invoiceExpired = true;
                for (const id of ['open-wallet', 'copy-invoice', 'invoice-text']) {
                    const el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                }
                return;
            }

            const timerEl = document.getElementById('timer');
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;

            let timeStr;
            if (remaining < 600) {
                // Less than 10 minutes: show minutes:seconds
                timeStr = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            } else if (hours > 0) {
                // 1+ hours: show Xh Ym
                timeStr = `${hours}h ${minutes}m`;
            } else {
                // 10-59 minutes: show just minutes
                timeStr = `${minutes} min`;
            }

            timerEl.textContent = `Expires in ${timeStr}`;
            timerEl.className = remaining < 300 ? 'timer urgent' : 'timer';
        }

        const closedMessages = <?php echo json_encode(['Expired' => CLOSED_MESSAGE_EXPIRED, 'Invalid' => CLOSED_MESSAGE_INVALID]); ?>;
        const pageOpened = Date.now();
        let processingSince = currentStatus === 'Processing' ? Date.now() : null;

        // Only these answers change the card; anything else (an error body, a proxy's
        // page) keeps the last good state on screen.
        const knownStatuses = ['New', 'Processing', 'Settled', 'Expired', 'Invalid'];
        let pollFailures = 0;
        function setPollTrouble(on) {
            document.getElementById('poll-trouble').classList.toggle('hidden', !on);
        }

        // Poll for status. An expired or cancelled invoice is still checked, slowly, for
        // half an hour: a payment made at the last second still arrives (C1, C2).
        async function pollStatus() {
            if (currentStatus === 'Settled') {
                return;
            }
            const closed = currentStatus === 'Expired' || currentStatus === 'Invalid';
            if (closed && Date.now() - pageOpened > 30 * 60 * 1000) {
                return;
            }

            try {
                const pollUrl = new URL(window.location.href);
                pollUrl.searchParams.set('id', invoiceId);
                pollUrl.searchParams.set('json', '1');
                const response = await fetch(pollUrl.toString());
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                const data = await response.json();
                if (!data || !knownStatuses.includes(data.status)) {
                    throw new Error('Unexpected status answer');
                }
                pollFailures = 0;
                setPollTrouble(false);

                if (data.status !== currentStatus) {
                    currentStatus = data.status;
                    updateUI(data.status);
                }
            } catch (e) {
                console.error('Poll error:', e);
                pollFailures++;
                if (pollFailures >= 3) {
                    setPollTrouble(true);
                }
            }

            if (currentStatus === 'Processing' && processingSince !== null
                && Date.now() - processingSince > 45000) {
                document.getElementById('processing-slow').classList.remove('hidden');
            }

            const isClosed = currentStatus === 'Expired' || currentStatus === 'Invalid';
            setTimeout(pollStatus, isClosed ? 15000 : 2000);
        }

        // Update UI based on status
        function updateUI(status) {
            document.getElementById('payment-pending').classList.add('hidden');
            document.getElementById('payment-processing').classList.add('hidden');
            document.getElementById('payment-success').classList.remove('show');
            document.getElementById('payment-expired').classList.add('hidden');

            switch (status) {
                case 'New':
                    document.getElementById('payment-pending').classList.remove('hidden');
                    break;
                case 'Processing':
                    processingSince = processingSince ?? Date.now();
                    document.getElementById('payment-processing').classList.remove('hidden');
                    break;
                case 'Settled':
                    document.getElementById('payment-success').classList.add('show');
                    if (redirectUrl && redirectAuto) {
                        setTimeout(() => {
                            window.location.href = redirectUrl;
                        }, 2000);
                    }
                    break;
                case 'Expired':
                case 'Invalid':
                    document.getElementById('closed-badge').textContent =
                        status === 'Invalid' ? 'Payment Cancelled' : 'Invoice Expired';
                    document.getElementById('closed-message').textContent = closedMessages[status];
                    document.getElementById('payment-pending').classList.add('hidden');
                    document.getElementById('payment-expired').classList.remove('hidden');
                    break;
            }
        }

        // Start polling and timer
        if (currentStatus !== 'Settled') {
            pollStatus();
            if (currentStatus === 'New') {
                updateTimer();
                setInterval(updateTimer, 1000);
            }
        }
        drawInvoiceQr();

        // Handle settled state on load with redirect
        if (currentStatus === 'Settled' && redirectUrl && redirectAuto) {
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 2000);
        }
    </script>
</body>
</html>
