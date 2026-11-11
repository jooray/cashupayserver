<?php
/**
 * NUT-18 Payment Request Endpoint
 *
 * This endpoint:
 * - Generates payment request QR codes (GET with ?store_id=X&amount=X)
 * - Receives token payments (POST with {store_id, token})
 * - Can be used as the transport target for payment requests
 */

require_once __DIR__ . '/includes/http_status.php';

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/urls.php';
require_once __DIR__ . '/includes/rates.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/offline_cashu.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\PaymentRequest;
use Cashu\Transport;
use Cashu\TokenSerializer;
use Cashu\CashuNetworkException;

// Initialize database
if (!Database::isInitialized()) {
    cashupay_status(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server not configured']);
    exit;
}

// Check setup complete
if (!Config::isSetupComplete()) {
    cashupay_status(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server setup not complete']);
    exit;
}

/**
 * Handle POST - Receive token payment
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Rate-limit this unauthenticated endpoint per client IP: every accepted
    // POST triggers a full mint swap (or offline DLEQ verification) plus DB
    // writes, so unthrottled it lets anyone holding a (semi-public) store_id
    // burn the mint's API budget and pin FPM workers. Same posture as the
    // public self-serve create path in pay.php.
    $rlIp = Security::getClientIp();
    if (!Security::checkRateLimit('receive_token', $rlIp, 30)) {
        cashupay_status(429);
        echo json_encode(['error' => 'Too many requests. Please wait a minute and try again.']);
        exit;
    }

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
        cashupay_status(400);
        echo json_encode(['error' => 'Missing store_id parameter']);
        exit;
    }

    if (!$tokenString) {
        cashupay_status(400);
        echo json_encode(['error' => 'Missing token']);
        exit;
    }

    // Verify store exists and is configured
    if (!Config::isStoreConfigured($storeId)) {
        cashupay_status(400);
        echo json_encode(['error' => 'Store not found or not configured']);
        exit;
    }

    // A presented token may correlate to an invoice via the request id — the
    // Cashu request page OR the admin "Request Payment" modal. We settle that
    // invoice by Cashu regardless of the rail it was originally created for.
    $existingInvoice = null;
    if ($requestId) {
        $maybe = Invoice::getById($requestId);
        if ($maybe && $maybe['store_id'] === $storeId
            && in_array($maybe['status'], ['New', 'Provisional'], true)) {
            $existingInvoice = $maybe;
        }
    }

    $unit = Config::getStoreMintUnit($storeId);

    // Read the token once for pre-swap validation, BEFORE any irreversible swap:
    //   1. Reject a unit mismatch. The token's amount is later credited 1:1 in
    //      the store's unit, so a msat (1000x) or usd token would otherwise
    //      corrupt the recorded settlement.
    //   2. When paying a specific invoice, verify the face value covers it.
    try {
        $probeToken = (new Wallet(Config::getStoreMintUrl($storeId), $unit))
            ->deserializeToken($tokenString);
    } catch (\Throwable $e) {
        // Throwable, not Exception: a TypeError/Error from a malformed token
        // (or a missing extension) must not become an HTML fatal on a JSON
        // endpoint. Only library errors carry a message safe to relay.
        cashupay_status(400);
        $detail = ($e instanceof \Cashu\CashuException) ? ': ' . $e->getMessage() : '';
        echo json_encode(['error' => 'Could not read token' . $detail]);
        exit;
    }
    $tokenUnit = OfflineCashu::normUnit($probeToken->unit ?? null);
    $wantUnit = OfflineCashu::normUnit($unit);
    if ($tokenUnit !== $wantUnit) {
        cashupay_status(400);
        echo json_encode(['error' => "Token unit ({$tokenUnit}) does not match this store's unit ({$wantUnit})"]);
        exit;
    }
    if ($existingInvoice !== null && !empty($existingInvoice['amount_sats'])) {
        $faceAmount = (int)$probeToken->getAmount();
        if ($faceAmount < (int)$existingInvoice['amount_sats']) {
            cashupay_status(400);
            echo json_encode(['error' => "Token amount ($faceAmount) is less than the invoice amount ({$existingInvoice['amount_sats']})"]);
            exit;
        }
    }

    try {
        // Initialize wallet with store's configuration
        $mintUrl = Config::getStoreMintUrl($storeId);
        $seed = Config::getStoreSeedPhrase($storeId);
        $dbPath = Database::getDbPath();

        $wallet = new Wallet($mintUrl, $unit, $dbPath);
        $wallet->loadMint();
        $wallet->initFromMnemonic($seed);

        // Online path: swap the token at the mint immediately (no double-spend
        // risk) and record a Settled cashu invoice.
        $proofs = $wallet->receive($tokenString);
        $amount = Wallet::sumProofs($proofs);

        $invoice = OfflineCashu::recordOnlineReceipt($storeId, (int)$amount, $mintUrl, $existingInvoice);

        echo json_encode([
            'success' => true,
            'settlement' => 'online',
            'amount' => $amount,
            'unit' => $unit,
            'proofs_count' => count($proofs),
            'invoice_id' => $invoice['id'] ?? null,
        ]);

    } catch (CashuNetworkException $netEx) {
        // The mint is unreachable. If the store has opted into offline
        // acceptance, verify the token offline (NUT-12 DLEQ) and record it as a
        // Provisional invoice to reconcile on reconnect. Otherwise surface the
        // outage as an error (unchanged behavior).
        if (!OfflineCashu::isEnabled($storeId)) {
            cashupay_status(503);
            echo json_encode(['error' => 'Mint is currently unreachable. Please try again shortly.']);
            exit;
        }

        $result = OfflineCashu::acceptOffline($storeId, $tokenString, ['invoice' => $existingInvoice]);
        if ($result['ok']) {
            echo json_encode([
                'success' => true,
                'settlement' => 'offline_provisional',
                'amount' => $result['amount'],
                'unit' => $unit,
                'invoice_id' => $result['invoice']['id'] ?? null,
                'warning' => 'Accepted OFFLINE — the mint was unreachable so this payment is '
                    . 'provisional and not yet settled. It will be confirmed automatically when '
                    . 'connectivity is restored. There is a small double-spend risk until then.',
            ]);
        } else {
            cashupay_status(400);
            echo json_encode([
                'error' => 'Offline acceptance failed: ' . $result['reason'],
                'settlement' => 'rejected',
            ]);
        }
    } catch (\Throwable $e) {
        // Mint responded and rejected (already spent, wrong mint, etc.) or any
        // other error: never accept these offline. Non-library throwables
        // (TypeError, Error) get a generic public message — their text can
        // carry file paths and internals.
        cashupay_status(400);
        $public = ($e instanceof \Cashu\CashuException)
            ? $e->getMessage()
            : 'Could not accept the token right now.';
        if (!($e instanceof \Cashu\CashuException)) {
            error_log('CashuPayServer: receive failed: ' . get_class($e) . ': ' . $e->getMessage());
        }
        echo json_encode(['error' => $public]);
    }
    exit;
}

/**
 * Handle GET - Generate payment request
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = $_GET['store_id'] ?? null;
    $amountRaw = trim((string)($_GET['amount'] ?? ''));
    $requestCurrency = trim((string)($_GET['currency'] ?? ''));
    $memo = $_GET['memo'] ?? null;
    $format = $_GET['format'] ?? 'html';

    // Admin-only: GET generates payment requests and lists stores.
    // POST stays public (NUT-18 token receipt) — handled above.
    if (!Auth::isLoggedIn()) {
        if ($format === 'json') {
            cashupay_status(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        Auth::requireLogin(); // redirects HTML clients to the admin login page
    }

    // Get list of stores for selector
    $stores = Database::fetchAll("SELECT id, name, mint_unit, default_currency FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL ORDER BY created_at DESC");
    $supportedCurrencies = Config::getSupportedDisplayCurrencies();

    // If no store_id provided, show store selector
    if (!$storeId) {
        if ($format === 'json') {
            cashupay_status(400);
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
    <title>Request Payment - BareBits</title>
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
        /* Dark dropdown options so the open <select> menu stays legible
           against the dark theme (white-on-white is the OS default). */
        select option {
            background-color: #1a202c;
            color: #e2e8f0;
        }
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
                <select name="store_id" id="store-select" required onchange="onStoreChange()">
                    <option value="">Select a store...</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?= htmlspecialchars($store['id']) ?>"
                                data-unit="<?= htmlspecialchars($store['mint_unit'] ?? 'sat') ?>"
                                data-default-currency="<?= htmlspecialchars($store['default_currency'] ?? ($store['mint_unit'] ?? 'sat')) ?>">
                            <?= htmlspecialchars($store['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Amount</label>
                <div style="display:flex; gap:0.5rem;">
                    <input type="number" name="amount" id="amount-input" placeholder="100" min="1" step="1" required style="flex:1;">
                    <select name="currency" id="currency-select" onchange="updateAmountConstraints()" style="width:auto; padding:0.75rem 1rem; border:1px solid rgba(255,255,255,0.1); background:rgba(0,0,0,0.2); border-radius:12px; color:inherit;">
                        <!-- populated by JS based on selected store -->
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Memo (optional)</label>
                <input type="text" name="memo" placeholder="Payment for...">
            </div>
            <button type="submit" class="btn">Generate Request</button>
        </form>
        <script>
            const SUPPORTED_CURRENCIES = <?= json_encode($supportedCurrencies) ?>;

            function isFiat(code) {
                const c = (code || '').toUpperCase();
                return !['SAT', 'SATS', 'MSAT', 'BTC'].includes(c);
            }

            function rebuildCurrencyOptions(mintUnit, defaultCurrency) {
                const sel = document.getElementById('currency-select');
                sel.innerHTML = '';
                const seen = new Set();
                const add = (code) => {
                    const norm = code.toLowerCase() === 'sats' ? 'sat' : code;
                    const key = norm.toLowerCase();
                    if (seen.has(key)) return;
                    seen.add(key);
                    const opt = document.createElement('option');
                    opt.value = norm;
                    opt.textContent = norm.toUpperCase();
                    sel.appendChild(opt);
                };
                add(mintUnit || 'sat');
                SUPPORTED_CURRENCIES.forEach(add);
                sel.value = defaultCurrency || mintUnit || 'sat';
            }

            function onStoreChange() {
                const select = document.getElementById('store-select');
                const opt = select.options[select.selectedIndex];
                const unit = opt?.dataset?.unit || 'sat';
                const def = opt?.dataset?.defaultCurrency || unit;
                rebuildCurrencyOptions(unit, def);
                updateAmountConstraints();
            }

            function updateAmountConstraints() {
                const cur = document.getElementById('currency-select').value;
                const amountInput = document.getElementById('amount-input');
                if (isFiat(cur)) {
                    amountInput.placeholder = '1.00';
                    amountInput.min = '0.01';
                    amountInput.step = '0.01';
                } else {
                    amountInput.placeholder = '100';
                    amountInput.min = '1';
                    amountInput.step = '1';
                }
            }

            // Initialize empty (no store selected yet)
            rebuildCurrencyOptions('sat', 'sat');
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
            cashupay_status(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Store not found or not configured']);
            exit;
        }
        cashupay_status(400);
        echo "Error: Store not found or not configured";
        exit;
    }

    // Get store config
    $mintUrl = Config::getStoreMintUrl($storeId);
    $unit = Config::getStoreMintUnit($storeId);
    $storeDefaultCurrency = Config::getStoreDefaultCurrency($storeId);

    // Resolve `amount` (in mint smallest unit) from the user-supplied amount and currency.
    // Three cases:
    //   1. New form: ?currency=X&amount=Y (Y is in natural denomination, e.g. "10.00 USD")
    //   2. Legacy callers: ?amount=Y with no currency (Y is already in mint smallest unit)
    //   3. Form that left amount blank: $amountRaw is empty, fall through to show form
    $amount = 0;
    $conversionError = null;
    if ($amountRaw !== '' && (float)$amountRaw > 0) {
        try {
            if ($requestCurrency !== '') {
                $providers = Config::getStorePriceProviders($storeId);
                $exchangeFee = Config::getStoreExchangeFee($storeId);
                $amount = ExchangeRates::convertToMintUnit(
                    $amountRaw,
                    $requestCurrency,
                    $unit,
                    $exchangeFee,
                    $providers['primary'],
                    $providers['secondary']
                );
            } else {
                // Legacy: treat as already in mint smallest unit
                $amount = (int)$amountRaw;
            }
        } catch (Exception $e) {
            $conversionError = $e->getMessage();
            $amount = 0;
        }
    }

    if ($amount <= 0) {
        // Show form if no amount specified
        if ($format === 'json') {
            cashupay_status(400);
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
    <title>Request Payment - BareBits</title>
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
        <?php if ($conversionError): ?>
        <div style="background: rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:8px; padding:0.75rem; margin-bottom:1rem; font-size:0.85rem; color:#fca5a5;">
            Could not convert amount: <?= htmlspecialchars($conversionError) ?>
        </div>
        <?php endif; ?>
        <form method="GET">
            <input type="hidden" name="store_id" value="<?= htmlspecialchars($storeId) ?>">
            <?php
                // Build the currency option list: mint unit first, then any
                // supported fiats not already represented.
                $currencyOptions = [];
                $seen = [];
                $pushCur = function(string $code) use (&$currencyOptions, &$seen) {
                    $norm = strtolower($code) === 'sats' ? 'sat' : $code;
                    $key = strtolower($norm);
                    if (isset($seen[$key])) return;
                    $seen[$key] = true;
                    $currencyOptions[] = $norm;
                };
                $pushCur($unit);
                foreach ($supportedCurrencies as $c) $pushCur($c);
                $selectedCurrency = $storeDefaultCurrency;
                $isFiatSelected = !in_array(strtoupper($selectedCurrency), ['SAT', 'SATS', 'MSAT', 'BTC'], true);
            ?>
            <div class="form-group">
                <label>Amount</label>
                <div style="display:flex; gap:0.5rem;">
                    <input type="number" name="amount" id="amount-input"
                           placeholder="<?= $isFiatSelected ? '1.00' : '100' ?>"
                           min="<?= $isFiatSelected ? '0.01' : '1' ?>"
                           step="<?= $isFiatSelected ? '0.01' : '1' ?>"
                           required style="flex:1;">
                    <select name="currency" id="currency-select" onchange="updateAmountConstraints()" style="width:auto; padding:0.75rem 1rem; border:1px solid rgba(255,255,255,0.1); background:rgba(0,0,0,0.2); border-radius:12px; color:inherit;">
                        <?php foreach ($currencyOptions as $cur): ?>
                            <option value="<?= htmlspecialchars($cur) ?>" <?= strtolower($cur) === strtolower($selectedCurrency) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($cur)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Memo (optional)</label>
                <input type="text" name="memo" placeholder="Payment for...">
            </div>
            <?php if (OfflineCashu::isEnabled($storeId) && OfflineCashu::perTxOverrideEnabled($storeId)): ?>
            <div class="form-group">
                <label style="display:flex; align-items:flex-start; gap:0.5rem; font-weight:400;">
                    <input type="checkbox" name="allow_any_mint" value="1" style="margin-top:0.2rem;">
                    <span style="font-size:0.85rem;">Allow payment from any mint
                        <span style="display:block; font-size:0.75rem; color:#a0aec0;">For offline acceptance on this payment only, accept a token from any mint (ignore the allowlist).</span>
                    </span>
                </label>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn">Generate Request</button>
        </form>
        <script>
            function updateAmountConstraints() {
                const cur = document.getElementById('currency-select').value.toUpperCase();
                const isFiat = !['SAT','SATS','MSAT','BTC'].includes(cur);
                const a = document.getElementById('amount-input');
                if (isFiat) { a.placeholder = '1.00'; a.min = '0.01'; a.step = '0.01'; }
                else { a.placeholder = '100'; a.min = '1'; a.step = '1'; }
            }
        </script>
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

        // Back the request with a 'New' cashu invoice so the POS screen can
        // poll for the outcome (online-settled vs offline-provisional) and the
        // received token attaches to a persistent ledger row. The NUT-18
        // request id is set to the invoice id so the paying wallet echoes it
        // back on POST.
        $allowAnyMint = OfflineCashu::perTxOverrideEnabled($storeId)
            && !empty($_GET['allow_any_mint']);
        $cashuInvoiceId = OfflineCashu::createPendingCashuInvoice(
            $storeId,
            $amountRaw !== '' ? $amountRaw : (string)$amount,
            $requestCurrency !== '' ? $requestCurrency : $unit,
            (int)$amount,
            is_string($memo) ? $memo : null,
            3600,
            $allowAnyMint
        );
        $pr->id = $cashuInvoiceId;
        $prString = $pr->serialize();
        $offlineEnabled = OfflineCashu::isEnabled($storeId);

        // Return based on format
        if ($format === 'json') {
            header('Content-Type: application/json');
            echo json_encode([
                'id' => $pr->id,
                'invoice_id' => $cashuInvoiceId,
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
    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>qrcode-generator.js"></script>
    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>qr-shim.js"></script>
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
            width: 100%;
            margin-bottom: 1.5rem;
        }
        .qr-container canvas {
            display: block;
            width: 100%;
            height: auto;
            aspect-ratio: 1;
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
        .result-banner {
            margin-top: 1.25rem;
            padding: 1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            line-height: 1.4;
            display: none;
        }
        .result-banner.show { display: block; }
        .result-banner.settled {
            background: rgba(72, 187, 120, 0.15);
            border: 1px solid rgba(72, 187, 120, 0.5);
            color: #9ae6b4;
        }
        .result-banner.provisional {
            background: rgba(237, 137, 54, 0.15);
            border: 1px solid rgba(237, 137, 54, 0.6);
            color: #fbd38d;
        }
        .result-banner strong { display: block; margin-bottom: 0.35rem; font-size: 1rem; }
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
        <div class="result-banner" id="result-banner"></div>
    </div>

    <script>
        const prString = <?= json_encode($prString) ?>;
        const invoiceId = <?= json_encode($cashuInvoiceId) ?>;
        const offlineEnabled = <?= $offlineEnabled ? 'true' : 'false' ?>;

        // Generate QR code
        if (typeof QRious !== 'undefined') {
            const canvas = document.createElement('canvas');
            document.getElementById('qr-container').appendChild(canvas);
            // Rendered oversized; CSS scales it down to fill the card, which
            // keeps the canvas crisp on hidpi screens.
            new QRious({
                element: canvas,
                value: prString,
                size: 640,
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

        // Poll for the receipt outcome and surface a clear banner — especially
        // the offline/provisional warning so the merchant understands the
        // double-spend risk before handing over goods.
        let pollTimer = null;
        function showBanner(kind, title, body) {
            const el = document.getElementById('result-banner');
            el.className = 'result-banner show ' + kind;
            el.innerHTML = '<strong>' + title + '</strong>' + body;
            document.getElementById('status').style.display = 'none';
        }
        async function poll() {
            try {
                const res = await fetch('payment.php?id=' + encodeURIComponent(invoiceId) + '&json=1', { cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                if (data.status === 'Settled') {
                    showBanner('settled', '✓ Payment received',
                        'The token was swapped at the mint and the payment is settled.');
                    if (pollTimer) clearInterval(pollTimer);
                } else if (data.status === 'Provisional') {
                    showBanner('provisional', '⚠ Accepted OFFLINE — not yet settled',
                        'The mint was unreachable, so this payment was accepted on the strength of its '
                        + 'offline signature only. It is <b>provisional</b> and will be confirmed '
                        + 'automatically once the mint is reachable again. There is a small risk it could '
                        + 'fail to settle (double-spend) until then — take this into account before '
                        + 'handing over goods.');
                    if (pollTimer) clearInterval(pollTimer);
                } else if (data.status === 'Invalid' || data.status === 'Expired') {
                    if (pollTimer) clearInterval(pollTimer);
                }
            } catch (e) { /* keep polling */ }
        }
        if (invoiceId) {
            pollTimer = setInterval(poll, 2000);
            poll();
        }
    </script>
</body>
</html>
        <?php
    } catch (Exception $e) {
        if ($format === 'json') {
            cashupay_status(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        } else {
            cashupay_status(500);
            echo "Error: " . htmlspecialchars($e->getMessage());
        }
    }
    exit;
}

// Method not allowed
cashupay_status(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Method not allowed']);
