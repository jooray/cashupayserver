<?php
/**
 * CashuPayServer - Payment Page
 *
 * Customer-facing payment page with Lightning QR code.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/background.php';

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

// Get invoice ID
$invoiceId = $_GET['id'] ?? '';
if (empty($invoiceId)) {
    http_response_code(400);
    echo 'Invoice ID required';
    exit;
}

// H3: Poll only this specific invoice (fast) instead of all pending quotes
Invoice::pollSingleQuote($invoiceId);

// Trigger background processing for other tasks (non-blocking)
Background::trigger();

// Get invoice
$invoice = Invoice::getById($invoiceId);
if ($invoice === null) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

// Get store name for display
$store = Database::fetchOne(
    "SELECT name FROM stores WHERE id = ?",
    [$invoice['store_id']]
);
$storeName = $store['name'] ?? 'Payment';

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
            <div class="merchant-name"><?= htmlspecialchars($storeName) ?></div>

            <div id="payment-pending" class="<?= $invoice['status'] !== 'New' ? 'hidden' : '' ?>">
                <div class="amount"><?= htmlspecialchars($displayAmount) ?></div>

                <div class="status-badge new">
                    <div class="spinner"></div>
                    Waiting for payment
                </div>

                <div class="qr-container" id="qr-code"></div>

                <div class="invoice-input" id="invoice-text" onclick="copyInvoice()">
                    <?= htmlspecialchars(substr($invoice['bolt11'], 0, 40) . '...' . substr($invoice['bolt11'], -10)) ?>
                </div>

                <a href="lightning:<?= htmlspecialchars($invoice['bolt11']) ?>" class="btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    Open in Wallet
                </a>

                <button class="btn btn-secondary" onclick="copyInvoice()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                    Copy Invoice
                </button>

                <div class="timer" id="timer"></div>
            </div>

            <div id="payment-processing" class="<?= $invoice['status'] !== 'Processing' ? 'hidden' : '' ?>">
                <div class="status-badge processing">
                    <div class="spinner"></div>
                    Processing payment...
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    Payment detected. Please wait...
                </p>
            </div>

            <div id="payment-success" class="success-animation <?= $invoice['status'] === 'Settled' ? 'show' : '' ?>">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div class="amount"><?= htmlspecialchars($displayAmount) ?></div>
                <div class="status-badge settled">
                    Payment Complete
                </div>
                <?php if ($redirectUrl): ?>
                    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn" id="redirect-btn">
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
                <?php if ($redirectUrl): ?>
                    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn" style="margin-top: 1.5rem;">
                        Return to Shop
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        Powered by <a href="#">CashuPayServer</a>
    </div>

    <div class="copy-toast" id="copy-toast">Copied to clipboard!</div>

    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script>
        const invoice = <?= json_encode($invoice['bolt11']) ?>;
        const invoiceId = <?= json_encode($invoiceId) ?>;
        const expirationTime = <?= (int)$invoice['expiration_time'] ?>;
        const redirectUrl = <?= json_encode($redirectUrl) ?>;
        const redirectAuto = <?= json_encode($redirectAuto) ?>;
        let currentStatus = <?= json_encode($invoice['status']) ?>;

        // Generate QR code with lightning: prefix
        if (invoice && currentStatus === 'New') {
            const qrData = 'lightning:' + invoice.toUpperCase();
            if (typeof QRious !== 'undefined') {
                const canvas = document.createElement('canvas');
                document.getElementById('qr-code').appendChild(canvas);
                new QRious({
                    element: canvas,
                    value: qrData,
                    size: 220,
                    backgroundAlpha: 1,
                    foreground: '#000000',
                    background: '#ffffff',
                    level: 'M'
                });
            } else {
                console.error('QRious library not loaded');
                document.getElementById('qr-code').innerHTML = '<p style="color:#666;padding:2rem;">QR code failed to load</p>';
            }
        }

        // Copy invoice to clipboard
        function copyInvoice() {
            navigator.clipboard.writeText(invoice).then(() => {
                const toast = document.getElementById('copy-toast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            });
        }

        // Update timer
        function updateTimer() {
            const now = Math.floor(Date.now() / 1000);
            const remaining = expirationTime - now;

            if (remaining <= 0) {
                document.getElementById('timer').textContent = 'Invoice expired';
                document.getElementById('timer').className = 'timer expired';
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

                if (data.status !== currentStatus) {
                    currentStatus = data.status;
                    updateUI(data.status);
                }
            } catch (e) {
                console.error('Poll error:', e);
            }

            setTimeout(pollStatus, 2000);
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
                    document.getElementById('payment-expired').classList.remove('hidden');
                    break;
            }
        }

        // Start polling and timer
        if (currentStatus === 'New' || currentStatus === 'Processing') {
            pollStatus();
            if (currentStatus === 'New') {
                updateTimer();
                setInterval(updateTimer, 1000);
            }
        }

        // Handle settled state on load with redirect
        if (currentStatus === 'Settled' && redirectUrl && redirectAuto) {
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 2000);
        }
    </script>
</body>
</html>
