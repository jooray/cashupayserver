<?php
/**
 * CashuPayServer - Public Self-Serve Invoice Page
 *
 * Lets a customer create AND pay their own invoice without an account, when the
 * merchant has enabled self-serve invoices for the store (site-wide default +
 * per-store override; see includes/selfserve.php). Reached at:
 *
 *   /pay/{storeId}            (router / built-in-server / rewrite mode)
 *   /pay.php?store={storeId}  (direct file access)
 *
 * GET  renders a small form: amount + currency (sat or the store's default
 *      fiat) + optional note.
 * POST validates the (untrusted) input, creates the invoice via Invoice::create
 *      and redirects to the regular /payment page for display + payment.
 *
 * Disabled / unknown stores return a generic 404 so we don't leak which store
 * IDs exist or which have self-serve turned on.
 */

require_once __DIR__ . '/includes/http_status.php';

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/selfserve.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/rates.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/urls.php';

// Bootstrap checks.
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    cashupay_status(503);
    echo 'Service unavailable';
    exit;
}

/** Render a generic 404 and stop. Used for unknown / disabled stores. */
function selfserve_not_found(): void {
    cashupay_status(404);
    echo 'Not found';
    exit;
}

$storeId = trim((string)($_GET['store'] ?? ''));
if ($storeId === '') {
    selfserve_not_found();
}

$store = Config::getStore($storeId);
if ($store === null || !SelfServe::isEnabledForStore($storeId)) {
    selfserve_not_found();
}

$storeName = $store['name'] ?? 'Payment';
$allowedCurrencies = SelfServe::allowedCurrencies($storeId);
$maxSats = SelfServe::effectiveMaxSats($storeId);

// ---------------------------------------------------------------------------
// Product mode: a ?products=prod_x:2,prod_y link pre-populates a fixed order
// (products + quantities chosen by the merchant). The customer can only add an
// optional tip and pay. Missing/disabled products are dropped with a notice;
// a malformed parameter or a link with nothing left 404s like a bad store.
// ---------------------------------------------------------------------------
$productMode = isset($_GET['products']) && trim((string)$_GET['products']) !== '';
$linkItems = [];        // [['product' => row, 'quantity' => int], ...]
$droppedCount = 0;
$pricedLines = [];      // Cart::priceItems lines, for display
$subtotalSats = 0;
$pricingError = null;   // rates unavailable etc. — page renders but can't price
$tipCurrency = SelfServe::defaultCurrency($storeId);
$tipRateSatsPerUnit = null; // client-side tip preview only; server recomputes

/** Map link items to the Cart::priceItems/checkout input shape. */
function selfserve_cart_items(array $linkItems): array {
    return array_map(
        fn($li) => ['product_id' => $li['product']['id'], 'quantity' => $li['quantity']],
        $linkItems
    );
}

if ($productMode) {
    require_once __DIR__ . '/includes/cart.php';
    require_once __DIR__ . '/includes/products.php';
    try {
        $parsed = SelfServe::parseProductsParam($storeId, (string)$_GET['products']);
    } catch (SelfServeValidationException $e) {
        selfserve_not_found();
    }
    $linkItems = $parsed['items'];
    $droppedCount = $parsed['dropped'];
    if (empty($linkItems)) {
        selfserve_not_found();
    }
    try {
        $priced = Cart::priceItems($storeId, selfserve_cart_items($linkItems));
        $pricedLines = $priced['lines'];
        $subtotalSats = $priced['totalSats'];
        if (strtolower($tipCurrency) !== 'sat') {
            // Sats per 1 unit of the store currency, with the store's exchange
            // fee — matches how a custom fiat tip will be priced at submit.
            $tipRateSatsPerUnit = ExchangeRates::convertToMintUnit(
                '1', $tipCurrency, 'sat',
                (float)($store['exchange_fee_percent'] ?? 0),
                $store['price_provider_primary'] ?? 'coingecko',
                $store['price_provider_secondary'] ?? 'binance'
            );
        }
    } catch (Throwable $e) {
        error_log('[selfserve] product pricing failed for store ' . $storeId . ': ' . $e->getMessage());
        $pricingError = 'Could not get an exchange rate right now. Please try again shortly.';
    }
}

