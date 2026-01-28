<?php
/**
 * NUT-18 Payment Request Endpoint
 *
 * This endpoint:
 * - Generates payment request QR codes (GET with ?store_id=X&amount=X)
 * - Receives token payments (POST with {store_id, token})
 * - Can be used as the transport target for payment requests
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/urls.php';
require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\PaymentRequest;
use Cashu\Transport;
use Cashu\TokenSerializer;

// Initialize database
if (!Database::isInitialized()) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server not configured']);
    exit;
}

// Check setup complete
if (!Config::isSetupComplete()) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server setup not complete']);
    exit;
}

/**
 * Handle POST - Receive token payment
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Get JSON body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        // Try form data
        $data = [
            'id' => $_POST['id'] ?? null,
            'token' => $_POST['token'] ?? null,
            'store_id' => $_POST['store_id'] ?? null
        ];
    }

    $requestId = $data['id'] ?? null;
    $tokenString = $data['token'] ?? null;
    $storeId = $data['store_id'] ?? null;

    if (!$storeId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing store_id parameter']);
        exit;
    }

    if (!$tokenString) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing token']);
        exit;
    }

    // Verify store exists and is configured
    if (!Config::isStoreConfigured($storeId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Store not found or not configured']);
        exit;
    }

    try {
        // Initialize wallet with store's configuration
        $mintUrl = Config::getStoreMintUrl($storeId);
        $unit = Config::getStoreMintUnit($storeId);
        $seed = Config::getStoreSeedPhrase($storeId);
        $dbPath = Database::getDbPath();

        $wallet = new Wallet($mintUrl, $unit, $dbPath);
        $wallet->loadMint();
        $wallet->initFromMnemonic($seed);

        // Receive the token
        $proofs = $wallet->receive($tokenString);
        $amount = Wallet::sumProofs($proofs);

        // Log the receipt if we have a request ID
        if ($requestId) {
            error_log("Payment received for request $requestId: $amount $unit");
        }

        echo json_encode([
            'success' => true,
            'amount' => $amount,
            'unit' => $unit,
            'proofs_count' => count($proofs)
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Handle GET - Generate payment request
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = $_GET['store_id'] ?? null;
    $amount = (int)($_GET['amount'] ?? 0);
    $memo = $_GET['memo'] ?? null;
    $format = $_GET['format'] ?? 'html';

    // Get list of stores for selector
    $stores = Database::fetchAll("SELECT id, name, mint_unit FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL ORDER BY created_at DESC");

    // If no store_id provided, show store selector
    if (!$storeId) {
        if ($format === 'json') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'store_id required. Use ?store_id=X&amount=Y']);
            exit;
        }

        // Show store selector form
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Payment - CashuPayServer</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            background: rgba(30, 41, 59, 0.8);
            border-radius: 24px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            backdrop-filter: blur(20px);
        }
        h1 { font-size: 1.5rem; margin-bottom: 1.5rem; text-align: center; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
        input, select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.2);
            border-radius: 12px;
            color: inherit;
            font-size: 1rem;
        }
        input:focus, select:focus { outline: none; border-color: #f7931a; }
        .btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #f7931a 0%, #ff6b00 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 1rem;
        }
        .btn:hover { transform: translateY(-1px); }
        .help-text { font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Request Payment</h1>
        <?php if (empty($stores)): ?>
            <p style="color: #a0aec0; text-align: center;">No configured stores found. Please complete setup in the admin panel.</p>
        <?php else: ?>
        <form method="GET" id="request-form">
            <div class="form-group">
                <label>Store</label>
                <select name="store_id" id="store-select" required onchange="updateAmountLabel()">
                    <option value="">Select a store...</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?= htmlspecialchars($store['id']) ?>" data-unit="<?= htmlspecialchars($store['mint_unit'] ?? 'sat') ?>">
                            <?= htmlspecialchars($store['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label id="amount-label">Amount</label>
                <input type="number" name="amount" id="amount-input" placeholder="100" min="1" required>
            </div>
            <div class="form-group">
                <label>Memo (optional)</label>
                <input type="text" name="memo" placeholder="Payment for...">
            </div>
            <button type="submit" class="btn">Generate Request</button>
        </form>
        <script>
            function updateAmountLabel() {
                const select = document.getElementById('store-select');
                const selectedOption = select.options[select.selectedIndex];
                const unit = selectedOption?.dataset?.unit || 'sat';
                document.getElementById('amount-label').textContent = 'Amount (' + unit.toUpperCase() + ')';

                const amountInput = document.getElementById('amount-input');
                if (unit === 'sat' || unit === 'msat') {
                    amountInput.placeholder = '100';
                    amountInput.min = '1';
                    amountInput.step = '1';
                } else {
                    amountInput.placeholder = '1.00';
                    amountInput.min = '0.01';
                    amountInput.step = '0.01';
                }
            }
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
        exit;
    }

    // Verify store exists and is configured
    if (!Config::isStoreConfigured($storeId)) {
        if ($format === 'json') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Store not found or not configured']);
            exit;
        }
        http_response_code(400);
        echo "Error: Store not found or not configured";
        exit;
    }

    // Get store config
    $mintUrl = Config::getStoreMintUrl($storeId);
    $unit = Config::getStoreMintUnit($storeId);

    if ($amount <= 0) {
        // Show form if no amount specified
        if ($format === 'json') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Amount required. Use ?store_id=X&amount=Y']);
            exit;
        }

        // Show simple form with store pre-selected
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Payment - CashuPayServer</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            background: rgba(30, 41, 59, 0.8);
            border-radius: 24px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            backdrop-filter: blur(20px);
        }
        h1 { font-size: 1.5rem; margin-bottom: 1.5rem; text-align: center; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
        input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.2);
            border-radius: 12px;
            color: inherit;
            font-size: 1rem;
        }
        input:focus { outline: none; border-color: #f7931a; }
        .btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #f7931a 0%, #ff6b00 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 1rem;
        }
        .btn:hover { transform: translateY(-1px); }
    </style>
</head>
<body>
    <div class="card">
        <h1>Request Payment</h1>
        <form method="GET">
            <input type="hidden" name="store_id" value="<?= htmlspecialchars($storeId) ?>">
            <div class="form-group">
                <label>Amount (<?= htmlspecialchars(strtoupper($unit)) ?>)</label>
                <input type="number" name="amount"
                       placeholder="<?= $unit === 'sat' || $unit === 'msat' ? '100' : '1.00' ?>"
                       min="<?= $unit === 'sat' || $unit === 'msat' ? '1' : '0.01' ?>"
                       step="<?= $unit === 'sat' || $unit === 'msat' ? '1' : '0.01' ?>"
                       required>
            </div>
            <div class="form-group">
                <label>Memo (optional)</label>
                <input type="text" name="memo" placeholder="Payment for...">
            </div>
            <button type="submit" class="btn">Generate Request</button>
        </form>
    </div>
</body>
</html>
        <?php
        exit;
    }

    try {
        // Initialize wallet with store's configuration
        // Create payment request with HTTP transport to this endpoint
        $receiveUrl = Urls::receive();

        $wallet = new Wallet($mintUrl, $unit);
        $wallet->loadMint();

        $pr = $wallet->createHttpPaymentRequest($amount, $receiveUrl, $memo);
        $prString = $pr->serialize();

        // Return based on format
        if ($format === 'json') {
            header('Content-Type: application/json');
            echo json_encode([
                'id' => $pr->id,
                'amount' => $pr->amount,
                'unit' => $pr->unit,
                'memo' => $pr->memo,
                'mint' => $mintUrl,
                'store_id' => $storeId,
                'request' => $prString
            ]);
            exit;
        }

        // HTML format - show QR code
        $unitHelper = $wallet->getUnitHelper();
        $formattedAmount = $unitHelper->format($amount);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Request - <?= htmlspecialchars($formattedAmount) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            background: rgba(30, 41, 59, 0.8);
            border-radius: 24px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            backdrop-filter: blur(20px);
            text-align: center;
        }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .amount { font-size: 2rem; font-weight: 700; color: #f7931a; margin-bottom: 0.5rem; }
        .memo { color: #a0aec0; margin-bottom: 1.5rem; }
        .qr-container {
            background: white;
            padding: 1rem;
            border-radius: 16px;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        .request-string {
            background: rgba(0,0,0,0.2);
            padding: 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            word-break: break-all;
            color: #a0aec0;
            margin-bottom: 1rem;
            max-height: 80px;
            overflow-y: auto;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            color: white;
            font-weight: 500;
            cursor: pointer;
        }
        .btn:hover { background: rgba(255,255,255,0.15); }
        .status { margin-top: 1rem; font-size: 0.9rem; color: #a0aec0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Payment Request</h1>
        <div class="amount"><?= htmlspecialchars($formattedAmount) ?></div>
        <?php if ($memo): ?>
        <div class="memo"><?= htmlspecialchars($memo) ?></div>
        <?php endif; ?>

        <div class="qr-container" id="qr-container"></div>

        <div class="request-string"><?= htmlspecialchars($prString) ?></div>

        <button class="btn" onclick="copyRequest()">Copy Request</button>

        <div class="status" id="status">Scan with a Cashu wallet to pay</div>
    </div>

    <script>
        const prString = <?= json_encode($prString) ?>;

        // Generate QR code
        if (typeof QRious !== 'undefined') {
            const canvas = document.createElement('canvas');
            document.getElementById('qr-container').appendChild(canvas);
            new QRious({
                element: canvas,
                value: prString,
                size: 200,
                backgroundAlpha: 1,
                foreground: '#000000',
                background: '#ffffff',
                level: 'L'
            });
        } else {
            document.getElementById('qr-container').innerHTML = '<p style="color:#666;padding:2rem;">QR code failed to load</p>';
        }

        // Copy to clipboard
        function copyRequest() {
            navigator.clipboard.writeText(prString).then(() => {
                document.getElementById('status').textContent = 'Copied to clipboard!';
                setTimeout(() => {
                    document.getElementById('status').textContent = 'Scan with a Cashu wallet to pay';
                }, 2000);
            });
        }
    </script>
</body>
</html>
        <?php
    } catch (Exception $e) {
        if ($format === 'json') {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } else {
            http_response_code(500);
            echo "Error: " . htmlspecialchars($e->getMessage());
        }
    }
    exit;
}

// Method not allowed
http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Method not allowed']);