// Values echoed back into the form so a validation error doesn't wipe what the
// customer typed.
$formAmount = '';
// Pre-select the store's default currency (falls back to 'sat' for sat-only
// stores). Always one of $allowedCurrencies.
$formCurrency = SelfServe::defaultCurrency($storeId);
$formNotes = '';
$formTipMode = '';
$formTipPercent = '';
$formTipAmount = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $productMode) {
    $formNotes      = (string)($_POST['notes'] ?? '');
    $formTipMode    = (string)($_POST['tip_mode'] ?? '');
    $formTipPercent = (string)($_POST['tip_percent'] ?? '');
    $formTipAmount  = (string)($_POST['tip_amount'] ?? '');

    try {
        // Same unauthenticated-creation rate limit as the simple form below.
        $ip = Security::getClientIp();
        if (!Security::checkRateLimit('selfserve_create', $ip, 10)) {
            throw new SelfServeValidationException('Too many requests. Please wait a minute and try again.');
        }

        $notes = SelfServe::validateNotes($formNotes);
        if ($pricingError !== null) {
            throw new SelfServeValidationException($pricingError);
        }

        $items = selfserve_cart_items($linkItems);
        $tipLine = SelfServe::buildTipLine($storeId, $formTipMode, $formTipPercent, $formTipAmount, $subtotalSats);
        if ($tipLine !== null) {
            $items[] = $tipLine;
        }

        // The per-store self-serve maximum applies to the full total, tip
        // included. Re-price with the tip line so a fiat tip uses the same
        // conversion the invoice will.
        $totalSats = $tipLine === null ? $subtotalSats : Cart::priceItems($storeId, $items)['totalSats'];
        SelfServe::assertWithinMax($storeId, $totalSats);

        // Cart::checkout creates the invoice + persists the invoice_items
        // snapshots (products and the tip line), exactly like the admin cart.
        $memo = $notes !== '' ? $notes : count($linkItems) . ' item(s)';
        $result = Cart::checkout($storeId, $items, $memo, null, [], true);

        header('Location: ' . Urls::payment($result['invoiceId']));
        exit;
    } catch (SelfServeValidationException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        // Never leak internal detail to the public page.
        error_log('[selfserve] product invoice creation failed for store ' . $storeId . ': ' . $e->getMessage());
        $error = 'Could not create the invoice right now. Please try again later.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$productMode) {
    $formAmount   = (string)($_POST['amount'] ?? '');
    $formCurrency = (string)($_POST['currency'] ?? SelfServe::defaultCurrency($storeId));
    $formNotes    = (string)($_POST['notes'] ?? '');

    try {
        // Rate-limit creation per client IP — this endpoint is unauthenticated
        // and each success hits the mint / allocates an address.
        $ip = Security::getClientIp();
        if (!Security::checkRateLimit('selfserve_create', $ip, 10)) {
            throw new SelfServeValidationException('Too many requests. Please wait a minute and try again.');
        }

        $currency = SelfServe::validateCurrency($storeId, $formCurrency);
        $amount   = SelfServe::validateAmount($formAmount, $currency);
        $notes    = SelfServe::validateNotes($formNotes);

        // Convert to sats for the liquidity-cap check. A fiat conversion can
        // fail if no exchange rate is available; surface that cleanly.
        try {
            $sats = ExchangeRates::convertToSats($amount, $currency, 'sat');
        } catch (Throwable $e) {
            throw new SelfServeValidationException('Could not get an exchange rate right now. Please try again shortly.');
        }
        SelfServe::assertWithinMax($storeId, $sats);

        $options = [
            'amount'   => $amount,
            // Uppercase to match the Greenfield API / admin Request modal, which
            // store the currency code uppercased (e.g. SAT, USD).
            'currency' => strtoupper($currency),
            // A self-serve invoice is a payment request the customer may come
            // back to (or forward): long-lived, Lightning re-sourced on view.
            'lifetime_sec' => Config::getSharedInvoiceLifetime(),
            'long_lived' => true,
        ];
        if ($notes !== '') {
            // itemDesc is the payer-facing note convention used across the app
            // (admin Request modal, payment.php display).
            $options['metadata'] = ['itemDesc' => $notes];
        }

        $invoice = Invoice::create($storeId, $options);

        // Hand off to the normal payment display page.
        header('Location: ' . Urls::payment($invoice['id']));
        exit;
    } catch (SelfServeValidationException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        // Never leak internal detail to the public page.
        error_log('[selfserve] invoice creation failed for store ' . $storeId . ': ' . $e->getMessage());
        $error = 'Could not create the invoice right now. Please try again later.';
    }
}

$hasFiat = count($allowedCurrencies) > 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay <?= htmlspecialchars($storeName) ?></title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: rgba(255, 255, 255, 0.05);
            --text-primary: #ffffff;
            --text-secondary: #a0aec0;
            --accent: #f7931a;
            --accent-hover: #e8820a;
            --error: #e53e3e;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            min-height: 100vh;
            min-height: 100dvh;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        .logo { font-size: 2.5rem; margin-bottom: 0.25rem; }
        .merchant-name { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
        .subtitle { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem; }
        .form-group { text-align: left; margin-bottom: 1.1rem; }
        .form-label { display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.4rem; }
        .amount-row { display: flex; gap: 0.5rem; }
        .form-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .form-input:focus { outline: none; border-color: var(--accent); }
        select.form-input { width: auto; flex: 0 0 auto; }
        .form-help { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.35rem; }
        .btn {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 0.5rem;
        }
        .btn:hover { background: var(--accent-hover); }
        .error {
            background: rgba(229, 62, 62, 0.15);
            color: #feb2b2;
            border: 1px solid rgba(229, 62, 62, 0.3);
            border-radius: 12px;
            padding: 0.7rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 1.1rem;
            text-align: left;
        }
        .notice {
            background: rgba(247, 147, 26, 0.12);
            color: #fbd38d;
            border: 1px solid rgba(247, 147, 26, 0.35);
            border-radius: 12px;
            padding: 0.7rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 1.1rem;
            text-align: left;
        }
        .items {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0.4rem 0.9rem;
            margin-bottom: 1.1rem;
            text-align: left;
        }
        .item-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.55rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            font-size: 0.9rem;
        }
        .item-img {
            width: 36px; height: 36px; border-radius: 8px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0, 0, 0, 0.3); overflow: hidden; font-size: 1.25rem;
        }
        .item-img img { width: 100%; height: 100%; object-fit: cover; }
        .item-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .item-qty { color: var(--text-secondary); font-size: 0.8rem; }
        .item-amount { text-align: right; white-space: nowrap; }
        .item-fiat { display: block; color: var(--text-secondary); font-size: 0.75rem; }
        .subtotal-row {
            display: flex; justify-content: space-between; padding: 0.6rem 0 0.35rem;
            font-size: 0.9rem; color: var(--text-secondary);
        }
        .total-row {
            display: flex; justify-content: space-between; align-items: baseline;
            font-weight: 600; font-size: 1rem; margin-bottom: 1.1rem; text-align: left;
        }
        .tip-row { display: flex; gap: 0.4rem; flex-wrap: wrap; }
        .tip-btn {
            flex: 1 1 auto;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            color: var(--text-primary);
            padding: 0.5rem 0.6rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .tip-btn.active {
            border-color: var(--accent);
            background: rgba(247, 147, 26, 0.18);
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">⚡</div>
        <div class="merchant-name"><?= htmlspecialchars($storeName) ?></div>
        <div class="subtitle"><?= $productMode ? 'Review your order and pay' : 'Enter an amount to generate a payment' ?></div>

        <?php if ($error !== null): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($productMode && $droppedCount > 0): ?>
            <div class="notice">Some items in this link are no longer available.</div>
        <?php endif; ?>

        <?php if ($productMode && $pricingError !== null): ?>
            <?php if ($error === null): ?>
                <div class="error"><?= htmlspecialchars($pricingError) ?></div>
            <?php endif; ?>
            <a class="btn" href="" style="display:block; text-decoration:none;">Try again</a>
        <?php elseif ($productMode): ?>
        <!-- POSTs back to the same URL (incl. ?products=…) so the order is
             re-resolved and re-priced server-side on submit. -->
        <form method="POST" autocomplete="off">
            <div class="items">
                <?php foreach ($pricedLines as $ln): ?>
                    <div class="item-row">
                        <div class="item-img"><?php
                            if ($ln['image_type'] === 'upload' && $ln['image_value']) {
                                echo '<img src="' . htmlspecialchars(Product::imageUrl((string)$ln['image_value'])) . '" alt="">';
                            } else {
                                echo htmlspecialchars(($ln['image_type'] === 'emoji' && $ln['image_value']) ? $ln['image_value'] : "\u{1F4E6}");
                            }
                        ?></div>
                        <div class="item-title">
                            <?= htmlspecialchars($ln['title']) ?>
                            <span class="item-qty">&times;<?= (int)$ln['quantity'] ?></span>
                        </div>
                        <div class="item-amount">
                            <?= number_format((int)$ln['amount_sats']) ?> sats
                            <?php if ($ln['display_amount'] !== null && strtolower((string)$ln['display_currency']) !== 'sat'): ?>
                                <span class="item-fiat">&asymp; <?= htmlspecialchars(number_format((float)$ln['display_amount'], 2)) ?> <?= htmlspecialchars(strtoupper((string)$ln['display_currency'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="subtotal-row">
                    <span>Subtotal</span>
                    <span><?= number_format($subtotalSats) ?> sats</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Add a tip <span style="opacity:0.6;">(optional)</span></label>
                <div class="tip-row">
                    <button type="button" class="tip-btn" data-tip-mode="none">No tip</button>
                    <?php foreach (SelfServe::TIP_PERCENT_PRESETS as $pct): ?>
                        <button type="button" class="tip-btn" data-tip-mode="percent" data-tip-percent="<?= (int)$pct ?>"><?= (int)$pct ?>%</button>
                    <?php endforeach; ?>
                    <button type="button" class="tip-btn" data-tip-mode="custom">Custom</button>
                </div>
                <div class="amount-row" id="tip-custom-row" style="display:none; margin-top:0.5rem;">
                    <input type="text" inputmode="decimal" class="form-input" id="tip-amount" name="tip_amount"
                           value="<?= htmlspecialchars($formTipAmount) ?>" placeholder="0">
                    <span class="form-input" style="display:flex;align-items:center;color:var(--text-secondary);">
                        <?= strtolower($tipCurrency) === 'sat' ? 'SATS' : htmlspecialchars(strtoupper($tipCurrency)) ?>
                    </span>
                </div>
                <input type="hidden" name="tip_mode" id="tip-mode" value="<?= htmlspecialchars($formTipMode) ?>">
                <input type="hidden" name="tip_percent" id="tip-percent" value="<?= htmlspecialchars($formTipPercent) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Note <span style="opacity:0.6;">(optional)</span></label>
                <input type="text" class="form-input" id="notes" name="notes"
                       maxlength="<?= SelfServe::NOTES_MAX_LEN ?>"
                       value="<?= htmlspecialchars($formNotes) ?>" placeholder="What's this payment for?">
            </div>

            <div class="total-row">
                <span>Total</span>
                <span id="ss-total"><?= number_format($subtotalSats) ?> sats</span>
            </div>

            <button type="submit" class="btn">Continue to payment</button>
        </form>
        <script>
        (function() {
            var SUBTOTAL = <?= (int)$subtotalSats ?>;
            // Sats per 1 unit of the store currency (null when the store prices
            // in sats). Client-side tip PREVIEW only — the server re-prices the
            // tip with live rates on submit.
            var TIP_RATE = <?= $tipRateSatsPerUnit !== null ? (int)$tipRateSatsPerUnit : 'null' ?>;
            var modeEl = document.getElementById('tip-mode');
            var pctEl = document.getElementById('tip-percent');
            var amtEl = document.getElementById('tip-amount');
            var customRow = document.getElementById('tip-custom-row');
            var totalEl = document.getElementById('ss-total');
            var btns = Array.prototype.slice.call(document.querySelectorAll('.tip-btn'));
            function fmt(n) { return n.toLocaleString() + ' sats'; }
            function tipSats() {
                if (modeEl.value === 'percent') {
                    return Math.round(SUBTOTAL * (parseInt(pctEl.value, 10) || 0) / 100);
                }
                if (modeEl.value === 'custom') {
                    var v = parseFloat(amtEl.value);
                    if (!isFinite(v) || v <= 0) return 0;
                    return Math.round(TIP_RATE === null ? v : v * TIP_RATE);
                }
                return 0;
            }
            function update() {
                var mode = modeEl.value || 'none';
                btns.forEach(function(b) {
                    var on = b.dataset.tipMode === mode
                        && (mode !== 'percent' || b.dataset.tipPercent === pctEl.value);
                    b.classList.toggle('active', on);
                });
                customRow.style.display = mode === 'custom' ? 'flex' : 'none';
                var t = tipSats();
                totalEl.textContent = fmt(SUBTOTAL + t) + (t > 0 ? ' (incl. ' + fmt(t) + ' tip)' : '');
            }
            btns.forEach(function(b) {
                b.addEventListener('click', function() {
                    modeEl.value = b.dataset.tipMode === 'none' ? '' : b.dataset.tipMode;
                    pctEl.value = b.dataset.tipPercent || '';
                    update();
                    if (b.dataset.tipMode === 'custom') amtEl.focus();
                });
            });
            amtEl.addEventListener('input', update);
            update();
        })();
        </script>
        <?php else: ?>
        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label class="form-label" for="amount">Amount</label>
                <div class="amount-row">
                    <input type="text" inputmode="decimal" class="form-input" id="amount" name="amount"
                           value="<?= htmlspecialchars($formAmount) ?>" placeholder="0" required>
                    <?php if ($hasFiat): ?>
                        <select class="form-input" id="currency" name="currency">
                            <?php foreach ($allowedCurrencies as $cur): ?>
                                <option value="<?= htmlspecialchars($cur) ?>"
                                    <?= strcasecmp($cur, $formCurrency) === 0 ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(strtoupper($cur)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="currency" value="<?= htmlspecialchars($allowedCurrencies[0]) ?>">
                        <span class="form-input" style="display:flex;align-items:center;color:var(--text-secondary);">SATS</span>
                    <?php endif; ?>
                </div>
                <div class="form-help">Maximum <?= number_format($maxSats) ?> sats per invoice.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Note <span style="opacity:0.6;">(optional)</span></label>
                <input type="text" class="form-input" id="notes" name="notes"
                       maxlength="<?= SelfServe::NOTES_MAX_LEN ?>"
                       value="<?= htmlspecialchars($formNotes) ?>" placeholder="What's this payment for?">
                <div class="form-help">Up to <?= SelfServe::NOTES_MAX_LEN ?> characters.</div>
            </div>

            <button type="submit" class="btn">Continue to payment</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
