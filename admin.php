<?php
/**
 * CashuPayServer - Admin Interface
 *
 * Modern PWA admin dashboard.
 */

require_once __DIR__ . '/includes/http_status.php';

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/lightning_address.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/urls.php';
// Defines CASHUPAY_DEV_FEE_PERCENT etc. consumed by the Hosting Fee card copy.
require_once __DIR__ . '/includes/dev_fee.php';
require_once __DIR__ . '/includes/stats.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/offline_cashu.php';
require_once __DIR__ . '/includes/products.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/managed.php';

use Cashu\ProofState;

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    header('Location: ' . Urls::setup());
    exit;
}

// C1: Trigger background sync if needed (logged in users only)
// NOTE: Auto-melt is handled exclusively by cron.php via Background::trigger()
// to prevent race conditions from concurrent melt attempts
Auth::initSession();
if (Auth::isLoggedIn() && Background::shouldSync()) {
    Background::trigger();
}

/**
 * H5: Check if data directory is protected from HTTP access
 */
function checkDataDirectoryProtection(): ?string {
    $baseUrl = Config::getBaseUrl();
    $testUrl = rtrim($baseUrl, '/') . '/data/cashupay.sqlite';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Note: curl_close() not needed since PHP 8.0 - handle auto-closes

    if ($httpCode === 200) {
        return 'WARNING: Your database may be exposed via HTTP! Check server configuration.';
    }

    return null;
}

/**
 * Parse and validate the SMTP server fields shared by the global notification
 * settings and the per-store SMTP override. Returns trimmed scalars plus the
 * raw password and a clear flag (the password is handled by the caller so the
 * UI can keep it write-only). Throws on invalid port / encryption / from-address.
 *
 * Used by save_notifications_settings (global) and save_store_notifications
 * (per-store); both POST the same smtp_* field names from separate forms.
 */
function smtpFieldsFromPost(array $post): array {
    $host = trim((string)($post['smtp_host'] ?? ''));
    $port = trim((string)($post['smtp_port'] ?? ''));
    $username = trim((string)($post['smtp_username'] ?? ''));
    $encryption = strtolower(trim((string)($post['smtp_encryption'] ?? '')));
    $fromAddress = trim((string)($post['smtp_from_address'] ?? ''));
    $fromName = trim((string)($post['smtp_from_name'] ?? ''));
    // Password is intentionally not trimmed — leading/trailing spaces can be
    // significant in a credential.
    $password = (string)($post['smtp_password'] ?? '');
    $passwordClear = ($post['smtp_password_clear'] ?? '0') === '1';

    if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) {
        throw new Exception('SMTP port must be a number between 1 and 65535');
    }
    if ($encryption !== '' && !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        throw new Exception('SMTP encryption must be tls, ssl, or none');
    }
    if ($fromAddress !== '' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid From address');
    }

    return [
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'encryption' => $encryption,
        'from_address' => $fromAddress,
        'from_name' => $fromName,
        'password' => $password,
        'password_clear' => $passwordClear,
    ];
}

// Password-reset landing page (Mechanism 1). The emailed link opens here as
// ?action=reset&token=... No session required — the operator is locked out by
// definition. Rendered as a standalone page (not the SPA) so it works even if
// the cached SPA is stale. The form POSTs back to action=reset_with_token.
if (($_GET['action'] ?? '') === 'reset') {
    $token = (string)($_GET['token'] ?? '');
    $valid = Auth::peekPasswordResetToken($token) !== null;
    $tokenEsc  = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $adminHref = htmlspecialchars(Urls::admin(), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password</title>
<style>
  :root { color-scheme: dark; }
  body { font-family: -apple-system, system-ui, sans-serif; background:#0e0f13; color:#e7e7ea;
         display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:1.5rem; }
  .box { width:100%; max-width:380px; background:#1a1c22; border:1px solid #2a2d36; border-radius:14px; padding:1.75rem; }
  h1 { font-size:1.25rem; margin:0 0 0.5rem; }
  p { color:#a4a6ad; font-size:0.9rem; line-height:1.5; margin:0 0 1rem; }
  input { width:100%; box-sizing:border-box; padding:0.7rem 0.85rem; margin-bottom:0.75rem;
          background:#0e0f13; border:1px solid #2a2d36; border-radius:8px; color:#e7e7ea; font-size:1rem; }
  button { width:100%; padding:0.75rem; border:0; border-radius:8px; background:#f7931a; color:#1a1c22;
           font-weight:600; font-size:1rem; cursor:pointer; }
  a { color:#f7931a; }
  .msg { font-size:0.9rem; margin-top:0.75rem; min-height:1.2em; }
  .err { color:#ef4444; } .ok { color:#22c55e; }
</style></head>
<body>
  <div class="box">
    <h1>Reset admin password</h1>
    <?php if (!$valid): ?>
      <p>This reset link is invalid or has expired. Reset links are valid for one hour and can only be used once.</p>
      <p><a href="<?= $adminHref ?>">Back to sign in</a></p>
    <?php else: ?>
      <p>Choose a new password for your admin account (minimum 8 characters).</p>
      <input type="password" id="pw" placeholder="New password" autocomplete="new-password">
      <input type="password" id="pw2" placeholder="Confirm new password" autocomplete="new-password">
      <button id="submit">Set new password</button>
      <div class="msg" id="msg"></div>
      <script>
        var postUrl = window.location.pathname;
        var token = <?= json_encode($tokenEsc) ?>;
        var adminHref = <?= json_encode($adminHref) ?>;
        var msg = document.getElementById('msg');
        function setMsg(t, cls) { msg.textContent = t; msg.className = 'msg ' + (cls || ''); }
        document.getElementById('submit').addEventListener('click', async function () {
          var pw = document.getElementById('pw').value;
          var pw2 = document.getElementById('pw2').value;
          if (pw.length < 8) { setMsg('Password must be at least 8 characters', 'err'); return; }
          if (pw !== pw2) { setMsg('Passwords do not match', 'err'); return; }
          setMsg('Saving...');
          try {
            var body = 'action=reset_with_token&token=' + encodeURIComponent(token)
                     + '&new_password=' + encodeURIComponent(pw);
            var r = await fetch(postUrl, { method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body });
            var data = await r.json().catch(function () { return {}; });
            if (r.ok && data.success) {
              setMsg('Password updated. Redirecting to sign in...', 'ok');
              setTimeout(function () { window.location.href = adminHref; }, 1500);
            } else {
              setMsg(data.error || 'Could not reset password', 'err');
            }
          } catch (e) { setMsg('Network error, please try again', 'err'); }
        });
      </script>
    <?php endif; ?>
  </div>
</body></html>
<?php
    exit;
}

// Handle API-style requests from the SPA
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    // Verify session for API requests
    Auth::initSession();
    if (!Auth::isLoggedIn()) {
        cashupay_status(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    $action = $_GET['api'];

    switch ($action) {
        case 'dashboard':
            $storeId = $_GET['store_id'] ?? null;

            // Get all stores for selector
            $stores = Database::fetchAll("SELECT * FROM stores ORDER BY created_at DESC");

            // Ensure each store has an internal API key. Redact the raw secret
            // columns (seed phrase, SMTP password, xpubs, raw internal_api_key)
            // AFTER attaching the receive-only camelCase internalApiKey the
            // Request Payment feature needs — this endpoint is reachable by the
            // non-admin ROLE_USER, who must not receive spendable key material.
            foreach ($stores as &$store) {
                $store['internalApiKey'] = Auth::getOrCreateInternalApiKey($store['id']);
                $store['isConfigured'] = Config::isStoreConfigured($store['id']);
                // isConfigured only reflects the mint rail; the store selector
                // needs "can this store take payments at all" so a
                // Lightning-only or on-chain-only store isn't labeled
                // "(not configured)".
                $store['hasPaymentRail'] = Config::storeHasPaymentRail($store['id']);
                $store = Config::redactStoreSecrets($store);
            }
            unset($store);

            // If no store selected, return stores list only
            if (!$storeId) {
                echo json_encode([
                    'stores' => $stores,
                    'noStoreSelected' => true,
                ]);
                break;
            }

            // Verify store exists
            if (!Config::getStore($storeId)) {
                cashupay_status(404);
                echo json_encode(['error' => 'Store not found']);
                break;
            }

            // Check if store is configured
            $storeConfigured = Config::isStoreConfigured($storeId);
            $balance = 0;
            $swapFee = 0;
            $exportAvailable = 0;
            $mintUnit = Config::getStoreMintUnit($storeId);

            // Dashboard uses LOCAL balance (no mint contact) for fast loading
            // The "balanceCached" flag tells UI to show refresh button
            $balanceCached = false;

            if ($storeConfigured) {
                // Dashboard uses offline-first balance (no mint contact)
                // This ensures dashboard loads even when mint is unreachable
                // User can click "Refresh" to contact mint and verify proof states
                $balance = Invoice::getBalance($storeId);
                $balanceCached = true;

                // When using offline balance, set exportAvailable = balance
                // This allows Max button to work even without mint contact
                // (no fee deduction since we can't calculate swap fee without mint)
                $exportAvailable = $balance;
            }

            // Get invoices for this store
            $recentInvoices = Invoice::getByStore($storeId, null, 10);

            // Get store details including auto-melt settings
            $store = Config::getStore($storeId);
            require_once __DIR__ . '/includes/swap/auto_melt.php';
            $autoMeltMode = SwapAutoMelt::modeForStore($store);
            // Legacy -1 "inherit" rows (from when a site-wide default existed)
            // resolve to lightning, so the UI only ever sees 0/1.
            $autoMeltUseSwap = (isset($store['auto_melt_use_swap'])
                && (int)$store['auto_melt_use_swap'] === SwapAutoMelt::FORCE_SWAP)
                ? SwapAutoMelt::FORCE_SWAP
                : SwapAutoMelt::FORCE_LIGHTNING;
            require_once __DIR__ . '/includes/lnurl_receive.php';
            require_once __DIR__ . '/includes/store_ln_addresses.php';
            // Ordered Lightning-address fallback chain. Each entry carries its
            // own LUD-21 verify-URL support flag (null = not probed / unreachable,
            // 0 = unsupported → payments route via the mint, 1 = direct-receive ok).
            $lnAddressRows = StoreLnAddresses::listForStore($storeId);
            $autoMeltAddresses = array_map(static function (array $r): array {
                // NWC connection URIs embed the wallet secret and this
                // endpoint is reachable by the non-admin ROLE_USER, so nwc
                // rows go out as their masked display label plus an opaque
                // keep:<id> ref. On save, the UI posts the ref back and the
                // server resolves it to the stored value — the raw URI never
                // round-trips through the browser.
                if ($r['type'] === StoreLnAddresses::TYPE_NWC
                        || $r['type'] === StoreLnAddresses::TYPE_STRIKE) {
                    // Strike API keys are the account credential and get the
                    // same masked-label + keep-ref treatment.
                    return [
                        'address' => StoreLnAddresses::displayValue($r['type'], $r['address']),
                        'type' => $r['type'],
                        'lud21Support' => null,
                        'ref' => StoreLnAddresses::KEEP_REF_PREFIX . $r['id'],
                    ];
                }
                return [
                    'address' => $r['address'],
                    'type' => $r['type'],
                    'lud21Support' => $r['supports_verify'],
                ];
            }, $lnAddressRows);
            $autoMelt = [
                // Ordered list of {address, lud21Support}; priority = array order.
                'addresses' => $autoMeltAddresses,
                'enabled' => (bool)($store['auto_melt_enabled'] ?? 0),
                'threshold' => (int)($store['auto_melt_threshold'] ?? 2000),
                'modeOverride' => $autoMeltUseSwap,
                'mode' => $autoMeltMode,
                'swapMinSats' => SwapAutoMelt::minSats(),
                'swapMaxFeePct' => SwapAutoMelt::maxFeePct(),
            ];

            $storeNotifications = [
                'enabled' => (bool)($store['notifications_enabled'] ?? 0),
                'email' => $store['notification_email'] ?? '',
                // Newsletter checkbox default override. '' = inherit site default,
                // '1'/'0' = force checked/unchecked. siteDefault lets the UI show
                // what "inherit" currently resolves to.
                'newsletterDefault' => isset($store['newsletter_default_checked']) && $store['newsletter_default_checked'] !== null
                    ? (string)(int)$store['newsletter_default_checked']
                    : '',
                'newsletterSiteDefault' => Config::get('newsletter_default_checked', true) === true,
                // Per-store SMTP override. Password is never sent to the browser —
                // only whether one is stored (write-only field, see the per-store
                // notifications card).
                'smtpOverrideEnabled' => (bool)($store['smtp_override_enabled'] ?? 0),
                'smtpHost' => (string)($store['smtp_host'] ?? ''),
                'smtpPort' => $store['smtp_port'] !== null ? (string)$store['smtp_port'] : '',
                'smtpUsername' => (string)($store['smtp_username'] ?? ''),
                'smtpEncryption' => (string)($store['smtp_encryption'] ?? ''),
                'smtpFromAddress' => (string)($store['smtp_from_address'] ?? ''),
                'smtpFromName' => (string)($store['smtp_from_name'] ?? ''),
                'smtpPasswordSet' => ((string)($store['smtp_password'] ?? '')) !== '',
            ];

            // Submarine swap (LN→onchain) per-store settings. Legacy -1
            // "inherit" rows (from when a site-wide default existed) are
            // normalized to their built-in default so the UI only sees 0/1.
            require_once __DIR__ . '/includes/swap/config.php';
            require_once __DIR__ . '/includes/swap/factory.php';
            $swapEnabled = isset($store['swaps_enabled']) && (int)$store['swaps_enabled'] === SwapsConfig::FORCE_ON;
            $swapFeeEff = SwapsConfig::effectiveFeeFallbackForStore($storeId);
            $storeSwaps = [
                'enabled' => $swapEnabled,
                'effective' => SwapsConfig::isEnabledForStore($storeId),
                // Provider preferences (per-store; null column = built-in default).
                'providerOrder' => SwapsConfig::providerOrderForStore($storeId),
                'knownProviders' => SwapProviderFactory::knownProviderNames(),
                'autoSelectCheapest' => SwapsConfig::autoSelectCheapestForStore($storeId),
                'autoSelectThresholdPct' => SwapsConfig::autoSelectThresholdPctForStore($storeId),
                'minimumTargetSats' => SwapsConfig::minimumTargetSatsForStore($storeId),
                // Per-store fee-fallback values (null = inherit the config-file
                // constant) for the input boxes, plus the resolved effective
                // values for help text and the config-file defaults for the
                // placeholders.
                'feeFallbackMaxPct' => ($store['swaps_fee_fallback_max_pct'] ?? null) !== null
                    ? (float)$store['swaps_fee_fallback_max_pct'] : null,
                'feeFallbackMaxSats' => ($store['swaps_fee_fallback_max_sats'] ?? null) !== null
                    ? (int)$store['swaps_fee_fallback_max_sats'] : null,
                'feeFallbackEffectivePct' => $swapFeeEff['pct'],
                'feeFallbackEffectiveSats' => $swapFeeEff['sats'],
                'feeFallbackDefaultPct' => SwapsConfig::configFileFeeFallbackMaxPct(),
                'feeFallbackDefaultSats' => SwapsConfig::configFileFeeFallbackMaxSats(),
                // Per-store "never fall back to a cashu mint" flag.
                'strict' => SwapsConfig::strictNoMintFallbackForStore($storeId),
            ];

            // Self-serve invoice per-store settings.
            require_once __DIR__ . '/includes/selfserve.php';
            $storeSelfServe = [
                'enabled'          => SelfServe::storeOverride($storeId) === SelfServe::FORCE_ON,
                'effective'        => SelfServe::isEnabledForStore($storeId),
                'paymentCapable'   => SelfServe::storeIsPaymentCapable($store),
                'maxSatsOverride'  => SelfServe::storeMaxSats($storeId),   // null = built-in default
                'effectiveMaxSats' => SelfServe::effectiveMaxSats($storeId),
                'maxSatsDefault'   => SelfServe::DEFAULT_MAX_SATS,
                'payUrl'           => Urls::selfServe($storeId),
            ];

            // On-chain Bitcoin payment settings.
            $onchainXpub = $store['onchain_xpub'] ?? '';
            $onchainMode = $store['onchain_address_mode'] ?? 'xpub';
            $onchainStaticAddress = $store['onchain_static_address'] ?? '';
            $onchainEnabled = ($onchainMode === 'static')
                ? ($onchainStaticAddress !== '')
                : ($onchainXpub !== '');
            require_once __DIR__ . '/includes/onchain/payments.php';
            require_once __DIR__ . '/includes/onchain/config.php';
            $onchain = [
                'enabled' => $onchainEnabled,
                'mode' => $onchainMode,
                'xpub' => $onchainXpub,
                'staticAddress' => $onchainStaticAddress,
                'staticTweakRange' => (int)($store['onchain_static_tweak_range'] ?? 1000),
                'network' => $store['onchain_network'] ?? 'mainnet',
                'addressType' => $store['onchain_address_type'] ?? 'P2WPKH',
                'minConfs' => (int)($store['onchain_min_confs'] ?? 1),
                'confirmTimeoutSec' => (int)($store['onchain_confirm_timeout_sec'] ?? 86400),
                'nextIndex' => (int)($store['onchain_next_index'] ?? 0),
                'providerUrl' => $store['onchain_provider_url'] ?? '',
                'needsManualConfirmation' => OnchainPayments::countNeedingManualConfirmation($storeId),
                // Whether the on-chain rail is OFFERED to customers (per-store
                // flag; legacy -1 rows resolve to on).
                'offerEnabled' => OnchainConfig::isEnabledForStore($storeId),
                // "Also accept on-chain payments via Strike": addresses are
                // minted in the merchant's Strike account, xpub/static as
                // fallback. Only effective with a Strike key + mainnet.
                'strikeOnchainEnabled' => OnchainConfig::strikeEnabledForStore($storeId),
                'strikeKeyConfigured' => (function () use ($storeId): bool {
                    require_once __DIR__ . '/includes/store_ln_addresses.php';
                    foreach (StoreLnAddresses::listForStore($storeId) as $lnRow) {
                        if ($lnRow['type'] === StoreLnAddresses::TYPE_STRIKE) {
                            return true;
                        }
                    }
                    return false;
                })(),
            ];

            // Calculate balance in sats for fiat mints (uses cached exchange rates)
            $balanceInSats = null;
            if ($storeConfigured && $balance > 0) {
                $isFiatMint = !in_array(strtolower($mintUnit), ['sat', 'sats', 'msat']);
                if ($isFiatMint) {
                    require_once __DIR__ . '/includes/rates.php';
                    try {
                        $balanceInSats = ExchangeRates::convertMintUnitToSats(
                            $balance,
                            $mintUnit,
                            $store['price_provider_primary'] ?? null,
                            $store['price_provider_secondary'] ?? null
                        );
                    } catch (Exception $e) {
                        error_log("Failed to convert balance to sats: " . $e->getMessage());
                        $balanceInSats = null;
                    }
                } else {
                    // Already in sats (or close)
                    $balanceInSats = $mintUnit === 'msat' ? (int)ceil($balance / 1000) : $balance;
                }
            }

            // Convert balance to the store's default display currency (if fiat
            // and different from the mint unit) so the dashboard can show a
            // secondary line.
            $defaultCurrency = Config::getStoreDefaultCurrency($storeId);
            $balanceFiat = null;
            $balanceFiatCurrency = null;
            $defaultCurrencyUpper = strtoupper($defaultCurrency);
            $mintUnitUpper = strtoupper($mintUnit);
            $isDefaultFiat = !in_array($defaultCurrencyUpper, ['SAT', 'SATS', 'MSAT', 'BTC'], true);
            if ($storeConfigured && $isDefaultFiat && $defaultCurrencyUpper !== $mintUnitUpper && $balanceInSats !== null) {
                require_once __DIR__ . '/includes/rates.php';
                try {
                    $fiat = ExchangeRates::satsToFiat($balanceInSats, $defaultCurrency);
                    if ($fiat !== null) {
                        $balanceFiat = $fiat;
                        $balanceFiatCurrency = $defaultCurrencyUpper;
                    }
                } catch (Exception $e) {
                    error_log("Failed to convert balance to fiat: " . $e->getMessage());
                }
            }

            echo json_encode([
                'storeId' => $storeId,
                'storeName' => $store['name'] ?? 'Unknown',
                'storeConfigured' => $storeConfigured,
                'balance' => $balance,
                'balanceInSats' => $balanceInSats,
                'balanceFiat' => $balanceFiat,
                'balanceFiatCurrency' => $balanceFiatCurrency,
                'defaultCurrency' => $defaultCurrency,
                'swapFee' => $swapFee,
                'exportAvailable' => $exportAvailable,
                'mintUnit' => $mintUnit,
                'balanceCached' => $balanceCached,
                'invoices' => array_map([Invoice::class, 'formatForApi'], $recentInvoices),
                'stores' => $stores,
                'autoMelt' => $autoMelt,
                'notifications' => $storeNotifications,
                // Per-store invoice-memo privacy defaults. Drives both the
                // store-settings checkboxes and the pre-fill of the per-invoice
                // override checkboxes in the Request/Cart modals.
                'invoicePrivacy' => [
                    'hideStoreName' => (int)($store['hide_store_name_on_invoice'] ?? 0) === 1,
                    'hideNote' => (int)($store['hide_note_on_invoice'] ?? 0) === 1,
                ],
                'onchain' => $onchain,
                'swaps' => $storeSwaps,
                'selfserve' => $storeSelfServe,
                'reliability' => (function() {
                    require_once __DIR__ . '/includes/mint_reliability.php';
                    $disabled = MintReliability::listDisabledMints();
                    return [
                        'hasStaleSuspect' => MintReliability::hasStaleSuspect(24 * 3600),
                        'disabledCount' => count($disabled),
                    ];
                })(),
                'cronStaleWarning' => Background::cronStaleWarning(),
            ]);
            break;

        case 'cron_url':
            // Surfaces the operator-facing cron URL with key. The key is seeded
            // at install time (Database::initialize) and lazily regenerated
            // here for the rare case of an install that predates seeding.
            // Crontab is rendered with the key passed via the X-CRON-KEY
            // header instead of ?key= so it doesn't leak through access logs.
            // cron.php still accepts ?key= for backward compatibility.
            Auth::requireAdmin();
            $key = Config::get('cron_key');
            if (!$key) {
                $key = bin2hex(random_bytes(32));
                Config::set('cron_key', $key);
            }
            $baseUrl = Urls::cron();
            $swapsBaseUrl = $baseUrl . '?only=swaps';
            $headerArg = "-H 'X-CRON-KEY: " . $key . "'";
            $now = time();
            $fullSeenAt = (int) Config::get('last_external_cron_at', 0);
            $swapsSeenAt = (int) Config::get('last_external_cron_swaps_at', 0);
            echo json_encode([
                'url' => $baseUrl,
                'swaps_url' => $swapsBaseUrl,
                'crontab' => '* * * * * curl -fsS ' . $headerArg . ' ' . $baseUrl . ' > /dev/null',
                'crontab_swaps' => "* * * * * for i in 0 10 20 30 40 50; do curl -fsS " . $headerArg . " '" . $swapsBaseUrl . "' > /dev/null & sleep 10; done",
                'last_full_seen_ago_sec' => $fullSeenAt > 0 ? ($now - $fullSeenAt) : null,
                'last_swaps_seen_ago_sec' => $swapsSeenAt > 0 ? ($now - $swapsSeenAt) : null,
            ]);
            break;

        case 'dismiss_cron_warning':
            Auth::requireAdmin();
            Background::dismissCronWarning();
            echo json_encode(['success' => true]);
            break;

        case 'upgrade_banner':
            Auth::requireAdmin();
            echo json_encode(['state' => Stats::upgradeBannerState()]);
            break;

        case 'update_status':
            Auth::requireAdmin();
            $info = Updater::getLocalBuildInfo();
            $last = Config::get('updater_last_update');
            $dismissed = (bool)Config::get('updater_banner_dismissed', true);
            // Recommended dedicated cron line for the isolated updater. Built
            // here (admin-only) so the operator can copy it straight from the
            // Auto-update card. Key travels via the X-CRON-KEY header, same as
            // the main cron line, so it doesn't leak through access logs.
            $updKey = Config::get('cron_key');
            $updUrl = rtrim(Config::getBaseUrl(), '/') . '/update.php';
            $recommendedCron = $updKey
                ? "*/15 * * * * curl -fsS -H 'X-CRON-KEY: " . $updKey . "' " . $updUrl . ' > /dev/null'
                : null;
            echo json_encode([
                'channel' => Updater::getChannel(),
                'recommended_cron' => $recommendedCron,
                'current_version' => $info['VERSION'] ?? CASHUPAY_VERSION,
                'current_sha' => $info['COMMIT_SHA'] ?? '',
                'last_update' => $last,
                'banner_dismissed' => $dismissed,
                'backups' => Updater::listBackups(),
                'htaccess_new_exists' => is_file(__DIR__ . '/.htaccess.new'),
                // Crash-recovery state surfaced by the isolated updater:
                // a build that failed its post-update health check is rolled
                // back automatically and its SHA parked here.
                'last_auto_rollback' => Config::get('updater_last_auto_rollback'),
                'auto_rollback_dismissed' => (bool)Config::get('updater_auto_rollback_dismissed', true),
                'blocked_shas' => Updater::getBlockedShas(),
                'pending_verify' => Updater::getPendingVerify(),
                // "Update available" verdict for the dashboard banner + card.
                // Cached daily by cron (Task 12c), independent of the
                // auto-update opt-in. null until the first cron check runs.
                'available' => Updater::getAvailableUpdate(),
                // In-flight / last manual ("Update now") run, polled by the UI.
                'manual_run' => Config::get('updater_manual_run'),
                // Why a manual update can't run here (dev kill switch), or
                // null if it can. Drives the button's enabled state.
                'manual_blocked' => Updater::manualUpdateBlockedReason(),
            ]);
            break;

        // Product catalog for the request modal — any logged-in user. Only
        // enabled products, ordered by the store's configured default sort.
        case 'products':
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId) {
                cashupay_status(400);
                echo json_encode(['error' => 'store_id required']);
                break;
            }
            $store = Config::getStore($storeId);
            echo json_encode([
                'products' => array_map(
                    [Product::class, 'formatForApi'],
                    Product::listByStore($storeId, null, true)
                ),
                'sort' => Product::storeSort($storeId),
                'storeCurrency' => $store['default_currency'] ?? 'sat',
            ]);
            break;

        // Full product list incl. disabled — admin management view only.
        case 'products_manage':
            Auth::requireAdmin();
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId) {
                cashupay_status(400);
                echo json_encode(['error' => 'store_id required']);
                break;
            }
            $store = Config::getStore($storeId);
            echo json_encode([
                'products' => array_map(
                    [Product::class, 'formatForApi'],
                    Product::listByStore($storeId, null, false)
                ),
                'sort' => Product::storeSort($storeId),
                'sorts' => Product::SORTS,
                'storeCurrency' => $store['default_currency'] ?? 'sat',
            ]);
            break;

        // Cart line items for an invoice — used by the admin invoice detail.
        case 'invoice_items':
            $invoiceId = $_GET['id'] ?? '';
            if ($invoiceId === '') {
                cashupay_status(400);
                echo json_encode(['error' => 'id required']);
                break;
            }
            echo json_encode([
                'items' => Cart::formatItemsForApi(Cart::getItems($invoiceId)),
            ]);
            break;

        case 'invoices':
            $status = $_GET['status'] ?? null;
            $storeId = $_GET['store_id'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 50), 100);
            $offset = (int)($_GET['offset'] ?? 0);

            // Whitelist filter values so the UI can't slip arbitrary status
            // strings past the LIKE-style WHERE.
            $allowedStatuses = ['New', 'Processing', 'Provisional', 'Settled', 'Expired', 'Invalid'];
            if ($status !== null && !in_array($status, $allowedStatuses, true)) {
                $status = null;
            }

            $sql = "SELECT * FROM invoices";
            $params = [];
            $conditions = [];

            if ($status) {
                $conditions[] = "status = ?";
                $params[] = $status;
            }

            if ($storeId) {
                $conditions[] = "store_id = ?";
                $params[] = $storeId;
            }

            $whereClause = count($conditions) > 0 ? (" WHERE " . implode(" AND ", $conditions)) : "";
            $sql .= $whereClause;

            // Total matching count drives the list's pagination controls. Sent
            // as a header so the JSON body stays a bare array (its only consumer
            // is loadInvoices, but other tooling may rely on the shape).
            $total = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS c FROM invoices" . $whereClause,
                $params
            )['c'] ?? 0);
            header('X-Total-Count: ' . $total);

            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $invoices = Database::fetchAll($sql, $params);
            $formatted = array_map([Invoice::class, 'formatForApi'], $invoices);
            // Flag which invoices carry cart line items (one query for the page)
            // so the UI can offer an itemized view only where it's meaningful.
            $ids = array_column($invoices, 'id');
            $withItems = [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                foreach (Database::fetchAll(
                    "SELECT DISTINCT invoice_id FROM invoice_items WHERE invoice_id IN ($placeholders)",
                    $ids
                ) as $r) {
                    $withItems[$r['invoice_id']] = true;
                }
            }
            foreach ($formatted as &$inv) {
                $inv['hasItems'] = isset($withItems[$inv['id']]);
            }
            unset($inv);
            echo json_encode($formatted);
            break;

        case 'customers':
            // Aggregated customer list: one row per distinct email, scoped by
            // store + subscription filter, paginated. Admin-only — it's a bulk
            // PII/marketing surface (same gate as the CSV exports).
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/customers.php';
            [$custStore, $custSub] = Customers::filterArgs($_GET);
            $limit = min(max((int)($_GET['limit'] ?? 50), 1), 100);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            $total = Customers::count($custStore, $custSub);
            $rows = Customers::page($custStore, $custSub, $limit, $offset);

            // Resolve store names once for the page.
            $storeNames = [];
            foreach (Database::fetchAll("SELECT id, name FROM stores") as $s) {
                $storeNames[$s['id']] = $s['name'];
            }

            $customers = array_map(function (array $r) use ($storeNames) {
                return [
                    'email' => $r['email'],
                    'invoiceId' => $r['invoice_id'],
                    'storeId' => $r['store_id'],
                    'storeName' => $storeNames[$r['store_id']] ?? '(unknown store)',
                    'paidTime' => $r['paid_ts'] !== null ? (int)$r['paid_ts'] : null,
                    'newsletterOptIn' => (int)($r['newsletter_opt_in'] ?? 0) === 1,
                    // Link target for "most recent invoice": the public payment
                    // page, which shows the settled invoice.
                    'checkoutLink' => Urls::payment($r['invoice_id']),
                ];
            }, $rows);

            echo json_encode([
                'customers' => $customers,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
            break;

        case 'stores':
            // Store selector list. Consumers only read id/name/mint_url, so
            // strip every secret column — this endpoint is reachable by the
            // non-admin ROLE_USER (no requireAdmin gate on ?api=).
            $stores = Database::fetchAll("SELECT * FROM stores ORDER BY created_at DESC");
            $stores = array_map([Config::class, 'redactStoreSecrets'], $stores);
            echo json_encode($stores);
            break;

        case 'stats_summary':
            Auth::requireAdmin();
            $storeId = (string)($_GET['store_id'] ?? Stats::ALL_STORES);
            $range   = (string)($_GET['range']    ?? 'all');
            echo json_encode(Stats::summary($storeId, $range));
            break;

        case 'stats_chart':
            Auth::requireAdmin();
            $storeId = (string)($_GET['store_id'] ?? Stats::ALL_STORES);
            $range   = (string)($_GET['range']    ?? 'all');
            $type    = (string)($_GET['type']     ?? 'revenue');
            if (!in_array($type, ['revenue', 'count', 'fees'], true)) {
                cashupay_status(400);
                echo json_encode(['error' => 'invalid chart type']);
                break;
            }
            echo json_encode(Stats::chart($storeId, $range, $type));
            break;

        case 'stats_payouts':
            Auth::requireAdmin();
            $storeId = (string)($_GET['store_id'] ?? Stats::ALL_STORES);
            $range   = (string)($_GET['range']    ?? 'all');
            $page    = max(1, (int)($_GET['page'] ?? 1));
            echo json_encode(Stats::payouts($storeId, $range, $page));
            break;

        case 'stats_fee_payments':
            Auth::requireAdmin();
            $storeId = (string)($_GET['store_id'] ?? Stats::ALL_STORES);
            $range   = (string)($_GET['range']    ?? 'all');
            $page    = max(1, (int)($_GET['page'] ?? 1));
            echo json_encode(Stats::feePayments($storeId, $range, $page));
            break;

        case 'export_invoices_csv':
        case 'export_payouts_csv':
        case 'export_fee_payments_csv':
        case 'export_combined_csv':
        case 'export_all_invoices_csv':
            Auth::requireAdmin();
            $storeId = (string)($_GET['store_id'] ?? Stats::ALL_STORES);
            $range   = (string)($_GET['range']    ?? 'all');

            // Filename hint reflects the action + a timestamp; not stored on disk.
            $stamp = date('Ymd-His');
            $filenameMap = [
                'export_invoices_csv'       => "invoices-{$stamp}.csv",
                'export_payouts_csv'        => "payouts-{$stamp}.csv",
                'export_fee_payments_csv'   => "fee-payments-{$stamp}.csv",
                'export_combined_csv'       => "combined-{$stamp}.csv",
                'export_all_invoices_csv'   => "all-invoices-{$stamp}.csv",
            ];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filenameMap[$action] . '"');
            header('Cache-Control: no-store');

            $out = fopen('php://output', 'w');

            $writeHeader = function (array $row) use ($out) {
                fputcsv($out, array_keys($row));
            };
            $writeRow = function (array $row) use ($out) {
                // Neutralize spreadsheet formula injection on every value cell
                // (memo/email/note/destination fields are payer-controlled).
                fputcsv($out, array_map([Security::class, 'csvCell'], array_values($row)));
            };

            if ($action === 'export_invoices_csv' || $action === 'export_all_invoices_csv') {
                $rangeArg = $action === 'export_all_invoices_csv' ? null : $range;
                $headerWritten = false;
                foreach (Stats::streamInvoices($storeId, $rangeArg, true) as $row) {
                    if (!$headerWritten) { $writeHeader($row); $headerWritten = true; }
                    $writeRow($row);
                }
            } elseif ($action === 'export_payouts_csv') {
                $headerWritten = false;
                foreach (Stats::streamMelts($storeId, $range, 'payout') as $row) {
                    if (!$headerWritten) { $writeHeader($row); $headerWritten = true; }
                    $writeRow($row);
                }
            } elseif ($action === 'export_fee_payments_csv') {
                $headerWritten = false;
                foreach (Stats::streamMelts($storeId, $range, 'fee') as $row) {
                    if (!$headerWritten) { $writeHeader($row); $headerWritten = true; }
                    $writeRow($row);
                }
            } else { // export_combined_csv
                // Unified rows. Header is the union of invoices + melts
                // columns with a leading `source` discriminator. Rows from a
                // table leave the other table's columns blank.
                $columns = Stats::combinedColumns();
                fputcsv($out, $columns);
                $emit = function (string $source, array $row) use ($out, $columns, $writeRow) {
                    $merged = ['source' => $source];
                    foreach ($columns as $col) {
                        if ($col === 'source') continue;
                        $merged[$col] = $row[$col] ?? '';
                    }
                    $writeRow($merged);
                };
                foreach (Stats::streamInvoices($storeId, $range, true) as $row) {
                    $emit('invoice', $row);
                }
                foreach (Stats::streamMelts($storeId, $range, 'payout') as $row) {
                    $emit('payout', $row);
                }
                foreach (Stats::streamMelts($storeId, $range, 'fee') as $row) {
                    $emit('fee_payment', $row);
                }
            }

            fclose($out);
            exit;

        case 'export_diagnostic_report':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/diagnostics.php';

            // range: 'all' (everything) or '1m' (past 30 days). anonymize
            // defaults ON; only an explicit '0' opts into a full-fidelity dump.
            $rangeArg  = ((string)($_GET['range'] ?? 'all')) === '1m' ? '1m' : null;
            $anonymize = ((string)($_GET['anonymize'] ?? '1')) !== '0';

            $stamp = date('Ymd-His');
            $suffix = $anonymize ? 'anon' : 'full';
            $scope  = $rangeArg === '1m' ? '30d' : 'all';
            $filename = "diagnostic-report-{$scope}-{$suffix}-{$stamp}.json";

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');

            $out = fopen('php://output', 'w');
            (new Diagnostics($anonymize, $rangeArg))->stream($out);
            fclose($out);
            exit;

        case 'export_customers_csv':
            // CSV of the customers list, honoring the same store + subscription
            // filters as the on-screen list (but every matching row, not just the
            // current page). Admin-only, like the other exports.
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/customers.php';
            [$custStore, $custSub] = Customers::filterArgs($_GET);
            [$base, $baseParams] = Customers::baseQuery($custStore, $custSub);

            $storeNames = [];
            foreach (Database::fetchAll("SELECT id, name FROM stores") as $s) {
                $storeNames[$s['id']] = $s['name'];
            }

            $stamp = date('Ymd-His');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="customers-' . $stamp . '.csv"');
            header('Cache-Control: no-store');

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Email', 'Subscribed', 'Store', 'Most recent invoice', 'Paid at (UTC)']);
            foreach (Database::fetchAll($base . " ORDER BY paid_ts DESC, email ASC", $baseParams) as $r) {
                fputcsv($out, array_map([Security::class, 'csvCell'], [
                    $r['email'],
                    (int)($r['newsletter_opt_in'] ?? 0) === 1 ? 'yes' : 'no',
                    $storeNames[$r['store_id']] ?? '',
                    $r['invoice_id'],
                    $r['paid_ts'] !== null ? gmdate('Y-m-d H:i:s', (int)$r['paid_ts']) : '',
                ]));
            }
            fclose($out);
            exit;

        case 'api_keys':
            Auth::requireAdmin();
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId) {
                echo json_encode([]);
                break;
            }
            $keys = Auth::getApiKeys($storeId);
            echo json_encode($keys);
            break;

        case 'export_info':
            Auth::requireAdmin();
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId || !Config::isStoreConfigured($storeId)) {
                echo json_encode(['available' => 0, 'error' => 'Store not configured']);
                break;
            }

            $mintReachable = true;

            // Try to resolve pending proofs (requires mint contact)
            try {
                Invoice::checkPendingProofs($storeId);
            } catch (\Exception $e) {
                $mintReachable = false;
                error_log("CashuPayServer: export_info checkPendingProofs failed: " . $e->getMessage());
            }

            if ($mintReachable) {
                // Mint is reachable - get proofs and check states
                $proofs = Invoice::getUnspentProofs($storeId);
                $wallet = Invoice::getWalletInstance($storeId);

                // Check proof states at mint (same as export_token)
                if (!empty($proofs)) {
                    try {
                        $states = $wallet->checkProofState($proofs);
                        $validProofs = [];
                        $spentSecrets = [];

                        foreach ($states as $i => $state) {
                            // Normalize case - mints may return lowercase states
                            $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                            if ($mintState === ProofState::UNSPENT) {
                                $validProofs[] = $proofs[$i];
                            } elseif ($mintState === ProofState::SPENT) {
                                $spentSecrets[] = $proofs[$i]->secret;
                            }
                            // Skip PENDING proofs (same as export_token)
                        }

                        // Update spent proofs locally
                        if (!empty($spentSecrets)) {
                            Invoice::markProofsSpent($storeId, $spentSecrets);
                        }

                        $proofs = $validProofs;
                    } catch (\Cashu\CashuException $e) {
                        // If checkstate fails, fall back to local balance
                        $mintReachable = false;
                        error_log("CashuPayServer: export_info checkProofState failed: " . $e->getMessage());
                    }
                }

                if ($mintReachable) {
                    $balance = \Cashu\Wallet::sumProofs($proofs);
                    $fee = $wallet->calculateFee($proofs);
                    $available = max(0, $balance - $fee);
                    echo json_encode(['available' => $available, 'mintUnit' => Config::getStoreMintUnit($storeId)]);
                    break;
                }
            }

            // Mint unreachable - fall back to offline balance with no fee calculation
            $offlineBalance = Invoice::getBalance($storeId);
            echo json_encode([
                'available' => $offlineBalance,
                'mintUnit' => Config::getStoreMintUnit($storeId),
                'mintUnreachable' => true
            ]);
            break;

        case 'proofs':
            // Admin-only: these rows carry secret + C — i.e. spendable bearer
            // ecash. Match the sibling fund endpoints (export_info/export_token).
            Auth::requireAdmin();
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId || !Config::isStoreConfigured($storeId)) {
                echo json_encode([]);
                break;
            }
            $wallet = Invoice::getWalletInstance($storeId);
            $rows = $wallet->getStorage()->getProofs(ProofState::UNSPENT);
            echo json_encode($rows);
            break;

        // ===== Mint reliability + trusted-mints (admin-only) =====
        case 'list_disabled_mints':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/mint_reliability.php';
            $rows = MintReliability::listDisabledMints();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'mintUrl' => $r['mint_url'],
                    'totalFailures' => (int)$r['total_failures'],
                    'consecutiveFailures' => (int)$r['consecutive_failures'],
                    'disabledPendingSuccess' => (int)$r['disabled_pending_success'] === 1,
                    'permanentlyDisabled' => (int)$r['permanently_disabled'] === 1,
                    'trustedListDisabled' => (int)$r['trusted_list_disabled'] === 1,
                    'trustedListDisabledReason' => $r['trusted_list_disabled_reason'],
                    'lastFailureAt' => $r['last_failure_at'] !== null ? (int)$r['last_failure_at'] : null,
                    'lastFailureKind' => $r['last_failure_kind'],
                    'lastFailureMessage' => $r['last_failure_message'],
                    'lastSuccessAt' => $r['last_success_at'] !== null ? (int)$r['last_success_at'] : null,
                ];
            }
            echo json_encode(['mints' => $out]);
            break;

        case 'stuck_funds_summary':
            // Per-store breakdown of sats stranded in mints with an active
            // withdrawal-failure flag, plus how much has been absorbed against
            // each owed fee bucket. Drives the Stuck Funds admin card.
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/stuck_funds.php';
            require_once __DIR__ . '/includes/dev_fee.php';
            $rows = Database::fetchAll(
                "SELECT id, name FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
            );
            $storesOut = [];
            $totalStuckSats = 0;
            $totalAbsorbedSats = 0;
            foreach ($rows as $r) {
                $owed = DevFee::computeOwed($r['id']);
                $perMint = $owed['stuck_per_mint'] ?? [];
                $stuckTotal = (int)($owed['stuck_total_sats'] ?? 0);
                if ($stuckTotal === 0 && empty($perMint)) {
                    continue;
                }
                $mintsOut = [];
                foreach ($perMint as $mintUrl => $sats) {
                    $mintsOut[] = ['mintUrl' => $mintUrl, 'stuckSats' => (int)$sats];
                }
                $storesOut[] = [
                    'storeId' => $r['id'],
                    'storeName' => $r['name'],
                    'stuckTotalSats' => $stuckTotal,
                    'absorbedTotalSats' => (int)($owed['stuck_absorbed_total'] ?? 0),
                    'absorbedDevSats' => (int)($owed['stuck_absorbed_dev'] ?? 0),
                    'absorbedHostingSats' => (int)($owed['stuck_absorbed_hosting'] ?? 0),
                    'uncoveredSats' => (int)($owed['stuck_uncovered'] ?? 0),
                    'mints' => $mintsOut,
                ];
                $totalStuckSats += $stuckTotal;
                $totalAbsorbedSats += (int)($owed['stuck_absorbed_total'] ?? 0);
            }
            echo json_encode([
                'stores' => $storesOut,
                'totals' => [
                    'stuckSats' => $totalStuckSats,
                    'absorbedSats' => $totalAbsorbedSats,
                ],
            ]);
            break;

        case 'get_mint_diagnostic':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/mint_reliability.php';
            $mintUrl = $_GET['mint_url'] ?? '';
            if ($mintUrl === '') {
                cashupay_status(400);
                echo json_encode(['error' => 'mint_url required']);
                break;
            }
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
            $eventType = $_GET['event_type'] ?? null;
            $sinceTs = isset($_GET['since']) && $_GET['since'] !== '' ? (int)$_GET['since'] : null;
            $untilTs = isset($_GET['until']) && $_GET['until'] !== '' ? (int)$_GET['until'] : null;
            $record = MintReliability::ensureRecord($mintUrl);
            $events = MintReliability::getEventLog($mintUrl, $limit, $eventType, $sinceTs, $untilTs);
            $eventsOut = [];
            foreach ($events as $e) {
                $eventsOut[] = [
                    'timestamp' => (int)$e['timestamp'],
                    'eventType' => $e['event_type'],
                    'failureType' => $e['failure_type'],
                    'storeId' => $e['store_id'],
                    'address' => $e['address'],
                    'details' => $e['details'],
                ];
            }
            echo json_encode([
                'mintUrl' => $mintUrl,
                'status' => [
                    'totalFailures' => (int)$record['total_failures'],
                    'consecutiveFailures' => (int)$record['consecutive_failures'],
                    'disabledPendingSuccess' => (int)$record['disabled_pending_success'] === 1,
                    'permanentlyDisabled' => (int)$record['permanently_disabled'] === 1,
                    'trustedListDisabled' => (int)$record['trusted_list_disabled'] === 1,
                    'trustedListDisabledReason' => $record['trusted_list_disabled_reason'],
                    'lastFailureAt' => $record['last_failure_at'] !== null ? (int)$record['last_failure_at'] : null,
                    'lastFailureKind' => $record['last_failure_kind'],
                    'lastFailureMessage' => $record['last_failure_message'],
                    'lastSuccessAt' => $record['last_success_at'] !== null ? (int)$record['last_success_at'] : null,
                ],
                'events' => $eventsOut,
            ]);
            break;

        case 'get_trusted_mints_settings':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/trusted_mints.php';
            echo json_encode([
                'url' => TrustedMints::getUrl(),
                'urlFromEnv' => TrustedMints::isUrlFromEnv(),
                'refreshMinutes' => TrustedMints::getRefreshMinutes(),
                'refreshFromEnv' => TrustedMints::isRefreshIntervalFromEnv(),
                'lastFetchAt' => TrustedMints::getLastFetchAt(),
                'lastOkAt' => TrustedMints::getLastOkAt(),
                'lastError' => TrustedMints::getLastError(),
                'cached' => TrustedMints::getCachedList(),
            ]);
            break;

        case 'get_suggested_mints':
            // Non-disabled trusted-list URLs, surfaced to the mint-discovery
            // modal so it can badge them and pin them to the top. No env
            // override needed — same source as TrustedMints uses internally.
            require_once __DIR__ . '/includes/trusted_mints.php';
            $list = TrustedMints::getCachedList();
            $urls = [];
            if (is_array($list) && isset($list['mints']) && is_array($list['mints'])) {
                foreach ($list['mints'] as $entry) {
                    if (!is_array($entry) || empty($entry['url']) || !empty($entry['disabled'])) {
                        continue;
                    }
                    $urls[] = rtrim((string)$entry['url'], '/');
                }
            }
            echo json_encode(['mints' => $urls]);
            break;

        case 'mint_country':
            // Resolve a mint URL to an ISO 3166-1 alpha-2 country code via
            // local DB-IP lookup. Returns null on any failure (DNS, missing
            // CSV, .onion, etc.) — the caller treats null as "no flag".
            require_once __DIR__ . '/includes/ipgeo.php';
            $url = $_GET['url'] ?? '';
            $cc = is_string($url) && $url !== '' ? IpGeo::lookupCountry($url) : null;
            echo json_encode(['country' => $cc]);
            break;

        case 'mint_country_batch':
            // Batched lookup: many URLs in one request so the per-process
            // CSV index gets built once and reused.
            require_once __DIR__ . '/includes/ipgeo.php';
            $raw = $_GET['urls'] ?? '';
            $urls = array_filter(array_map('trim', explode(',', $raw)));
            $out = [];
            foreach ($urls as $u) {
                $u = (string)$u;
                if ($u === '') continue;
                $out[$u] = IpGeo::lookupCountry($u);
            }
            echo json_encode(['countries' => $out]);
            break;

        case 'get_offline_cashu':
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId) {
                cashupay_status(400);
                echo json_encode(['error' => 'Store ID required']);
                break;
            }
            echo json_encode([
                'enabled' => OfflineCashu::isEnabled($storeId),
                'policy' => OfflineCashu::policy($storeId),
                'max_per_tx' => OfflineCashu::maxPerTx($storeId),
                'max_outstanding' => OfflineCashu::maxOutstanding($storeId),
                'accept_all_mints' => OfflineCashu::acceptAllMints($storeId),
                'per_tx_override' => OfflineCashu::perTxOverrideEnabled($storeId),
                'outstanding' => OfflineCashu::outstandingExposure($storeId),
                'unit' => Config::getStoreMintUnit($storeId),
                'mints' => OfflineCashu::allowlist($storeId),
            ]);
            break;

        default:
            cashupay_status(404);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Handle form POSTs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    // Login doesn't require existing session or CSRF (first request)
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIp = Security::getClientIp();

        // Check if locked out
        if (Security::isLockedOut($clientIp)) {
            $remaining = Security::getLockoutRemaining($clientIp);
            cashupay_status(429);
            echo json_encode(['error' => "Too many failed attempts. Try again in {$remaining} seconds."]);
            exit;
        }

        if ($username !== '' && Auth::login($username, $password)) {
            Security::clearLoginAttempts($clientIp);
            $user = Auth::currentUser();

            // C1: Trigger background sync on login
            if (Background::shouldSync()) {
                Background::trigger();
            }

            // H5: Check DB protection on login
            $securityWarning = null;
            if (!Database::isDataDirOutsideWebroot()) {
                $securityWarning = checkDataDirectoryProtection();
            }

            echo json_encode([
                'success' => true,
                'securityWarning' => $securityWarning,
                'csrfToken' => Auth::generateCsrfToken(),
                'user' => [
                    'username' => $user['username'] ?? null,
                    'role'     => $user['role'] ?? null,
                ],
            ]);
        } else {
            Security::recordFailedLogin($clientIp);
            cashupay_status(401);
            echo json_encode(['error' => 'Invalid username or password']);
        }
        exit;
    }

    // ---- Password recovery (no existing session; the operator is locked out) ----

    // Mechanism 1: request an emailed reset link. Always returns the same
    // generic response so the endpoint can't be used to discover whether an
    // address maps to an account (enumeration guard). Rate-limited per IP.
    if ($action === 'request_password_reset') {
        $clientIp = Security::getClientIp();
        if (!Security::checkRateLimit('pwreset_request', $clientIp, 5)) {
            cashupay_status(429);
            echo json_encode(['error' => 'Too many requests. Please wait a minute and try again.']);
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && Auth::validateEmail($email) === null) {
            $admin = Auth::findAdminByEmail($email);
            if ($admin) {
                try {
                    require_once __DIR__ . '/includes/email_sender.php';
                    $raw  = Auth::createPasswordResetToken($admin['id']);
                    $base = rtrim(Config::getBaseUrl(), '/');
                    $adminAbs = Config::getUrlMode() === 'direct'
                        ? $base . '/admin.php'
                        : $base . '/router.php/admin.php';
                    $link = $adminAbs . '?action=reset&token=' . urlencode($raw);
                    $body = "A password reset was requested for your CashuPayServer admin account.\n\n"
                          . "Open this link to choose a new password (valid for 1 hour, single use):\n\n"
                          . $link . "\n\n"
                          . "If you did not request this, you can ignore this email. The link does "
                          . "nothing until used and expires on its own.\n";
                    EmailSender::send($admin['email'], 'Reset your CashuPayServer admin password', $body);
                } catch (\Throwable $e) {
                    // Never surface SMTP/config detail to an unauthenticated
                    // caller; log it for the operator instead.
                    error_log('CashuPayServer: password-reset email failed: ' . $e->getMessage());
                }
            }
        }
        echo json_encode([
            'success' => true,
            'message' => 'If an admin account has that email, a reset link has been sent.',
        ]);
        exit;
    }

    // Mechanism 1 (cont.): consume an emailed token and set the new password.
    if ($action === 'reset_with_token') {
        $clientIp = Security::getClientIp();
        if (!Security::checkRateLimit('pwreset_submit', $clientIp, 10)) {
            cashupay_status(429);
            echo json_encode(['error' => 'Too many attempts. Please wait a minute and try again.']);
            exit;
        }
        $token = $_POST['token'] ?? '';
        $new   = $_POST['new_password'] ?? '';
        try {
            if (Auth::resetPasswordWithToken($token, $new)) {
                echo json_encode(['success' => true]);
            } else {
                cashupay_status(400);
                echo json_encode(['error' => 'This reset link is invalid or has expired. Request a new one.']);
            }
        } catch (\InvalidArgumentException $e) {
            cashupay_status(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // Mechanism 2: complete a file-based reset. The trigger file must still be
    // present (operator controls the window); it's deleted on success. Only the
    // seed 'admin' account is reset. The old password stays valid until this
    // succeeds.
    if ($action === 'file_reset_set_password') {
        if (!Auth::fileResetRequested()) {
            cashupay_status(409);
            echo json_encode(['error' => 'No password-reset file is present.']);
            exit;
        }
        $new = $_POST['new_password'] ?? '';
        try {
            if (Auth::completeFileReset($new)) {
                echo json_encode(['success' => true]);
            } else {
                cashupay_status(409);
                echo json_encode(['error' => 'Reset could not be completed. Ensure the reset file is still present.']);
            }
        } catch (\InvalidArgumentException $e) {
            cashupay_status(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // All other actions require authentication
    Auth::initSession();
    if (!Auth::isLoggedIn()) {
        cashupay_status(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    // M2: CSRF validation for authenticated POST requests
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!Auth::validateCsrfToken($csrfToken)) {
        cashupay_status(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    switch ($action) {
        case 'logout':
            Auth::logout();
            echo json_encode(['success' => true]);
            break;

        // ---- Product catalog management (admin only) ----
        case 'create_product':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                if ($storeId === '') {
                    throw new InvalidArgumentException('store_id required');
                }
                $product = Product::create($storeId, [
                    'title' => $_POST['title'] ?? '',
                    'price' => $_POST['price'] ?? '',
                    'image_type' => $_POST['image_type'] ?? 'none',
                    'image_value' => $_POST['image_value'] ?? null,
                ]);
                echo json_encode(['success' => true, 'product' => Product::formatForApi($product)]);
            } catch (InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'update_product':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $productId = $_POST['product_id'] ?? '';
                if ($storeId === '' || $productId === '') {
                    throw new InvalidArgumentException('store_id and product_id required');
                }
                // Only forward fields that were actually sent so a partial
                // edit doesn't clobber untouched columns.
                $data = [];
                foreach (['title', 'price', 'image_type', 'image_value'] as $k) {
                    if (array_key_exists($k, $_POST)) {
                        $data[$k] = $_POST[$k];
                    }
                }
                if (array_key_exists('enabled', $_POST)) {
                    $data['enabled'] = ($_POST['enabled'] === '1' || $_POST['enabled'] === 'true');
                }
                $product = Product::update($productId, $storeId, $data);
                echo json_encode(['success' => true, 'product' => Product::formatForApi($product)]);
            } catch (InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'delete_product':
            Auth::requireAdmin();
            $storeId = $_POST['store_id'] ?? '';
            $productId = $_POST['product_id'] ?? '';
            if ($storeId === '' || $productId === '') {
                cashupay_status(400);
                echo json_encode(['error' => 'store_id and product_id required']);
                break;
            }
            Product::delete($productId, $storeId);
            echo json_encode(['success' => true]);
            break;

        case 'save_product_sort':
            Auth::requireAdmin();
            $storeId = $_POST['store_id'] ?? '';
            if ($storeId === '') {
                cashupay_status(400);
                echo json_encode(['error' => 'store_id required']);
                break;
            }
            Product::setStoreSort($storeId, $_POST['sort'] ?? Product::DEFAULT_SORT);
            echo json_encode(['success' => true, 'sort' => Product::storeSort($storeId)]);
            break;

        case 'upload_product_image':
            Auth::requireAdmin();
            try {
                $f = $_FILES['image'] ?? null;
                if (!is_array($f)) {
                    throw new InvalidArgumentException('No file uploaded');
                }
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Upload failed');
                }
                if (($f['size'] ?? 0) <= 0 || $f['size'] > 2 * 1024 * 1024) {
                    throw new InvalidArgumentException('Image must be 2 MB or smaller');
                }
                if (!is_uploaded_file($f['tmp_name'])) {
                    throw new InvalidArgumentException('Invalid upload');
                }
                // Detect the real image type from content (not the client name),
                // which also rejects non-image / polyglot files.
                $info = @getimagesize($f['tmp_name']);
                $extByType = [
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_WEBP => 'webp',
                ];
                if ($info === false || !isset($extByType[$info[2]])) {
                    throw new InvalidArgumentException('Only PNG, JPEG, or WebP images are allowed');
                }
                $dir = Product::uploadsDir();
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('Could not create uploads directory');
                }
                $filename = 'prod_' . bin2hex(random_bytes(12)) . '.' . $extByType[$info[2]];
                $dest = $dir . '/' . $filename;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not store uploaded image');
                }
                @chmod($dest, 0644);
                echo json_encode([
                    'success' => true,
                    'filename' => $filename,
                    'url' => Product::imageUrl($filename),
                ]);
            } catch (Throwable $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ---- Cart checkout (any logged-in user, like the request modal) ----
        case 'cart_checkout':
            try {
                $storeId = $_POST['store_id'] ?? '';
                if ($storeId === '') {
                    throw new InvalidArgumentException('store_id required');
                }
                $items = json_decode((string)($_POST['items'] ?? ''), true);
                if (!is_array($items) || empty($items)) {
                    throw new InvalidArgumentException('Cart is empty');
                }
                $memo = isset($_POST['memo']) ? (string)$_POST['memo'] : null;
                $redirect = isset($_POST['redirect']) ? (string)$_POST['redirect'] : null;
                // Per-invoice memo-privacy overrides. Sent on every cart
                // checkout (the modal checkboxes are pre-filled from the store
                // defaults), so they always win for cart-created invoices.
                $privacy = [];
                if (isset($_POST['hide_store_name'])) {
                    $privacy['hideStoreName'] = $_POST['hide_store_name'] === '1';
                }
                if (isset($_POST['hide_note'])) {
                    $privacy['hideNote'] = $_POST['hide_note'] === '1';
                }
                $result = Cart::checkout($storeId, $items, $memo, $redirect, $privacy);
                echo json_encode(['success' => true] + $result);
            } catch (InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            } catch (Throwable $e) {
                cashupay_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'dismiss_upgrade_banner':
            Auth::requireAdmin();
            Stats::dismissUpgradeBanner();
            echo json_encode(['success' => true]);
            break;

        case 'save_url_mode':
            Auth::requireAdmin();
            $mode = $_POST['mode'] ?? 'router';
            if (in_array($mode, ['clean', 'direct', 'router'])) {
                Config::set('url_mode', $mode);
                echo json_encode([
                    'success' => true,
                    'mode' => $mode,
                    'serverUrl' => Urls::server()
                ]);
            } else {
                cashupay_status(400);
                echo json_encode(['error' => 'Invalid mode']);
            }
            break;

        case 'save_update_channel':
            Auth::requireAdmin();
            $channel = (string)($_POST['channel'] ?? '');
            if (!in_array($channel, ['main', 'testing'], true)) {
                cashupay_status(400);
                echo json_encode(['error' => 'Invalid channel']);
                break;
            }
            Updater::setChannel($channel);
            // Forget the cached last-check so the next cron tick re-evaluates
            // against the newly chosen channel immediately.
            Config::set('updater_last_check', 0);
            echo json_encode(['success' => true, 'channel' => Updater::getChannel()]);
            break;

        case 'rollback_update':
            Auth::requireAdmin();
            $ok = Updater::rollbackToMostRecent();
            echo json_encode(['success' => $ok]);
            break;

        case 'dismiss_update_banner':
            Auth::requireAdmin();
            Config::set('updater_banner_dismissed', true);
            echo json_encode(['success' => true]);
            break;

        case 'dismiss_auto_rollback':
            Auth::requireAdmin();
            Config::set('updater_auto_rollback_dismissed', true);
            echo json_encode(['success' => true]);
            break;

        // Operator-initiated "Update now". The click is the consent, so this
        // bypasses the auto-update opt-in. Fire-and-forget: set a "running"
        // marker and nudge the crash-isolated update.php with ?manual=1, which
        // downloads/backs-up/overlays, health-verifies, and auto-rolls-back a
        // bad build exactly like the automatic path. The UI polls update_status
        // (manual_run) for the outcome.
        case 'start_manual_update':
            Auth::requireAdmin();
            $blocked = Updater::manualUpdateBlockedReason();
            if ($blocked !== null) {
                echo json_encode(['success' => false, 'error' => $blocked]);
                break;
            }
            if (!Config::get('cron_key')) {
                echo json_encode(['success' => false, 'error' => 'no_cron_key']);
                break;
            }
            Config::set('updater_manual_run', ['state' => 'running', 'started_at' => time()]);
            Updater::triggerSelfCheck(true);
            echo json_encode(['success' => true]);
            break;

        case 'create_store':
            Auth::requireAdmin();
            try {
                $name = trim($_POST['name'] ?? 'New Store');
                $mintUrl = $_POST['mint_url'] ?? null;
                $mintUnit = $_POST['mint_unit'] ?? 'sat';
                $seedPhrase = $_POST['seed_phrase'] ?? null;
                $exchangeFee = (float)($_POST['exchange_fee_percent'] ?? 0);
                $primaryProvider = $_POST['price_provider_primary'] ?? 'coingecko';
                $secondaryProvider = $_POST['price_provider_secondary'] ?? 'binance';

                // Check for duplicate store name
                $existingStore = Database::fetchOne(
                    "SELECT id FROM stores WHERE LOWER(name) = LOWER(?)",
                    [$name]
                );
                if ($existingStore) {
                    throw new Exception('A store with this name already exists.');
                }

                // If mint URL provided, test connection
                if ($mintUrl) {
                    $test = Config::testMintConnection($mintUrl);
                    if (!$test['success']) {
                        throw new Exception('Cannot connect to mint: ' . $test['error']);
                    }
                }

                // Generate seed phrase if not provided
                if ($mintUrl && !$seedPhrase) {
                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    $seedPhrase = \Cashu\Mnemonic::generate();
                }

                // Check for duplicate seed phrase (critical - same seed with different units = lost funds)
                if ($seedPhrase) {
                    $existing = Database::fetchOne(
                        "SELECT id, name FROM stores WHERE seed_phrase = ?",
                        [$seedPhrase]
                    );
                    if ($existing) {
                        throw new Exception("This seed phrase is already used by store: {$existing['name']}. Using the same seed for multiple stores can result in lost funds.");
                    }
                }

                $storeId = Database::generateId('store');
                // primary_mint_source: 'manual' when the admin enters a mint URL
                // here, otherwise 'setup' so the trusted-list applier can fill
                // it in.
                Database::insert('stores', [
                    'id' => $storeId,
                    'name' => $name,
                    'mint_url' => $mintUrl,
                    'mint_unit' => $mintUnit,
                    'seed_phrase' => $seedPhrase,
                    'exchange_fee_percent' => $exchangeFee,
                    'price_provider_primary' => $primaryProvider,
                    'price_provider_secondary' => $secondaryProvider,
                    'primary_mint_source' => !empty($mintUrl) ? 'manual' : 'setup',
                    'created_at' => Database::timestamp(),
                ]);

                require_once __DIR__ . '/includes/trusted_mints.php';
                try {
                    TrustedMints::applyToNewStore($storeId);
                } catch (Exception $e) {
                    error_log("TrustedMints::applyToNewStore failed in admin create_store: " . $e->getMessage());
                }

                echo json_encode([
                    'id' => $storeId,
                    'name' => $name,
                    'isConfigured' => !empty($mintUrl) && !empty($seedPhrase),
                    'seedPhrase' => $seedPhrase, // Show once for backup
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'update_store':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (!$storeId) {
                    throw new Exception('Store ID required');
                }

                $store = Config::getStore($storeId);
                if (!$store) {
                    throw new Exception('Store not found');
                }

                $updates = [];

                if (isset($_POST['name'])) {
                    $updates['name'] = $_POST['name'];
                }
                if (isset($_POST['mint_url'])) {
                    $mintUrl = $_POST['mint_url'];
                    if ($mintUrl) {
                        $test = Config::testMintConnection($mintUrl);
                        if (!$test['success']) {
                            throw new Exception('Cannot connect to mint: ' . $test['error']);
                        }
                    }
                    $updates['mint_url'] = $mintUrl;
                }
                if (isset($_POST['mint_unit'])) {
                    $updates['mint_unit'] = $_POST['mint_unit'];
                }
                if (isset($_POST['seed_phrase'])) {
                    $updates['seed_phrase'] = $_POST['seed_phrase'];
                }
                if (isset($_POST['exchange_fee_percent'])) {
                    $updates['exchange_fee_percent'] = (float)$_POST['exchange_fee_percent'];
                }
                if (isset($_POST['price_provider_primary'])) {
                    $updates['price_provider_primary'] = $_POST['price_provider_primary'];
                }
                if (isset($_POST['price_provider_secondary'])) {
                    $updates['price_provider_secondary'] = $_POST['price_provider_secondary'];
                }
                if (isset($_POST['default_currency'])) {
                    $supported = Config::getSupportedDisplayCurrencies();
                    $value = $_POST['default_currency'];
                    // Normalize: 'sat' lowercase, fiats uppercase
                    $normalized = (strtolower($value) === 'sat' || strtolower($value) === 'sats')
                        ? 'sat' : strtoupper($value);
                    if (!in_array($normalized, $supported, true)) {
                        // Allow the mint's own unit too (e.g. a USD-denominated mint)
                        $store = Config::getStore($storeId);
                        $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
                        if (strtolower($normalized) !== $mintUnit) {
                            throw new Exception('Unsupported default currency');
                        }
                    }
                    $updates['default_currency'] = $normalized;
                }
                if (isset($_POST['hosting_fee_percent'])) {
                    $pct = (float) $_POST['hosting_fee_percent'];
                    if ($pct < 0 || $pct > 100) {
                        throw new Exception('Hosting fee must be between 0 and 100');
                    }
                    $updates['hosting_fee_percent'] = $pct;
                }
                if (isset($_POST['hosting_fee_destination'])) {
                    $dest = trim((string) $_POST['hosting_fee_destination']);
                    if ($dest !== '' && !LightningAddress::isValid($dest)) {
                        throw new Exception('Hosting fee destination must be a valid Lightning address');
                    }
                    $updates['hosting_fee_destination'] = $dest === '' ? null : $dest;
                }

                Config::updateStore($storeId, $updates);

                echo json_encode([
                    'success' => true,
                    'isConfigured' => Config::isStoreConfigured($storeId),
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_store_privacy':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (!Config::getStore($storeId)) {
                    throw new Exception('Store not found');
                }
                // Per-store invoice-memo privacy defaults. 1 = hide, 0 = show.
                // Written via Database::update directly rather than
                // Config::updateStore, keeping the updateStore allowlist tight.
                $hideStoreName = ($_POST['hide_store_name_on_invoice'] ?? '0') === '1' ? 1 : 0;
                $hideNote = ($_POST['hide_note_on_invoice'] ?? '0') === '1' ? 1 : 0;
                Database::update('stores', [
                    'hide_store_name_on_invoice' => $hideStoreName,
                    'hide_note_on_invoice' => $hideNote,
                ], 'id = ?', [$storeId]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'generate_seed':
            Auth::requireAdmin();
            require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
            echo json_encode(['seedPhrase' => \Cashu\Mnemonic::generate()]);
            break;

        case 'validate_seed':
            Auth::requireAdmin();
            try {
                $seedPhrase = $_POST['seed_phrase'] ?? '';
                if (empty($seedPhrase)) {
                    throw new Exception('Seed phrase required');
                }

                // Check for duplicate seed phrase
                $existing = Database::fetchOne(
                    "SELECT id, name FROM stores WHERE seed_phrase = ?",
                    [$seedPhrase]
                );

                if ($existing) {
                    echo json_encode([
                        'valid' => false,
                        'error' => "This seed phrase is already used by store: {$existing['name']}",
                        'existingStore' => $existing['name'],
                    ]);
                } else {
                    echo json_encode(['valid' => true]);
                }
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'delete_store':
            Auth::requireAdmin();
            $storeId = $_POST['store_id'] ?? '';
            Database::delete('stores', 'id = ?', [$storeId]);
            echo json_encode(['success' => true]);
            break;

        case 'create_api_key':
            Auth::requireAdmin();
            $storeId = $_POST['store_id'] ?? '';
            $label = $_POST['label'] ?? 'API Key';
            $apiKey = Auth::createApiKey($storeId, $label);
            echo json_encode($apiKey);
            break;

        case 'delete_api_key':
            Auth::requireAdmin();
            $keyId = $_POST['key_id'] ?? '';
            Auth::deleteApiKey($keyId);
            echo json_encode(['success' => true]);
            break;

        case 'check_proofs_spent':
            // Check if specific proofs have been spent (for detecting token claims)
            try {
                $storeId = $_POST['store_id'] ?? '';
                $secretsRaw = $_POST['secrets'] ?? '[]';
                $secrets = json_decode($secretsRaw, true);

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (empty($secrets)) {
                    echo json_encode(['spent' => false]);
                    break;
                }

                // Check database state first using library storage (quick check)
                $wallet = Invoice::getWalletInstance($storeId);
                $spentProofs = $wallet->getStorage()->getProofs(ProofState::SPENT);
                $spentSecrets = array_column($spentProofs, 'secret');
                $spentCount = count(array_intersect($secrets, $spentSecrets));

                if ($spentCount == count($secrets)) {
                    // All spent in database
                    echo json_encode(['spent' => true, 'source' => 'db']);
                    break;
                }

                // Check at mint for real-time status
                $Ys = [];
                foreach ($secrets as $secret) {
                    $Y = \Cashu\Crypto::hashToCurve($secret);
                    $Ys[] = bin2hex(\Cashu\Secp256k1::compressPoint($Y));
                }

                $store = Config::getStore($storeId);
                $client = new \Cashu\MintClient($store['mint_url']);
                $response = $client->post('checkstate', ['Ys' => $Ys]);


                $allSpent = true;
                foreach ($response['states'] ?? [] as $state) {
                    // Normalize case - mints may return lowercase states
                    $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                    if ($mintState !== ProofState::SPENT) {
                        $allSpent = false;
                        break;
                    }
                }

                // If all spent at mint but not in DB, update DB
                if ($allSpent && $spentCount < count($secrets)) {
                    Invoice::markProofsSpent($storeId, $secrets);
                }

                echo json_encode(['spent' => $allSpent, 'source' => 'mint']);
            } catch (Exception $e) {
                error_log("CashuPayServer: check_proofs_spent error: " . $e->getMessage());
                echo json_encode(['spent' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_withdrawal_estimate':
            // Get mint's actual cost for a Lightning withdrawal (to handle exchange rate differences)
            try {
                $storeId = $_POST['store_id'] ?? '';
                $destination = $_POST['destination'] ?? '';
                $amountSats = (int)($_POST['amount_sats'] ?? 0);

                if (empty($storeId) || !Config::isStoreConfigured($storeId)) {
                    throw new Exception('Store not configured');
                }
                if (empty($destination)) {
                    throw new Exception('Destination required');
                }
                if ($amountSats < 1) {
                    throw new Exception('Amount required');
                }

                $mintUnit = Config::getStoreMintUnit($storeId);
                $isFiatMint = !in_array(strtolower($mintUnit), ['sat', 'sats', 'msat']);

                // Get wallet for this store
                $wallet = Invoice::getWalletInstance($storeId);
                $balance = Invoice::getBalance($storeId);

                // Check if it's a BOLT-11 invoice or Lightning address
                $isBolt11 = LightningAddress::isBolt11Invoice($destination);
                $isLightningAddress = !$isBolt11 && strpos($destination, '@') !== false;

                if (!$isBolt11 && !$isLightningAddress) {
                    throw new Exception('Invalid destination');
                }

                // Get invoice for the amount
                if ($isBolt11) {
                    // For BOLT-11, extract the amount and use the invoice directly
                    $bolt11Info = LightningAddress::getBolt11Amount($destination, $storeId);
                    if ($bolt11Info !== null && $bolt11Info['amountSats'] > 0) {
                        $amountSats = $bolt11Info['amountSats'];
                    }
                    $bolt11 = $destination;
                } else {
                    // Get invoice from Lightning address
                    $bolt11 = \Cashu\LightningAddress::getInvoice($destination, $amountSats, null);
                }

                // Request melt quote to get actual cost in mint units
                $meltQuote = $wallet->requestMeltQuote($bolt11);

                $costMintUnit = $meltQuote->amount;
                $feeReserve = $meltQuote->feeReserve;
                $totalCost = $costMintUnit + $feeReserve;
                $canAfford = $balance >= $totalCost;

                // Calculate max SAT that user can afford at mint's rate
                $maxAffordableSats = 0;
                if ($isFiatMint && $costMintUnit > 0) {
                    // Rate: sats per mint unit
                    $ratePerMintUnit = $amountSats / $costMintUnit;
                    // How many mint units available after fee reserve?
                    // Need to account for fee reserve scaling with amount
                    // Approximate: use same fee percentage
                    $feePercent = ($feeReserve / $costMintUnit) * 100;
                    $availableForMelt = $balance / (1 + $feePercent / 100);
                    $maxAffordableSats = (int)floor($availableForMelt * $ratePerMintUnit);
                }

                echo json_encode([
                    'success' => true,
                    'amountSats' => $amountSats,
                    'costMintUnit' => $costMintUnit,
                    'feeReserve' => $feeReserve,
                    'totalCost' => $totalCost,
                    'balance' => $balance,
                    'canAfford' => $canAfford,
                    'maxAffordableSats' => $maxAffordableSats,
                    'mintUnit' => $mintUnit,
                    'isFiatMint' => $isFiatMint,
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;

        case 'save_onchain_offer':
            Auth::requireAdmin();
            // Toggle whether the on-chain rail is OFFERED to customers for this
            // store, independent of whether an xpub/static address is
            // configured. Lets a merchant keep the xpub (submarine swaps still
            // settle on-chain to it) while presenting a Lightning-only checkout.
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                require_once __DIR__ . '/includes/onchain/config.php';
                $raw = (string)($_POST['offer_enabled'] ?? '1');
                if (!in_array($raw, ['0', '1'], true)) {
                    throw new Exception('Invalid on-chain offer value');
                }
                OnchainConfig::setStoreOverride($storeId, (int)$raw);
                echo json_encode([
                    'success' => true,
                    'offerEnabled' => OnchainConfig::isEnabledForStore($storeId),
                ]);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'save_onchain_strike':
            Auth::requireAdmin();
            // Toggle "also accept on-chain payments via Strike" from the
            // on-chain settings card (the Lightning payments card saves the
            // same flag alongside the Strike key). Enabling probes a real
            // receive request against every stored Strike key so a key
            // without partner.receive-request.create is refused here instead
            // of silently dropping the Strike address from checkouts.
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                require_once __DIR__ . '/includes/onchain/config.php';
                require_once __DIR__ . '/includes/store_ln_addresses.php';
                $raw = (string)($_POST['strike_onchain'] ?? '0');
                if (!in_array($raw, ['0', '1'], true)) {
                    throw new Exception('Invalid Strike on-chain value');
                }
                if ($raw === '1') {
                    $strikeKeys = [];
                    $storedStrikeKeys = [];
                    foreach (StoreLnAddresses::listForStore($storeId) as $row) {
                        if ($row['type'] === StoreLnAddresses::TYPE_STRIKE) {
                            $strikeKeys[] = $row['address'];
                            $storedStrikeKeys[$row['address']] = true;
                        }
                    }
                    OnchainConfig::gateStrikeOnchainEnable(
                        $storeId,
                        $strikeKeys,
                        $storedStrikeKeys,
                        OnchainConfig::strikeEnabledForStore($storeId)
                    );
                }
                OnchainConfig::setStrikeEnabled($storeId, (int)$raw);
                echo json_encode([
                    'success' => true,
                    'strikeOnchainEnabled' => OnchainConfig::strikeEnabledForStore($storeId),
                ]);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'save_onchain':
            Auth::requireAdmin();
            // Persist a store's on-chain Bitcoin payment configuration.
            // Two modes are supported: 'xpub' (server derives a fresh address
            // per invoice) and 'static' (server reuses a single address and
            // tweaks each invoice's amount for disambiguation).
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                $mode = $_POST['mode'] ?? 'xpub';
                if (!in_array($mode, ['xpub', 'static'], true)) {
                    throw new Exception('Invalid mode');
                }
                $network = $_POST['network'] ?? 'mainnet';
                $type = $_POST['address_type'] ?? 'P2WPKH';
                $minConfs = max(0, (int)($_POST['min_confs'] ?? 1));
                $confirmTimeoutSec = max(60, (int)($_POST['confirm_timeout_sec'] ?? 86400));
                $providerUrl = trim($_POST['provider_url'] ?? '');

                require_once __DIR__ . '/includes/onchain/wallet.php';

                if ($mode === 'static') {
                    $staticAddress = trim($_POST['static_address'] ?? '');
                    $tweakRange = max(100, min(100000, (int)($_POST['static_tweak_range'] ?? 1000)));

                    if ($staticAddress === '') {
                        // Empty address -> no address-based on-chain source.
                        // The shared confirmation-policy settings still
                        // persist: a Strike-only store (addresses minted in
                        // the merchant's Strike account) settles against
                        // onchain_min_confs / the confirmation window via the
                        // same chain watcher, and this save is its only way
                        // to change them.
                        require_once __DIR__ . '/includes/onchain/config.php';
                        Database::update('stores', [
                            'onchain_address_mode' => 'static',
                            'onchain_static_address' => null,
                            'onchain_xpub' => null,
                            'onchain_min_confs' => $minConfs,
                            'onchain_confirm_timeout_sec' => $confirmTimeoutSec,
                            'onchain_provider' => 'esplora',
                            'onchain_provider_url' => $providerUrl ?: null,
                        ], 'id = ?', [$storeId]);
                        echo json_encode([
                            'success' => true,
                            'disabled' => true,
                            'strikeOnchain' => OnchainConfig::strikeEnabledForStore($storeId),
                        ]);
                        break;
                    }

                    $check = OnchainWallet::validateAddress($staticAddress, $network);
                    if (!$check['valid']) {
                        throw new Exception($check['error'] ?: 'Invalid address');
                    }

                    $update = [
                        'onchain_address_mode' => 'static',
                        'onchain_static_address' => $staticAddress,
                        'onchain_static_tweak_range' => $tweakRange,
                        // Clearing the xpub enforces the "one OR the other"
                        // invariant: switching to static mode drops any
                        // previously-set xpub so it can't be silently used.
                        'onchain_xpub' => null,
                        'onchain_network' => $network,
                        'onchain_min_confs' => $minConfs,
                        'onchain_confirm_timeout_sec' => $confirmTimeoutSec,
                        'onchain_provider' => 'esplora',
                        'onchain_provider_url' => $providerUrl ?: null,
                    ];
                    Database::update('stores', $update, 'id = ?', [$storeId]);
                    echo json_encode([
                        'success' => true,
                        'mode' => 'static',
                        'staticAddress' => $staticAddress,
                        'tweakRange' => $tweakRange,
                    ]);
                    break;
                }

                // xpub mode (existing behavior)
                $xpub = trim($_POST['xpub'] ?? '');

                if ($xpub === '') {
                    // Empty xpub -> no address-based on-chain source. Also
                    // clear any leftover static address so the row is in a
                    // clean disabled state. The shared confirmation-policy
                    // settings still persist — same Strike-only reasoning as
                    // the static branch above.
                    require_once __DIR__ . '/includes/onchain/config.php';
                    Database::update('stores', [
                        'onchain_address_mode' => 'xpub',
                        'onchain_xpub' => null,
                        'onchain_static_address' => null,
                        'onchain_min_confs' => $minConfs,
                        'onchain_confirm_timeout_sec' => $confirmTimeoutSec,
                        'onchain_provider' => 'esplora',
                        'onchain_provider_url' => $providerUrl ?: null,
                    ], 'id = ?', [$storeId]);
                    echo json_encode([
                        'success' => true,
                        'disabled' => true,
                        'strikeOnchain' => OnchainConfig::strikeEnabledForStore($storeId),
                    ]);
                    break;
                }

                $check = OnchainWallet::validateXpub($xpub, $network, $type);
                if (!$check['valid']) {
                    throw new Exception($check['error'] ?: 'Invalid xpub');
                }

                $existing = Database::fetchOne(
                    "SELECT onchain_xpub FROM stores WHERE id = ?", [$storeId]
                );
                $xpubChanged = $existing && ($existing['onchain_xpub'] ?? null) !== $xpub;

                // The admin form exposes only a provider URL, not a provider
                // kind. Force the kind to Esplora here — bitcoind-RPC is only
                // used by the dev fixtures, which seed the column directly.
                // Without this, a row left over from a fixture stays stuck on
                // 'bitcoind-rpc' even after the user clears the URL, and every
                // poll throws "bitcoind provider requires onchain_provider_url".
                $update = [
                    'onchain_address_mode' => 'xpub',
                    'onchain_xpub' => $xpub,
                    // Clear static-mode address on switch (mutual exclusion).
                    'onchain_static_address' => null,
                    'onchain_network' => $network,
                    'onchain_address_type' => $type,
                    'onchain_min_confs' => $minConfs,
                    'onchain_confirm_timeout_sec' => $confirmTimeoutSec,
                    'onchain_provider' => 'esplora',
                    'onchain_provider_url' => $providerUrl ?: null,
                ];
                // If the xpub changed, sync the displayed counter from the
                // per-xpub state table — re-adding a previously used xpub
                // resumes from where it left off; a fresh xpub starts at 0.
                $resumedIndex = null;
                if ($xpubChanged) {
                    $xpubHash = hash('sha256', $xpub);
                    $row = Database::fetchOne(
                        "SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?",
                        [$xpubHash]
                    );
                    $resumedIndex = $row ? (int)$row['next_index'] : 0;
                    $update['onchain_next_index'] = $resumedIndex;
                }
                Database::update('stores', $update, 'id = ?', [$storeId]);
                echo json_encode([
                    'success' => true,
                    'warnings' => $check['warnings'],
                    'xpubChanged' => $xpubChanged,
                    'resumedIndex' => $resumedIndex,
                ]);
            } catch (Throwable $e) {
                // Throwable, not Exception: on hosts without the GMP
                // extension the on-chain stack throws Error, and an uncaught
                // one turns this JSON endpoint into an HTML fatal.
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'validate_onchain_xpub':
            Auth::requireAdmin();
            // Used by the admin store-settings form to validate + preview
            // addresses before saving. Mirrors setup.php's validate_xpub action
            // so the same JS can drive either context.
            try {
                require_once __DIR__ . '/includes/onchain/wallet.php';
                $xpub = trim($_POST['xpub'] ?? '');
                $network = $_POST['network'] ?? 'mainnet';
                $type = $_POST['address_type'] ?? 'P2WPKH';
                $check = OnchainWallet::validateXpub($xpub, $network, $type);
                $preview = [];
                if ($check['valid']) {
                    try {
                        $preview = OnchainWallet::deriveFirstN($xpub, $type, $network, 3);
                    } catch (Throwable $e) {
                        $check['valid'] = false;
                        $check['error'] = $e->getMessage();
                    }
                }
                echo json_encode([
                    'valid' => $check['valid'],
                    'error' => $check['error'],
                    'warnings' => $check['warnings'],
                    'inferredType' => $check['inferredType'],
                    'inferredNetwork' => $check['inferredNetwork'],
                    'preview' => $preview,
                ]);
            } catch (Throwable $e) {
                // Throwable for the same GMP-less-host reason as save_onchain.
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'test_onchain_xpub':
            Auth::requireAdmin();
            // "Test xpub" button: derive the address at the current
            // onchain_next_index without consuming it, so the user can
            // confirm derivation matches their signing wallet.
            try {
                require_once __DIR__ . '/includes/onchain/wallet.php';
                $storeId = $_POST['store_id'] ?? '';
                $store = Database::fetchOne(
                    "SELECT onchain_xpub, onchain_address_type, onchain_network, onchain_next_index
                     FROM stores WHERE id = ?",
                    [$storeId]
                );
                if (!$store || empty($store['onchain_xpub'])) {
                    throw new Exception('No xpub configured for this store');
                }
                $idx = (int)$store['onchain_next_index'];
                $addr = OnchainWallet::deriveAddress(
                    $store['onchain_xpub'],
                    $store['onchain_address_type'] ?: 'P2WPKH',
                    $store['onchain_network'] ?: 'mainnet',
                    $idx
                );
                echo json_encode(['address' => $addr, 'index' => $idx]);
            } catch (Throwable $e) {
                // Throwable for the same GMP-less-host reason as save_onchain.
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'list_onchain_manual':
            Auth::requireAdmin();
            // List invoices awaiting manual confirmation. Scoped to a single
            // store if store_id is provided; otherwise lists across every
            // store the (admin) user can see.
            try {
                require_once __DIR__ . '/includes/onchain/payments.php';
                $storeIds = null;
                $storeId = $_GET['store_id'] ?? null;
                if ($storeId) {
                    $storeIds = [$storeId];
                }
                $rows = OnchainPayments::listNeedingManualConfirmation($storeIds);
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'id' => $r['id'],
                        'store_id' => $r['store_id'],
                        'amount' => $r['amount'],
                        'currency' => $r['currency'],
                        'onchain_address' => $r['onchain_address'],
                        'onchain_amount_sat' => (int)($r['onchain_amount_sat'] ?? 0),
                        'onchain_amount_tweak_sats' => isset($r['onchain_amount_tweak_sats'])
                            ? (int)$r['onchain_amount_tweak_sats'] : null,
                        'created_at' => $r['created_at'],
                        'expiration_time' => $r['expiration_time'],
                        'status' => $r['status'],
                        'candidates' => $r['onchain_manual_candidates_decoded'],
                    ];
                }
                echo json_encode(['invoices' => $out]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'resolve_onchain_manual':
            Auth::requireAdmin();
            // Attribute a specific (txid, vout) to the chosen invoice and
            // clear the candidate from every other invoice that listed it.
            try {
                require_once __DIR__ . '/includes/onchain/payments.php';
                $invoiceId = $_POST['invoice_id'] ?? '';
                $txid = $_POST['txid'] ?? '';
                $vout = (int)($_POST['vout'] ?? 0);
                if ($invoiceId === '' || $txid === '') {
                    throw new Exception('invoice_id and txid required');
                }
                OnchainPayments::manuallyAttribute($invoiceId, $txid, $vout);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_lightning_payments':
            // The store's ordered Lightning destination chain (LNURL/lightning
            // addresses, NWC connections, CLINK noffers). Used both for
            // invoice receive routing and for the Lightning auto-cashout rail;
            // lives in the store-settings "Lightning payments" card.
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/lnurl_receive.php';
            require_once __DIR__ . '/includes/store_ln_addresses.php';
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }

                // Destination chain. Preferred contract: three separate operator
                // lists kept apart in the UI — ln_addresses[] (tried first),
                // nwc[] (second) and noffers[] (final fallback). chainFromLists
                // validates each value against its declared type, dedups across
                // the lists, and returns the ordered LN-first chain. Older
                // callers may still send a single auto-detected addresses[] /
                // address; honour that by classifying each entry by shape
                // ('noffer1…' vs nostr+walletconnect://… vs local@host).
                if (isset($_POST['ln_addresses']) || isset($_POST['noffers'])
                        || isset($_POST['nwc']) || isset($_POST['strike'])) {
                    $lnList = $_POST['ln_addresses'] ?? [];
                    $nofferList = $_POST['noffers'] ?? [];
                    $nwcList = $_POST['nwc'] ?? [];
                    $strikeList = $_POST['strike'] ?? [];
                    if (!is_array($lnList)) {
                        $lnList = [(string)$lnList];
                    }
                    if (!is_array($nofferList)) {
                        $nofferList = [(string)$nofferList];
                    }
                    if (!is_array($nwcList)) {
                        $nwcList = [(string)$nwcList];
                    }
                    if (!is_array($strikeList)) {
                        $strikeList = [(string)$strikeList];
                    }
                    // NWC and Strike entries arrive either as full secrets
                    // (new) or opaque keep:<row-id> refs (kept) — the browser
                    // never holds the stored secret-bearing values. Resolve
                    // refs to the stored values before validation; kept
                    // entries also skip the save-time probe in
                    // probeAndGateChain.
                    [$nwcList, ] = StoreLnAddresses::resolveKeepRefs($storeId, $nwcList);
                    [$strikeList, ] = StoreLnAddresses::resolveKeepRefs(
                        $storeId, $strikeList, StoreLnAddresses::TYPE_STRIKE
                    );
                    $destinations = StoreLnAddresses::chainFromLists($lnList, $nofferList, $nwcList, $strikeList);
                } else {
                    // Legacy single auto-detected chain.
                    $rawAddresses = $_POST['addresses'] ?? null;
                    if ($rawAddresses === null) {
                        $single = trim((string)($_POST['address'] ?? ''));
                        $rawAddresses = $single === '' ? [] : [$single];
                    } elseif (!is_array($rawAddresses)) {
                        $rawAddresses = [(string)$rawAddresses];
                    }
                    $destinations = [];
                    $seen = [];
                    foreach ($rawAddresses as $raw) {
                        $val = trim((string)$raw);
                        if ($val === '') {
                            continue;
                        }
                        if (ClinkNoffer::isValid($val)) {
                            $type = StoreLnAddresses::TYPE_NOFFER;
                        } elseif (NwcUri::isValid($val)) {
                            $type = StoreLnAddresses::TYPE_NWC;
                        } elseif (stripos($val, 'nostr+walletconnect:') === 0) {
                            // Malformed NWC paste: reject WITHOUT echoing it —
                            // even a broken one may carry the wallet secret.
                            throw new Exception('Invalid NWC connection string (value hidden).');
                        } else {
                            $type = StoreLnAddresses::TYPE_LNADDRESS;
                        }
                        if (!StoreLnAddresses::isValidEntry($type, $val)) {
                            $label = $type === StoreLnAddresses::TYPE_NOFFER ? 'noffer' : 'Lightning address';
                            throw new Exception("Invalid {$label} format: {$val}");
                        }
                        $key = strtolower($val);
                        if (isset($seen[$key])) {
                            throw new Exception('Duplicate destination: '
                                . StoreLnAddresses::displayValue($type, $val));
                        }
                        $seen[$key] = true;
                        $destinations[] = ['type' => $type, 'value' => $val];
                    }
                }

                // Environment gate: the CLINK client can't sign Nostr requests
                // without bignum math (GMP or BCMath), so a noffer added on
                // such a host would silently drop Lightning from the checkout.
                // Refuse NEW noffers with the
                // actionable message; ones already stored pass through so the
                // operator can still keep (or remove) them while editing the
                // rest of the card. The UI greys the section out — this catches
                // a direct POST past that.
                require_once __DIR__ . '/includes/clink/client.php';
                require_once __DIR__ . '/includes/nwc/client.php';
                if (($nofferEnvError = ClinkClient::environmentError()) !== null) {
                    $storedNoffers = [];
                    foreach (StoreLnAddresses::listForStore($storeId) as $row) {
                        if ($row['type'] === StoreLnAddresses::TYPE_NOFFER) {
                            $storedNoffers[strtolower($row['address'])] = true;
                        }
                    }
                    foreach ($destinations as $dest) {
                        if ($dest['type'] === StoreLnAddresses::TYPE_NOFFER
                                && !isset($storedNoffers[strtolower($dest['value'])])) {
                            throw new Exception('noffers can\'t be added on this server yet. ' . $nofferEnvError);
                        }
                    }
                }
                // Same gate for NWC: signing NIP-47 requests needs the same math.
                // NEW connections are refused with the actionable message;
                // kept ones (resolved from keep: refs above) pass through so
                // the operator can still edit the rest of the card.
                if (($nwcEnvError = NwcClient::environmentError()) !== null) {
                    $storedNwcKeys = [];
                    foreach (StoreLnAddresses::listForStore($storeId) as $row) {
                        if ($row['type'] === StoreLnAddresses::TYPE_NWC) {
                            $storedNwcKeys[NwcUri::dedupKey($row['address'])] = true;
                        }
                    }
                    foreach ($destinations as $dest) {
                        if ($dest['type'] === StoreLnAddresses::TYPE_NWC
                                && !isset($storedNwcKeys[NwcUri::dedupKey($dest['value'])])) {
                            throw new Exception('NWC connections can\'t be added on this server yet. ' . $nwcEnvError);
                        }
                    }
                }

                // Probe LUD-21 support for each address and gate the save:
                // a NEW lnaddress whose host can't confirm a verify URL (or
                // can't be reached) is refused, so a broken direct-receive
                // rail is caught here instead of silently dropping Lightning
                // from the checkout. Already-stored addresses pass through
                // with a refreshed probe result (and the per-address warning)
                // so the operator can still edit the rest of the card.
                $gate = StoreLnAddresses::probeAndGateChain($storeId, $destinations);
                $entries = $gate['entries'];
                $addressResults = $gate['results'];

                // Strike on-chain option ("also accept on-chain payments via
                // Strike") rides this save when the card posts it; when the
                // field is absent the stored flag is left untouched so legacy
                // callers can't silently clear it. Gated BEFORE anything is
                // persisted — see OnchainConfig::gateStrikeOnchainEnable.
                require_once __DIR__ . '/includes/onchain/config.php';
                $strikeOnchainParam = isset($_POST['strike_onchain'])
                    ? (string)$_POST['strike_onchain'] : null;
                if ($strikeOnchainParam !== null && !in_array($strikeOnchainParam, ['0', '1'], true)) {
                    throw new Exception('Invalid Strike on-chain value');
                }
                if ($strikeOnchainParam === '1') {
                    $finalStrikeKeys = [];
                    foreach ($entries as $chainEntry) {
                        if (($chainEntry['type'] ?? '') === StoreLnAddresses::TYPE_STRIKE) {
                            $finalStrikeKeys[] = (string)$chainEntry['address'];
                        }
                    }
                    $storedStrikeKeys = [];
                    foreach (StoreLnAddresses::listForStore($storeId) as $row) {
                        if ($row['type'] === StoreLnAddresses::TYPE_STRIKE) {
                            $storedStrikeKeys[$row['address']] = true;
                        }
                    }
                    OnchainConfig::gateStrikeOnchainEnable(
                        $storeId,
                        $finalStrikeKeys,
                        $storedStrikeKeys,
                        OnchainConfig::strikeEnabledForStore($storeId)
                    );
                }

                // Replace the whole ordered chain in one transaction.
                StoreLnAddresses::replaceForStore($storeId, $entries);
                if ($strikeOnchainParam !== null) {
                    OnchainConfig::setStrikeEnabled($storeId, (int)$strikeOnchainParam);
                }

                echo json_encode([
                    'success' => true,
                    // Per-address LUD-21 results, in priority order.
                    'addresses' => $addressResults,
                    'strikeOnchainEnabled' => OnchainConfig::strikeEnabledForStore($storeId),
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_auto_melt':
            // Cashu automatic cashout: mode (lightning vs on-chain swap),
            // enable toggle and threshold. The Lightning destination chain
            // itself is saved by save_lightning_payments above.
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/swap/auto_melt.php';
            require_once __DIR__ . '/includes/swap/config.php';
            try {
                $storeId = $_POST['store_id'] ?? '';
                $enabled = ($_POST['enabled'] ?? '0') === '1' ? 1 : 0;
                $threshold = (int)($_POST['threshold'] ?? 2000);
                $modeOverrideRaw = (string)($_POST['mode_override'] ?? (string)SwapAutoMelt::FORCE_LIGHTNING);
                $modeOverride = (int)$modeOverrideRaw;
                if (!in_array($modeOverride, [SwapAutoMelt::FORCE_LIGHTNING, SwapAutoMelt::FORCE_SWAP], true)) {
                    throw new Exception('Invalid auto-melt mode');
                }

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }

                // On-chain auto-cashout requires the store to have an on-chain
                // xpub / static address — refuse to save otherwise so we never
                // select a destination that can't actually receive funds.
                if ($modeOverride === SwapAutoMelt::FORCE_SWAP) {
                    $ocRow = Database::fetchOne(
                        "SELECT onchain_address_mode, onchain_xpub, onchain_static_address FROM stores WHERE id = ?",
                        [$storeId]
                    );
                    $hasOnchain = $ocRow && ((($ocRow['onchain_address_mode'] ?? 'xpub') === 'static')
                        ? (($ocRow['onchain_static_address'] ?? '') !== '')
                        : (($ocRow['onchain_xpub'] ?? '') !== ''));
                    if (!$hasOnchain) {
                        throw new Exception('On-chain withdrawal requires an on-chain xpub or address on the Bitcoin tab.');
                    }
                }

                Database::update('stores', [
                    'auto_melt_enabled' => $enabled,
                    'auto_melt_threshold' => $threshold,
                    'auto_melt_use_swap' => $modeOverride,
                ], 'id = ?', [$storeId]);

                // Choosing on-chain auto-cashout forces submarine swaps on for
                // this store (they are the mechanism that settles the sweep).
                if ($modeOverride === SwapAutoMelt::FORCE_SWAP) {
                    SwapsConfig::setStoreOverride($storeId, SwapsConfig::FORCE_ON);
                }

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_store_notifications':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $enabled = ($_POST['enabled'] ?? '0') === '1' ? 1 : 0;
                $email = trim((string)($_POST['email'] ?? ''));

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (!Config::getStore($storeId)) {
                    throw new Exception('Store not found');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address');
                }

                // Per-store SMTP override (same fields/validation as the global
                // settings). Written via Database::update directly rather than
                // Config::updateStore, keeping the updateStore allowlist tight.
                $smtpOverride = ($_POST['smtp_override_enabled'] ?? '0') === '1' ? 1 : 0;
                $smtp = smtpFieldsFromPost($_POST);

                // Per-store newsletter checkbox default. Tri-state: '' = inherit
                // the site-wide default (stored NULL); '1'/'0' = force checked/
                // unchecked. See Config::getNewsletterDefaultChecked().
                $newsletterDefault = (string)($_POST['newsletter_default_checked'] ?? '');
                $newsletterDefaultVal = ($newsletterDefault === '1' || $newsletterDefault === '0')
                    ? (int)$newsletterDefault
                    : null;

                $update = [
                    'notifications_enabled' => $enabled,
                    'notification_email' => $email !== '' ? $email : null,
                    'smtp_override_enabled' => $smtpOverride,
                    'smtp_host' => $smtp['host'] !== '' ? $smtp['host'] : null,
                    'smtp_port' => $smtp['port'] !== '' ? (int)$smtp['port'] : null,
                    'smtp_username' => $smtp['username'] !== '' ? $smtp['username'] : null,
                    'smtp_encryption' => $smtp['encryption'] !== '' ? $smtp['encryption'] : null,
                    'smtp_from_address' => $smtp['from_address'] !== '' ? $smtp['from_address'] : null,
                    'smtp_from_name' => $smtp['from_name'] !== '' ? $smtp['from_name'] : null,
                    'newsletter_default_checked' => $newsletterDefaultVal,
                ];
                // Password: preserve on blank, overwrite on new value, wipe on clear.
                if ($smtp['password_clear']) {
                    $update['smtp_password'] = null;
                } elseif ($smtp['password'] !== '') {
                    $update['smtp_password'] = $smtp['password'];
                }

                Database::update('stores', $update, 'id = ?', [$storeId]);

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'get_notifications_settings':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/email_sender.php';
            require_once __DIR__ . '/includes/notification_sender.php';
            echo json_encode([
                'enabled' => Config::get('notifications_enabled', false) === true,
                'invoicePaidEnabled' => Config::get('notifications_invoice_paid_enabled', false) === true,
                'autoCashoutEnabled' => Config::get('notifications_auto_cashout_enabled', false) === true,
                // Effective value: explicit payer_email_capture_enabled config
                // wins, else ON standalone / OFF on managed installs (the shop
                // platform owns customer emails).
                'payerEmailCaptureEnabled' => Config::isPayerEmailCaptureEnabled(),
                'payerReceiptEnabled' => Config::get('notifications_payer_receipt_enabled', false) === true,
                // Site-wide default for the payment-screen newsletter checkbox.
                // Independent of receipt sending — email/newsletter capture works
                // even when receipts are off. Per-store override lives on stores.
                'newsletterDefaultChecked' => Config::get('newsletter_default_checked', true) === true,
                'toEmail' => (string)Config::get('notifications_to_email', ''),
                'smtpConfigured' => EmailSender::isSmtpConfigured(),
                'pendingQueueCount' => NotificationSender::pendingCount(),
                // Global SMTP server settings. The password is never sent to the
                // browser — only whether one is stored, so the field can show a
                // "leave blank to keep" placeholder.
                'smtpHost' => (string)Config::get('smtp_host', ''),
                'smtpPort' => (string)Config::get('smtp_port', ''),
                'smtpUsername' => (string)Config::get('smtp_username', ''),
                'smtpEncryption' => (string)Config::get('smtp_encryption', ''),
                'smtpFromAddress' => (string)Config::get('smtp_from_address', ''),
                'smtpFromName' => (string)Config::get('smtp_from_name', ''),
                'smtpPasswordSet' => ((string)Config::get('smtp_password', '')) !== '',
            ]);
            break;

        case 'save_notifications_settings':
            Auth::requireAdmin();
            try {
                $enabled = ($_POST['enabled'] ?? '0') === '1';
                $invoicePaidEnabled = ($_POST['invoice_paid_enabled'] ?? '0') === '1';
                $autoCashoutEnabled = ($_POST['auto_cashout_enabled'] ?? '0') === '1';
                $payerEmailCaptureEnabled = ($_POST['payer_email_capture_enabled'] ?? '0') === '1';
                $payerReceiptEnabled = ($_POST['payer_receipt_enabled'] ?? '0') === '1';
                $newsletterDefaultChecked = ($_POST['newsletter_default_checked'] ?? '0') === '1';
                $toEmail = trim((string)($_POST['to_email'] ?? ''));

                if ($toEmail !== '' && !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address');
                }

                // Global SMTP server settings. Validation is shared with the
                // per-store override (save_store_notifications) via smtpFieldsFromPost().
                $smtp = smtpFieldsFromPost($_POST);

                Config::set('notifications_enabled', $enabled);
                Config::set('notifications_invoice_paid_enabled', $invoicePaidEnabled);
                Config::set('notifications_auto_cashout_enabled', $autoCashoutEnabled);
                // Explicit boolean: once saved, it overrides the managed/
                // standalone default in Config::isPayerEmailCaptureEnabled().
                Config::set('payer_email_capture_enabled', $payerEmailCaptureEnabled);
                Config::set('notifications_payer_receipt_enabled', $payerReceiptEnabled);
                Config::set('newsletter_default_checked', $newsletterDefaultChecked);
                Config::set('notifications_to_email', $toEmail);

                Config::set('smtp_host', $smtp['host']);
                Config::set('smtp_port', $smtp['port']);
                Config::set('smtp_username', $smtp['username']);
                Config::set('smtp_encryption', $smtp['encryption']);
                Config::set('smtp_from_address', $smtp['from_address']);
                Config::set('smtp_from_name', $smtp['from_name']);
                // Password: preserve on blank, overwrite on new value, wipe on
                // explicit clear (so the field never has to echo the secret back).
                if ($smtp['password_clear']) {
                    Config::set('smtp_password', '');
                } elseif ($smtp['password'] !== '') {
                    Config::set('smtp_password', $smtp['password']);
                }

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ----- Developer / debug settings -----

        case 'get_developer_settings':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/payment_path_debug.php';
            echo json_encode([
                'paymentPathDebug' => PaymentPathDebug::enabled(),
            ]);
            break;

        // Unified recent-issues feed for the Log view: admin_event_log
        // (NWC/noffer/LNURL endpoint failures) merged with mint reliability
        // events, failed webhook deliveries, failed notification emails, and
        // swap/sweep errors. POST (not ?api=) — see the path-routing note in
        // loadDeveloperSettings.
        case 'get_admin_log':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/admin_log.php';
            $limit = min(200, max(1, (int)($_POST['limit'] ?? 50)));
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $category = trim((string)($_POST['category'] ?? ''));
            $storeId = trim((string)($_POST['store_id'] ?? ''));
            echo json_encode(AdminLog::recent(
                $limit,
                $offset,
                $category !== '' ? $category : null,
                ($storeId !== '' && $storeId !== '__all__') ? $storeId : null
            ));
            break;

        case 'get_invoice_error_settings':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/admin_log.php';
            echo json_encode([
                'suppressErrors' => AdminLog::suppressOnInvoice(),
            ]);
            break;

        case 'save_invoice_error_settings':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/admin_log.php';
            try {
                $suppress = ($_POST['suppress_errors'] ?? '0') === '1';
                Config::set(AdminLog::SUPPRESS_CONFIG_KEY, $suppress);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_developer_settings':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/payment_path_debug.php';
            try {
                $paymentPathDebug = ($_POST['payment_path_debug'] ?? '0') === '1';
                Config::set(PaymentPathDebug::CONFIG_KEY, $paymentPathDebug);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_store_swaps':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/swap/config.php';
            require_once __DIR__ . '/includes/swap/factory.php';
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                $rawEnabled = (string)($_POST['enabled'] ?? '0');
                if (!in_array($rawEnabled, ['0', '1'], true)) {
                    throw new Exception('Invalid swaps enabled value');
                }
                $enabled = (int)$rawEnabled;
                // Submarine swaps settle on-chain, so turning them on requires
                // the store to have an on-chain xpub / static address. Refuse +
                // warn otherwise rather than saving a setting that can't work.
                if ($enabled === SwapsConfig::FORCE_ON) {
                    $ocRow = Database::fetchOne(
                        "SELECT onchain_address_mode, onchain_xpub, onchain_static_address FROM stores WHERE id = ?",
                        [$storeId]
                    );
                    $hasOnchain = $ocRow && ((($ocRow['onchain_address_mode'] ?? 'xpub') === 'static')
                        ? (($ocRow['onchain_static_address'] ?? '') !== '')
                        : (($ocRow['onchain_xpub'] ?? '') !== ''));
                    if (!$hasOnchain) {
                        throw new Exception('Submarine swaps require an on-chain xpub or address on the Bitcoin tab.');
                    }
                }
                SwapsConfig::setStoreOverride($storeId, $enabled);

                // Provider preference order (comma-separated, validated against
                // the registry). Empty while swaps are on is refused — an
                // enabled swap rail with zero providers can never work.
                $orderRaw = trim((string)($_POST['provider_order'] ?? ''));
                $known = SwapProviderFactory::knownProviderNames();
                $order = [];
                foreach (explode(',', $orderRaw) as $p) {
                    $p = strtolower(trim($p));
                    if ($p !== '' && in_array($p, $known, true) && !in_array($p, $order, true)) {
                        $order[] = $p;
                    }
                }
                if ($enabled === SwapsConfig::FORCE_ON && empty($order)) {
                    throw new Exception('At least one provider must be selected when swaps are enabled');
                }
                SwapsConfig::setStoreProviderOrder($storeId, $order);

                $autoSelect = ($_POST['auto_select_cheapest'] ?? '0') === '1';
                SwapsConfig::setStoreAutoSelectCheapest($storeId, $autoSelect);

                $rawThreshold = trim((string)($_POST['auto_select_threshold_pct'] ?? ''));
                if ($rawThreshold === '' || !is_numeric($rawThreshold)) {
                    $autoThreshold = SwapsConfig::DEFAULT_AUTO_SELECT_THRESHOLD_PCT;
                } else {
                    $autoThreshold = (int)$rawThreshold;
                    if ($autoThreshold < 1 || $autoThreshold > 90) {
                        throw new Exception('Threshold must be between 1 and 90 percent');
                    }
                }
                SwapsConfig::setStoreAutoSelectThresholdPct($storeId, $autoThreshold);

                // Optional local swap floor. Blank clears it.
                $rawMin = trim((string)($_POST['minimum_target_sats'] ?? ''));
                SwapsConfig::setStoreMinimumTargetSats(
                    $storeId,
                    $rawMin === '' ? null : max(0, (int)$rawMin)
                );

                // Per-store fee-too-high → mint fallback thresholds. Blank on a
                // field clears the value so it inherits the config-file
                // constant; 0 explicitly disables that check for this store.
                $rawPct = trim((string)($_POST['fee_fallback_max_pct'] ?? ''));
                $rawSats = trim((string)($_POST['fee_fallback_max_sats'] ?? ''));
                $pct = null;
                $sats = null;
                if ($rawPct !== '') {
                    if (!is_numeric($rawPct) || (float)$rawPct < 0 || (float)$rawPct > 100) {
                        throw new Exception('Fee % threshold must be between 0 and 100');
                    }
                    $pct = (float)$rawPct;
                }
                if ($rawSats !== '') {
                    if (!ctype_digit($rawSats)) {
                        throw new Exception('Fee sats threshold must be a whole number ≥ 0');
                    }
                    $sats = (int)$rawSats;
                }
                SwapsConfig::setStoreFeeFallback($storeId, $pct, $sats);

                // Per-store strict-no-mint-fallback flag. Absent from the
                // POST means "leave as-is" so older clients don't clobber it.
                if (array_key_exists('strict_no_mint_fallback', $_POST)) {
                    $rawStrict = (string)$_POST['strict_no_mint_fallback'];
                    if (!in_array($rawStrict, ['0', '1'], true)) {
                        throw new Exception('Invalid mint-fallback mode');
                    }
                    SwapsConfig::setStoreStrictOverride($storeId, (int)$rawStrict);
                }

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'save_store_selfserve':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/selfserve.php';
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                $store = Config::getStore($storeId);
                if (!$store) {
                    throw new Exception('Store not found');
                }
                $rawEnabled = (string)($_POST['enabled'] ?? '0');
                if (!in_array($rawEnabled, ['0', '1'], true)) {
                    throw new Exception('Invalid self-serve enabled value');
                }
                $enabled = (int)$rawEnabled;
                // Turning self-serve on only makes sense if the store can take a
                // payment — otherwise every customer submission would error.
                if ($enabled === SelfServe::FORCE_ON && !SelfServe::storeIsPaymentCapable($store)) {
                    throw new Exception('This store has no payment method configured (add a Cashu mint or on-chain address first).');
                }
                SelfServe::setStoreOverride($storeId, $enabled);

                // Per-store max. Blank clears it (use the built-in default);
                // a positive whole number sets it.
                $rawMax = trim((string)($_POST['max_sats'] ?? ''));
                if ($rawMax === '') {
                    SelfServe::setStoreMaxSats($storeId, null);
                } else {
                    if (!ctype_digit($rawMax) || (int)$rawMax <= 0) {
                        throw new Exception('Maximum must be a positive whole number of sats');
                    }
                    SelfServe::setStoreMaxSats($storeId, (int)$rawMax);
                }

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'send_test_notification':
            Auth::requireAdmin();
            require_once __DIR__ . '/includes/email_sender.php';
            try {
                $to = trim((string)($_POST['to'] ?? Config::get('notifications_to_email', '')));
                if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Provide a valid recipient email');
                }
                // Optional store_id tests that store's resolved SMTP config
                // (its override, if enabled, else the global settings). Blank
                // tests the global config.
                $testStoreId = trim((string)($_POST['store_id'] ?? ''));
                if ($testStoreId !== '' && !Config::getStore($testStoreId)) {
                    throw new Exception('Store not found');
                }
                $scope = $testStoreId !== '' ? ' (store override)' : '';
                EmailSender::send(
                    $to,
                    'CashuPayServer test email',
                    "This is a test email from CashuPayServer{$scope}.\n\n"
                    . "If you received this, SMTP delivery is working.\n",
                    $testStoreId !== '' ? $testStoreId : null
                );
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'manual_melt':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $destination = $_POST['address'] ?? '';
                $amount = (int)($_POST['amount'] ?? 0);
                // For ALL Lightning destinations with fiat mints, amount is in SATS (from frontend)
                $amountIsSats = isset($_POST['amount_is_sats']) && $_POST['amount_is_sats'] === '1';

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (!Config::isStoreConfigured($storeId)) {
                    throw new Exception('Store not configured');
                }
                if (empty($destination)) {
                    throw new Exception('Destination (Lightning address or BOLT-11 invoice) required');
                }

                $isBolt11 = LightningAddress::isBolt11Invoice($destination);
                $isLightningAddress = !$isBolt11 && strpos($destination, '@') !== false;
                $isLightningDestination = $isBolt11 || $isLightningAddress;
                $mintUnit = Config::getStoreMintUnit($storeId);
                $isFiatMint = !in_array(strtolower($mintUnit), ['sat', 'sats', 'msat']);

                // For BOLT-11 with encoded amount, get the sat amount from the invoice
                // This overrides the frontend amount since the invoice has a fixed amount
                if ($isBolt11) {
                    $bolt11Info = LightningAddress::getBolt11Amount($destination, $storeId);
                    if ($bolt11Info !== null && $bolt11Info['amountSats'] > 0) {
                        // Use the invoice's encoded amount in SATS
                        $amount = $bolt11Info['amountSats'];
                        $amountIsSats = true; // Bolt11 encoded amount is in sats
                    }
                }

                if ($amount < 1) {
                    throw new Exception('Amount required');
                }

                // Fees (dev / hosting) are no longer assessed on
                // manual withdrawals — they settle on the cron fee-settlement
                // tick (see DevFee::settleStore) against accumulated revenue.
                if ($isLightningDestination && $isFiatMint && $amountIsSats) {
                    // Amount is in SATS — fetch the bolt11 invoice for the
                    // requested sat amount, then melt it.
                    if ($isBolt11) {
                        $bolt11ForQuote = $destination;
                    } else {
                        $bolt11ForQuote = LightningAddress::getInvoice($destination, $amount, 'BareBits withdrawal');
                    }
                    $result = LightningAddress::meltToBolt11($storeId, $bolt11ForQuote);
                } else {
                    // Standard flow: amount is in mint units (sat mint or non-Lightning destination)
                    if ($isBolt11) {
                        $result = LightningAddress::meltToBolt11($storeId, $destination, $amount);
                    } else {
                        // For Lightning address with sat mint, amount is already in sats
                        $result = LightningAddress::meltToAddress($storeId, $destination, $amount, 'BareBits withdrawal');
                    }
                }

                // Record successful melt for fee-base accounting + future stats.
                // Convert network fee to sats when the store mint is fiat.
                $amountSatsForLog = $isFiatMint
                    ? (int) ExchangeRates::convertMintUnitToSats(
                        (int)($result['amountPaid'] ?? $amount),
                        $mintUnit,
                        Config::getStorePriceProviders($storeId)['primary'],
                        Config::getStorePriceProviders($storeId)['secondary']
                      )
                    : (int)($result['amountPaid'] ?? $amount);
                $networkFeeSats = (int)($result['fee'] ?? 0);
                if ($isFiatMint && $networkFeeSats > 0) {
                    $networkFeeSats = (int) ExchangeRates::convertMintUnitToSats(
                        $networkFeeSats,
                        $mintUnit,
                        Config::getStorePriceProviders($storeId)['primary'],
                        Config::getStorePriceProviders($storeId)['secondary']
                    );
                }
                require_once __DIR__ . '/includes/dev_fee.php';
                MeltLog::record(
                    $storeId,
                    $amountSatsForLog,
                    $networkFeeSats,
                    $destination,
                    $result['preimage'] ?? null,
                    null
                );

                echo json_encode($result);
            } catch (Exception $e) {
                // If melt failed due to "already spent", sync proof states
                if (stripos($e->getMessage(), 'already spent') !== false ||
                    stripos($e->getMessage(), 'proof already spent') !== false) {
                    try {
                        $wallet = Invoice::getWalletInstance($storeId);
                        $wallet->syncProofStates();
                        cashupay_status(400);
                        echo json_encode([
                            'error' => 'Some proofs were already spent. Balance updated. Please try again.',
                            'sync' => true
                        ]);
                        break;
                    } catch (Exception $syncError) {
                        // Sync failed, fall through to generic error
                    }
                }
                cashupay_status(400);
                $errorMsg = ($e instanceof \Cashu\CashuProtocolException)
                    ? 'Mint returned error: ' . $e->getMessage()
                    : $e->getMessage();
                echo json_encode(['error' => $errorMsg]);
            }
            break;

        case 'get_bolt11_amount':
            try {
                $bolt11 = $_POST['bolt11'] ?? '';
                $storeId = $_POST['store_id'] ?? '';

                if (empty($bolt11) || !LightningAddress::isBolt11Invoice($bolt11)) {
                    echo json_encode(['hasAmount' => false, 'amountSats' => null, 'feeEstimate' => null]);
                    break;
                }

                // Use store-specific wallet if store_id is provided
                $info = LightningAddress::getBolt11Amount($bolt11, $storeId ?: null);

                if ($info === null) {
                    echo json_encode(['hasAmount' => false, 'amountSats' => null, 'feeEstimate' => null]);
                } else {
                    // The invoice amount is encoded in SATS - this is what Lightning invoices use
                    // The melt quote 'amount' is in the mint's unit, but the invoice itself is in sats
                    // We return amountSats which is the sat amount from the invoice
                    echo json_encode([
                        'hasAmount' => $info['amountSats'] > 0,
                        'amountSats' => $info['amountSats'],
                        'amountMintUnit' => $info['amountMintUnit'] ?? null,
                        'feeEstimate' => $info['feeReserve'],
                        'meltError' => $info['meltError'] ?? null
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode(['hasAmount' => false, 'amountSats' => null, 'feeEstimate' => null, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_balance_in_sats':
            // Get store balance converted to satoshis (for fiat mints)
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }

                $store = Config::getStore($storeId);
                if (!$store) {
                    throw new Exception('Store not found');
                }

                require_once __DIR__ . '/includes/rates.php';

                $balance = Invoice::getBalance($storeId);
                $mintUnit = strtolower($store['mint_unit'] ?? 'sat');

                if (in_array($mintUnit, ['sat', 'sats', 'msat'])) {
                    // Already in sats (or close)
                    $balanceInSats = $mintUnit === 'msat' ? (int)ceil($balance / 1000) : $balance;
                } else {
                    // Fiat mint - convert to sats
                    $balanceInSats = ExchangeRates::convertMintUnitToSats(
                        $balance,
                        $mintUnit,
                        $store['price_provider_primary'] ?? null,
                        $store['price_provider_secondary'] ?? null
                    );
                }

                echo json_encode([
                    'balanceMintUnit' => $balance,
                    'balanceSats' => $balanceInSats,
                    'mintUnit' => $mintUnit,
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'export_token':
            Auth::requireAdmin();
            // OFFLINE-FIRST EXPORT: Minimize mint contact, maximize reliability
            //
            // Flow:
            // 1. Get proofs from local storage
            // 2. Try greedy selection (no mint needed if exact change available)
            // 3. If no exact change, try mint swap (if reachable)
            // 4. If mint unreachable, ask user to confirm larger amount
            // 5. Serialize and return token
            try {
                require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';

                $storeId = $_POST['store_id'] ?? '';
                $amount = (int)($_POST['amount'] ?? 0);
                $forceAmount = isset($_POST['force_amount']) ? (int)$_POST['force_amount'] : null;

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (!Config::isStoreConfigured($storeId)) {
                    throw new Exception('Store not configured');
                }
                if ($amount < 1) {
                    throw new Exception('Amount required');
                }

                // Resolve any pending proofs first
                Invoice::checkPendingProofs($storeId);

                // 1. Get proofs from local storage (offline-first)
                $proofs = Invoice::getUnspentProofs($storeId);
                $balance = \Cashu\Wallet::sumProofs($proofs);

                if ($balance < $amount) {
                    $store = Config::getStore($storeId);
                    $mintUnit = $store['mint_unit'] ?? 'sat';
                    throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$amount} {$mintUnit}");
                }

                // 2. Try greedy selection first (no mint needed if exact change available)
                $selected = \Cashu\Wallet::selectProofs($proofs, $amount);
                $selectedSum = \Cashu\Wallet::sumProofs($selected);
                $hasExactChange = ($selectedSum === $amount);

                // Track state
                $sendProofs = null;
                $mintUsed = false;
                $mintReachable = true;
                $wallet = null;

                // 3. If no exact change, try mint swap
                if (!$hasExactChange) {
                    try {
                        $wallet = Invoice::getWalletInstance($storeId);

                        // Check proof states at mint first (filter out spent/pending)
                        if (!empty($proofs)) {
                            $states = $wallet->checkProofState($proofs);
                            $validProofs = [];
                            $spentSecrets = [];

                            foreach ($states as $i => $state) {
                                $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                                if ($mintState === ProofState::SPENT) {
                                    $spentSecrets[] = $proofs[$i]->secret;
                                } elseif ($mintState === ProofState::UNSPENT) {
                                    $validProofs[] = $proofs[$i];
                                }
                                // Skip PENDING proofs
                            }

                            if (!empty($spentSecrets)) {
                                Invoice::markProofsSpent($storeId, $spentSecrets);
                            }
                            $proofs = $validProofs;
                            $balance = \Cashu\Wallet::sumProofs($proofs);
                        }

                        $fee = $wallet->calculateFee($proofs);

                        if ($balance >= $amount + $fee) {
                            $result = $wallet->split($proofs, $amount);
                            $sendProofs = $result['send'];
                            $mintUsed = true;
                        } else {
                            $mintUnit = $wallet->getUnit();
                            throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: " . ($amount + $fee) . " {$mintUnit}");
                        }

                    } catch (Exception $e) {
                        // Check if mint is unreachable
                        if (Invoice::isMintUnreachable($e)) {
                            $mintReachable = false;
                            // Continue with greedy selection below
                        } elseif (stripos($e->getMessage(), 'already spent') !== false ||
                                  stripos($e->getMessage(), 'proof already spent') !== false) {
                            // Sync proof states and ask user to retry
                            if ($wallet) {
                                try { $wallet->syncProofStates(); } catch (Exception $se) {}
                            }
                            cashupay_status(400);
                            echo json_encode([
                                'error' => 'Some proofs were already spent. Balance updated. Please try again.',
                                'sync' => true
                            ]);
                            break;
                        } else {
                            throw $e; // Re-throw other errors
                        }
                    }
                }

                // 4. If mint not used (exact change locally OR mint unreachable), use greedy selection
                if (!$mintUsed) {
                    // Re-select in case proofs changed during state check
                    $selected = \Cashu\Wallet::selectProofs($proofs, $amount);
                    $selectedSum = \Cashu\Wallet::sumProofs($selected);

                    // Ask user to confirm if amount differs and mint is unreachable
                    if ($selectedSum !== $amount && $forceAmount !== $selectedSum) {
                        $message = $mintReachable
                            ? "Exact change unavailable. Export {$selectedSum} instead of {$amount}?"
                            : "Mint unreachable. Exact change unavailable. Export {$selectedSum} instead of {$amount}?";

                        echo json_encode([
                            'error' => 'change_needed',
                            'requested' => $amount,
                            'available' => $selectedSum,
                            'mintUnreachable' => !$mintReachable,
                            'message' => $message
                        ]);
                        break;
                    }

                    $sendProofs = $selected;
                    $amount = $selectedSum; // Update to actual amount
                }

                // 5. Serialize and return token
                if (empty($sendProofs)) {
                    throw new Exception("Export failed: no proofs available");
                }

                // Get store config for serialization
                $store = Config::getStore($storeId);
                $mintUrl = $store['mint_url'];
                $mintUnit = $store['mint_unit'] ?? 'sat';

                // Use wallet serialization if available, otherwise manual
                if ($wallet && $mintUsed) {
                    $token = $wallet->serializeToken($sendProofs);
                } else {
                    // Offline serialization - detect V4 vs V3 format
                    $useV4 = true;
                    foreach ($sendProofs as $proof) {
                        if (!\Cashu\TokenSerializer::isHexKeysetId($proof->id)) {
                            $useV4 = false;
                            break;
                        }
                    }
                    $token = $useV4
                        ? \Cashu\TokenSerializer::serializeV4($mintUrl, $sendProofs, $mintUnit)
                        : \Cashu\TokenSerializer::serializeV3($mintUrl, $sendProofs, $mintUnit);
                }

                // Mark sent proofs as PENDING for claim tracking
                $sentSecrets = array_map(fn($p) => $p->secret, $sendProofs);
                Invoice::markProofsPending($storeId, $sentSecrets);

                $response = [
                    'token' => $token,
                    'amount' => \Cashu\Wallet::sumProofs($sendProofs),
                    'secrets' => $sentSecrets,
                ];

                if (!$mintUsed) {
                    $response['offlineExport'] = true;
                }
                if (!$mintReachable) {
                    $response['mintUnreachable'] = true;
                }

                echo json_encode($response);

            } catch (Exception $e) {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollback();
                }
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'get_backup_mints':
            $storeId = $_GET['store_id'] ?? $_POST['store_id'] ?? null;
            if (!$storeId) {
                cashupay_status(400);
                echo json_encode(['error' => 'Store ID required']);
                break;
            }
            echo json_encode(Config::getStoreBackupMints($storeId));
            break;

        case 'add_backup_mint':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $mintUrl = $_POST['mint_url'] ?? '';
                $unit = $_POST['unit'] ?? 'sat';
                $priority = (int)($_POST['priority'] ?? 100);

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }

                // Test connection first
                $test = Config::testMintConnection($mintUrl);
                if (!$test['success']) {
                    throw new Exception('Cannot connect to mint: ' . $test['error']);
                }

                $id = Config::addStoreBackupMint($storeId, $mintUrl, $unit, $priority);
                echo json_encode([
                    'success' => true,
                    'id' => $id,
                    'mint_info' => $test['info']
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'update_backup_mint':
            Auth::requireAdmin();
            try {
                $id = (int)($_POST['id'] ?? 0);
                $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : null;
                $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : null;

                $data = [];
                if ($enabled !== null) $data['enabled'] = $enabled;
                if ($priority !== null) $data['priority'] = $priority;

                Config::updateStoreBackupMint($id, $data);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'remove_backup_mint':
            Auth::requireAdmin();
            try {
                $id = (int)($_POST['id'] ?? 0);
                Config::removeStoreBackupMint($id);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'test_mint':
            try {
                $mintUrl = $_POST['mint_url'] ?? '';
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }
                $result = Config::testMintConnection($mintUrl);
                echo json_encode($result);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ===== Offline Cashu acceptance (NUT-12) =====
        case 'save_offline_cashu':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                $enabling = !empty($_POST['enabled']);
                OfflineCashu::saveSettings($storeId, [
                    'enabled' => $enabling,
                    // P2PK is not yet available; never persist it as the floor.
                    'policy' => 'dleq',
                    'max_per_tx' => (int)($_POST['max_per_tx'] ?? 0),
                    'max_outstanding' => (int)($_POST['max_outstanding'] ?? 0),
                    'accept_all_mints' => !empty($_POST['accept_all_mints']),
                    'per_tx_override' => !empty($_POST['per_tx_override']),
                ]);
                // Convenience: when first enabling and the allowlist is empty,
                // seed it from the store's configured mints.
                $seeded = 0;
                if ($enabling && empty(OfflineCashu::allowlist($storeId))) {
                    $seeded = OfflineCashu::seedAllowlistFromStoreMints($storeId);
                }
                echo json_encode(['success' => true, 'seeded' => $seeded]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'set_invoice_allow_any_mint':
            Auth::requireAdmin();
            try {
                $invoiceId = $_POST['invoice_id'] ?? '';
                $allow = !empty($_POST['allow']) ? 1 : 0;
                if ($invoiceId === '') {
                    throw new Exception('Invoice ID required');
                }
                Database::update('invoices', ['cashu_offline_allow_any_mint' => $allow], 'id = ?', [$invoiceId]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'add_offline_mint':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $mintUrl = trim($_POST['mint_url'] ?? '');
                if (empty($storeId) || $mintUrl === '') {
                    throw new Exception('Store ID and mint URL required');
                }
                OfflineCashu::addAllowedMint($storeId, $mintUrl);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'toggle_offline_mint':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $mintUrl = trim($_POST['mint_url'] ?? '');
                $enabled = !empty($_POST['enabled']);
                if (empty($storeId) || $mintUrl === '') {
                    throw new Exception('Store ID and mint URL required');
                }
                OfflineCashu::setMintEnabled($storeId, $mintUrl, $enabled);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'remove_offline_mint':
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                $mintUrl = trim($_POST['mint_url'] ?? '');
                if (empty($storeId) || $mintUrl === '') {
                    throw new Exception('Store ID and mint URL required');
                }
                OfflineCashu::removeAllowedMint($storeId, $mintUrl);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ===== Mint reliability admin actions =====
        case 'admin_reenable_mint':
            Auth::requireAdmin();
            try {
                require_once __DIR__ . '/includes/mint_reliability.php';
                $mintUrl = $_POST['mint_url'] ?? '';
                if ($mintUrl === '') {
                    throw new Exception('mint_url required');
                }
                MintReliability::adminReenable($mintUrl, $_SESSION['username'] ?? null);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'admin_mark_mint_bad':
            Auth::requireAdmin();
            try {
                require_once __DIR__ . '/includes/mint_reliability.php';
                $mintUrl = $_POST['mint_url'] ?? '';
                if ($mintUrl === '') {
                    throw new Exception('mint_url required');
                }
                MintReliability::adminConfirmedBad($mintUrl, $_SESSION['username'] ?? null);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'reset_mint_counters':
            Auth::requireAdmin();
            try {
                require_once __DIR__ . '/includes/mint_reliability.php';
                $mintUrl = $_POST['mint_url'] ?? '';
                if ($mintUrl === '') {
                    throw new Exception('mint_url required');
                }
                MintReliability::resetCounters($mintUrl, $_SESSION['username'] ?? null);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'reset_all_mint_counters':
            Auth::requireAdmin();
            try {
                require_once __DIR__ . '/includes/mint_reliability.php';
                MintReliability::resetAllCounters($_SESSION['username'] ?? null);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ===== Trusted mints settings =====
        case 'save_trusted_mints_settings':
            Auth::requireAdmin();
            try {
                require_once __DIR__ . '/includes/trusted_mints.php';
                $url = trim($_POST['url'] ?? '');
                $minutes = (int)($_POST['refresh_minutes'] ?? 0);
                if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                    throw new Exception('URL must start with http:// or https://');
                }
                if ($url === '') {
                    Config::delete(TrustedMints::CONFIG_URL_KEY);
                } else {
                    Config::set(TrustedMints::CONFIG_URL_KEY, $url);
                }
                if ($minutes > 0) {
                    Config::set(TrustedMints::CONFIG_INTERVAL_KEY, $minutes);
                }
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'refresh_trusted_mints':
            Auth::requireAdmin();
            try {
                require_once __DIR__ . '/includes/trusted_mints.php';
                $refreshed = TrustedMints::refresh(true);
                if ($refreshed) {
                    TrustedMints::applyToAllStores();
                }
                echo json_encode([
                    'success' => true,
                    'refreshed' => $refreshed,
                    'lastError' => TrustedMints::getLastError(),
                    'lastOkAt' => TrustedMints::getLastOkAt(),
                    'cached' => TrustedMints::getCachedList(),
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'test_mint_expiry':
            try {
                require_once __DIR__ . '/includes/mint_helpers.php';
                $mintUrl = $_POST['mint_url'] ?? '';
                $unit = $_POST['unit'] ?? 'sat';
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }
                $result = MintHelpers::testExpiry($mintUrl, $unit);
                echo json_encode($result);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ===== User management (admin-only) =====
        case 'list_users':
            Auth::requireAdmin();
            echo json_encode(['users' => Auth::listUsers()]);
            break;

        case 'create_user':
            Auth::requireAdmin();
            try {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? Auth::ROLE_USER;
                $userId = Auth::createUser($username, $password, $role);
                echo json_encode(['success' => true, 'id' => $userId]);
            } catch (\InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            } catch (\RuntimeException $e) {
                cashupay_status(409);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'delete_user':
            Auth::requireAdmin();
            try {
                $userId = $_POST['user_id'] ?? '';
                $self = Auth::currentUser();
                if ($self && $self['id'] === $userId) {
                    throw new \RuntimeException('You cannot delete your own account');
                }
                Auth::deleteUser($userId);
                echo json_encode(['success' => true]);
            } catch (\RuntimeException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'reset_password':
            // Admin resets another user's password.
            Auth::requireAdmin();
            try {
                $userId = $_POST['user_id'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                Auth::changePassword($userId, $newPassword);
                echo json_encode(['success' => true]);
            } catch (\InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            } catch (\RuntimeException $e) {
                cashupay_status(404);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        // ===== Self-service (any logged-in user) =====
        case 'change_own_password':
            try {
                $self = Auth::currentUser();
                if (!$self) {
                    cashupay_status(401);
                    echo json_encode(['error' => 'Not authenticated']);
                    break;
                }
                $current = $_POST['current_password'] ?? '';
                $new     = $_POST['new_password'] ?? '';

                // Re-verify the current password to defeat session-hijack
                // attempts where the attacker doesn't know the current pass.
                $row = Database::fetchOne(
                    "SELECT password_hash FROM users WHERE id = ?",
                    [$self['id']]
                );
                if (!$row || !password_verify($current, $row['password_hash'])) {
                    cashupay_status(401);
                    echo json_encode(['error' => 'Current password is incorrect']);
                    break;
                }
                Auth::changePassword($self['id'], $new);
                echo json_encode(['success' => true]);
            } catch (\InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'reveal_store_seed':
            // Return a store's Cashu wallet seed phrase so the operator can
            // back it up. The onboarding wizard generates this silently and
            // shows it once on the completion screen; without this endpoint an
            // operator who missed it had no way to ever recover mint funds.
            //
            // The seed is spendable key material, so this is the most
            // sensitive read in the panel: admin-only, and gated on re-entering
            // the acting admin's own password, so a hijacked session alone is
            // not enough. The seed itself is never logged and never appears in
            // any bulk payload — only ever returned from here.
            Auth::requireAdmin();
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (!$storeId) {
                    throw new Exception('Store ID required');
                }
                if (!Auth::verifyCurrentUserPassword((string)($_POST['password'] ?? ''))) {
                    cashupay_status(401);
                    echo json_encode(['error' => 'Password is incorrect']);
                    break;
                }
                $store = Database::fetchOne(
                    "SELECT seed_phrase FROM stores WHERE id = ?",
                    [$storeId]
                );
                if (!$store) {
                    throw new Exception('Store not found');
                }
                $seed = (string)($store['seed_phrase'] ?? '');
                error_log(sprintf(
                    'CashuPayServer: recovery phrase revealed for store %s by user %s from %s',
                    $storeId, Auth::currentActorLabel(), Security::getClientIp()
                ));
                echo json_encode([
                    'success' => true,
                    // Empty when this store has no mint wallet (mints declined
                    // during setup) — the UI says so rather than showing blank.
                    'seedPhrase' => $seed,
                ]);
            } catch (Exception $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'set_recovery_email':
            // Set/clear the current user's recovery email. Powers the email
            // reset-link flow (only effective for admin accounts, which the
            // reset request looks up by email). Admin-gated: only admins have
            // a meaningful recovery path here.
            Auth::requireAdmin();
            try {
                $self = Auth::currentUser();
                if (!$self) {
                    cashupay_status(401);
                    echo json_encode(['error' => 'Not authenticated']);
                    break;
                }
                $email = trim($_POST['email'] ?? '');
                Auth::setUserEmail($self['id'], $email === '' ? null : $email);
                echo json_encode(['success' => true, 'email' => $email]);
            } catch (\InvalidArgumentException $e) {
                cashupay_status(400);
                echo json_encode(['error' => $e->getMessage()]);
            } catch (\RuntimeException $e) {
                cashupay_status(404);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        default:
            cashupay_status(400);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Serve the SPA HTML
$baseUrl = Config::getBaseUrl();
$isLoggedIn = Auth::isLoggedIn();
$currentUser = Auth::currentUser();   // null when not logged in
$isManaged = ManagedInstall::isManaged();
$currentRole = $currentUser['role'] ?? ($isLoggedIn ? Auth::ROLE_ADMIN : null);
$currentUsername = $currentUser['username'] ?? ($isLoggedIn ? 'admin' : '');
// Mechanism 2: surface the file-based reset on the lock screen when the trigger
// file is present and nobody is signed in.
$fileResetRequested = !$isLoggedIn && Auth::fileResetRequested();

// -----------------------------------------------------------------------------
// SPA view routing
//
// Every SPA view has its own URL slug (e.g. /admin/invoices). The view is
// parsed here so a refresh restores the operator's current page instead of
// always dropping them back on the dashboard.
//
// The view slug comes from $_SERVER['PATH_INFO'] (clean mode's front
// controller, router mode, Apache AcceptPathInfo) or, on hosts that route
// neither, from the ?view= query parameter — the form every host serves.
//
// Whether URLs GENERATED for this session are path-style or query-style is
// decided by Urls::adminUsesPathUrls(): path URLs only where the host
// provably routes them (clean mode, or this very request arrived with a
// PATH_INFO tail). Everywhere else — notably the WordPress-alongside install
// on a stock nginx host (Local WP), where /barebits/admin.php/dashboard
// falls through the web server into WordPress's themed 404 — views ride
// admin.php?view=… instead.
//
// Unknown or empty view: with path URLs, 302 to <base>/dashboard so /admin
// canonicalizes to /admin/dashboard and bookmarks of removed views still
// resolve sensibly; with query URLs, serve the dashboard directly (a
// path-style redirect is exactly what such hosts cannot serve).
// -----------------------------------------------------------------------------
const ADMIN_VIEWS = ['dashboard', 'invoices', 'customers', 'stores', 'products', 'settings', 'stats', 'log'];

$adminUsesPathUrls = Urls::adminUsesPathUrls($_SERVER['PATH_INFO'] ?? null, Config::getUrlMode());

$rawAdminView = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
if ($rawAdminView === '' && isset($_GET['view'])) {
    $rawAdminView = (string)$_GET['view'];
}

// Compute the base path the JS uses to build view URLs and to call back into
// admin.php for ?api=... requests: REQUEST_URI minus the PATH_INFO tail and
// any query string.
$reqPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$pi = (string)($_SERVER['PATH_INFO'] ?? '');
if ($pi !== '' && $pi !== '/' && substr($reqPath, -strlen($pi)) === $pi) {
    $reqPath = substr($reqPath, 0, -strlen($pi));
}
$adminBasePath = rtrim($reqPath, '/');
if ($adminBasePath === '') {
    // Fallback if REQUEST_URI was unexpectedly empty.
    $adminBasePath = rtrim(Urls::admin(), '/');
}

// Canonicalize: unknown or empty view → /admin/dashboard, preserving query —
// but only where path URLs provably route (see above). Query-URL hosts serve
// the dashboard directly: the request's own query string is already in place,
// and the client canonicalizes the visible URL via history.replaceState.
if (!in_array($rawAdminView, ADMIN_VIEWS, true)) {
    if ($adminUsesPathUrls) {
        $reqQuery = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        $location = $adminBasePath . '/dashboard' . ($reqQuery ? '?' . $reqQuery : '');
        header('Location: ' . $location);
        exit;
    }
    $rawAdminView = 'dashboard';
}

$adminView = $rawAdminView;

// The admin SPA ships its CSS/JS inline in this document, so a stale cached
// copy pins the old layout/styles until a hard refresh. Tell the browser to
// always revalidate the page shell.
header('Cache-Control: no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f23">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="BareBits">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
    <title>BareBits Admin</title>
    <link rel="manifest" href="<?= htmlspecialchars(Config::getBaseUrl()) ?>/manifest.json">
    <link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect fill='%230f0f23' width='100' height='100' rx='20'/><text x='50' y='70' font-size='60' text-anchor='middle'>⚡</text></svg>">
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
            --bg-card-hover: rgba(255, 255, 255, 0.08);
            --text-primary: #ffffff;
            --text-secondary: #a0aec0;
            --text-muted: #4a5568;
            --accent: #f7931a;
            --accent-hover: #e8820a;
            --success: #48bb78;
            --error: #e53e3e;
            --warning: #ed8936;
            --border: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
        }

        /* Lock Screen */
        .lock-screen {
            position: fixed;
            inset: 0;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 2rem;
        }

        .lock-screen.hidden {
            display: none;
        }

        .lock-logo {
            width: 280px;
            max-width: 70vw;
            height: auto;
            margin-bottom: 2rem;
        }

        .lock-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .lock-subtitle {
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

        .password-fallback input {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 1rem;
            width: 100%;
            margin-bottom: 1rem;
        }

        .password-fallback input:focus {
            outline: none;
            border-color: var(--accent);
        }

        /* App Shell */
        .app {
            display: none;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
        }

        .app.visible {
            display: flex;
        }

        /* Header */
        .header {
            position: sticky;
            top: 0;
            background: rgba(15, 15, 35, 0.9);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            z-index: 100;
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
            min-width: 0;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .header-logo {
            height: 22px;
            width: auto;
            display: block;
        }

        .header-store-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            min-width: 0;
            max-width: 350px;
        }

        .store-selector-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .header-store-selector select {
            flex: 1;
            min-width: 0;
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            background-color: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            text-overflow: ellipsis;
        }

        /* Style the open dropdown list to match the dark theme. <option>
           elements inherit OS/browser defaults regardless of the <select>'s
           own styling, so without this the open list shows light-on-light
           and is unreadable. */
        .header-store-selector select option {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }

        .header-store-selector select:focus {
            outline: none;
            border-color: var(--accent);
        }

        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        /* Mobile responsive header */
        @media (max-width: 500px) {
            .header {
                padding: 0.5rem;
            }

            .header-left {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .header-store-selector {
                max-width: none;
                width: 100%;
                order: 2;
            }

            .header-title {
                order: 1;
            }

            .store-selector-label {
                display: none;
            }

            /* The fixed bottom nav must fit every item across a narrow viewport
               without overflowing (which would push the last items off-screen).
               Equal flex basis + min-width:0 lets the items shrink to share the
               width as more views (e.g. Customers) are added. */
            .nav-item {
                flex: 1 1 0;
                min-width: 0;
                padding: 0.5rem 0.25rem;
                font-size: 0.68rem;
            }
            .nav-item svg {
                width: 22px;
                height: 22px;
            }
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .icon-btn:hover {
            background: var(--bg-card-hover);
        }

        /* User dropdown menu (top-right account control) */
        .user-menu {
            position: relative;
        }

        .user-menu-panel {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            min-width: 180px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.5rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            z-index: 200;
        }

        .user-menu-username {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border);
            margin-bottom: 0.25rem;
            word-break: break-all;
        }

        .user-menu-item {
            display: block;
            width: 100%;
            padding: 0.5rem 0.75rem;
            background: transparent;
            border: none;
            color: var(--text-primary);
            text-align: left;
            font-size: 0.9rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .user-menu-item:hover {
            background: var(--bg-card-hover);
        }

        /* Navigation */
        .nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(15, 15, 35, 0.95);
            backdrop-filter: blur(20px);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-around;
            padding: 0.5rem 0;
            padding-bottom: max(0.5rem, env(safe-area-inset-bottom));
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.75rem;
            transition: color 0.2s;
            cursor: pointer;
            background: none;
            border: none;
        }

        .nav-item.active {
            color: var(--accent);
        }

        .nav-item svg {
            width: 24px;
            height: 24px;
        }

        /* Main Content */
        .main {
            flex: 1;
            padding: 1rem;
            padding-bottom: calc(5rem + env(safe-area-inset-bottom));
            /* Fill the full available width (right of the fixed sidebar on
               desktop) rather than a centered, boxed column. */
            max-width: none;
            margin: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
        }

        /* Views */
        .view {
            display: none;
        }

        .view.active {
            /* Flex column so the view fills the full height of .main (which is
               itself flex:1 inside the .app column). Without this the view is
               only as tall as its content, leaving empty space below on short
               pages such as Settings. */
            display: flex;
            flex-direction: column;
            flex: 1;
            /* Top-align so content fills from the top of the viewport rather
               than floating in the vertical centre. */
            margin: 0;
            width: 100%;
        }

        /* List-style views (dashboard / invoices / products): the primary
           content card absorbs the remaining vertical space so a short list
           fills the viewport instead of leaving a gap below it. min-height
           defaults to auto, so a long list still grows the card and scrolls. */
        .view-fill {
            flex: 1 1 auto;
        }

        /* Stacked-card views (settings / stats / stores): pin a trailing
           footer/version line to the bottom of the viewport. The view flexes
           to full height above it while the cards keep their natural size. */
        .view-footer {
            margin-top: auto;
        }

        /* Settings: on desktop the card list scrolls inside its own region
           (.settings-scroll) while the version footer stays fixed at the bottom
           and always visible. An inner scroll region needs a definite height,
           so the app is capped to the viewport ONLY while Settings is the active
           view (via :has); every other view keeps normal whole-page scrolling.
           On mobile this rule does not apply — .settings-scroll is a plain block
           and the footer keeps margin-top:auto, scrolling with the page above
           the fixed bottom nav. */
        @media (min-width: 768px) {
            .app:has(#view-settings.active) {
                height: 100vh;
                height: 100dvh;
            }
            .app:has(#view-settings.active) .main {
                min-height: 0;
            }
            #view-settings.active {
                min-height: 0;
            }
            #view-settings.active .settings-scroll {
                flex: 1 1 auto;
                min-height: 0;
                overflow-y: auto;
            }
            #view-settings.active .view-footer {
                flex-shrink: 0;
                margin-top: 0;
                border-top: 1px solid var(--border);
            }
        }

        /* Generic visibility helper — used by the Users / Settings UI so JS
           can toggle whole cards/buttons by classList. Lock-screen has its
           own .lock-screen.hidden rule above; this is the catch-all. */
        .hidden {
            display: none !important;
        }

        /* Managed single-shop installs (ManagedInstall::isManaged) hide the
           surfaces the shop platform owns and the account plumbing (login is
           automatic via SSO). Server-rendered on purpose: a class the merchant
           could untoggle in DevTools only hides UI, never capability. */
        .managed-hidden { display: none !important; }

        /* Balance Card */
        .balance-card {
            background: linear-gradient(135deg, var(--accent) 0%, #d97706 100%);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
        }

        .balance-label {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .balance-amount {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .balance-unit {
            font-size: 0.875rem;
            opacity: 0.8;
        }

        .balance-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .balance-btn {
            flex: 1;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .balance-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-weight: 600;
        }

        .card-body {
            padding: 1rem;
        }

        /* Collapsible cards (default open; add .collapsed to start closed) */
        .card.collapsible > .card-header {
            cursor: pointer;
            user-select: none;
            justify-content: flex-start;
            gap: 0.5rem;
        }
        .card.collapsible > .card-header > .card-title {
            margin-right: auto;
        }
        .card.collapsible > .card-header::after {
            content: "";
            width: 0.55rem;
            height: 0.55rem;
            border-right: 2px solid var(--text-secondary);
            border-bottom: 2px solid var(--text-secondary);
            transform: rotate(45deg);
            transition: transform 0.2s;
            flex-shrink: 0;
            margin-bottom: 2px;
        }
        .card.collapsible.collapsed > .card-header::after {
            transform: rotate(-45deg);
            margin-bottom: 0;
        }
        .card.collapsible.collapsed > :not(.card-header) {
            display: none !important;
        }

        /* Collapsible subsection (e.g. Advanced inside a card) */
        .subsection-toggle {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
            user-select: none;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.5rem 0;
            color: var(--text-secondary);
        }
        .subsection-toggle::after {
            content: "";
            width: 0.5rem;
            height: 0.5rem;
            border-right: 2px solid var(--text-secondary);
            border-bottom: 2px solid var(--text-secondary);
            transform: rotate(45deg);
            transition: transform 0.2s;
            margin-bottom: 2px;
        }
        .subsection.collapsed > .subsection-toggle::after {
            transform: rotate(-45deg);
            margin-bottom: 0;
        }
        .subsection.collapsed > .subsection-body {
            display: none;
        }

        /* Auto-cashout column selector */
        .aw-title {
            font-weight: 600;
            font-size: 0.95rem;
            margin: 0 0 0.6rem 0;
        }
        .aw-cols {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .aw-col {
            flex: 1 1 0;
            min-width: 190px;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 0.85rem;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .aw-col:hover { border-color: var(--text-secondary); }
        .aw-col.selected {
            border-color: var(--accent);
            background: rgba(247, 147, 26, 0.08);
        }
        .aw-col-head {
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .aw-col-name { font-weight: 600; font-size: 0.95rem; }
        .aw-col-badge {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .aw-col-desc { font-size: 0.8rem; color: var(--text-secondary); line-height: 1.35; }
        /* Keep the Strike/CoinOS links white — the default link colour is hard
           to read against the column's tinted background. */
        .aw-col a { color: #fff; text-decoration: underline; }
        .aw-col ul { margin: 0.1rem 0 0 0; padding-left: 1.1rem; font-size: 0.78rem; color: var(--text-secondary); }
        .aw-col li { margin-bottom: 0.12rem; }
        .aw-col-check {
            position: absolute;
            top: 0.55rem;
            right: 0.7rem;
            opacity: 0;
            color: var(--accent);
            font-weight: 700;
        }
        .aw-col.selected .aw-col-check { opacity: 1; }

        /* Lists */
        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.2s;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item:hover {
            background: var(--bg-card-hover);
        }

        .list-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .list-icon.success { background: rgba(72, 187, 120, 0.2); }
        .list-icon.pending { background: rgba(247, 147, 26, 0.2); }
        .list-icon.expired { background: rgba(229, 62, 62, 0.2); }

        .list-content {
            flex: 1;
            min-width: 0;
        }

        .list-title {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .list-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .list-amount {
            text-align: right;
        }

        .list-amount-value {
            font-weight: 600;
        }

        .list-amount-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }

        .list-amount-status.settled { background: rgba(72, 187, 120, 0.2); color: var(--success); }
        .list-amount-status.new { background: rgba(247, 147, 26, 0.2); color: var(--accent); }
        .list-amount-status.expired { background: rgba(229, 62, 62, 0.2); color: var(--error); }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        /* Dark dropdown options so the open <select> menu stays legible.
           Mirrors the .header-store-selector option rule, but applies to
           every <select> on the page so future dropdowns don't have to
           opt in individually. */
        select option {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }

        .form-help {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        /* Inline info-tip: a small "i" badge with a popup on hover/focus. */
        .info-tip {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            margin-left: 0.4rem;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
            color: var(--text-secondary);
            font-size: 10px;
            font-weight: 700;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            cursor: help;
            user-select: none;
            vertical-align: middle;
        }
        .info-tip:hover, .info-tip:focus-within {
            background: rgba(255,255,255,0.2);
            color: var(--text-primary);
        }
        .info-tip .info-tip-text {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: 130%;
            left: 50%;
            transform: translateX(-50%);
            min-width: 260px;
            max-width: 320px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.6rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 400;
            line-height: 1.4;
            color: var(--text-primary);
            text-align: left;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            z-index: 10;
            transition: opacity 0.15s;
            pointer-events: none;
        }
        .info-tip:hover .info-tip-text,
        .info-tip:focus-within .info-tip-text {
            visibility: visible;
            opacity: 1;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn:hover {
            background: var(--accent-hover);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-card-hover);
        }

        .btn-danger {
            background: var(--error);
        }

        .btn-full {
            width: 100%;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-icon:hover {
            background: rgba(255,255,255,0.15);
        }

        /* Toggle */
        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
        }

        .toggle {
            position: relative;
            width: 50px;
            height: 28px;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--text-muted);
            border-radius: 28px;
            transition: background 0.3s;
        }

        .toggle-slider::before {
            position: absolute;
            content: "";
            width: 22px;
            height: 22px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: transform 0.3s;
        }

        .toggle input:checked + .toggle-slider {
            background: var(--accent);
        }

        .toggle input:checked + .toggle-slider::before {
            transform: translateX(22px);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 200;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        /* The forgot-password modal is opened from the lock screen, which sits
           at z-index 1000 with an opaque background. Without a higher stacking
           order the modal would render *behind* the lock screen (invisible).
           Lift it (and its scrim) above the lock screen. */
        #modal-forgot-password {
            z-index: 1100;
        }

        .modal {
            background: var(--bg-secondary);
            border-radius: 24px 24px 0 0;
            padding: 1.5rem;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(100%);
            transition: transform 0.3s;
        }

        .modal-overlay.visible .modal {
            transform: translateY(0);
        }

        .modal-handle {
            width: 40px;
            height: 4px;
            background: var(--text-muted);
            border-radius: 2px;
            margin: 0 auto 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        /* Token Display */
        .token-display {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 1rem;
            font-family: monospace;
            font-size: 0.75rem;
            word-break: break-all;
            margin: 1rem 0;
            max-height: 150px;
            overflow-y: auto;
        }

        /* QR Container in Modal */
        .modal-qr {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 1.5rem 0;
            width: 100%;
            box-sizing: border-box;
        }

        /* Store Info Items */
        .store-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }

        .store-info-item:last-child {
            border-bottom: none;
        }

        .store-info-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .store-info-value {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
            word-break: break-all;
            text-align: right;
            max-width: 60%;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: calc(5rem + env(safe-area-inset-bottom) + 1rem);
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-size: 0.875rem;
            z-index: 300;
            transition: transform 0.3s, opacity 0.3s, visibility 0.3s;
            /* Keep the (empty) toast fully hidden until shown — the transform
               alone left a small empty rounded box floating at the bottom. */
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .toast.success {
            border-color: var(--success);
        }

        .toast.error {
            border-color: var(--error);
        }

        .toast.info {
            border-color: #3b82f6;
        }

        /* Loading */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (min-width: 768px) {
            .nav {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                right: auto;
                width: 80px;
                flex-direction: column;
                justify-content: flex-start;
                padding: 1rem 0;
                border-top: none;
                border-right: 1px solid var(--border);
            }

            /* Reserve the fixed sidebar's width on the whole app column so the
               header and content sit to its right; content then centers within
               the remaining space (margin:0 auto on .main) instead of being
               pinned left by a margin-left offset. */
            .app {
                padding-left: 80px;
            }

            .main {
                padding-bottom: 2rem;
            }

            .modal {
                border-radius: 24px;
                margin: auto;
            }
        }

        /* Stats dashboard */
        .stats-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 0.35rem 0;
            gap: 1rem;
        }
        .stats-stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        .stats-stat-value {
            font-weight: 600;
            text-align: right;
            white-space: nowrap;
        }
        .stats-stat-fiat {
            color: var(--text-secondary);
            font-weight: normal;
            margin-left: 0.35rem;
            font-size: 0.85em;
        }
        .stats-range-btn.active,
        .stats-unit-btn.active {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }
        .stats-amount-abbr {
            text-decoration: underline dotted;
            cursor: help;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        .stats-table th,
        .stats-table td {
            text-align: left;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .stats-table th {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stats-table td.truncate {
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Invoices table: rail/swap chips, monospace truncation, status colors. */
        .inv-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .inv-table th,
        .inv-table td {
            text-align: left;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .inv-table th {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        .inv-table tbody tr:hover {
            background: var(--bg-hover, rgba(255,255,255,0.02));
        }
        .inv-table td.inv-amount {
            text-align: right;
            white-space: nowrap;
        }
        .inv-chip {
            display: inline-block;
            padding: 0.1rem 0.5rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            background: rgba(255,255,255,0.08);
            color: var(--text-secondary);
            white-space: nowrap;
        }
        .inv-chip.status-Settled  { background: rgba(72, 187, 120, 0.2); color: #48bb78; }
        .inv-chip.status-New      { background: rgba(247, 147, 26, 0.2); color: #f7931a; }
        .inv-chip.status-Processing { background: rgba(247, 147, 26, 0.2); color: #f7931a; }
        .inv-chip.status-Provisional { background: rgba(159, 122, 234, 0.2); color: #9f7aea; }
        .inv-chip.status-Expired  { background: rgba(229, 62, 62, 0.2); color: #e53e3e; }
        .inv-chip.status-Invalid  { background: rgba(229, 62, 62, 0.2); color: #e53e3e; }
        .inv-chip.swap-confirming { background: rgba(247, 147, 26, 0.2); color: #f7931a; }
        .inv-chip.swap-waiting    { background: rgba(160, 174, 192, 0.2); color: var(--text-secondary); }
        .inv-chip.swap-failed     { background: rgba(229, 62, 62, 0.2); color: #e53e3e; }
        .inv-fee-badge {
            display: inline-block;
            padding: 0.1rem 0.5rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            background: rgba(159, 122, 234, 0.2);
            color: #9f7aea;
            white-space: nowrap;
            cursor: help;
        }
        .inv-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.78rem;
        }
        .inv-mono a {
            color: var(--accent);
            text-decoration: none;
        }
        .inv-mono a:hover { text-decoration: underline; }
        .inv-mono a.inv-copy { cursor: copy; }
        .inv-filter-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0 0 0.75rem 0;
        }
        .inv-filter-row label {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        /* Match the header store selector so the dropdown is readable and
           consistent with the rest of the admin UI. */
        .inv-filter-row select {
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            background-color: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
        }
        .inv-filter-row select option {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }
        /* Prev/next pagination footer shared by the invoices + customers lists. */
        .list-pagination {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 0.75rem 0 0;
        }
        .list-pagination:empty {
            display: none;
        }
        .list-pagination .list-pagination-info {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        .list-pagination button[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .inv-filter-row select:focus {
            outline: none;
            border-color: var(--accent);
        }
        /* Short invoice id + ⓘ tooltip trigger sit inline on one row. */
        .inv-id-row {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.8rem;
        }

        /* Invoice ID tooltip: a small ⓘ next to the truncated id reveals
           the full id + a copy button on hover/focus. */
        .inv-id-tip {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            color: var(--text-secondary);
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            user-select: none;
        }
        .inv-id-tip:hover,
        .inv-id-tip:focus-within {
            background: rgba(255,255,255,0.16);
            color: var(--text-primary);
        }
        .inv-id-tip .inv-id-pop {
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.1s ease-in;
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.5rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 6px;
            white-space: nowrap;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.75rem;
            color: var(--text-primary);
            box-shadow: 0 6px 16px rgba(0,0,0,0.4);
        }
        .inv-id-tip:hover .inv-id-pop,
        .inv-id-tip:focus-within .inv-id-pop {
            visibility: visible;
            opacity: 1;
        }
        .inv-id-copy {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0;
            display: inline-flex;
            align-items: center;
        }
        .inv-id-copy:hover { color: var(--accent); }
    </style>
</head>
<body>
    <!-- Lock Screen -->
    <div class="lock-screen<?= $isLoggedIn ? ' hidden' : '' ?>" id="lock-screen">
        <img class="lock-logo" src="<?= htmlspecialchars(Urls::assets('img/barebits-logo.svg')) ?>" alt="BareBits">
        <div class="lock-subtitle">Enter your password</div>

        <div class="password-fallback" id="password-fallback">
            <input type="text" id="username-input" placeholder="Username"
                   value="admin" autocomplete="username">
            <input type="password" id="password-input" placeholder="Password"
                   autocomplete="current-password">
            <button class="btn btn-full" id="password-submit">Unlock</button>
            <button class="btn-link" id="forgot-password-link" type="button"
                    style="background:none;border:0;color:var(--text-secondary);text-decoration:underline;cursor:pointer;margin-top:0.75rem;font-size:0.85rem;">
                Forgot password?
            </button>
        </div>

        <?php if ($fileResetRequested): ?>
        <!-- Mechanism 2: a reset trigger file (data/reset-admin-password) was
             detected. Let the operator set a new admin password right here; the
             file is deleted once the new password is saved. -->
        <div class="file-reset-box" id="file-reset-box"
             style="margin-top:1.25rem;max-width:360px;width:100%;background:rgba(247,147,26,0.1);border:1px solid rgba(247,147,26,0.4);border-radius:10px;padding:1rem;">
            <div style="font-weight:600;margin-bottom:0.5rem;">Password-reset file detected</div>
            <p style="color:var(--text-secondary);font-size:0.85rem;margin:0 0 0.75rem;">
                Set a new password for the <strong>admin</strong> account. The reset file will be removed automatically once this succeeds.
            </p>
            <input type="password" id="file-reset-pw" placeholder="New password" autocomplete="new-password" style="margin-bottom:0.5rem;">
            <input type="password" id="file-reset-pw2" placeholder="Confirm new password" autocomplete="new-password" style="margin-bottom:0.5rem;">
            <button class="btn btn-full" id="file-reset-submit">Set new password</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- App -->
    <div class="app" id="app">
        <header class="header">
            <div class="header-left">
                <div class="header-title">
                    <img class="header-logo" src="<?= htmlspecialchars(Urls::assets('img/barebits-logo.svg')) ?>" alt="BareBits">
                    <span id="header-text">Dashboard</span>
                </div>
                <div class="header-store-selector<?= $isManaged ? ' managed-hidden' : '' ?>" id="header-store-selector">
                    <span class="store-selector-label">Selected Store:</span>
                    <select id="store-select">
                        <option value="">Loading stores...</option>
                    </select>
                </div>
            </div>
            <div class="header-actions">
                <button class="icon-btn" id="refresh-btn" title="Refresh">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M23 4v6h-6M1 20v-6h6"></path>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                </button>
                <div class="user-menu<?= $isManaged ? ' managed-hidden' : '' ?>" id="user-menu">
                    <button class="icon-btn" id="user-btn" title="Account">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </button>
                    <div class="user-menu-panel hidden" id="user-menu-panel" role="menu">
                        <div class="user-menu-username" id="user-menu-username"></div>
                        <button class="user-menu-item" id="user-menu-logout" role="menuitem">Log out</button>
                    </div>
                </div>
            </div>
        </header>

        <main class="main">
            <!-- Global banner: on-chain static-address payments awaiting manual confirmation.
                 Shown across all admin views (dashboard, invoices, settings) so the
                 issue isn't missed. Hidden by default; populated by JS from
                 dashboardData.onchain.needsManualConfirmation. -->
            <div id="onchain-manual-banner" class="hidden" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.45); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.9rem; display: flex; align-items: center; gap: 0.75rem;" data-admin-only="true">
                <span style="flex-shrink: 0; font-size: 1.1rem; line-height: 1.2;">&#9888;</span>
                <span style="flex: 1;">
                    <strong id="onchain-manual-banner-text">On-chain payment(s) need manual confirmation.</strong>
                    <span style="display: block; color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.15rem;">
                        Multiple invoices matched the same incoming amount on your static address. Resolve in the Invoices view.
                    </span>
                </span>
                <a href="#" class="btn btn-secondary js-goto-invoices" style="padding: 0.25rem 0.75rem; font-size: 0.8rem; flex-shrink: 0;">Open invoices</a>
            </div>

            <!-- Dashboard View -->
            <div class="view active" id="view-dashboard">
                <!-- Update-available banner. Non-dismissible by design: an
                     available update may carry critical security fixes, so it
                     stays until the operator actually updates. Populated by JS
                     from update_status `available`. Admin-only. The "Update now"
                     button kicks off the crash-isolated manual update and the
                     banner switches to a live progress line. -->
                <div id="update-available-banner" class="hidden" style="background: rgba(247, 147, 26, 0.12); border: 1px solid rgba(247, 147, 26, 0.4); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.9rem; display: flex; align-items: flex-start; gap: 0.75rem;" data-admin-only="true">
                    <span style="flex-shrink: 0; font-size: 1.1rem; line-height: 1.2;">&#9888;</span>
                    <span style="flex: 1;">
                        <strong id="update-available-text">A software update is available.</strong>
                        <span style="display: block; color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.15rem;">
                            Updates deliver new features and critical security enhancements. Please don't delay — apply it as soon as you can to keep your server secure.
                        </span>
                        <span id="update-available-progress" class="hidden" style="display: block; margin-top: 0.4rem; font-size: 0.85rem;"></span>
                    </span>
                    <button id="btn-update-now-banner" class="btn" style="padding: 0.3rem 0.9rem; font-size: 0.85rem; flex-shrink: 0;">Update now</button>
                </div>
                <div id="reliability-banner" class="hidden" style="background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.5); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.9rem; display: flex; align-items: center; gap: 0.75rem;" data-admin-only="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;">
                        <path d="M12 9v4M12 17h.01"></path>
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    </svg>
                    <span id="reliability-banner-text" style="flex: 1;"></span>
                    <a href="#" class="btn btn-secondary js-goto-settings" style="padding: 0.25rem 0.75rem; font-size: 0.8rem;">Open settings</a>
                </div>
                <div id="cron-stale-banner" class="hidden" style="background: rgba(247, 147, 26, 0.12); border: 1px solid rgba(247, 147, 26, 0.4); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.9rem; display: flex; align-items: flex-start; gap: 0.75rem;" data-admin-only="true">
                    <span style="flex-shrink: 0; font-size: 1.1rem; line-height: 1.2;">⚡</span>
                    <span style="flex: 1;">
                        <strong style="display: block; margin-bottom: 0.15rem;">Heads up — no external cron in 24h+</strong>
                        <span style="color: var(--text-secondary); font-size: 0.85rem;">Not required, but a one-line cron entry makes payment confirmations, auto-cashouts, and fee settlements much faster.</span>
                    </span>
                    <a href="#" class="btn btn-secondary js-goto-settings" style="padding: 0.25rem 0.75rem; font-size: 0.8rem; flex-shrink: 0;">Settings · Copy cron URL</a>
                    <button id="btn-dismiss-cron-stale" aria-label="Dismiss" style="background: transparent; border: 0; color: var(--text-secondary); cursor: pointer; font-size: 1.2rem; line-height: 1; padding: 0 0.25rem; flex-shrink: 0;">×</button>
                </div>
                <div class="balance-card">
                    <div class="balance-label">Balance stored in Cashu Mint</div>
                    <div class="balance-amount" id="balance-amount">---</div>
                    <div class="balance-unit" id="balance-unit">sats</div>
                    <div class="balance-fiat" id="balance-fiat" style="display:none; margin-top:0.5rem; color:#000; font-size:1rem;"></div>
                    <div class="balance-actions">
                        <button class="balance-btn" id="btn-withdraw">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12l7-7 7 7"></path>
                            </svg>
                            Withdraw
                        </button>
                        <button class="balance-btn" id="btn-request">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                            Create invoice
                        </button>
                        <button class="balance-btn" id="btn-request-simple">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path>
                            </svg>
                            Create invoice (simple)
                        </button>
                    </div>
                </div>

                <div class="card view-fill">
                    <div class="card-header">
                        <div class="card-title">Recent Invoices</div>
                    </div>
                    <div id="recent-invoices">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                </div>
            </div>

            <!-- Invoices View -->
            <div class="view" id="view-invoices">
                <!-- Static-address manual-confirmation queue. Hidden when empty.
                     Each row shows the invoice plus the candidate (txid, vout)
                     pairs that matched its amount. Admin picks one to attribute. -->
                <div class="card hidden" id="card-onchain-manual" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title" style="color: #f59e0b;">&#9888; Payments awaiting manual confirmation</div>
                    </div>
                    <div class="card-body">
                        <p class="form-help" style="margin-bottom: 0.75rem;">
                            These invoices are in static-address mode and matched the same
                            incoming amount as one or more other open invoices, so the
                            server cannot safely auto-attribute the payment. Pick the
                            invoice each candidate transaction belongs to.
                        </p>
                        <div id="onchain-manual-list">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                </div>
                <!-- Self-serve link banner. Surfaces the public /pay/<store> link
                     for the selected store so the operator knows the feature
                     exists and can share it. Shown only when self-serve is
                     effectively enabled for the current store; the toggle itself
                     lives in the store's Settings card. -->
                <div class="card hidden" id="card-selfserve-link">
                    <div class="card-body" style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                        <span style="flex:1 1 240px; min-width:200px;">
                            &#9889; <strong>Self-serve payments are on.</strong>
                            Share this link so customers can create and pay their own invoice:
                        </span>
                        <input type="text" class="form-input" id="invoices-selfserve-link" readonly
                               style="flex:2 1 260px; min-width:200px; font-size:0.8rem;">
                        <button class="btn btn-secondary" id="btn-copy-invoices-selfserve-link" style="width:auto; flex:0 0 auto;">Copy</button>
                        <a class="btn" id="invoices-selfserve-open" target="_blank" rel="noopener" style="width:auto; flex:0 0 auto;">Open</a>
                    </div>
                </div>
                <div class="card view-fill">
                    <div class="card-header">
                        <div class="card-title">All Invoices</div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-secondary" id="btn-export-invoices-csv">Export CSV</button>
                            <button class="btn" id="btn-new-invoice">+ New Invoice</button>
                        </div>
                    </div>
                    <div class="inv-filter-row">
                        <label for="invoice-status-filter">Status:</label>
                        <select id="invoice-status-filter">
                            <option value="">All</option>
                            <option value="New">New</option>
                            <option value="Processing">Processing</option>
                            <option value="Settled">Paid</option>
                            <option value="Expired">Expired</option>
                            <option value="Invalid">Invalid</option>
                        </select>
                        <label for="invoice-store-filter">Store:</label>
                        <select id="invoice-store-filter">
                            <option value="__all__">All stores</option>
                        </select>
                    </div>
                    <div id="all-invoices">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                    <div id="invoices-pagination" class="list-pagination"></div>
                </div>
            </div>

            <!-- Log View: unified recent-issues feed (admin-only). Endpoint
                 failures (NWC / noffer / LNURL) from admin_event_log merged
                 with mint reliability events, failed webhook deliveries,
                 failed notification emails, and swap/sweep errors. -->
            <div class="view" id="view-log">
                <div class="card view-fill">
                    <div class="card-header">
                        <div class="card-title">Recent Issues</div>
                        <button class="btn btn-secondary" id="btn-refresh-log">Refresh</button>
                    </div>
                    <div class="inv-filter-row">
                        <label for="log-category-filter">Category:</label>
                        <select id="log-category-filter">
                            <option value="">All</option>
                            <option value="nwc">NWC wallet</option>
                            <option value="noffer">Noffer</option>
                            <option value="lnurl">Lightning address</option>
                            <option value="mint">Mint</option>
                            <option value="webhook">Webhook</option>
                            <option value="email">Email</option>
                            <option value="swap">Swap</option>
                            <option value="sweep">Sweep</option>
                        </select>
                        <label for="log-store-filter">Store:</label>
                        <select id="log-store-filter">
                            <option value="__all__">All stores</option>
                        </select>
                    </div>
                    <div id="admin-log-list">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                    <div id="admin-log-pagination" class="list-pagination"></div>
                </div>
            </div>

            <!-- Customers View: aggregated list of customer emails captured on
                 the payment screen. Admin-only (same gate as the API). -->
            <div class="view" id="view-customers">
                <div class="card view-fill">
                    <div class="card-header">
                        <div class="card-title">Customers</div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-secondary" id="btn-export-customers-csv">Export CSV</button>
                        </div>
                    </div>
                    <div class="inv-filter-row">
                        <label for="customer-subscription-filter">Newsletter:</label>
                        <select id="customer-subscription-filter">
                            <option value="">All</option>
                            <option value="subscribed">Subscribed</option>
                            <option value="unsubscribed">Not subscribed</option>
                        </select>
                        <label for="customer-store-filter">Store:</label>
                        <select id="customer-store-filter">
                            <option value="__all__">All stores</option>
                        </select>
                    </div>
                    <div id="all-customers">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                    <div id="customers-pagination" class="list-pagination"></div>
                </div>
            </div>

            <!-- Store Settings View -->
            <div class="view" id="view-stores">
                <div id="store-settings-content">
                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Store Info</div>
                            <button class="btn btn-secondary" id="btn-edit-store">Edit</button>
                        </div>
                        <div class="card-body">
                            <div class="store-info-item">
                                <span class="store-info-label">Store Name</span>
                                <span class="store-info-value" id="store-settings-name">-</span>
                            </div>
                            <div class="store-info-item">
                                <span class="store-info-label">Store ID</span>
                                <span class="store-info-value" id="store-settings-id">-</span>
                            </div>
                            <div class="store-info-item">
                                <span class="store-info-label">Mint URL</span>
                                <span class="store-info-value" id="store-settings-mint">-</span>
                            </div>
                            <div class="store-info-item" id="store-info-selfserve-row" style="display:none;">
                                <span class="store-info-label">Self-serve link</span>
                                <span class="store-info-value" style="display:flex; gap:0.4rem; align-items:center; max-width:70%;">
                                    <input type="text" class="form-input" id="store-info-selfserve-link" readonly
                                           style="flex:1; min-width:0; font-size:0.8rem; padding:0.25rem 0.4rem;">
                                    <button type="button" class="btn btn-secondary" id="btn-copy-store-info-selfserve"
                                            style="width:auto; flex:0 0 auto; padding:0.25rem 0.6rem;">Copy</button>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card collapsible" id="card-lightning-payments">
                        <div class="card-header">
                            <div class="card-title">Lightning payments</div>
                        </div>
                        <div class="card-body">
                            <?php
                            // Shared by this card and the cashout / on-chain /
                            // swap cards below it in this settings view:
                            // features that run EC math (xpub derivation, swap
                            // signing) are dead on a host without GMP, so each
                            // affected card says so instead of failing on save.
                            // The CLINK client has the same dependency
                            // (Schnorr-signed Nostr requests), so the noffer
                            // section below gets its own gate, as does NWC.
                            // Cashu itself runs on GMP or BCMath — only when
                            // neither is usable is the cashout card dead.
                            require_once __DIR__ . '/includes/onchain/wallet.php';
                            require_once __DIR__ . '/includes/clink/client.php';
                            require_once __DIR__ . '/includes/nwc/client.php';
                            require_once __DIR__ . '/includes/cashu_env.php';
                            $gmpEnvError = OnchainWallet::environmentError();
                            $nofferEnvError = ClinkClient::environmentError();
                            $nwcEnvError = NwcClient::environmentError();
                            $cashuEnvError = CashuEnv::environmentError();
                            ?>
                            <p class="form-help" style="margin-top:0;">
                                Lightning payment paths are tried in the following order &mdash;
                                you can use multiple paths: Strike API, LNURL/lightning address,
                                NWC, noffer.
                            </p>

                            <div class="form-group" id="auto-melt-strike-group">
                                <label class="form-label">Strike API keys (priority order)</label>
                                <p class="form-help">
                                    Take Lightning payments straight into your
                                    <a href="https://strike.me" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">Strike</a>
                                    account. Strike lightning addresses (&hellip;@strike.me)
                                    don&rsquo;t support LUD-21 payment verification and can&rsquo;t
                                    be used in the Lightning Addresses list below &mdash; an API key
                                    works fully, and is tried <em>first</em> when generating
                                    invoices. Create a key in the
                                    <a href="https://dashboard.strike.me/" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">Strike dashboard</a>
                                    (API Keys section) with <em>only</em> the
                                    <strong>create invoices</strong>, <strong>generate invoice
                                    quotes</strong> and <strong>read invoices</strong> scopes
                                    (plus <strong>create receive requests</strong> if you tick the
                                    on-chain option below) &mdash; such a key cannot spend or
                                    withdraw funds. New keys are tested with a 1-sat test invoice
                                    when you save (it&rsquo;s never paid). Saved keys show only a
                                    masked label; the key stays on the server.
                                </p>
                                <div id="auto-melt-strike-list"></div>
                                <button type="button" class="btn btn-secondary" id="btn-add-strike" style="margin-top: 0.5rem;">
                                    + Add Strike API key
                                </button>
                                <label style="display:block; margin-top: 0.75rem; font-size: 0.9rem;">
                                    <input type="checkbox" id="strike-onchain-checkbox">
                                    Also accept on-chain payments via Strike
                                </label>
                                <p class="form-help" style="margin: 0.35rem 0 0 1.5rem;">
                                    Each invoice&rsquo;s Bitcoin address is then created in your
                                    Strike account (your xpub / static address stays the fallback
                                    when Strike is unreachable). The key additionally needs the
                                    <strong>create receive requests</strong> scope
                                    (<code>partner.receive-request.create</code>); it&rsquo;s tested
                                    when you save. Mainnet only. This setting also appears in the
                                    On-chain Bitcoin payments card.
                                </p>
                            </div>

                            <div class="form-group" id="auto-melt-address-group">
                                <label class="form-label">Lightning Addresses (priority order)</label>
                                <p class="form-help">
                                    Invoices are requested from the first address. If a host is down
                                    or can&rsquo;t produce an invoice, the next address is tried
                                    automatically. Use the arrows to set priority.
                                    e.g., yourname@walletofsatoshi.com, yourname@blink.sv
                                </p>
                                <div id="auto-melt-address-list"></div>
                                <button type="button" class="btn btn-secondary" id="btn-add-ln-address" style="margin-top: 0.5rem;">
                                    + Add address
                                </button>
                            </div>

                            <div class="form-group" id="auto-melt-nwc-group"
                                 <?= $nwcEnvError !== null ? 'data-env-error="1"' : '' ?>>
                                <label class="form-label">NWC connections (priority order)</label>
                                <?php if ($nwcEnvError !== null): ?>
                                    <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                        &#9888; <strong>NWC is unavailable on this server.</strong>
                                        <?= htmlspecialchars($nwcEnvError) ?>
                                        Existing entries stay saved (and can be removed) but are
                                        skipped at checkout until then.
                                    </div>
                                <?php elseif (($nwcNotice = NwcClient::environmentNotice()) !== null): ?>
                                    <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(59,130,246,0.10); border:1px solid rgba(59,130,246,0.35); font-size:0.82rem;">
                                        &#9432; <?= htmlspecialchars($nwcNotice) ?>
                                    </div>
                                <?php endif; ?>
                                <p class="form-help">
                                    Nostr Wallet Connect (NIP-47) lets BareBits request Lightning
                                    invoices straight from your own wallet and confirm payment
                                    automatically. Tried <em>after</em> the lightning addresses
                                    above and before noffers. Paste a
                                    <code>nostr+walletconnect://&hellip;</code> string &mdash; ideally a
                                    <strong>receive-only</strong> connection (make_invoice +
                                    lookup_invoice). New connections are tested with a 1-sat test
                                    invoice when you save. Saved connections show only wallet +
                                    relay; the secret stays on the server.
                                </p>
                                <div id="auto-melt-nwc-list"></div>
                                <div id="auto-melt-nwc-warning" class="hidden" style="margin-top:0.5rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;"></div>
                                <button type="button" class="btn btn-secondary" id="btn-add-nwc" style="margin-top: 0.5rem;<?= $nwcEnvError !== null ? ' opacity: 0.4;' : '' ?>"
                                        <?= $nwcEnvError !== null ? 'disabled' : '' ?>>
                                    + Add NWC connection
                                </button>
                            </div>

                            <div class="form-group" id="auto-melt-noffer-group"
                                 <?= $nofferEnvError !== null ? 'data-env-error="1"' : '' ?>>
                                <label class="form-label">CLINK noffers (priority order)</label>
                                <?php if ($nofferEnvError !== null): ?>
                                    <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                        &#9888; <strong>noffers are unavailable on this server.</strong>
                                        <?= htmlspecialchars($nofferEnvError) ?>
                                        Existing entries stay saved (and can be removed) but are
                                        skipped at checkout until then.
                                    </div>
                                <?php elseif (($nofferNotice = ClinkClient::environmentNotice()) !== null): ?>
                                    <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(59,130,246,0.10); border:1px solid rgba(59,130,246,0.35); font-size:0.82rem;">
                                        &#9432; <?= htmlspecialchars($nofferNotice) ?>
                                    </div>
                                <?php endif; ?>
                                <p class="form-help">
                                    CLINK noffers (NIP-69) request a Lightning invoice over Nostr and
                                    settle via the merchant&rsquo;s payment receipt. They are tried
                                    <em>after</em> the lightning addresses and NWC connections above,
                                    in order, if those can&rsquo;t produce an invoice. Paste a
                                    <code>noffer1&hellip;</code> string.
                                </p>
                                <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                    &#9888; Using
                                    <a href="https://electrum.org" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">Electrum</a>
                                    with the
                                    <a href="https://github.com/BareBits/electrum_clink" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">CLINK plugin</a>
                                    is STRONGLY recommended as opposed to other wallets with CLINK
                                    support. CLINK payment receipts are temporary when issued, which
                                    means they can be lost during small network outages and report
                                    invoices as &ldquo;unpaid&rdquo;. There is no risk to funds from
                                    this, but it does mean an order may show unpaid when it was, in
                                    fact, paid.
                                </div>
                                <div id="auto-melt-noffer-list"></div>
                                <button type="button" class="btn btn-secondary" id="btn-add-noffer" style="margin-top: 0.5rem;<?= $nofferEnvError !== null ? ' opacity: 0.4;' : '' ?>"
                                        <?= $nofferEnvError !== null ? 'disabled' : '' ?>>
                                    + Add noffer
                                </button>
                            </div>

                            <div id="lightning-payments-error" class="hidden" style="margin-top:0.75rem; color: var(--error); font-size: 0.85rem;"></div>
                            <button class="btn btn-full" id="btn-save-lightning-payments" style="margin-top: 1rem;">
                                Save Lightning payments
                            </button>
                        </div>
                    </div>

                    <div class="card collapsible" id="card-cashu-cashout">
                        <div class="card-header">
                            <div class="card-title">Cashu automatic cashout</div>
                        </div>
                        <div class="card-body">
                            <?php if ($cashuEnvError !== null): ?>
                                <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                    &#9888; <strong>Cashu is unavailable on this server.</strong>
                                    <?= htmlspecialchars($cashuEnvError) ?>
                                    Automatic cashout of a Cashu mint balance can't run until one of them is enabled.
                                </div>
                            <?php endif; ?>
                            <div id="cashu-cashout-mint-warning" class="hidden" style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                &#9888; <strong>Cashu is disabled for this store.</strong>
                                This store has no Cashu mint wallet configured, so there is no mint
                                balance to cash out. Add a mint to use automatic cashout.
                            </div>
                            <!-- Everything below greys out when Cashu can't run: either the
                                 server lacks GMP/BCMath (rendered inert here) or the selected
                                 store has no mint wallet (toggled by renderAutoMeltMode). -->
                            <div id="cashu-cashout-body"<?= $cashuEnvError !== null
                                ? ' data-env-error="1" style="opacity: 0.5; pointer-events: none;"'
                                : '' ?>>
                            <div id="aw-store" data-aw data-aw-scope="store">
                            <p class="aw-title">auto-cashout settings</p>
                            <?php if ($gmpEnvError !== null): ?>
                                <div style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                    &#9888; <strong>On-chain withdrawal is unavailable on this server.</strong>
                                    <?= htmlspecialchars($gmpEnvError) ?>
                                    Lightning-address withdrawals still work<?= $nofferEnvError === null ? ', as do noffers' : '' ?>.
                                </div>
                            <?php endif; ?>
                            <div id="aw-store-warning" class="hidden" style="margin-bottom:0.75rem; padding:0.6rem 0.8rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); font-size:0.82rem;">
                                &#9888; This store has no on-chain xpub or withdrawal address configured on the Bitcoin tab. On-chain withdrawal cannot be used until you add one.
                            </div>
                            <input type="hidden" id="auto-melt-mode-override" value="0">
                            <div class="aw-cols">
                                <div class="aw-col" data-aw-mode="0" tabindex="0" role="button" aria-pressed="false">
                                    <span class="aw-col-check">&#10003;</span>
                                    <div class="aw-col-head"><span class="aw-col-name">Lightning Withdrawal</span><span class="aw-col-badge">Suggested</span></div>
                                    <div class="aw-col-desc">Withdraw to a Lightning payment path from the Lightning payments section above (LNURL address, NWC connection, or noffer).</div>
                                    <ul>
                                        <li>Get your funds the fastest</li>
                                        <li>Automatic conversion to USD on Strike</li>
                                        <li>Lowest fees</li>
                                    </ul>
                                </div>
                                <div class="aw-col" data-aw-mode="1" tabindex="0" role="button" aria-pressed="false">
                                    <span class="aw-col-check">&#10003;</span>
                                    <div class="aw-col-head"><span class="aw-col-name">On-chain Withdrawal</span></div>
                                    <div class="aw-col-desc">Withdraw to On-chain Address.</div>
                                    <ul>
                                        <li>Slower funds transfers</li>
                                        <li>Works with all Bitcoin wallets</li>
                                        <li>Funds may sit in the mint until sufficient for the on-chain transaction fee (custodial risk)</li>
                                    </ul>
                                </div>
                            </div>
                            <p class="form-help" style="margin-top:0.6rem;">
                                Currently effective: <strong id="auto-melt-mode-effective">Lightning address</strong>.
                            </p>

                            <p class="form-help" id="auto-melt-swap-info" style="display: none;">
                                Sweeps the mint balance through a reverse submarine swap to the store's
                                on-chain xpub. May result in longer intervals between auto-cashouts
                                during high-fee periods. Minimum sweep:
                                <strong id="auto-melt-mode-min-sats">5,000</strong> sats (~$5);
                                swap cost must be ≤
                                <strong id="auto-melt-mode-max-fee-pct">1%</strong> of the swept amount.
                            </p>

                            <div class="toggle-container">
                                <span>Auto-cashout when balance reaches threshold</span>
                                <label class="toggle">
                                    <input type="checkbox" id="auto-melt-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label" id="auto-melt-threshold-label">Threshold (<span class="unit-label">SAT</span>)</label>
                                <input type="number" class="form-input" id="auto-melt-threshold"
                                       value="2000" min="1" step="1">
                                <p class="form-help" style="margin-top:0.5rem;">
                                    This threshold applies only to <strong>on-chain</strong>
                                    withdrawals &mdash; it sets the minimum mint balance before an
                                    on-chain swap is attempted. Lightning withdrawals ignore it:
                                    whenever a Lightning payment path (LNURL address, NWC
                                    connection, or noffer) is configured in the Lightning payments
                                    section, the mint balance is cashed out to it automatically
                                    each cycle (no minimum), so funds don&rsquo;t sit in the mint
                                    waiting for the on-chain fee to become economical.
                                </p>
                            </div>

                            <div id="aw-store-error" class="hidden" style="margin-top:0.75rem; color: var(--error); font-size: 0.85rem;"></div>
                            <button class="btn btn-full" id="btn-save-auto-melt" style="margin-top: 1rem;">
                                Save Settings
                            </button>
                            </div><!-- /#aw-store -->
                            </div><!-- /#cashu-cashout-body -->
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">On-chain Bitcoin payments</div>
                        </div>
                        <div class="card-body">
                            <p class="form-help" style="margin-bottom: 1rem;">
                                Accept direct on-chain Bitcoin transactions in addition to Lightning.
                                The recommended option is to provide an extended public key (xpub)
                                so the server can derive a fresh address per invoice. If your wallet
                                does not expose an xpub, you can fall back to reusing a single
                                static address.
                            </p>
                            <?php if ($gmpEnvError !== null): ?>
                                <div style="margin-bottom:1rem; padding:0.75rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); font-size:0.85rem;">
                                    <strong>&#9888; Xpub mode is unavailable on this server.</strong>
                                    <?= htmlspecialchars($gmpEnvError) ?>
                                    A single static address still works.
                                </div>
                            <?php endif; ?>

                            <!-- Whether to OFFER the on-chain rail to customers, independent of
                                 whether an xpub/static address is configured. Keeping the xpub but
                                 turning this off gives a Lightning-only checkout while submarine
                                 swaps still settle on-chain to that xpub. Saves instantly on change. -->
                            <div class="form-group">
                                <label class="form-label">Offer on-chain to customers</label>
                                <select class="form-input" id="onchain-offer-override" onchange="onchainOfferChanged()">
                                    <option value="1">On for this store</option>
                                    <option value="0">Off &mdash; Lightning-only checkout</option>
                                </select>
                                <p class="form-help">
                                    Currently effective: <strong id="onchain-offer-effective">&mdash;</strong>.
                                    Your xpub is still used for submarine-swap settlement even when
                                    on-chain checkout is off.
                                </p>
                                <div id="onchain-offer-warning" style="display:none; margin-top:0.5rem; padding:0.75rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); font-size:0.85rem;">
                                    <strong>&#9888; Heads up.</strong>
                                    Not all wallets support Lightning. Disabling on-chain payments will
                                    mean some wallets can&rsquo;t make payment to you. Additionally, if your
                                    LNURL/noffer/mint is down, it may leave you with no way to accept payment.
                                </div>
                            </div>

                            <!-- "Also accept on-chain payments via Strike": invoice addresses
                                 are minted in the merchant's Strike account (receive request);
                                 the xpub/static source below stays the fallback. Saves
                                 instantly on change; enabling probes the key's
                                 receive-request scope server-side. -->
                            <div class="form-group" id="onchain-strike-group">
                                <div class="toggle-container">
                                    <span>Also accept on-chain payments via Strike</span>
                                    <label class="toggle">
                                        <input type="checkbox" id="onchain-strike-toggle" onchange="onchainStrikeChanged()">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <p class="form-help" id="onchain-strike-help">
                                    Each invoice&rsquo;s Bitcoin address is created in your Strike
                                    account, so on-chain payments land there like your Strike
                                    Lightning payments do. The xpub / static address below is the
                                    fallback when Strike is unreachable. Needs a Strike API key
                                    (Lightning payments card) with the
                                    <strong>create receive requests</strong> scope; mainnet only.
                                </p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Address source</label>
                                <select class="form-input" id="onchain-mode">
                                    <option value="xpub">Extended public key (recommended)</option>
                                    <option value="static">Single static address (not recommended)</option>
                                </select>
                            </div>
                            <div id="onchain-static-warning" style="display:none; margin-bottom:1rem; padding:0.75rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); font-size:0.85rem;">
                                <strong>&#9888; Static address re-use is strongly discouraged.</strong>
                                It is STRONGLY recommended that you use an xpub instead of a static
                                address. Static address re-use decreases the privacy of you and your
                                customers and prevents correctly detecting payment when multiple
                                transactions are used to pay an invoice. Each invoice will be assigned
                                a unique sat-tweak so totals don&rsquo;t collide; customers must pay the
                                exact amount in a single transaction.
                            </div>
                            <div class="form-group" id="onchain-xpub-row">
                                <label class="form-label">Extended public key (xpub / zpub / vpub / etc.)</label>
                                <textarea class="form-input" id="onchain-xpub" rows="2"
                                          style="font-family: monospace; font-size: 0.85rem;"
                                          placeholder="xpub... or zpub... or vpub..."></textarea>
                                <p class="form-help" id="onchain-xpub-meta"></p>
                            </div>
                            <div class="form-group" id="onchain-static-address-row" style="display:none;">
                                <label class="form-label">Static receive address</label>
                                <input type="text" class="form-input" id="onchain-static-address"
                                       style="font-family: monospace; font-size: 0.85rem;"
                                       placeholder="bc1q... / 3... / 1... (or testnet / regtest equivalent)">
                                <p class="form-help" id="onchain-static-address-meta">
                                    Paste a single Bitcoin address you control. The server will reuse
                                    it for every invoice. Leave blank to disable on-chain payments.
                                </p>
                            </div>
                            <div class="subsection collapsed" id="onchain-advanced">
                            <div class="subsection-toggle">Advanced</div>
                            <div class="subsection-body">
                            <div class="form-group" id="onchain-static-tweak-row" style="display:none;">
                                <label class="form-label">
                                    Tweak range (number of unique sat-offsets;
                                    each open invoice consumes one slot)
                                </label>
                                <input type="number" class="form-input" id="onchain-static-tweak-range"
                                       min="100" max="100000" value="1000">
                                <p class="form-help">
                                    Larger ranges allow more concurrent open invoices but make customer
                                    over-payment more visible. Default 1000 (up to 999 extra sats per
                                    invoice).
                                </p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Network</label>
                                <select class="form-input" id="onchain-network">
                                    <option value="mainnet">mainnet</option>
                                    <option value="testnet">testnet</option>
                                    <option value="signet">signet</option>
                                    <option value="regtest">regtest</option>
                                </select>
                            </div>
                            <div class="form-group" id="onchain-address-type-row">
                                <label class="form-label">Address type</label>
                                <select class="form-input" id="onchain-address-type">
                                    <option value="P2WPKH">P2WPKH (native segwit, recommended)</option>
                                    <option value="P2SH-P2WPKH">P2SH-P2WPKH (wrapped segwit)</option>
                                </select>
                                <p class="form-help" id="onchain-address-type-help">
                                    The xpub prefix above doesn’t pin a script type — pick the one your wallet uses.
                                </p>
                            </div>
                            <p class="form-help" id="onchain-address-type-inferred" style="display:none;"></p>
                            <div class="form-group">
                                <label class="form-label">
                                    Required confirmations (0 = accept zero-conf)
                                    <span class="info-tip" tabindex="0" aria-label="More info">i<span class="info-tip-text">
                                        How many block confirmations the on-chain payment
                                        needs before the invoice is marked paid.
                                        <br><br>
                                        <strong>0 (zero-conf):</strong> instant settlement, but a malicious
                                        customer could double-spend the unconfirmed tx. For ~90% of merchants
                                        taking small consumer payments, zero-conf is a fine default.
                                        <br><br>
                                        <strong>1+ confirmations:</strong> safer for high-value invoices, but
                                        customers wait ~10 minutes per confirmation.
                                    </span></span>
                                </label>
                                <input type="number" class="form-input" id="onchain-min-confs" min="0" max="100" value="1">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Confirmation window (seconds; how long to wait once a tx appears in mempool)</label>
                                <input type="number" class="form-input" id="onchain-confirm-timeout" min="60" value="86400">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Provider URL (optional &mdash; leave blank for default mempool.space)</label>
                                <input type="text" class="form-input" id="onchain-provider-url"
                                       placeholder="https://mempool.space/api">
                            </div>
                            </div><!-- /.subsection-body -->
                            </div><!-- /#onchain-advanced -->
                            <div id="onchain-validation-box" style="display:none; margin: 0.75rem 0; padding: 0.75rem; border-radius: 8px; font-size: 0.85rem;"></div>
                            <div id="onchain-xpub-buttons">
                                <button class="btn btn-secondary btn-full" id="btn-validate-onchain" style="margin-top: 0.5rem;">
                                    Validate &amp; preview first 3 addresses
                                </button>
                                <button class="btn btn-secondary btn-full" id="btn-test-onchain" style="margin-top: 0.5rem;">
                                    Test current next address (m/0/<span id="onchain-current-index">0</span>)
                                </button>
                            </div>
                            <button class="btn btn-full" id="btn-save-onchain" style="margin-top: 0.5rem;">
                                Save on-chain settings
                            </button>
                        </div>
                    </div>

                    <!-- Per-store submarine-swap settings (there is no
                         site-wide layer; every knob lives on the store). -->
                    <div class="card collapsible" id="card-store-swaps">
                        <div class="card-header">
                            <div class="card-title">Submarine Swaps (LN&rarr;on-chain)</div>
                        </div>
                        <div class="card-body">
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                                When your customers try to pay with lightning and you have no viable
                                lightning destination (LNURL provider down, no inbound liquidity, etc),
                                let them, but have them automatically pay the lightning -&gt; on-chain
                                conversion fee (around 10c in low fee environments). Requires an
                                on-chain xpub on the Bitcoin tab.
                            </p>
                            <?php if ($gmpEnvError !== null): ?>
                                <div style="margin-bottom:0.75rem; padding:0.75rem; border-radius:8px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); font-size:0.85rem;">
                                    <strong>&#9888; Swaps are unavailable on this server.</strong>
                                    <?= htmlspecialchars($gmpEnvError) ?>
                                    Invoices fall back to Lightning / mint until it is enabled.
                                </div>
                            <?php endif; ?>

                            <div class="toggle-container">
                                <span><strong>Enable submarine swaps</strong> (this store)</span>
                                <label class="toggle">
                                    <input type="checkbox" id="store-swaps-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <p class="form-help">
                                Currently effective: <strong id="store-swaps-effective">&mdash;</strong>
                            </p>

                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label">Providers</label>
                                <div id="store-swaps-provider-checkboxes" style="display: flex; flex-direction: column; gap: 0.4rem; padding: 0.5rem 0;">
                                    <!-- populated by refreshStoreSwapsCard(); one checkbox per known provider -->
                                </div>
                                <p class="form-help">
                                    Each enabled provider is tried in the order shown. At invoice creation
                                    we use the first reachable one &mdash; unless the auto-select option below
                                    finds a meaningfully cheaper alternative.
                                </p>
                            </div>

                            <div class="toggle-container" style="margin-top: 0.75rem;">
                                <span><strong>Automatically select the cheapest swap provider</strong></span>
                                <label class="toggle">
                                    <input type="checkbox" id="store-swaps-auto-select">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <p class="form-help" style="margin-top: -0.25rem;">
                                Fetches a quote from every enabled provider in parallel and prefers a
                                cheaper one when it beats the highest-priority provider by more than the
                                threshold below. Falls back to the priority order whenever a quote can't
                                be fetched, so it never adds a new failure mode.
                            </p>

                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label class="form-label" for="store-swaps-auto-threshold">Minimum savings to switch providers (%)</label>
                                <input type="number" class="form-input" id="store-swaps-auto-threshold"
                                       min="1" max="90" step="1" value="10" style="max-width: 8rem;">
                                <p class="form-help">
                                    How much cheaper a lower-priority provider must be before we
                                    use it instead. Default 10%.
                                </p>
                            </div>

                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label" for="store-swaps-min-sats">Minimum target (sats)</label>
                                <input type="number" class="form-input" id="store-swaps-min-sats"
                                       placeholder="provider default" min="0" step="1" style="max-width: 9rem;">
                                <p class="form-help">
                                    Local floor in addition to the provider's own minimum. Leave blank
                                    to use the provider's minimum (Boltz mainnet: 10,000 sats).
                                </p>
                            </div>

                            <div class="form-group" style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color, rgba(255,255,255,0.08));">
                                <label class="form-label">Cashu mint fallback (this store)</label>
                                <select class="form-input" id="store-swaps-strict">
                                    <option value="0">Allow falling back to a mint</option>
                                    <option value="1">Never fall back to a mint (strict)</option>
                                </select>
                                <p class="form-help">
                                    Strict means an invoice errors rather than being issued by a Cashu
                                    mint when Lightning and swaps both fail. Setup sets this to strict
                                    when you decline mints.
                                </p>
                            </div>

                            <div class="form-group" style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color, rgba(255,255,255,0.08));">
                                <label class="form-label">Fee-too-high mint fallback (this store)</label>
                                <p class="form-help" style="margin-top: -0.25rem;">
                                    Skip an expensive swap in favour of a mint Lightning invoice
                                    (requires a mint on this store). Leave blank for the config-file
                                    default; 0 disables a check.
                                    Effective now: <strong id="store-swaps-fee-effective">&mdash;</strong>
                                </p>
                                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                    <div>
                                        <label class="form-label" for="store-swaps-fee-pct">Max fee (%)</label>
                                        <input type="number" class="form-input" id="store-swaps-fee-pct"
                                               min="0" max="100" step="0.1" placeholder="inherit" style="max-width: 8rem;">
                                    </div>
                                    <div>
                                        <label class="form-label" for="store-swaps-fee-sats">Max fee (sats)</label>
                                        <input type="number" class="form-input" id="store-swaps-fee-sats"
                                               min="0" step="1" placeholder="inherit" style="max-width: 8rem;">
                                    </div>
                                </div>
                            </div>

                            <div id="store-swaps-error" class="hidden" style="margin-top:0.5rem; color: var(--error); font-size: 0.85rem;"></div>
                            <button class="btn btn-full" id="btn-save-store-swaps" style="margin-top: 0.5rem;">
                                Save
                            </button>
                        </div>
                    </div>

                    <div class="card collapsible" id="card-store-seed">
                        <div class="card-header">
                            <div class="card-title">Recovery phrase</div>
                        </div>
                        <div class="card-body">
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                                The 12 words that recover any ecash this store holds at its
                                Cashu mints if this server's database is lost. Setup shows
                                them once; this is where you get them back. Anyone who has
                                them can spend those funds, so keep them offline.
                            </p>
                            <div class="form-group">
                                <label class="form-label" for="store-seed-password">
                                    Confirm your password
                                </label>
                                <input type="password" class="form-input" id="store-seed-password"
                                       autocomplete="current-password"
                                       placeholder="Your admin password">
                            </div>
                            <div id="store-seed-error" class="hidden" style="margin-bottom:0.5rem; color: var(--error); font-size: 0.85rem;"></div>
                            <div id="store-seed-output" class="hidden" style="margin-bottom: 0.75rem;">
                                <code id="store-seed-value" style="display:block; padding:0.75rem; border-radius:6px;
                                      background: rgba(0,0,0,0.3); word-spacing:0.4rem; line-height:1.8;
                                      user-select:all; font-size:0.9rem;"></code>
                            </div>
                            <button class="btn btn-full" id="btn-reveal-store-seed">Show recovery phrase</button>
                        </div>
                    </div>

                    <div class="card collapsible" id="card-store-selfserve">
                        <div class="card-header">
                            <div class="card-title">Self-Serve Invoices</div>
                        </div>
                        <div class="card-body">
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                                Let customers create and pay their own invoice (no login) from a public
                                link, choosing the amount and an optional note. Requires a payment method
                                on this store. Off by default.
                            </p>

                            <div class="form-group">
                                <label class="form-label">Mode</label>
                                <select class="form-input" id="store-selfserve-override">
                                    <option value="0">Off for this store</option>
                                    <option value="1">On for this store</option>
                                </select>
                                <p class="form-help">
                                    Currently effective: <strong id="store-selfserve-effective">&mdash;</strong>
                                </p>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="store-selfserve-max">Maximum invoice (sats)</label>
                                <input type="number" class="form-input" id="store-selfserve-max"
                                       min="1" step="1" placeholder="500000">
                                <p class="form-help">
                                    Caps how much a single self-serve invoice can lock up. Leave blank
                                    for the default (500,000 sats). Effective now:
                                    <strong id="store-selfserve-max-effective">&mdash;</strong> sats.
                                </p>
                            </div>

                            <div class="form-group" id="store-selfserve-link-row" style="display:none;">
                                <label class="form-label">Public payment link</label>
                                <div style="display:flex; gap:0.5rem;">
                                    <input type="text" class="form-input" id="store-selfserve-link" readonly
                                           style="flex:1; font-size:0.8rem;">
                                    <button type="button" class="btn btn-secondary" id="btn-copy-selfserve-link"
                                            style="width:auto; flex:0 0 auto;">Copy</button>
                                </div>
                                <p class="form-help">Share this link with your customers.</p>
                            </div>

                            <div id="store-selfserve-error" class="hidden" style="margin-top:0.5rem; color: var(--error); font-size: 0.85rem;"></div>
                            <button class="btn btn-full" id="btn-save-store-selfserve" style="margin-top: 0.5rem;">
                                Save
                            </button>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Email Notifications</div>
                        </div>
                        <div class="card-body">
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                                Send notification emails for this store's events (invoice paid,
                                auto-cashout). Requires site-wide notifications to be enabled
                                in the Settings page.
                            </p>

                            <div class="toggle-container">
                                <span>Enable notifications for this store</span>
                                <label class="toggle">
                                    <input type="checkbox" id="store-notifications-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label">Notification email</label>
                                <input type="email" class="form-input" id="store-notification-email"
                                       placeholder="leave blank to use the site-wide address">
                                <p class="form-help">If blank, the site-wide notification address from Settings is used.</p>
                            </div>

                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label">Newsletter checkbox default</label>
                                <select class="form-input" id="store-newsletter-default">
                                    <option value="">Use site-wide default</option>
                                    <option value="1">Checked</option>
                                    <option value="0">Unchecked</option>
                                </select>
                                <p class="form-help" id="store-newsletter-default-help">
                                    Initial state of the newsletter opt-in checkbox on this store's
                                    payment page.
                                </p>
                            </div>

                            <div class="toggle-container" style="margin-top: 1rem;">
                                <span>Use a custom SMTP server for this store</span>
                                <label class="toggle">
                                    <input type="checkbox" id="store-smtp-override-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div id="store-smtp-fields" class="hidden" style="margin-top: 0.75rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px;">
                                <p class="form-help" style="margin-top: 0;">
                                    Any field left blank falls back to the global SMTP settings, then to
                                    <code>user_config.php</code>.
                                </p>
                                <div class="form-group" style="margin-top: 0.75rem;">
                                    <label class="form-label">Host</label>
                                    <input type="text" class="form-input" id="store-smtp-host"
                                           placeholder="smtp.example.com" autocomplete="off">
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <div class="form-group" style="flex: 1;">
                                        <label class="form-label">Port</label>
                                        <input type="number" class="form-input" id="store-smtp-port"
                                               placeholder="587" min="1" max="65535">
                                    </div>
                                    <div class="form-group" style="flex: 1;">
                                        <label class="form-label">Encryption</label>
                                        <select class="form-input" id="store-smtp-encryption">
                                            <option value="">Default (STARTTLS on 587)</option>
                                            <option value="tls">STARTTLS (tls)</option>
                                            <option value="ssl">SSL/TLS (ssl)</option>
                                            <option value="none">None</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-input" id="store-smtp-username"
                                           placeholder="leave blank for no authentication" autocomplete="off">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Password</label>
                                    <input type="password" class="form-input" id="store-smtp-password"
                                           autocomplete="new-password">
                                    <label style="display: flex; align-items: center; gap: 0.4rem; margin-top: 0.4rem; font-size: 0.85rem;">
                                        <input type="checkbox" id="store-smtp-password-clear"> Clear saved password
                                    </label>
                                    <p class="form-help" id="store-smtp-password-help">Leave blank to keep the saved password.</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">From address</label>
                                    <input type="email" class="form-input" id="store-smtp-from-address"
                                           placeholder="noreply@example.com">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label">From name</label>
                                    <input type="text" class="form-input" id="store-smtp-from-name"
                                           placeholder="CashuPayServer">
                                </div>
                            </div>

                            <button class="btn btn-full" id="btn-save-store-notifications" style="margin-top: 0.75rem;">
                                Save notification settings
                            </button>

                            <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.5rem;">
                                <input type="email" class="form-input" id="store-notifications-test-email"
                                       placeholder="test@example.com" style="flex: 1;">
                                <button class="btn btn-secondary" id="btn-send-store-test-notification">Send test</button>
                            </div>
                            <p class="form-help">Sends using this store's resolved SMTP config. Save first to test unsaved changes.</p>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Invoice Privacy</div>
                        </div>
                        <div class="card-body">
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                                Controls whether the store name and the payer-facing note are
                                embedded in the invoice memo a customer's wallet records (the
                                Lightning noffer description and Cashu payment-request memo). The
                                payment page itself still shows them. Each invoice can override
                                these defaults from the Create invoice screen.
                            </p>

                            <div class="toggle-container">
                                <span>Hide store name on invoices</span>
                                <label class="toggle">
                                    <input type="checkbox" id="store-hide-store-name">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div class="toggle-container" style="margin-top: 1rem;">
                                <span>Hide notes on invoices</span>
                                <label class="toggle">
                                    <input type="checkbox" id="store-hide-note">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <button class="btn btn-full" id="btn-save-store-privacy" style="margin-top: 0.75rem;">
                                Save privacy settings
                            </button>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Exchange Rate Settings</div>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Default Currency</label>
                                <select class="form-input" id="default-currency">
                                    <?php foreach (Config::getSupportedDisplayCurrencies() as $cur): ?>
                                        <option value="<?= htmlspecialchars($cur) ?>"><?= htmlspecialchars(strtoupper($cur)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help">Default unit for the Create invoice page and dashboard balance. The mint still settles in its native unit (<span class="unit-label">SAT</span>); fiat amounts are converted at quote time.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Primary Rate Provider</label>
                                <select class="form-input" id="price-provider-primary">
                                    <option value="coingecko">CoinGecko</option>
                                    <option value="binance">Binance</option>
                                    <option value="kraken">Kraken</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Backup Rate Provider</label>
                                <select class="form-input" id="price-provider-secondary">
                                    <option value="binance">Binance</option>
                                    <option value="coingecko">CoinGecko</option>
                                    <option value="kraken">Kraken</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Exchange Fee (%)</label>
                                <input type="number" class="form-input" id="exchange-fee-percent"
                                       value="0" min="0" max="10" step="0.1">
                                <p class="form-help">Fee added to currency conversions (0-10%)</p>
                            </div>
                            <button class="btn btn-full" id="btn-save-exchange-settings">Save Settings</button>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Hosting Fee</div>
                        </div>
                        <div class="card-body">
                            <p class="form-help" style="margin-bottom: 0.75rem;">
                                Optional percentage taken on all revenue and paid to your web host's Lightning
                                address. Talk to your web host before disabling this.
                                Intended for white-label deployers who collect a deployment fee. Default 0%.
                                Settled automatically on the cron tick once at least
                                <?= (int) (defined('CASHUPAY_FEE_SETTLE_THRESHOLD_SATS') ? CASHUPAY_FEE_SETTLE_THRESHOLD_SATS : 1000) ?> sats are owed.
                                The mandatory <?= (int) CASHUPAY_DEV_FEE_PERCENT ?>% development fee is not configurable here.
                            </p>
                            <div class="form-group">
                                <label class="form-label">Hosting Fee (%)</label>
                                <input type="number" class="form-input" id="hosting-fee-percent"
                                       value="0" min="0" max="100" step="0.1">
                                <p class="form-help">Charged on every paid invoice (this fee does not subtract network fees).</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hosting Fee Destination</label>
                                <input type="text" class="form-input" id="hosting-fee-destination"
                                       placeholder="you@yourdomain.com">
                                <p class="form-help">Lightning address where the hosting fee is sent.</p>
                            </div>
                            <button class="btn btn-full" id="btn-save-hosting-fee">Save Hosting Fee</button>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">API Keys</div>
                            <button class="btn" id="btn-create-api-key">+ New</button>
                        </div>
                        <div id="store-api-keys">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Offline Cashu Acceptance</div>
                        </div>
                        <div class="card-body">
                            <p class="form-help" style="margin-bottom: 0.75rem;">
                                Keep taking Cashu ecash even when the mint is briefly unreachable. Tokens are
                                verified by their built-in signature (NUT-12 DLEQ) and confirmed automatically
                                once the mint is back online.
                            </p>
                            <div id="offline-cashu-body"><div class="loading"><div class="spinner"></div></div></div>
                        </div>
                    </div>

                    <div class="card collapsible">
                        <div class="card-header">
                            <div class="card-title">Danger Zone</div>
                        </div>
                        <div class="card-body">
                            <button class="btn btn-danger btn-full" id="btn-delete-store">
                                Delete Store
                            </button>
                        </div>
                    </div>
                </div>

                <div id="store-settings-empty" style="display: none;">
                    <div class="empty-state">
                        <div class="empty-state-icon">🏪</div>
                        <p>No store selected</p>
                        <!-- Managed installs run exactly one shop-provisioned store,
                             so adding stores from the menu is hidden there. -->
                        <button class="btn<?= $isManaged ? ' managed-hidden' : '' ?>" id="btn-create-store" style="margin-top: 1rem;">Create Store</button>
                    </div>
                </div>
            </div>

            <!-- Products View (admin only; per-store catalog for the cart) -->
            <div class="view" id="view-products">
                <div class="card view-fill">
                    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem; flex-wrap:wrap;">
                        <div class="card-title">Products</div>
                        <button class="btn" id="btn-new-product" style="width:auto; padding:0.4rem 0.9rem;">+ New product</button>
                    </div>
                    <div class="card-body">
                        <p style="font-size:0.85rem; color:var(--text-secondary); margin:0 0 0.75rem 0;">
                            Products for the selected store. They appear in the cart-based
                            <strong>Create invoice</strong> flow on the dashboard. Prices are in the store's
                            display currency (<span id="products-currency-label">sat</span>).
                        </p>
                        <div class="form-group" style="max-width:260px;">
                            <label class="form-label" for="products-default-sort">Default sort in Create invoice modal</label>
                            <select class="form-input" id="products-default-sort">
                                <option value="most_purchased">Most purchased</option>
                                <option value="newest">Newest first</option>
                                <option value="title_asc">Title A–Z</option>
                                <option value="price_asc">Price: low to high</option>
                                <option value="price_desc">Price: high to low</option>
                            </select>
                        </div>
                        <div id="products-admin-list">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings View (Global) -->
            <div class="view" id="view-settings">
                <!-- On desktop the cards scroll inside .settings-scroll while
                     the version footer below stays fixed and always visible;
                     on mobile this is a plain block and the footer scrolls with
                     the page (the bottom nav owns the bottom edge there). -->
                <div class="settings-scroll">
                <div id="settings-scope-note" style="margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 12px; background: rgba(96, 165, 250, 0.1); border: 1px solid rgba(96, 165, 250, 0.3); font-size: 0.9rem; line-height: 1.45;">
                    <strong>These are site-wide settings and defaults.</strong>
                    Most knobs operators want to tweak day-to-day &mdash; auto-cashout destination,
                    on-chain xpub, exchange-rate provider, hosting fee, per-store email &mdash;
                    live on the per-store settings page.
                    <a href="#" class="js-goto-stores"
                       style="color: var(--accent); text-decoration: underline; white-space: nowrap;">
                        Open Store Settings &rarr;
                    </a>
                </div>
                <div class="card collapsible" data-admin-only="true" id="card-developer">
                    <div class="card-header">
                        <div class="card-title">Developer / Debug</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                            Diagnostic options for operators. These never affect how
                            payments are processed &mdash; they only change what is shown
                            for debugging.
                        </p>
                        <div class="toggle-container">
                            <span><strong>Show payment-path labels</strong> on the payment page</span>
                            <label class="toggle">
                                <input type="checkbox" id="developer-payment-path-debug">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <p class="form-help" style="margin-top: 0.5rem;">
                            When enabled, a small label next to each &ldquo;Copy&rdquo;
                            button on the payment screen shows which payment path the
                            invoice uses (LNURL, CLINK noffer, submarine swap, Cashu mint
                            quote, on-chain xpub / static address, Cashu ecash). The label
                            is only visible to a <strong>logged-in admin</strong> &mdash; a
                            regular payer never sees it. Default off.
                        </p>
                        <button class="btn btn-full" id="btn-save-developer" style="margin-top: 0.75rem;">
                            Save developer settings
                        </button>
                    </div>
                </div>
                <div class="card collapsible" data-admin-only="true" id="card-invoice-errors">
                    <div class="card-header">
                        <div class="card-title">Invoice Page Errors</div>
                    </div>
                    <div class="card-body">
                        <div class="toggle-container">
                            <span><strong>Suppress errors on the invoice screen</strong></span>
                            <label class="toggle">
                                <input type="checkbox" id="suppress-invoice-errors">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <p class="form-help" style="margin-top: 0.5rem;">
                            When a store&rsquo;s wallet (NWC, noffer, Lightning address)
                            can&rsquo;t be reached while an invoice is created, the payment
                            page shows the customer a short sanitized notice. Enable this
                            to hide those notices site-wide. Failures are still recorded
                            and stay visible on the admin <strong>Log</strong> tab either
                            way. Default off (notices shown).
                        </p>
                        <button class="btn btn-full" id="btn-save-invoice-errors" style="margin-top: 0.75rem;">
                            Save invoice error settings
                        </button>
                    </div>
                </div>
                <div class="card collapsible" data-admin-only="true" id="card-notifications">
                    <div class="card-header">
                        <div class="card-title">Email Notifications</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                            Send notification emails for selected events. SMTP credentials
                            are read from <code>user_config.php</code>; without them, the
                            sender falls back to PHP's <code>mail()</code> function.
                        </p>
                        <div id="notifications-smtp-warning" class="hidden" style="background: rgba(247,147,26,0.15); border: 1px solid rgba(247,147,26,0.4); padding: 0.6rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 0.75rem;">
                            No SMTP host configured in <code>user_config.php</code>. The
                            server will fall back to PHP <code>mail()</code>, which only
                            works if a local MTA is installed.
                        </div>

                        <div class="toggle-container">
                            <span><strong>Enable email notifications</strong> (master switch)</span>
                            <label class="toggle">
                                <input type="checkbox" id="notifications-enabled">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div style="margin-top: 1rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px;">
                            <div style="font-weight: 500; margin-bottom: 0.5rem;">Notify on:</div>
                            <div class="toggle-container">
                                <span>Invoice paid</span>
                                <label class="toggle">
                                    <input type="checkbox" id="notifications-invoice-paid">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="toggle-container" style="margin-top: 0.5rem;">
                                <span>Auto-cashout (success &amp; failure)</span>
                                <label class="toggle">
                                    <input type="checkbox" id="notifications-auto-cashout">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="toggle-container" style="margin-top: 0.5rem;">
                                <span>Offer payers an email / newsletter form on the payment page</span>
                                <label class="toggle">
                                    <input type="checkbox" id="notifications-payer-email-capture">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <p class="form-help" style="margin-top: 0.5rem;">
                                When off, the payment-complete screen shows no email capture
                                and receipt emails are never offered. Managed shop installs
                                default this off &mdash; the shop owns customer emails.
                            </p>
                            <!-- The two toggles below only matter while the email capture
                                 above is on; JS greys them out when it is off. -->
                            <div id="payer-email-capture-dependent">
                            <div class="toggle-container" style="margin-top: 0.5rem;">
                                <span>Offer payer receipt on payment page</span>
                                <label class="toggle">
                                    <input type="checkbox" id="notifications-payer-receipt">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <p class="form-help" style="margin-top: 0.5rem;">
                                When enabled, after an invoice is paid the customer can
                                optionally enter an email address to receive a payment
                                confirmation. Receipts are queued to the same SMTP server.
                                Requires the email/newsletter form above.
                            </p>
                            <div class="toggle-container" style="margin-top: 0.75rem;">
                                <span>Newsletter checkbox checked by default</span>
                                <label class="toggle">
                                    <input type="checkbox" id="notifications-newsletter-default">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <p class="form-help" style="margin-top: 0.5rem;">
                                Default state of the &ldquo;Subscribe to our newsletter&rdquo;
                                checkbox on the payment page. The email/newsletter prompt is
                                shown regardless of whether receipts are enabled. Individual
                                stores can override this default in their store settings.
                            </p>
                            </div>
                        </div>

                        <div style="margin-top: 1rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px;">
                            <div style="font-weight: 500; margin-bottom: 0.5rem;">SMTP server</div>
                            <p class="form-help" style="margin-top: 0;">
                                Outgoing mail server. Any field left blank falls back to
                                <code>user_config.php</code> (<code>CASHUPAY_SMTP_*</code>); a value
                                here overrides the matching constant. Leave the whole section blank to
                                use <code>user_config.php</code> alone.
                            </p>
                            <div class="form-group" style="margin-top: 0.75rem;">
                                <label class="form-label">Host</label>
                                <input type="text" class="form-input" id="smtp-host"
                                       placeholder="smtp.example.com" autocomplete="off">
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Port</label>
                                    <input type="number" class="form-input" id="smtp-port"
                                           placeholder="587" min="1" max="65535">
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Encryption</label>
                                    <select class="form-input" id="smtp-encryption">
                                        <option value="">Default (STARTTLS on 587)</option>
                                        <option value="tls">STARTTLS (tls)</option>
                                        <option value="ssl">SSL/TLS (ssl)</option>
                                        <option value="none">None</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-input" id="smtp-username"
                                       placeholder="leave blank for no authentication" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-input" id="smtp-password"
                                       autocomplete="new-password">
                                <label style="display: flex; align-items: center; gap: 0.4rem; margin-top: 0.4rem; font-size: 0.85rem;">
                                    <input type="checkbox" id="smtp-password-clear"> Clear saved password
                                </label>
                                <p class="form-help" id="smtp-password-help">Leave blank to keep the saved password.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">From address</label>
                                <input type="email" class="form-input" id="smtp-from-address"
                                       placeholder="noreply@example.com">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">From name</label>
                                <input type="text" class="form-input" id="smtp-from-name"
                                       placeholder="CashuPayServer">
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 1rem;">
                            <label class="form-label">Site-wide notification email</label>
                            <input type="email" class="form-input" id="notifications-to-email"
                                   placeholder="ops@example.com">
                            <p class="form-help">Default recipient. Stores can override this individually in their settings.</p>
                        </div>

                        <button class="btn btn-full" id="btn-save-notifications" style="margin-bottom: 0.5rem;">
                            Save notification settings
                        </button>

                        <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.5rem;">
                            <input type="email" class="form-input" id="notifications-test-email"
                                   placeholder="test@example.com" style="flex: 1;">
                            <button class="btn btn-secondary" id="btn-send-test-notification">Send test</button>
                        </div>
                        <p class="form-help" id="notifications-pending" style="margin-top: 0.5rem;"></p>
                    </div>
                </div>
                <div class="card collapsible" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title">Server URL</div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Current Server URL</label>
                            <code id="current-server-url" style="display: block; background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px; font-size: 0.9rem; word-break: break-all; user-select: all;">
                                <?= htmlspecialchars(Urls::server()) ?>
                            </code>
                            <p class="form-help">This URL is used for e-commerce plugin integration.</p>
                        </div>

                        <div class="form-group" style="margin-bottom: 0.75rem;">
                            <label class="form-label">URL routing</label>
                            <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px;">
                                <span style="font-weight: 500;" id="url-mode-current-label">
                                    <?= htmlspecialchars(Urls::urlModeLabel()) ?>
                                </span>
                                <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                    (auto-detected)
                                </span>
                                <span id="url-mode-detect-status" style="margin-left: auto; font-size: 0.75rem; color: var(--text-secondary);"></span>
                            </div>
                            <p class="form-help">
                                Detected once during setup. Re-detect only if you change hosting (e.g. enabled rewrite rules, switched servers).
                            </p>
                        </div>

                        <button class="btn btn-secondary btn-full" id="btn-detect-url-mode">
                            Re-detect now
                        </button>
                    </div>
                </div>

                <!--
                    Cron URL — surfaces the operator-facing curl command so an
                    admin can set up a system cron entry. The key is auto-
                    generated lazily by the cron-url admin action; the
                    same key authenticates `?key=...` in cron.php. Optional —
                    background tasks also fire opportunistically from admin
                    page-loads (5-min gate) and checkout views.
                -->
                <div class="card collapsible" data-admin-only="true" id="card-cron-url">
                    <div class="card-header">
                        <div class="card-title">Cron URL</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                            Optional but recommended. Adding the line below to your hosting's system
                            cron makes invoice polling, auto-cashout, and fee settlement run on a
                            tight, predictable schedule. Without it, background tasks fire
                            opportunistically when an admin or customer loads a page.
                        </p>
                        <div class="form-group">
                            <label class="form-label">Main cron (every minute)</label>
                            <code id="cron-url-display" style="display: block; background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px; font-size: 0.85rem; word-break: break-all; user-select: all;">Loading…</code>
                            <p class="form-help" id="cron-url-status" style="margin-top: 0.4rem;">Checking…</p>
                        </div>
                        <button class="btn btn-secondary btn-full" id="btn-copy-cron-url">Copy</button>

                        <hr style="border: 0; border-top: 1px solid var(--border); margin: 1rem 0;">

                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                            <strong>Optional swap fast-lane.</strong> Submarine-swap settlement
                            is latency-sensitive (every poll-tick delay shows up in end-to-end
                            time). If you want sub-minute swap settlement, add this second
                            entry too — it hits cashupayserver every 10 seconds with
                            <code>?only=swaps</code> so only the swap-state poller runs, leaving
                            the expensive cashu / cleanup tasks on the main minute cadence.
                            Skip this entirely if you don't use submarine swaps.
                        </p>
                        <div class="form-group">
                            <label class="form-label">Swap fast-lane cron (every 10 seconds)</label>
                            <code id="cron-swaps-url-display" style="display: block; background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px; font-size: 0.85rem; word-break: break-all; user-select: all;">Loading…</code>
                            <p class="form-help" id="cron-swaps-url-status" style="margin-top: 0.4rem;">Checking…</p>
                        </div>
                        <button class="btn btn-secondary btn-full" id="btn-copy-cron-swaps-url">Copy</button>
                    </div>
                </div>

                <!--
                    Auto-update — channel selector + last-update info + rollback.
                    The isolated update.php endpoint (nudged by Task 12 in
                    cron.php, and by the optional dedicated cron line) fetches
                    the latest barebits-<tag>.zip built by CI for the chosen
                    channel and overlays it on the install. data/ and
                    user_config.php are preserved. .htaccess is only overwritten
                    if untouched. After applying, it probes health.php and
                    auto-rolls-back any build that fails to boot.
                -->
                <div class="card collapsible" data-admin-only="true" id="card-auto-update">
                    <div class="card-header">
                        <div class="card-title">Auto-update</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                            Daily check against GitHub. Updates apply automatically, then a health
                            check verifies the new build boots — a broken update is rolled back on
                            its own. data/ and user_config.php are preserved. Backups of the last 3
                            versions are kept under data/updates/backup/.
                        </p>
                        <div class="form-group">
                            <label class="form-label">Current version</label>
                            <code id="auto-update-current" style="display: block; background: rgba(0,0,0,0.2); padding: 0.6rem; border-radius: 8px; font-size: 0.85rem; user-select: all;">Loading…</code>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Update status</label>
                            <p id="auto-update-availability" class="form-help" style="margin-top: 0;">Checking…</p>
                            <p id="auto-update-progress" class="form-help hidden" style="margin-top: 0.25rem;"></p>
                            <button class="btn btn-full" id="btn-update-now" style="margin-top: 0.4rem;">Update now</button>
                            <p class="form-help">Downloads and applies the latest build for the selected channel, then verifies it boots and rolls back automatically if it doesn't. data/ and user_config.php are preserved.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="auto-update-channel">Channel</label>
                            <select id="auto-update-channel" class="form-control" style="width: 100%;">
                                <option value="main">main — stable</option>
                                <option value="testing">testing — pre-release</option>
                            </select>
                            <p class="form-help">Override the value set in user_config.php.</p>
                        </div>
                        <button class="btn btn-full" id="btn-save-update-channel" style="margin-bottom: 0.5rem;">Save channel</button>
                        <div id="auto-update-rollback-warning" class="hidden" style="background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.45); padding: 0.6rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 0.5rem;">
                            <strong>An update was automatically rolled back.</strong>
                            <span id="auto-update-rollback-detail"></span>
                            The failing build was blocked and will not be re-applied; updates resume
                            when the channel ships a different build.
                            <button class="btn btn-secondary" id="btn-dismiss-auto-rollback" style="margin-top: 0.5rem; font-size: 0.8rem; padding: 0.3rem 0.7rem;">Dismiss</button>
                        </div>
                        <div id="auto-update-htaccess-warning" class="hidden" style="background: rgba(247,147,26,0.15); border: 1px solid rgba(247,147,26,0.4); padding: 0.6rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 0.5rem;">
                            A new .htaccess shipped with the latest update, but your live
                            .htaccess was edited — it was left untouched. The new version is
                            in <code>.htaccess.new</code>. Review and merge by hand.
                        </div>
                        <button class="btn btn-secondary btn-full" id="btn-rollback-update" style="margin-bottom: 0.25rem;">Roll back to previous version</button>
                        <p class="form-help" id="auto-update-rollback-help">Restores the most recent backup. Disabled if no backup is available.</p>
                        <div class="form-group" style="margin-top: 1rem;">
                            <label class="form-label">Dedicated updater cron line (recommended)</label>
                            <code id="auto-update-cron-line" style="display: block; background: rgba(0,0,0,0.2); padding: 0.6rem; border-radius: 8px; font-size: 0.8rem; word-break: break-all; user-select: all;">Loading…</code>
                            <p class="form-help">
                                Optional but recommended: a second cron line that hits the isolated
                                update.php directly, so updates keep working even if a bad build
                                crashes the main app. The main cron line already nudges it as a
                                fallback. Harmless until you enable auto-update.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- My Account card: own password + logout, available to every logged-in user.
                     Managed installs hide it: login is automatic via SSO from the shop admin. -->
                <div class="card collapsible<?= $isManaged ? ' managed-hidden' : '' ?>" id="card-my-account">
                    <div class="card-header">
                        <div class="card-title">My Account</div>
                    </div>
                    <div class="card-body">
                        <div style="margin-bottom: 0.75rem;">
                            <span style="color: var(--text-secondary);">Signed in as</span>
                            <strong id="my-username" style="margin-left: 0.25rem;"></strong>
                            <span style="margin-left: 0.5rem; padding: 0.125rem 0.5rem; border-radius: 999px; font-size: 0.75rem; background: rgba(247,147,26,0.2);" id="my-role-badge"></span>
                        </div>
                        <button class="btn btn-secondary btn-full" id="btn-change-own-password" style="margin-bottom: 0.5rem;">
                            Change my password
                        </button>
                        <!-- Recovery email (admin only): powers the emailed
                             password-reset link. Revealed by renderAccountCard. -->
                        <div id="recovery-email-row" class="hidden" data-admin-only="true" style="margin-bottom: 0.5rem;">
                            <label for="recovery-email-input" style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.25rem;">
                                Recovery email
                            </label>
                            <input type="email" id="recovery-email-input" class="form-input"
                                   placeholder="you@example.com" autocomplete="email" style="margin-bottom:0.4rem;">
                            <p style="font-size:0.75rem;color:var(--text-secondary);margin:0 0 0.5rem;">
                                Used to email you a reset link if you're locked out. Requires SMTP to be configured.
                            </p>
                            <button class="btn btn-secondary btn-full" id="btn-save-recovery-email">Save recovery email</button>
                        </div>
                        <button class="btn btn-danger btn-full<?= $isManaged ? ' managed-hidden' : '' ?>" id="btn-logout">
                            Logout
                        </button>
                    </div>
                </div>

                <!-- Users card: admin-only. Managed installs hide it (SSO owns accounts). -->
                <div class="card collapsible hidden<?= $isManaged ? ' managed-hidden' : '' ?>" id="card-users" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title">Users</div>
                        <button class="btn" id="btn-add-user" style="padding: 0.25rem 0.75rem; font-size: 0.85rem;">Add user</button>
                    </div>
                    <div class="card-body">
                        <div id="users-list"></div>
                    </div>
                </div>

                <!-- Stuck Funds card: admin-only.
                     Hidden when no store has any sats stranded in a mint with
                     an active withdrawal-failure flag. -->
                <div class="card collapsible hidden" id="card-stuck-funds" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title">Stuck Funds</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Sats currently held in a mint whose last withdrawal attempt failed.
                            While funds are stuck, the equivalent amount is deducted from owed dev
                            fees (then hosting) so any unrecoverable loss comes out
                            of the operator share, not the merchant. Once a withdrawal succeeds
                            against that mint, the deduction stops automatically.
                        </p>
                        <div id="stuck-funds-totals" style="margin-bottom: 0.75rem; font-size: 0.85rem;"></div>
                        <div id="stuck-funds-list">
                            <p style="color: var(--text-secondary); font-size: 0.85rem;">Loading…</p>
                        </div>
                    </div>
                </div>

                <!-- Mint Reliability card: admin-only -->
                <div class="card collapsible hidden" id="card-mint-reliability" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title">Mint Reliability</div>
                        <button class="btn btn-secondary" id="btn-reset-all-mint-counters" style="padding: 0.25rem 0.75rem; font-size: 0.8rem;">Reset all counters</button>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Mints that are currently being skipped for new invoices because of failures or trusted-list blacklisting. Auto-melt will keep attempting withdrawals from disabled mints so funds aren't stranded.
                        </p>
                        <div id="disabled-mints-list">
                            <p style="color: var(--text-secondary); font-size: 0.85rem;">Loading…</p>
                        </div>
                    </div>
                </div>

                <!-- Trusted Mints card: admin-only -->
                <div class="card collapsible hidden" id="card-trusted-mints" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title">Trusted Mints</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Pull a JSON list of trusted mints from a URL and apply it to every store. See <code>docs/trusted-mints.md</code> for the schema.
                        </p>
                        <div class="form-group">
                            <label class="form-label" for="trusted-mints-url">List URL</label>
                            <input type="url" id="trusted-mints-url" class="form-input" placeholder="https://example.com/trusted-mints.json">
                            <p class="form-help" id="trusted-mints-url-help"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="trusted-mints-refresh">Refresh interval (minutes)</label>
                            <input type="number" id="trusted-mints-refresh" class="form-input" min="1" placeholder="1440">
                            <p class="form-help" id="trusted-mints-refresh-help">Default 1440 (24h).</p>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn" id="btn-save-trusted-mints" style="flex: 1;">Save</button>
                            <button class="btn btn-secondary" id="btn-refresh-trusted-mints" style="flex: 1;">Refresh now</button>
                        </div>
                        <div id="trusted-mints-status" style="margin-top: 0.75rem; font-size: 0.85rem; color: var(--text-secondary);"></div>
                        <div id="trusted-mints-cached" style="margin-top: 0.75rem;"></div>
                    </div>
                </div>

                <div class="card collapsible" id="card-diagnostics" data-admin-only="true">
                    <div class="card-header">
                        <div class="card-title">Diagnostic Report</div>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.75rem 0;">
                            Download a diagnostic report to send to the developers when something is wrong.
                            It bundles version &amp; build info, mint reliability and event logs, notification
                            failures, and anonymized invoice/payment records into a single JSON file.
                        </p>
                        <div class="form-group" style="margin-bottom: 0.75rem;">
                            <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="diagnostics-anonymize" checked style="width: auto; margin: 0;">
                                Anonymize data
                            </label>
                            <p class="form-help">
                                On by default. Removes product names, notes, addresses, txids, emails and other
                                customer-identifying data. Server secrets (wallet keys, passwords, API keys) are
                                never included.
                            </p>
                            <p class="form-help" id="diagnostics-deanon-warning" hidden style="color: var(--danger, #c0392b);">
                                ⚠ Unchecked: the report will include customer addresses, notes, product names and
                                emails in the clear. Only share it with developers you trust.
                            </p>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn" id="btn-export-diagnostics-all" style="flex: 1;">Export all</button>
                            <button class="btn btn-secondary" id="btn-export-diagnostics-30d" style="flex: 1;">Export past 30 days</button>
                        </div>
                    </div>
                </div>

                </div><!-- /.settings-scroll -->
                <div class="view-footer" style="text-align: center; padding: 1.5rem 0; color: var(--text-muted); font-size: 0.8rem;">
                    BareBits v<?= CASHUPAY_VERSION ?> &middot;
                    Deployment ID: <code style="background: rgba(0,0,0,0.2); padding: 0.1rem 0.4rem; border-radius: 4px;"><?= htmlspecialchars((string) Config::get('deployment_id', 'ANONYMOUS')) ?></code> &middot;
                    <a href="<?= htmlspecialchars(Updater::releasesUrl()) ?>" target="_blank" rel="noopener"
                       style="color: var(--text-secondary); text-decoration: none;">Check for updates</a>
                </div>
            </div>

            <!-- Stats Dashboard View (admin-only) -->
            <div class="view" id="view-stats">
                <div style="display: flex; justify-content: center; padding: 0.5rem 0 1.5rem;">
                    <img src="<?= htmlspecialchars(Urls::assets('img/barebits-logo.svg')) ?>" alt="BareBits" style="height: 48px; width: auto; max-width: 80%;">
                </div>
                <div id="upgrade-banner" class="hidden" style="background: rgba(247, 147, 26, 0.12); border: 1px solid rgba(247, 147, 26, 0.4); border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.9rem; display: flex; align-items: flex-start; gap: 0.75rem;" data-admin-only="true">
                    <span style="flex-shrink: 0; font-size: 1.1rem; line-height: 1.2;">🚀</span>
                    <span style="flex: 1;">
                        <strong style="display: block; margin-bottom: 0.15rem;">Heads up!</strong>
                        <span style="color: var(--text-secondary); font-size: 0.85rem;">Your store is generating enough revenue that it makes sense to upgrade to the full BareBits payment software. It costs $5/month to run on a VPS (virtual private server), but here's what you get: full custody of your funds (eliminate custodial risk), finer-grained user permission control, contacts &amp; inventory management, lower fees, and more! Head to <a href="https://getbarebits.com" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">getbarebits.com</a> for more information.</span>
                    </span>
                    <button id="btn-dismiss-upgrade-banner" aria-label="Dismiss for 90 days" title="Dismiss for 90 days" style="background: transparent; border: 0; color: var(--text-secondary); cursor: pointer; font-size: 1.2rem; line-height: 1; padding: 0 0.25rem; flex-shrink: 0;">×</button>
                </div>
                <div class="card">
                    <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
                        <div class="form-group" style="flex: 1 1 200px; margin: 0;">
                            <label class="form-label" for="stats-store-selector">Store</label>
                            <select id="stats-store-selector" class="form-input">
                                <option value="__all__">All stores</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 2 1 320px; margin: 0;">
                            <label class="form-label">Date range</label>
                            <div id="stats-range-buttons" style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                <button type="button" class="btn btn-secondary stats-range-btn active" data-range="all" style="flex: 1; min-width: 70px;">All time</button>
                                <button type="button" class="btn btn-secondary stats-range-btn" data-range="6m" style="flex: 1; min-width: 70px;">6 months</button>
                                <button type="button" class="btn btn-secondary stats-range-btn" data-range="1m" style="flex: 1; min-width: 70px;">1 month</button>
                                <button type="button" class="btn btn-secondary stats-range-btn" data-range="1w" style="flex: 1; min-width: 70px;">1 week</button>
                            </div>
                        </div>
                        <div class="form-group" style="flex: 0 0 160px; margin: 0;">
                            <label class="form-label">Unit</label>
                            <div id="stats-unit-buttons" style="display: flex; gap: 0.25rem;">
                                <button type="button" class="btn btn-secondary stats-unit-btn active" data-unit="sat" style="flex: 1;">Sats</button>
                                <button type="button" class="btn btn-secondary stats-unit-btn" data-unit="btc" style="flex: 1;">BTC</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financials -->
                <div class="card">
                    <div class="card-header"><div class="card-title">Financials</div></div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                            <div>
                                <div class="stats-stat-row"><span class="stats-stat-label">Total revenue</span><span class="stats-stat-value" id="stats-revenue">—</span></div>
                                <div class="stats-stat-row"><span class="stats-stat-label">Total invoices</span><span class="stats-stat-value" id="stats-invoice-count">—</span></div>
                                <div class="stats-stat-row"><span class="stats-stat-label">Total fees paid</span><span class="stats-stat-value" id="stats-total-fees">—</span></div>
                                <div class="stats-stat-row"><span class="stats-stat-label">Total profit</span><span class="stats-stat-value" id="stats-profit">—</span></div>
                            </div>
                            <div>
                                <div style="display: flex; justify-content: flex-end; margin-bottom: 0.5rem;">
                                    <select id="stats-financial-chart-type" class="form-input" style="width: auto;">
                                        <option value="revenue">Revenue by source</option>
                                        <option value="count">Count by source</option>
                                    </select>
                                </div>
                                <div style="position: relative; height: 240px;">
                                    <canvas id="stats-financial-chart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fees -->
                <div class="card">
                    <div class="card-header"><div class="card-title">Fees</div></div>
                    <div class="card-body">
                        <div id="stats-free-trial-banner" class="hidden" style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.9rem; line-height: 1.4;"></div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                            <div>
                                <div class="stats-stat-row" title="Dev fee from your deployment configuration. <?= (int) CASHUPAY_DEV_FEE_PERCENT ?>% of (revenue − network costs).">
                                    <span class="stats-stat-label">Dev fee</span><span class="stats-stat-value" id="stats-fee-dev-paid">—</span>
                                </div>
                                <div class="stats-stat-row" title="Hosting / referral / deployment fee, configured per-store and paid to the hosting destination.">
                                    <span class="stats-stat-label">Hosting fee</span><span class="stats-stat-value" id="stats-fee-hosting-paid">—</span>
                                </div>
                                <div class="stats-stat-row" title="Lightning routing fees and on-chain miner fees consumed by user payouts and fee settlements (sum of melts.network_fee_sats).">
                                    <span class="stats-stat-label">Network fees</span><span class="stats-stat-value" id="stats-fee-network-paid">—</span>
                                </div>
                                <div class="stats-stat-row" title="Submarine-swap fees paid by customers on swap-rail invoices (provider percentage + on-chain lockup miner fee, bundled into each LN invoice the customer paid).">
                                    <span class="stats-stat-label">Swap fees</span><span class="stats-stat-value" id="stats-fee-swap-paid">—</span>
                                </div>
                                <hr style="border: 0; border-top: 1px solid var(--border); margin: 0.5rem 0;">
                                <div class="stats-stat-row"><span class="stats-stat-label">Total fees owed</span><span class="stats-stat-value" id="stats-fees-owed">—</span></div>
                                <div class="stats-stat-row"><span class="stats-stat-label">Total fees paid</span><span class="stats-stat-value" id="stats-fees-paid">—</span></div>
                                <div class="stats-stat-row" title="Total fees paid divided by total revenue, expressed as a percentage. Uses fees that actually settled, not amounts still owed.">
                                    <span class="stats-stat-label">Effective fee % (paid &divide; revenue)</span>
                                    <span class="stats-stat-value" id="stats-effective-fee">—</span>
                                </div>
                            </div>
                            <div>
                                <div style="position: relative; height: 240px;">
                                    <canvas id="stats-fee-chart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 1.5rem; padding: 1rem; border: 1px solid var(--border); border-radius: 8px; text-align: center;">
                            <div style="display: flex; gap: 0.75rem; align-items: center; justify-content: center; flex-wrap: wrap; margin-bottom: 0.5rem;">
                                <span style="color: var(--text-secondary); font-size: 0.9rem;">Compare against credit card fee of</span>
                                <select id="stats-cc-rate" class="form-input" style="width: auto; padding: 0.25rem 0.5rem;">
                                    <option value="2">2%</option>
                                    <option value="5" selected>5%</option>
                                    <option value="10">10%</option>
                                </select>
                            </div>
                            <div style="font-size: 2.25rem; font-weight: bold; color: var(--accent);" id="stats-cc-saved">—</div>
                            <div style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">saved vs credit cards</div>
                        </div>
                    </div>
                </div>

                <!-- Recent payouts -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Recent payouts</div>
                        <button type="button" class="btn btn-secondary" id="btn-export-payouts-csv">Export CSV</button>
                    </div>
                    <div class="card-body">
                        <div id="stats-payouts-table"><div class="loading"><div class="spinner"></div></div></div>
                        <div id="stats-payouts-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;"></div>
                    </div>
                </div>

                <!-- Recent fee payments -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Recent fee payments</div>
                        <button type="button" class="btn btn-secondary" id="btn-export-fee-payments-csv">Export CSV</button>
                    </div>
                    <div class="card-body">
                        <div id="stats-fee-payments-table"><div class="loading"><div class="spinner"></div></div></div>
                        <div id="stats-fee-payments-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;"></div>
                    </div>
                </div>

                <!-- Combined export -->
                <div class="card">
                    <div class="card-header"><div class="card-title">Export</div></div>
                    <div class="card-body">
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 0.75rem;">
                            Download a combined CSV containing all paid invoices, payouts, and fee payments for the current filter.
                        </p>
                        <button type="button" class="btn" id="btn-export-combined-csv">Download combined CSV</button>
                    </div>
                </div>
            </div>
        </main>

        <!-- Navigation -->
        <nav class="nav">
            <button class="nav-item active" data-view="dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                Home
            </button>
            <button class="nav-item" data-view="invoices">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                Invoices
            </button>
            <button class="nav-item" data-view="stores">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <path d="M9 22V12h6v10"></path>
                </svg>
                Store
            </button>
            <button class="nav-item hidden<?= $isManaged ? ' managed-hidden' : '' ?>" data-view="products" data-admin-only="true" id="nav-products">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Products
            </button>
            <button class="nav-item hidden" data-view="stats" data-admin-only="true" id="nav-stats">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
                Stats
            </button>
            <button class="nav-item hidden<?= $isManaged ? ' managed-hidden' : '' ?>" data-view="customers" data-admin-only="true" id="nav-customers">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                Customers
            </button>
            <button class="nav-item hidden" data-view="log" data-admin-only="true" id="nav-log">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="4 17 10 11 4 5"></polyline>
                    <line x1="12" y1="19" x2="20" y2="19"></line>
                </svg>
                Log
            </button>
            <button class="nav-item" data-view="settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                Settings
            </button>
        </nav>
    </div>

    <!-- Modals -->
    <div class="modal-overlay" id="modal-withdraw">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Withdraw to Lightning</div>

            <div class="form-group">
                <label class="form-label">Destination</label>
                <input type="text" class="form-input" id="withdraw-address"
                       placeholder="user@wallet.com or lnbc1..." oninput="handleDestinationInput()">
                <p class="form-help" id="withdraw-destination-help">Lightning address or BOLT-11 invoice</p>
            </div>

            <div class="form-group" id="withdraw-amount-group">
                <label class="form-label">Amount (<span class="unit-label">SAT</span>)</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="number" class="form-input" id="withdraw-amount"
                           placeholder="0" min="1" step="1" style="flex: 1;">
                    <button type="button" class="btn btn-secondary" id="btn-withdraw-max" onclick="withdrawMax()">Max</button>
                </div>
                <p class="form-help" id="withdraw-amount-fiat-equiv" style="display: none;"></p>
                <p class="form-help">Available: <span id="withdraw-available">0</span></p>
            </div>

            <button class="btn btn-full" id="btn-confirm-withdraw">Withdraw</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-withdraw')">Cancel</button>
        </div>
    </div>

    <div class="modal-overlay" id="modal-apikey">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title" id="modal-apikey-title">Create API Key</div>
            <div id="modal-apikey-content"></div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-request">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Create invoice (simple)</div>

            <div id="request-form">
                <div class="form-group">
                    <label class="form-label" id="request-amount-label">Amount</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="number" class="form-input" id="request-amount"
                               placeholder="100" min="1" step="1" style="flex:1;">
                        <select class="form-input" id="request-currency"
                                onchange="updateRequestAmountConstraints()"
                                style="width:auto; flex:0;"></select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description (optional)</label>
                    <input type="text" class="form-input" id="request-memo"
                           placeholder="Payment for...">
                </div>

                <div class="form-group">
                    <label style="display:flex; align-items:flex-start; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="request-hide-store-name" style="margin-top:0.15rem;">
                        <span style="font-size:0.85rem;">Hide store name on invoice
                            <span style="display:block; font-size:0.74rem; color:var(--text-secondary);">Keep the store name out of the invoice memo the payer's wallet records.</span>
                        </span>
                    </label>
                    <label style="display:flex; align-items:flex-start; gap:0.5rem; cursor:pointer; margin-top:0.5rem;">
                        <input type="checkbox" id="request-hide-note" style="margin-top:0.15rem;">
                        <span style="font-size:0.85rem;">Hide note on invoice
                            <span style="display:block; font-size:0.74rem; color:var(--text-secondary);">Keep the description above out of the invoice memo (still shown on the payment page).</span>
                        </span>
                    </label>
                </div>

                <div class="form-group" id="request-allow-any-mint-row" style="display:none;">
                    <label style="display:flex; align-items:flex-start; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="request-allow-any-mint" style="margin-top:0.15rem;">
                        <span style="font-size:0.85rem;">Allow payment from any mint
                            <span style="display:block; font-size:0.74rem; color:var(--text-secondary);">For offline Cashu acceptance on this payment only, accept a token from any mint (ignore the allowlist).</span>
                        </span>
                    </label>
                </div>

                <button class="btn btn-full" id="btn-generate-request">Go to Checkout</button>
            </div>

            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-request')">Cancel</button>
        </div>
    </div>

    <!-- Cart-based request modal: pick products into a cart, then checkout. -->
    <div class="modal-overlay" id="modal-cart">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-handle"></div>
            <div class="modal-title">Create invoice</div>

            <div style="display:flex; gap:0.5rem; margin-bottom:0.5rem;">
                <input type="text" class="form-input" id="cart-search" placeholder="Search products…" style="flex:1;">
                <select class="form-input" id="cart-sort" style="width:auto; flex:0 0 auto;">
                    <option value="most_purchased">Most purchased</option>
                    <option value="newest">Newest</option>
                    <option value="title_asc">Title A–Z</option>
                    <option value="price_asc">Price ↑</option>
                    <option value="price_desc">Price ↓</option>
                </select>
            </div>

            <div id="cart-catalog" style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border, rgba(255,255,255,0.1)); border-radius: 10px;">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            <div id="cart-pagination" style="display:flex; align-items:center; justify-content:center; gap:0.75rem; margin:0.5rem 0; font-size:0.85rem;"></div>

            <button class="btn btn-secondary btn-full" id="btn-add-custom-line" style="margin-bottom:0.5rem;">+ Add custom amount</button>
            <div id="cart-custom-form" style="display:none; gap:0.5rem; margin-bottom:0.5rem;">
                <div style="display:flex; gap:0.5rem;">
                    <input type="text" class="form-input" id="cart-custom-label" placeholder="Label (optional)" style="flex:1;">
                    <input type="number" class="form-input" id="cart-custom-amount" placeholder="Amount" min="0" step="any" style="width:120px;">
                    <button class="btn" id="btn-add-custom-confirm" style="width:auto; padding:0.4rem 0.9rem;">Add</button>
                </div>
            </div>

            <div class="card" style="padding:0.75rem; margin:0;">
                <div class="card-title" style="font-size:0.95rem; margin-bottom:0.5rem;">Cart</div>
                <div id="cart-items"><p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">Cart is empty. Tap a product to add it.</p></div>
                <div id="cart-total" style="font-weight:600; text-align:right; margin-top:0.5rem;"></div>
            </div>

            <input type="text" class="form-input" id="cart-memo" placeholder="Description (optional)" style="margin-top:0.5rem;">
            <label style="display:flex; align-items:flex-start; gap:0.5rem; cursor:pointer; margin-top:0.5rem;">
                <input type="checkbox" id="cart-hide-store-name" style="margin-top:0.15rem;">
                <span style="font-size:0.85rem;">Hide store name on invoice
                    <span style="display:block; font-size:0.74rem; color:var(--text-secondary);">Keep the store name out of the invoice memo the payer's wallet records.</span>
                </span>
            </label>
            <label style="display:flex; align-items:flex-start; gap:0.5rem; cursor:pointer; margin-top:0.5rem;">
                <input type="checkbox" id="cart-hide-note" style="margin-top:0.15rem;">
                <span style="font-size:0.85rem;">Hide note on invoice
                    <span style="display:block; font-size:0.74rem; color:var(--text-secondary);">Keep the description above out of the invoice memo (still shown on the payment page).</span>
                </span>
            </label>
            <button class="btn btn-full" id="btn-cart-checkout" style="margin-top:0.5rem;" disabled>Checkout</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-cart')">Cancel</button>
        </div>
    </div>

    <!-- Product create/edit (admin) -->
    <div class="modal-overlay" id="modal-product-edit">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title" id="product-edit-title">New product</div>
            <input type="hidden" id="product-edit-id">

            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" class="form-input" id="product-edit-title-input" maxlength="200" placeholder="Coffee">
            </div>
            <div class="form-group">
                <label class="form-label">Price (<span id="product-edit-currency">sat</span>)</label>
                <input type="number" class="form-input" id="product-edit-price" min="0" step="any" placeholder="100">
            </div>
            <div class="form-group">
                <label class="form-label">Image</label>
                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                    <div id="product-edit-image-preview" style="width:56px; height:56px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:2rem; background:rgba(0,0,0,0.2); overflow:hidden; flex-shrink:0;">📦</div>
                    <input type="text" class="form-input" id="product-edit-emoji" placeholder="Emoji ☕" maxlength="8" style="width:120px;">
                    <button type="button" class="btn btn-secondary" id="btn-product-emoji-pick" title="Pick an emoji" style="width:auto; padding:0.4rem 0.7rem; font-size:1.1rem; line-height:1;">＋</button>
                    <button type="button" class="btn btn-secondary" id="btn-product-upload" style="width:auto; padding:0.4rem 0.8rem;">Upload</button>
                    <button type="button" class="btn btn-secondary" id="btn-product-clear-image" style="width:auto; padding:0.4rem 0.8rem;">Clear</button>
                    <input type="file" id="product-edit-file" accept="image/png,image/jpeg,image/webp" style="display:none;">
                </div>
                <div id="product-edit-emoji-picker" style="display:none; margin-top:0.5rem; padding:0.5rem; border:1px solid var(--border, rgba(255,255,255,0.1)); border-radius:10px; max-height:180px; overflow-y:auto;">
                    <div style="display:grid; grid-template-columns:repeat(8, 1fr); gap:0.25rem;"></div>
                </div>
                <p class="form-help">Pick an emoji with ＋, type one, or upload a PNG/JPG/WebP (≤2&nbsp;MB). Defaults to 📦.</p>
            </div>
            <div id="product-edit-error" style="color:#ef4444; font-size:0.85rem; margin-bottom:0.5rem; display:none;"></div>
            <button class="btn btn-full" id="btn-save-product">Save product</button>
            <button class="btn btn-secondary btn-full" style="margin-top:0.5rem;" onclick="closeModal('modal-product-edit')">Cancel</button>
        </div>
    </div>

    <!-- Admin invoice line-item detail -->
    <div class="modal-overlay" id="modal-invoice-detail">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Invoice items</div>
            <div id="invoice-detail-content"><div class="loading"><div class="spinner"></div></div></div>
            <button class="btn btn-secondary btn-full" style="margin-top:0.5rem;" onclick="closeModal('modal-invoice-detail')">Close</button>
        </div>
    </div>

    <div class="modal-overlay" id="modal-store">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title" id="store-modal-title">Store Details</div>
            <div id="store-modal-content"></div>
        </div>
    </div>

    <!-- Mint Diagnostic modal -->
    <div class="modal-overlay" id="modal-mint-diagnostic">
        <div class="modal" style="max-width: 720px;">
            <div class="modal-handle"></div>
            <div class="modal-title" id="mint-diagnostic-title">Mint Diagnostic</div>
            <div id="mint-diagnostic-content">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.75rem;" onclick="closeModal('modal-mint-diagnostic')">Close</button>
        </div>
    </div>

    <!-- Change My Password modal -->
    <div class="modal-overlay" id="modal-change-password">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Change my password</div>
            <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                Re-enter your current password to confirm, then set a new one (min 8 characters).
            </p>
            <div class="form-group">
                <input type="password" class="form-input" id="cp-current"
                       placeholder="Current password" autocomplete="current-password">
            </div>
            <div class="form-group">
                <input type="password" class="form-input" id="cp-new"
                       placeholder="New password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <input type="password" class="form-input" id="cp-confirm"
                       placeholder="Confirm new password" autocomplete="new-password">
            </div>
            <button class="btn btn-full" id="btn-confirm-change-password">Update password</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;"
                    onclick="closeModal('modal-change-password')">Cancel</button>
        </div>
    </div>

    <!-- Forgot password modal (lock screen). Documents both recovery paths. -->
    <div class="modal-overlay" id="modal-forgot-password">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Forgot your password?</div>

            <p style="color: var(--text-secondary); margin-bottom: 0.75rem; font-size: 0.9rem;">
                <strong>Option 1 — Email a reset link.</strong> If a recovery email is set on the admin
                account and the server can send email, we'll send a one-hour reset link.
            </p>
            <div class="form-group">
                <input type="email" class="form-input" id="fp-email"
                       placeholder="Admin recovery email" autocomplete="email">
            </div>
            <button class="btn btn-full" id="btn-send-reset-link">Email me a reset link</button>
            <div id="fp-message" style="font-size: 0.85rem; margin-top: 0.6rem; min-height: 1.1em;"></div>

            <hr style="border:0;border-top:1px solid var(--border, #2a2d36);margin:1.1rem 0;">

            <p style="color: var(--text-secondary); margin-bottom: 0.4rem; font-size: 0.9rem;">
                <strong>Option 2 — Reset via a file on the server.</strong> If you have filesystem
                access (SSH / SFTP / file manager) but no email, create an empty file named
                <code>reset-admin-password</code> inside the server's <code>data/</code> directory:
            </p>
            <pre style="background:rgba(0,0,0,0.3);border-radius:6px;padding:0.6rem 0.75rem;font-size:0.8rem;overflow:auto;margin:0 0 0.5rem;">data/reset-admin-password</pre>
            <p style="color: var(--text-secondary); margin: 0 0 1rem; font-size: 0.85rem;">
                Then reload the sign-in page: it will prompt you to set a new admin password and
                delete the file automatically. Remove the file yourself if you change your mind.
            </p>

            <button class="btn btn-secondary btn-full"
                    onclick="closeModal('modal-forgot-password')">Close</button>
        </div>
    </div>

    <!-- Add User modal (admin) -->
    <div class="modal-overlay" id="modal-add-user">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Add user</div>
            <div class="form-group">
                <input type="text" class="form-input" id="au-username"
                       placeholder="Username (3-32 chars, letters/digits/_-)" autocomplete="off">
            </div>
            <div class="form-group">
                <input type="password" class="form-input" id="au-password"
                       placeholder="Initial password (min 8)" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <select id="au-role" class="form-input">
                    <option value="user">User (read + create invoices)</option>
                    <option value="admin">Admin (full access)</option>
                </select>
            </div>
            <button class="btn btn-full" id="btn-confirm-add-user">Create user</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;"
                    onclick="closeModal('modal-add-user')">Cancel</button>
        </div>
    </div>

    <!-- Reset another user's password (admin) -->
    <div class="modal-overlay" id="modal-reset-user-password">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Reset password for <span id="rup-username"></span></div>
            <input type="hidden" id="rup-user-id">
            <div class="form-group">
                <input type="password" class="form-input" id="rup-new"
                       placeholder="New password (min 8)" autocomplete="new-password">
            </div>
            <button class="btn btn-full" id="btn-confirm-reset-user-password">Reset password</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;"
                    onclick="closeModal('modal-reset-user-password')">Cancel</button>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <!-- On-chain save confirm (replaces native confirm() which Chrome will
         silently suppress if the user once ticked "Prevent additional dialogs"). -->
    <div class="modal-overlay" id="modal-onchain-confirm">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title" id="modal-onchain-confirm-title">Confirm change</div>
            <p style="color: var(--text-secondary); margin-bottom: 1rem;" id="modal-onchain-confirm-body">
            </p>
            <button class="btn btn-full" id="btn-onchain-confirm-yes">Continue</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" id="btn-onchain-confirm-no">Cancel</button>
        </div>
    </div>

    <!-- Mint Discovery Modal -->
    <div id="mint-discovery-modal" class="modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1100; justify-content: center; align-items: center;">
        <div class="modal-content" style="max-width: 700px; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; background: var(--card-bg); border-radius: 12px; padding: 1.5rem;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Discover Mints</h3>
                <button type="button" onclick="closeMintDiscoveryModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text);">&times;</button>
            </div>

            <div style="background: rgba(237, 137, 54, 0.15); border: 1px solid rgba(237, 137, 54, 0.4); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <p style="font-size: 0.85rem; color: var(--warning); margin: 0 0 0.75rem 0; line-height: 1.5;">
                    Audit data is provided by independent third parties to help assess a mint's reliability over time. However, these results are informational only and do not guarantee the safety, solvency, or trustworthiness of any mint. Always conduct your own research and ensure you trust the mint operator before using their services. To be sure, run your own mint, this is the Bitcoin way!
                </p>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="mint-disclaimer-checkbox" onchange="onMintDisclaimerChange(this)" style="width: 18px; height: 18px;">
                    <span style="font-size: 0.85rem; color: var(--warning);">I understand the above</span>
                </label>
            </div>

            <div id="mint-discovery-status" style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                Loading mints from Nostr...
            </div>

            <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                <input type="text" id="mint-discovery-search" class="form-input" placeholder="Search mints..." style="flex: 1;" onkeyup="filterDiscoveredMints()">
                <select id="mint-discovery-unit-filter" class="form-input" style="width: auto;" onchange="filterDiscoveredMints()">
                    <option value="">All units</option>
                    <option value="sat">SAT</option>
                    <option value="eur">EUR</option>
                    <option value="usd">USD</option>
                </select>
                <button type="button" class="btn btn-secondary" onclick="startMintDiscovery()" style="white-space: nowrap;">Refresh</button>
            </div>

            <div id="mint-discovery-list" style="flex: 1; overflow-y: auto; max-height: 400px;">
                <p style="color: var(--text-secondary); font-size: 0.9rem; text-align: center; padding: 2rem;">
                    Loading...
                </p>
            </div>

            <div id="mint-discovery-loading" style="display: none; text-align: center; padding: 2rem;">
                <div class="spinner" style="width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--text-secondary);">Connecting to Nostr relays...</p>
            </div>
        </div>
    </div>

    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>mint-discovery.bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script type="module">
        // Import bc-ur library as ES module
        import { UR, UREncoder } from 'https://cdn.skypack.dev/@gandlaf21/bc-ur@1.1.12';

        // Expose to global scope for AnimatedQR class
        window.bcur = { UR, UREncoder };
    </script>
    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>animated-qr.js?v=4"></script>
    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>chart.min.js"></script>
    <script>
        // PHP session state, used to skip the password prompt on reload when
        // the server still considers us logged in.
        const phpLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
        // Server-rendered identity used by the lock screen and the Settings
        // visibility logic.
        const phpUser = {
            username: <?= json_encode($currentUsername) ?>,
            role:     <?= json_encode($currentRole) ?>,
            email:    <?= json_encode($currentUser['email'] ?? null) ?>,
        };
        // SPA routing: adminBasePath is the URL prefix the user reached the
        // admin under (no trailing slash, no view tail). All view URLs and
        // ?api=... fetches build off this so the same code works under
        // direct and router modes — and under PATH_INFO where relative URLs
        // would otherwise resolve incorrectly. adminUsesPathUrls picks the
        // view-URL shape: path-style (/admin/invoices) where the host
        // provably routes it, query-style (admin.php?view=invoices)
        // everywhere else — see the server-side routing block above.
        const adminBasePath = <?= json_encode($adminBasePath) ?>;
        const adminUsesPathUrls = <?= json_encode($adminUsesPathUrls) ?>;
        const adminUrl = adminBasePath;
        const setupUrl = <?= json_encode(Urls::setup()) ?>;
        // Public shop front page (managed installs) — where payer-facing
        // redirect links for admin-created invoices should land. The admin
        // SPA's own URL is login-gated, so it must never be handed to a
        // payer. Null in standalone mode, where there is no public shop to
        // return to.
        const shopHomeUrl = <?= json_encode(ManagedInstall::shopUrl() ?: null) ?>;
        // Where to point "get a free lightning address" links (Strike). Operator
        // override via CASHUPAY_STRIKE_URL in user_config.php; defaults to strike.me.
        const strikeUrl = <?= json_encode(defined('CASHUPAY_STRIKE_URL') ? CASHUPAY_STRIKE_URL : 'http://strike.me') ?>;

        // Server-parsed view slug (validated against the allowed list above).
        const initialView = <?= json_encode($adminView) ?>;
        const ADMIN_VIEWS = <?= json_encode(ADMIN_VIEWS) ?>;

        // URL mode config (embedded from PHP)
        const urlModeConfig = {
            currentMode: <?= json_encode(Config::getUrlMode()) ?>,
            baseUrl: <?= json_encode(Urls::siteBase()) ?>
        };

        // State
        let isAuthenticated = false;
        let dashboardData = null;

        // Local Storage Keys
        const STORAGE_AUTH = 'cashupay_auth';

        // M2: CSRF token helper - reads from meta tag dynamically
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        }

        // Server URL for e-commerce integration
        let serverUrl = <?= json_encode(Urls::server()) ?>;

        // Helper for POST requests with CSRF token
        async function postWithCsrf(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: body
            });
        }

        // Parse a fetch Response as JSON without ever throwing an unhandled
        // rejection: a PHP fatal, an expired-session redirect, or any non-JSON
        // body would otherwise make r.json() reject and leave the calling button
        // dead-silent (no toast, no inline error). Returns { ok, status, data }
        // where data is {} when the body could not be parsed.
        async function readJsonResponse(r) {
            let text = '';
            try { text = await r.text(); } catch (e) { text = ''; }
            let data = {};
            try { data = text ? JSON.parse(text) : {}; } catch (e) { data = {}; }
            return { ok: r.ok, status: r.status, data };
        }

        // Format amount for display based on unit (handles fiat decimals)
        function formatAmount(amount, unit) {
            const u = (unit || 'sat').toLowerCase();
            if (u === 'sat' || u === 'msat') {
                return Math.floor(amount).toLocaleString();
            }
            // Fiat: divide by 100 for cents → main unit
            return (amount / 100).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Parse user input to smallest unit (handles fiat decimals)
        function parseAmount(value, unit) {
            const u = (unit || 'sat').toLowerCase();
            const num = parseFloat(value) || 0;
            if (u === 'sat' || u === 'msat') {
                return Math.floor(num);
            }
            // Fiat: multiply by 100 for main unit → cents
            return Math.round(num * 100);
        }

        // Check if unit is fiat (needs decimal display)
        function isFiatUnit(unit) {
            const u = (unit || 'sat').toLowerCase();
            return u !== 'sat' && u !== 'msat';
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            checkAuth();
            setupEventListeners();
            setupStatsListeners();
        });

        // Check authentication state
        function checkAuth() {
            if (phpLoggedIn) {
                showApp();
            } else {
                // No server session — password lock; PHP already rendered
                // the lock screen visible.
                showLockScreen();
            }
        }

        function showLockScreen() {
            document.getElementById('lock-screen').classList.remove('hidden');
            document.getElementById('app').classList.remove('visible');
        }

        async function showApp() {
            document.getElementById('lock-screen').classList.add('hidden');
            document.getElementById('app').classList.add('visible');
            isAuthenticated = true;
            localStorage.setItem(STORAGE_AUTH, 'true');

            if (phpUser.role === 'admin') {
                const navStats = document.getElementById('nav-stats');
                if (navStats) navStats.classList.remove('hidden');
                const navProducts = document.getElementById('nav-products');
                if (navProducts) navProducts.classList.remove('hidden');
                const navCustomers = document.getElementById('nav-customers');
                if (navCustomers) navCustomers.classList.remove('hidden');
                const navLog = document.getElementById('nav-log');
                if (navLog) navLog.classList.remove('hidden');
            }

            // Check for store_created parameter from setup.php redirect
            const urlParams = new URLSearchParams(window.location.search);
            const createdStoreId = urlParams.get('store_created');

            if (createdStoreId) {
                // Set as current store so it gets selected
                currentStoreId = createdStoreId;
                localStorage.setItem('selectedStoreId', createdStoreId);

                // Strip ?store_created= but keep the current SPA view path
                // (e.g. /admin/dashboard) so refreshes still land here.
                urlParams.delete('store_created');
                const qs = urlParams.toString();
                window.history.replaceState({}, document.title,
                    window.location.pathname + (qs ? '?' + qs : ''));
            }

            await loadDashboard();

            // Show success toast after dashboard loaded
            if (createdStoreId) {
                showToast('Store created successfully!', 'success');
            }

            // Restore the server-parsed view (e.g. operator refreshed while on
            // /admin/invoices). Dashboard is already active from the markup, so
            // we only call switchView for non-dashboard views to avoid a
            // wasted re-render.
            if (initialView && initialView !== 'dashboard') {
                switchView(initialView, { replace: true });
            }
        }

        // Event Listeners
        function setupEventListeners() {
            // Password login
            document.getElementById('password-submit').addEventListener('click', async () => {
                const username = document.getElementById('username-input').value.trim();
                const password = document.getElementById('password-input').value;
                if (!username) {
                    showToast('Username is required', 'error');
                    return;
                }
                try {
                    const response = await fetch(adminUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=login&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`
                    });

                    if (response.ok) {
                        const data = await response.json();

                        // Update CSRF token after login
                        if (data.csrfToken) {
                            const metaTag = document.querySelector('meta[name="csrf-token"]');
                            if (metaTag) {
                                metaTag.content = data.csrfToken;
                            }
                        }

                        // Hydrate phpUser from the login response so the
                        // Settings UI knows who we are without a reload.
                        if (data.user) {
                            phpUser.username = data.user.username || phpUser.username;
                            phpUser.role     = data.user.role     || phpUser.role;
                        }

                        showApp();
                    } else {
                        showToast('Invalid username or password', 'error');
                    }
                } catch (e) {
                    showToast('Login failed', 'error');
                }
            });

            ['username-input', 'password-input'].forEach(id => {
                document.getElementById(id).addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        document.getElementById('password-submit').click();
                    }
                });
            });

            // ----- Password recovery (lock screen) -----
            const forgotLink = document.getElementById('forgot-password-link');
            if (forgotLink) {
                forgotLink.addEventListener('click', () => {
                    const m = document.getElementById('fp-message');
                    if (m) { m.textContent = ''; m.className = ''; }
                    openModal('modal-forgot-password');
                });
            }

            const sendResetBtn = document.getElementById('btn-send-reset-link');
            if (sendResetBtn) {
                sendResetBtn.addEventListener('click', async () => {
                    const email = (document.getElementById('fp-email').value || '').trim();
                    const msg = document.getElementById('fp-message');
                    if (!email) {
                        msg.textContent = 'Enter the recovery email for the admin account.';
                        msg.style.color = '#ef4444';
                        return;
                    }
                    msg.textContent = 'Sending...';
                    msg.style.color = 'var(--text-secondary)';
                    try {
                        const r = await fetch(adminUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=request_password_reset&email=${encodeURIComponent(email)}`
                        });
                        const data = await r.json().catch(() => ({}));
                        if (r.ok) {
                            // Generic response — never reveals whether the address matched.
                            msg.textContent = data.message || 'If an admin account has that email, a reset link has been sent.';
                            msg.style.color = '#22c55e';
                        } else {
                            msg.textContent = data.error || 'Could not send reset link.';
                            msg.style.color = '#ef4444';
                        }
                    } catch (e) {
                        msg.textContent = 'Network error, please try again.';
                        msg.style.color = '#ef4444';
                    }
                });
            }

            const fileResetBtn = document.getElementById('file-reset-submit');
            if (fileResetBtn) {
                fileResetBtn.addEventListener('click', async () => {
                    const pw = document.getElementById('file-reset-pw').value;
                    const pw2 = document.getElementById('file-reset-pw2').value;
                    if (pw.length < 8) { showToast('Password must be at least 8 characters', 'error'); return; }
                    if (pw !== pw2) { showToast('Passwords do not match', 'error'); return; }
                    try {
                        const r = await fetch(adminUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=file_reset_set_password&new_password=${encodeURIComponent(pw)}`
                        });
                        const data = await r.json().catch(() => ({}));
                        if (r.ok && data.success) {
                            showToast('Password set. You can now sign in.', 'success');
                            const box = document.getElementById('file-reset-box');
                            if (box) box.remove();
                            document.getElementById('password-input').value = pw;
                        } else {
                            showToast(data.error || 'Could not reset password', 'error');
                        }
                    } catch (e) {
                        showToast('Network error, please try again', 'error');
                    }
                });
            }

            // Navigation
            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    const view = item.dataset.view;
                    switchView(view);
                });
            });

            // Store selector
            document.getElementById('store-select').addEventListener('change', onStoreSelectChange);

            // Header buttons
            document.getElementById('refresh-btn').addEventListener('click', loadDashboard);
            setupUserMenu();

            // Balance actions
            document.getElementById('btn-withdraw').addEventListener('click', () => {
                if (!ensureAdmin('Only admins can withdraw funds.')) return;
                openModal('modal-withdraw');
            });
            document.getElementById('btn-request').addEventListener('click', () => openCartModal());
            const btnReqSimple = document.getElementById('btn-request-simple');
            if (btnReqSimple) btnReqSimple.addEventListener('click', () => openRequestModal());
            document.getElementById('btn-new-invoice').addEventListener('click', () => openRequestModal());

            // Products admin view + cart modal wiring.
            const btnNewProduct = document.getElementById('btn-new-product');
            if (btnNewProduct) btnNewProduct.addEventListener('click', () => openProductEditModal(null));
            const prodSortSel = document.getElementById('products-default-sort');
            if (prodSortSel) prodSortSel.addEventListener('change', saveProductDefaultSort);
            wireCartModal();
            wireProductEditModal();

            // Invoices status filter: persist choice in the URL + refetch.
            const invStatusSel = document.getElementById('invoice-status-filter');
            if (invStatusSel) {
                invStatusSel.addEventListener('change', () => {
                    writeInvoiceFilterToUrl(getInvoiceStatusFilter());
                    invoicesState.offset = 0;
                    loadInvoices();
                });
            }

            // Invoices store filter: picking a concrete store keeps the global
            // header selector in sync; "All stores" is an invoices-only view.
            const invStoreSel = document.getElementById('invoice-store-filter');
            if (invStoreSel) {
                invStoreSel.addEventListener('change', () => {
                    const val = invStoreSel.value;
                    if (val && val !== '__all__') {
                        currentStoreId = val;
                        localStorage.setItem('selectedStoreId', currentStoreId);
                        const header = document.getElementById('store-select');
                        if (header) header.value = currentStoreId;
                    }
                    invoicesState.offset = 0;
                    loadInvoices();
                });
            }

            // Customers view: newsletter + store filters and CSV export.
            const custSubSel = document.getElementById('customer-subscription-filter');
            if (custSubSel) {
                custSubSel.addEventListener('change', () => {
                    customersState.offset = 0;
                    loadCustomers();
                });
            }
            const custStoreSel = document.getElementById('customer-store-filter');
            if (custStoreSel) {
                custStoreSel.addEventListener('change', () => {
                    customersState.offset = 0;
                    loadCustomers();
                });
            }
            const custExportBtn = document.getElementById('btn-export-customers-csv');
            if (custExportBtn) {
                custExportBtn.addEventListener('click', () => {
                    const params = new URLSearchParams({ api: 'export_customers_csv' });
                    const store = getCustomerStoreFilter();
                    if (store && store !== '__all__') params.set('store_id', store);
                    const sub = getCustomerSubscriptionFilter();
                    if (sub) params.set('subscription', sub);
                    window.location = adminUrl + '?' + params.toString();
                });
            }

            // Log view: filters, refresh, and the settings-card save button.
            const logCatSel = document.getElementById('log-category-filter');
            if (logCatSel) {
                logCatSel.addEventListener('change', () => {
                    adminLogState.offset = 0;
                    loadAdminLog();
                });
            }
            const logStoreSel = document.getElementById('log-store-filter');
            if (logStoreSel) {
                logStoreSel.addEventListener('change', () => {
                    adminLogState.offset = 0;
                    loadAdminLog();
                });
            }
            const logRefreshBtn = document.getElementById('btn-refresh-log');
            if (logRefreshBtn) logRefreshBtn.addEventListener('click', () => loadAdminLog());
            const btnSaveInvoiceErrors = document.getElementById('btn-save-invoice-errors');
            if (btnSaveInvoiceErrors) btnSaveInvoiceErrors.addEventListener('click', saveInvoiceErrorSettings);

            // Withdraw modal
            document.getElementById('btn-confirm-withdraw').addEventListener('click', handleWithdraw);
            document.getElementById('withdraw-amount').addEventListener('input', updateWithdrawInfo);

            // Request modal
            document.getElementById('btn-generate-request').addEventListener('click', handleGenerateRequest);

            // Settings
            document.getElementById('btn-save-auto-melt').addEventListener('click', saveAutoMelt);
            const btnSaveLightning = document.getElementById('btn-save-lightning-payments');
            if (btnSaveLightning) btnSaveLightning.addEventListener('click', saveLightningPayments);
            const btnAddLnAddr = document.getElementById('btn-add-ln-address');
            if (btnAddLnAddr) btnAddLnAddr.addEventListener('click', addLnAddressRow);
            const btnAddNoffer = document.getElementById('btn-add-noffer');
            if (btnAddNoffer) btnAddNoffer.addEventListener('click', addNofferRow);
            const btnAddNwc = document.getElementById('btn-add-nwc');
            if (btnAddNwc) btnAddNwc.addEventListener('click', addNwcRow);
            const btnAddStrike = document.getElementById('btn-add-strike');
            if (btnAddStrike) btnAddStrike.addEventListener('click', addStrikeRow);
            // Live-update LN-address vs swap-mode hint pane when the operator
            // changes the dropdown, even before they save.
            const autoMeltModeSel = document.getElementById('auto-melt-mode-override');
            if (autoMeltModeSel) autoMeltModeSel.addEventListener('change', () => {
                if (!dashboardData || !dashboardData.autoMelt) return;
                const v = parseInt(autoMeltModeSel.value, 10);
                const mode = (v === 1) ? 'swap' : 'lightning';
                dashboardData.autoMelt = { ...dashboardData.autoMelt, modeOverride: v, mode };
                renderAutoMeltMode();
            });
            // Wire collapsible cards/subsections and the auto-cashout column
            // selector.
            wireCollapsibles();
            wireAwSelectors();
            const saveStoreNotifsBtn = document.getElementById('btn-save-store-notifications');
            if (saveStoreNotifsBtn) saveStoreNotifsBtn.addEventListener('click', saveStoreNotifications);
            const saveStorePrivacyBtn = document.getElementById('btn-save-store-privacy');
            if (saveStorePrivacyBtn) saveStorePrivacyBtn.addEventListener('click', saveStorePrivacy);
            const storeNotifsToggle = document.getElementById('store-notifications-enabled');
            if (storeNotifsToggle) storeNotifsToggle.addEventListener('change', () => {
                if (storeNotifsToggle.checked) warnIfSiteEmailUnavailable();
            });
            const storeSmtpToggle = document.getElementById('store-smtp-override-enabled');
            if (storeSmtpToggle) storeSmtpToggle.addEventListener('change', updateStoreSmtpFieldsVisibility);
            const storeTestBtn = document.getElementById('btn-send-store-test-notification');
            if (storeTestBtn) storeTestBtn.addEventListener('click', sendStoreTestNotification);
            const saveNotifsBtn = document.getElementById('btn-save-notifications');
            if (saveNotifsBtn) saveNotifsBtn.addEventListener('click', saveNotificationSettings);
            const payerCaptureToggle = document.getElementById('notifications-payer-email-capture');
            if (payerCaptureToggle) payerCaptureToggle.addEventListener('change', updatePayerCaptureDependents);
            const saveDevBtn = document.getElementById('btn-save-developer');
            if (saveDevBtn) saveDevBtn.addEventListener('click', saveDeveloperSettings);
            const testNotifsBtn = document.getElementById('btn-send-test-notification');
            if (testNotifsBtn) testNotifsBtn.addEventListener('click', sendTestNotification);
            const saveStoreSwapsBtn = document.getElementById('btn-save-store-swaps');
            if (saveStoreSwapsBtn) saveStoreSwapsBtn.addEventListener('click', saveStoreSwaps);
            const revealSeedBtn = document.getElementById('btn-reveal-store-seed');
            if (revealSeedBtn) revealSeedBtn.addEventListener('click', revealStoreSeed);
            const saveStoreSelfServeBtn = document.getElementById('btn-save-store-selfserve');
            if (saveStoreSelfServeBtn) saveStoreSelfServeBtn.addEventListener('click', saveStoreSelfServe);
            const copySelfServeLinkBtn = document.getElementById('btn-copy-selfserve-link');
            if (copySelfServeLinkBtn) copySelfServeLinkBtn.addEventListener('click', copySelfServeLink);
            const copyInfoSelfServeLinkBtn = document.getElementById('btn-copy-store-info-selfserve');
            if (copyInfoSelfServeLinkBtn) copyInfoSelfServeLinkBtn.addEventListener('click', () => copyLinkFromEl('store-info-selfserve-link'));
            const copyInvoicesSelfServeLinkBtn = document.getElementById('btn-copy-invoices-selfserve-link');
            if (copyInvoicesSelfServeLinkBtn) copyInvoicesSelfServeLinkBtn.addEventListener('click', () => {
                const el = document.getElementById('invoices-selfserve-link');
                if (!el || !el.value) return;
                navigator.clipboard.writeText(el.value).then(() => showToast('Link copied!', 'success'))
                    .catch(() => showToast('Could not copy link', 'error'));
            });
            document.getElementById('btn-validate-onchain').addEventListener('click', validateOnchainXpub);
            document.getElementById('btn-test-onchain').addEventListener('click', testOnchainCurrent);
            document.getElementById('btn-save-onchain').addEventListener('click', saveOnchain);
            document.getElementById('onchain-xpub').addEventListener('input', applyOnchainAddressTypeVisibility);
            document.getElementById('onchain-mode').addEventListener('change', applyOnchainModeVisibility);
            document.getElementById('btn-save-exchange-settings').addEventListener('click', saveExchangeSettings);
            document.getElementById('btn-save-hosting-fee').addEventListener('click', saveHostingFee);
            const copyCronBtn = document.getElementById('btn-copy-cron-url');
            if (copyCronBtn) copyCronBtn.addEventListener('click', copyCronUrl);
            const copyCronSwapsBtn = document.getElementById('btn-copy-cron-swaps-url');
            if (copyCronSwapsBtn) copyCronSwapsBtn.addEventListener('click', copyCronSwapsUrl);
            const saveChannelBtn = document.getElementById('btn-save-update-channel');
            if (saveChannelBtn) saveChannelBtn.addEventListener('click', saveUpdateChannel);
            const rollbackBtn = document.getElementById('btn-rollback-update');
            if (rollbackBtn) rollbackBtn.addEventListener('click', rollbackUpdate);
            const dismissAutoRbBtn = document.getElementById('btn-dismiss-auto-rollback');
            if (dismissAutoRbBtn) dismissAutoRbBtn.addEventListener('click', dismissAutoRollback);
            const updateNowBtn = document.getElementById('btn-update-now');
            if (updateNowBtn) updateNowBtn.addEventListener('click', startManualUpdate);
            const updateNowBannerBtn = document.getElementById('btn-update-now-banner');
            if (updateNowBannerBtn) updateNowBannerBtn.addEventListener('click', startManualUpdate);
            const dismissCronBtn = document.getElementById('btn-dismiss-cron-stale');
            if (dismissCronBtn) dismissCronBtn.addEventListener('click', dismissCronStaleBanner);
            document.getElementById('btn-logout').addEventListener('click', logout);

            // My Account + Users
            const btnChangePass = document.getElementById('btn-change-own-password');
            if (btnChangePass) {
                btnChangePass.addEventListener('click', () => openModal('modal-change-password'));
                document.getElementById('btn-confirm-change-password').addEventListener('click', changeOwnPassword);
            }
            const btnSaveRecoveryEmail = document.getElementById('btn-save-recovery-email');
            if (btnSaveRecoveryEmail) {
                btnSaveRecoveryEmail.addEventListener('click', saveRecoveryEmail);
            }
            const btnAddUser = document.getElementById('btn-add-user');
            if (btnAddUser) {
                btnAddUser.addEventListener('click', () => openModal('modal-add-user'));
                document.getElementById('btn-confirm-add-user').addEventListener('click', addUser);
                document.getElementById('btn-confirm-reset-user-password').addEventListener('click', confirmResetUserPassword);
            }

            // URL Mode re-detect button (standalone only). Detection is normally
            // run once during the setup wizard and persisted; this button is the
            // escape hatch for environment changes (e.g. enabling rewrite rules
            // after install).
            const btnDetectUrlMode = document.getElementById('btn-detect-url-mode');
            if (btnDetectUrlMode) {
                btnDetectUrlMode.addEventListener('click', detectAndSaveUrlMode);
            }

            // Stores
            document.getElementById('btn-create-store').addEventListener('click', () => {
                window.location.href = setupUrl + '?mode=add_store';
            });

            // Store Settings
            document.getElementById('btn-edit-store').addEventListener('click', () => {
                if (currentStoreId) {
                    const store = dashboardData?.stores?.find(s => s.id === currentStoreId);
                    if (store) showStoreDetails(currentStoreId, store.name);
                }
            });
            document.getElementById('btn-create-api-key').addEventListener('click', () => {
                if (currentStoreId) {
                    createApiKey(currentStoreId);
                }
            });
            document.getElementById('btn-delete-store').addEventListener('click', () => {
                if (currentStoreId) {
                    deleteStore(currentStoreId);
                }
            });

            // Mint reliability + trusted mints wiring (admin-only cards).
            const btnResetAll = document.getElementById('btn-reset-all-mint-counters');
            if (btnResetAll) btnResetAll.addEventListener('click', resetAllMintCounters);
            const btnSaveTm = document.getElementById('btn-save-trusted-mints');
            if (btnSaveTm) btnSaveTm.addEventListener('click', saveTrustedMintsSettings);
            const btnRefreshTm = document.getElementById('btn-refresh-trusted-mints');
            if (btnRefreshTm) btnRefreshTm.addEventListener('click', refreshTrustedMintsNow);

            // Diagnostic report export (admin-only card at the bottom of settings).
            const diagAnon = document.getElementById('diagnostics-anonymize');
            const diagWarning = document.getElementById('diagnostics-deanon-warning');
            if (diagAnon && diagWarning) {
                diagAnon.addEventListener('change', () => {
                    diagWarning.hidden = diagAnon.checked;
                });
            }
            const downloadDiagnostics = (range) => {
                const params = new URLSearchParams({
                    api: 'export_diagnostic_report',
                    range: range,
                    anonymize: (diagAnon && diagAnon.checked) ? '1' : '0',
                });
                window.location.href = adminUrl + '?' + params.toString();
            };
            const btnDiagAll = document.getElementById('btn-export-diagnostics-all');
            if (btnDiagAll) btnDiagAll.addEventListener('click', () => downloadDiagnostics('all'));
            const btnDiag30 = document.getElementById('btn-export-diagnostics-30d');
            if (btnDiag30) btnDiag30.addEventListener('click', () => downloadDiagnostics('1m'));

            // Banner "Open settings" links — kept on a dedicated class instead
            // of sharing data-view="settings" with the sidebar nav button, so
            // tests can target the nav unambiguously.
            document.querySelectorAll('.js-goto-settings').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    switchView('settings');
                });
            });

            // Banner "Open invoices" link mirrors the goto-settings pattern.
            document.querySelectorAll('.js-goto-invoices').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    switchView('invoices');
                });
            });

            // Settings-scope banner link to the Store Settings view.
            document.querySelectorAll('.js-goto-stores').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    switchView('stores');
                });
            });

            // Modal close on overlay click
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.classList.remove('visible');
                    }
                });
            });

            // Delegated handler for mint-reliability buttons rendered via
            // innerHTML in dynamic lists (mintDiagnosticIcon + diagnostic
            // modal + admin mint cards). Keeps the mint URL out of inline
            // JS — it lives only in a data-mint-url attribute (escapeAttr'd).
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-mint-action]');
                if (!btn) return;
                const url = btn.dataset.mintUrl;
                if (!url) return;
                e.stopPropagation();
                switch (btn.dataset.mintAction) {
                    case 'diagnostic':  openMintDiagnostic(url); break;
                    case 'reenable':    adminReenableMint(url); break;
                    case 'mark-bad':    adminMarkMintBad(url); break;
                    case 'reset':       resetMintCounters(url); break;
                }
            });
        }

        // View switching
        //
        // opts.replace=true uses history.replaceState instead of pushState.
        // Used on initial-view restore and on popstate so Back/Forward don't
        // grow a duplicate history entry.
        function switchView(view, opts) {
            if (!ADMIN_VIEWS.includes(view)) view = 'dashboard';
            if ((view === 'stats' || view === 'products' || view === 'customers' || view === 'log') && phpUser.role !== 'admin') {
                showToast('Admin role required', 'error');
                return;
            }
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

            document.getElementById(`view-${view}`).classList.add('active');
            document.querySelector(`[data-view="${view}"]`).classList.add('active');

            const titles = {
                dashboard: 'Dashboard',
                invoices: 'Invoices',
                customers: 'Customers',
                stores: 'Store Settings',
                products: 'Products',
                settings: 'Settings',
                stats: 'Stats Dashboard',
                log: 'Log'
            };
            document.getElementById('header-text').textContent = titles[view];

            // Show/hide store selector based on view (hide on global settings
            // and on the stats page, which has its own per-page selector).
            const storeSelector = document.getElementById('header-store-selector');
            if (view === 'settings' || view === 'stats' || view === 'customers' || view === 'log') {
                storeSelector.style.display = 'none';
            } else {
                storeSelector.style.display = 'flex';
            }

            // Reflect the active view in the URL so refreshes restore it.
            // Invoices keeps its ?status filter (read from the existing URL
            // when we re-enter the view) so the operator's selection survives
            // both refreshes and navigation across views.
            writeViewToUrl(view, opts && opts.replace);

            if (view === 'invoices') {
                setInvoiceStatusFilter(readInvoiceFilterFromUrl());
                invoicesState.offset = 0;
                // Populate (and default to the current store) before loading so
                // the list matches the dropdown selection on first entry.
                populateInvoiceStoreFilter().then(loadInvoices);
            }
            if (view === 'customers') {
                customersState.offset = 0;
                populateCustomerStoreFilter().then(loadCustomers);
            }
            if (view === 'stores') loadStoreSettings();
            if (view === 'products') loadProductsView();
            if (view === 'stats') loadStats();
            if (view === 'log') {
                adminLogState.offset = 0;
                populateLogStoreFilter().then(loadAdminLog);
            }
            if (view === 'settings') {
                renderAccountCard();
                if (phpUser.role === 'admin') {
                    renderUsersCard();
                    loadStuckFundsCard();
                    loadReliabilityCard();
                    loadTrustedMintsCard();
                    loadCronUrl();
                    loadAutoUpdateCard();
                    loadNotificationSettings();
                    loadDeveloperSettings();
                    loadInvoiceErrorSettings();
                }
            }
        }

        // Build a URL for a view, preserving the invoices ?status filter when
        // navigating between invoices states. Other views drop the query
        // string — none of them currently carry URL-resident state.
        // Path-style only where the host provably routes it; otherwise the
        // ?view= query form, which every host serves (see adminUsesPathUrls).
        function urlForView(view, query) {
            if (adminUsesPathUrls) {
                let url = adminBasePath + '/' + view;
                if (query) url += '?' + query;
                return url;
            }
            let url = adminBasePath + '?view=' + encodeURIComponent(view);
            if (query) url += '&' + query;
            return url;
        }

        function writeViewToUrl(view, replace) {
            // Preserve the current ?status= only when staying on /invoices;
            // navigating away from invoices drops it.
            let query = '';
            if (view === 'invoices') {
                const status = readInvoiceFilterFromUrl();
                if (status) query = 'status=' + encodeURIComponent(status);
            }
            const url = urlForView(view, query);
            const fn = replace ? history.replaceState : history.pushState;
            fn.call(history, { view }, '', url);
        }

        // Browser Back/Forward should navigate between admin views without a
        // full page reload — derive the target view from the new URL.
        window.addEventListener('popstate', () => {
            // Query-form URLs carry the view in ?view=; path-form URLs carry
            // it as the last non-empty path segment. Check the query first —
            // it is only ever present on query-form URLs.
            const qsView = new URLSearchParams(window.location.search).get('view');
            const path = window.location.pathname;
            const seg = qsView || path.split('/').filter(Boolean).pop() || 'dashboard';
            const view = ADMIN_VIEWS.includes(seg) ? seg : 'dashboard';
            switchView(view, { replace: true });
        });

        // Client-side admin guard for buttons whose modals shouldn't even
        // open for non-admins. Server-side gates still enforce the actual
        // action — this just stops the modal from flashing up and prompting
        // for input the request will be rejected for.
        function ensureAdmin(message) {
            if (phpUser.role === 'admin') return true;
            showToast(message || 'Admin role required', 'error');
            return false;
        }

        // Header user dropdown: shows the current username, opens/closes on
        // user-btn click, closes on outside click or Escape, hosts the
        // Logout entry.
        function setupUserMenu() {
            const btn = document.getElementById('user-btn');
            if (!btn) return;
            const panel = document.getElementById('user-menu-panel');
            const usernameEl = document.getElementById('user-menu-username');

            const close = () => panel.classList.add('hidden');
            const toggle = (e) => {
                e.stopPropagation();
                // Re-read phpUser here — listeners are wired in DOMContentLoaded
                // before the password POST hydrates phpUser, so a one-shot set
                // at setup time would show an empty username.
                usernameEl.textContent = phpUser.username || '';
                panel.classList.toggle('hidden');
            };
            btn.addEventListener('click', toggle);
            document.addEventListener('click', (e) => {
                if (!panel.classList.contains('hidden')
                    && !e.target.closest('#user-menu')) {
                    close();
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
            document.getElementById('user-menu-logout').addEventListener('click', () => {
                close();
                logout();
            });
        }

        // ===== My Account + Users (Settings) =====

        function renderAccountCard() {
            const u = phpUser;
            const usernameEl = document.getElementById('my-username');
            const badgeEl = document.getElementById('my-role-badge');
            if (usernameEl) usernameEl.textContent = u.username || '';
            if (badgeEl) badgeEl.textContent = u.role === 'admin' ? 'admin' : 'user';

            // Recovery email is only meaningful for admins (the reset-link flow
            // looks up admin accounts by email).
            const row = document.getElementById('recovery-email-row');
            if (row) {
                if (u.role === 'admin') {
                    row.classList.remove('hidden');
                    const input = document.getElementById('recovery-email-input');
                    if (input && document.activeElement !== input) input.value = u.email || '';
                } else {
                    row.classList.add('hidden');
                }
            }
        }

        async function saveRecoveryEmail() {
            const input = document.getElementById('recovery-email-input');
            const email = (input.value || '').trim();
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=set_recovery_email&email=${encodeURIComponent(email)}`);
                const res = await response.json();
                if (response.ok && res.success) {
                    phpUser.email = email || null;
                    showToast(email ? 'Recovery email saved' : 'Recovery email cleared', 'success');
                } else {
                    showToast(res.error || 'Failed to save recovery email', 'error');
                }
            } catch (e) {
                showToast(e.message || 'Failed to save recovery email', 'error');
            }
        }

        async function renderUsersCard() {
            const card = document.getElementById('card-users');
            if (!card) return;
            card.classList.remove('hidden');

            const container = document.getElementById('users-list');
            container.innerHTML = '<div class="empty-state"><p>Loading...</p></div>';
            try {
                const response = await postWithCsrf(adminUrl, 'action=list_users');
                const data = await response.json();
                if (!response.ok || !data.users || data.users.length === 0) {
                    container.innerHTML = '<div class="empty-state"><p>No users yet</p></div>';
                    return;
                }
                container.innerHTML = data.users.map(u => `
                    <div class="list-item">
                        <div class="list-content">
                            <div class="list-title">
                                ${escapeHtml(u.username)}
                                <span style="margin-left: 0.5rem; padding: 0.125rem 0.5rem; border-radius: 999px; font-size: 0.7rem; background: rgba(247,147,26,0.2);">${escapeHtml(u.role)}</span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 0.25rem;">
                            <button class="btn btn-secondary user-action" data-user-action="reset-password" data-user-id="${escapeAttr(u.id)}" data-username="${escapeAttr(u.username)}" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Reset password</button>
                            ${u.username === phpUser.username ? '' : `<button class="btn btn-danger user-action" data-user-action="delete" data-user-id="${escapeAttr(u.id)}" data-username="${escapeAttr(u.username)}" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Delete</button>`}
                        </div>
                    </div>
                `).join('');
                container.querySelectorAll('button.user-action').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.dataset.userId;
                        const name = btn.dataset.username;
                        switch (btn.dataset.userAction) {
                            case 'reset-password': openResetUserPassword(id, name); break;
                            case 'delete':         deleteUserById(id, name); break;
                        }
                    });
                });
            } catch (e) {
                container.innerHTML = '<div class="empty-state"><p>Failed to load users</p></div>';
            }
        }

        function escapeAttr(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            }[c]));
        }

        async function changeOwnPassword() {
            const current = document.getElementById('cp-current').value;
            const next = document.getElementById('cp-new').value;
            const confirm = document.getElementById('cp-confirm').value;
            if (next.length < 8) {
                showToast('New password must be at least 8 characters', 'error');
                return;
            }
            if (next !== confirm) {
                showToast('Passwords do not match', 'error');
                return;
            }
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=change_own_password&current_password=${encodeURIComponent(current)}&new_password=${encodeURIComponent(next)}`);
                const res = await response.json();
                if (response.ok && res.success) {
                    showToast('Password updated', 'success');
                    closeModal('modal-change-password');
                    ['cp-current','cp-new','cp-confirm'].forEach(id => document.getElementById(id).value = '');
                } else {
                    showToast(res.error || 'Failed to update password', 'error');
                }
            } catch (e) {
                showToast(e.message || 'Failed to update password', 'error');
            }
        }

        async function addUser() {
            const username = document.getElementById('au-username').value.trim();
            const password = document.getElementById('au-password').value;
            const role = document.getElementById('au-role').value;
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=create_user&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&role=${encodeURIComponent(role)}`);
                const res = await response.json();
                if (response.ok && res.success) {
                    showToast('User created', 'success');
                    closeModal('modal-add-user');
                    ['au-username','au-password'].forEach(id => document.getElementById(id).value = '');
                    renderUsersCard();
                } else {
                    showToast(res.error || 'Failed to create user', 'error');
                }
            } catch (e) {
                showToast(e.message || 'Failed to create user', 'error');
            }
        }

        function openResetUserPassword(userId, username) {
            document.getElementById('rup-user-id').value = userId;
            document.getElementById('rup-username').textContent = username;
            document.getElementById('rup-new').value = '';
            openModal('modal-reset-user-password');
        }

        async function confirmResetUserPassword() {
            const userId = document.getElementById('rup-user-id').value;
            const next = document.getElementById('rup-new').value;
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=reset_password&user_id=${encodeURIComponent(userId)}&new_password=${encodeURIComponent(next)}`);
                const res = await response.json();
                if (response.ok && res.success) {
                    showToast('Password reset', 'success');
                    closeModal('modal-reset-user-password');
                } else {
                    showToast(res.error || 'Failed to reset password', 'error');
                }
            } catch (e) {
                showToast(e.message || 'Failed to reset password', 'error');
            }
        }

        async function deleteUserById(userId, username) {
            if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=delete_user&user_id=${encodeURIComponent(userId)}`);
                const res = await response.json();
                if (response.ok && res.success) {
                    showToast('User deleted', 'success');
                    renderUsersCard();
                } else {
                    showToast(res.error || 'Failed to delete user', 'error');
                }
            } catch (e) {
                showToast(e.message || 'Failed to delete user', 'error');
            }
        }

        // Track currently selected store
        let currentStoreId = localStorage.getItem('selectedStoreId') || null;

        // Handle store selection change
        function onStoreSelectChange() {
            const select = document.getElementById('store-select');
            const value = select.value;

            // Handle "Create Store" option
            if (value === '__create__') {
                // Reset to previous selection
                if (currentStoreId) {
                    select.value = currentStoreId;
                } else if (dashboardData?.stores?.length > 0) {
                    select.value = dashboardData.stores[0].id;
                }
                // Redirect to setup in add_store mode
                window.location.href = setupUrl + '?mode=add_store';
                return;
            }

            currentStoreId = value || null;
            if (currentStoreId) {
                localStorage.setItem('selectedStoreId', currentStoreId);
            } else {
                localStorage.removeItem('selectedStoreId');
            }
            loadDashboard();

            // If on store settings view, reload store settings
            if (document.getElementById('view-stores').classList.contains('active')) {
                loadStoreSettings();
            }
            // If on invoices view, keep its store filter in sync and reload
            if (document.getElementById('view-invoices').classList.contains('active')) {
                const invStoreSel = document.getElementById('invoice-store-filter');
                if (invStoreSel) invStoreSel.value = currentStoreId || '__all__';
                loadInvoices();
            }
            // If on products view, reload that store's catalog
            if (document.getElementById('view-products').classList.contains('active')) {
                loadProductsView();
            }
        }

        // Populate store selector dropdown
        function updateStoreSelector(stores) {
            const select = document.getElementById('store-select');
            const previousValue = select.value;

            select.innerHTML = '';

            if (!stores || stores.length === 0) {
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = 'No stores configured';
                select.appendChild(emptyOpt);
            } else {
                stores.forEach(store => {
                    const opt = document.createElement('option');
                    opt.value = store.id;
                    opt.textContent = store.name + (store.hasPaymentRail ? '' : ' (not configured)');
                    select.appendChild(opt);
                });
            }

            // Add "Create Store" option
            const createOpt = document.createElement('option');
            createOpt.value = '__create__';
            createOpt.textContent = '+ Create Store';
            select.appendChild(createOpt);

            select.disabled = false;

            // Restore selection or select first store
            if (currentStoreId && stores && stores.find(s => s.id === currentStoreId)) {
                select.value = currentStoreId;
            } else if (stores && stores.length > 0) {
                select.value = stores[0].id;
                currentStoreId = stores[0].id;
                localStorage.setItem('selectedStoreId', currentStoreId);
            }
        }

        // Data loading
        async function loadDashboard() {
            try {
                // Fetch with store_id if one is selected
                let url = adminUrl + '?api=dashboard';
                if (currentStoreId) {
                    url += `&store_id=${encodeURIComponent(currentStoreId)}`;
                }

                const response = await fetch(url);

                // Handle stale store_id from localStorage (store deleted or new database)
                if (response.status === 404 && currentStoreId) {
                    currentStoreId = null;
                    localStorage.removeItem('selectedStoreId');
                    return loadDashboard(); // Retry without store_id
                }

                if (!response.ok) throw new Error('Failed to load');

                dashboardData = await response.json();

                // Update store selector with available stores
                updateStoreSelector(dashboardData.stores);

                // Handle case when no store is selected
                if (dashboardData.noStoreSelected) {
                    if (dashboardData.stores && dashboardData.stores.length > 0) {
                        // Auto-select the first store and reload
                        currentStoreId = dashboardData.stores[0].id;
                        localStorage.setItem('selectedStoreId', currentStoreId);
                        return loadDashboard();
                    } else {
                        // No stores configured
                        document.getElementById('balance-amount').textContent = '--';
                        document.getElementById('balance-unit').textContent = '';
                        document.querySelector('.balance-label').textContent = 'No stores configured';
                        document.getElementById('withdraw-available').textContent = '0';
                        renderInvoices('recent-invoices', []);
                        return;
                    }
                }

                // Verify the selected store still exists
                if (dashboardData.storeId) {
                    currentStoreId = dashboardData.storeId;
                    localStorage.setItem('selectedStoreId', currentStoreId);
                }

                const mintUnit = dashboardData.mintUnit || 'sat';
                const unitLabel = mintUnit.toUpperCase();

                // Update balance with proper decimal formatting
                document.getElementById('balance-amount').textContent =
                    formatAmount(dashboardData.balance ?? 0, mintUnit);
                document.getElementById('balance-unit').textContent = unitLabel;

                // Secondary fiat line (when default currency != mint unit and not sat)
                const fiatEl = document.getElementById('balance-fiat');
                if (dashboardData.balanceFiat && dashboardData.balanceFiatCurrency) {
                    fiatEl.textContent = '≈ ' + dashboardData.balanceFiat + ' ' + dashboardData.balanceFiatCurrency;
                    fiatEl.style.display = 'block';
                } else {
                    fiatEl.style.display = 'none';
                }

                // Show mint status warning if unreachable
                const balanceLabel = document.querySelector('.balance-label');
                if (dashboardData.mintUnreachable) {
                    balanceLabel.innerHTML = 'Balance stored in Cashu Mint <span style="color: #f59e0b; font-size: 0.75rem;">(mint offline - cached)</span>';
                    // Disable withdraw/export buttons when mint is unreachable
                    document.querySelectorAll('.withdraw-btn, .export-btn').forEach(btn => {
                        btn.disabled = true;
                        btn.title = 'Mint is currently unreachable';
                    });
                } else {
                    balanceLabel.textContent = 'Balance stored in Cashu Mint';
                    document.querySelectorAll('.withdraw-btn, .export-btn').forEach(btn => {
                        btn.disabled = false;
                        btn.title = '';
                    });
                }

                // Update available amounts in modals with proper formatting
                document.getElementById('withdraw-available').textContent =
                    formatAmount(dashboardData.balance ?? 0, mintUnit);

                // Update unit labels in modals
                document.querySelectorAll('.unit-label').forEach(el => el.textContent = unitLabel);

                // Update auto-melt threshold unit label and value
                const thresholdLabel = document.getElementById('auto-melt-threshold-label');
                if (thresholdLabel) thresholdLabel.textContent = `Threshold (${unitLabel})`;

                renderOnchainDashboard();

                // Update auto-melt settings (per-store)
                if (dashboardData.autoMelt) {
                    setLnAddressRowsFromData();
                    document.getElementById('auto-melt-enabled').checked = dashboardData.autoMelt.enabled;
                    // Format threshold for display (fiat needs decimal)
                    const thresholdInput = document.getElementById('auto-melt-threshold');
                    thresholdInput.value = isFiatUnit(mintUnit)
                        ? (dashboardData.autoMelt.threshold / 100).toFixed(2)
                        : dashboardData.autoMelt.threshold;
                    // Set input attributes for fiat
                    if (isFiatUnit(mintUnit)) {
                        thresholdInput.step = '0.01';
                        thresholdInput.min = '0.01';
                    } else {
                        thresholdInput.step = '1';
                        thresholdInput.min = '1';
                    }
                    renderAutoMeltMode();
                }

                // Render recent invoices
                renderInvoices('recent-invoices', dashboardData.invoices || []);

                // Reliability banner / settings cards.
                updateReliabilityBanner(dashboardData.reliability);
                updateCronStaleBanner(dashboardData.cronStaleWarning || null);

                // Static-address manual-confirmation banner. Visible on every
                // admin view (it lives above the view containers) but the
                // detailed queue lives on the invoices view.
                updateOnchainManualBanner(dashboardData.onchain);
                loadOnchainManualList();

                // Update-available banner (admin-only). Reads the cached
                // availability verdict + any in-flight manual run.
                fetchAndRenderUpdateState();

                // Per-store swap override + effective indicator.
                refreshStoreSwapsCard();

                // Per-store self-serve override, max + public link.
                refreshStoreSelfServeCard();

            } catch (e) {
                console.error(e);
                showToast('Failed to load dashboard', 'error');
            }
        }

        // Status filter for the All Invoices view. Persisted in the URL query
        // string (e.g. /admin/invoices?status=Settled) so a refresh preserves
        // the choice and the URL stays linkable.
        const INVOICE_STATUSES = ['New', 'Processing', 'Settled', 'Expired', 'Invalid'];

        function getInvoiceStatusFilter() {
            const sel = document.getElementById('invoice-status-filter');
            const v = sel ? sel.value : '';
            return INVOICE_STATUSES.includes(v) ? v : '';
        }

        function setInvoiceStatusFilter(v) {
            const sel = document.getElementById('invoice-status-filter');
            if (sel) sel.value = INVOICE_STATUSES.includes(v) ? v : '';
        }

        function readInvoiceFilterFromUrl() {
            const s = new URLSearchParams(window.location.search).get('status') || '';
            return INVOICE_STATUSES.includes(s) ? s : '';
        }

        function writeInvoiceFilterToUrl(status) {
            // Only touch the URL while the invoices view is the active view,
            // so we don't stomp on other views' URL state.
            const view = document.querySelector('.view.active');
            if (!view || view.id !== 'view-invoices') return;
            const url = urlForView('invoices', status ? 'status=' + encodeURIComponent(status) : '');
            history.replaceState({ view: 'invoices' }, '', url);
        }

        // The invoices store filter mirrors the global header store selector
        // (so the default view is the currently selected store) but adds an
        // "All stores" option that drops the store_id filter entirely.
        async function populateInvoiceStoreFilter() {
            const sel = document.getElementById('invoice-store-filter');
            if (!sel) return;
            try {
                const r = await fetch(adminUrl + '?api=stores');
                const stores = await r.json();
                sel.innerHTML = '<option value="__all__">All stores</option>'
                    + stores.map(s => `<option value="${s.id}">${escapeHtml(s.name || s.id)}</option>`).join('');
                const desired = currentStoreId || '__all__';
                sel.value = [...sel.options].some(o => o.value === desired) ? desired : '__all__';
            } catch (e) {
                // Leave the existing "All stores" option in place on failure.
            }
        }

        function getInvoiceStoreFilter() {
            const sel = document.getElementById('invoice-store-filter');
            return sel ? sel.value : (currentStoreId || '__all__');
        }

        // Shared list pagination state + footer renderer for the invoices and
        // customers lists. Page size is fixed; the backend reports the total
        // matching count so we can offer prev/next and a "X–Y of N" readout.
        const LIST_PAGE_SIZE = 50;
        const invoicesState = { offset: 0, total: 0 };
        const customersState = { offset: 0, total: 0 };

        function renderListPagination(containerId, state, onNavigate) {
            const el = document.getElementById(containerId);
            if (!el) return;
            const { offset, total } = state;
            if (!total || total <= LIST_PAGE_SIZE) { el.innerHTML = ''; return; }
            const from = total === 0 ? 0 : offset + 1;
            const to = Math.min(offset + LIST_PAGE_SIZE, total);
            const prevDisabled = offset <= 0 ? 'disabled' : '';
            const nextDisabled = offset + LIST_PAGE_SIZE >= total ? 'disabled' : '';
            el.innerHTML =
                `<button class="btn btn-secondary" data-nav="prev" ${prevDisabled}>Previous</button>`
                + `<span class="list-pagination-info">${from}–${to} of ${total}</span>`
                + `<button class="btn btn-secondary" data-nav="next" ${nextDisabled}>Next</button>`;
            const prevBtn = el.querySelector('[data-nav="prev"]');
            const nextBtn = el.querySelector('[data-nav="next"]');
            if (prevBtn && !prevDisabled) prevBtn.addEventListener('click', () => onNavigate(Math.max(0, offset - LIST_PAGE_SIZE)));
            if (nextBtn && !nextDisabled) nextBtn.addEventListener('click', () => onNavigate(offset + LIST_PAGE_SIZE));
        }

        async function loadInvoices() {
            try {
                let url = adminUrl + `?api=invoices&limit=${LIST_PAGE_SIZE}&offset=${invoicesState.offset}`;
                const storeFilter = getInvoiceStoreFilter();
                if (storeFilter && storeFilter !== '__all__') {
                    url += `&store_id=${encodeURIComponent(storeFilter)}`;
                }
                const status = getInvoiceStatusFilter();
                if (status) {
                    url += `&status=${encodeURIComponent(status)}`;
                }
                const response = await fetch(url);
                const invoices = await response.json();
                invoicesState.total = parseInt(response.headers.get('X-Total-Count') || '0', 10) || 0;
                renderInvoicesTable('all-invoices', invoices);
                renderListPagination('invoices-pagination', invoicesState, (off) => {
                    invoicesState.offset = off;
                    loadInvoices();
                });
            } catch (e) {
                showToast('Failed to load invoices', 'error');
            }
        }

        // ===== Customers view =====
        function getCustomerStoreFilter() {
            const sel = document.getElementById('customer-store-filter');
            return sel ? sel.value : '__all__';
        }

        function getCustomerSubscriptionFilter() {
            const sel = document.getElementById('customer-subscription-filter');
            const v = sel ? sel.value : '';
            return (v === 'subscribed' || v === 'unsubscribed') ? v : '';
        }

        async function populateCustomerStoreFilter() {
            const sel = document.getElementById('customer-store-filter');
            if (!sel) return;
            try {
                const r = await fetch(adminUrl + '?api=stores');
                const stores = await r.json();
                const prev = sel.value || '__all__';
                sel.innerHTML = '<option value="__all__">All stores</option>'
                    + stores.map(s => `<option value="${s.id}">${escapeHtml(s.name || s.id)}</option>`).join('');
                sel.value = [...sel.options].some(o => o.value === prev) ? prev : '__all__';
            } catch (e) {
                // Leave the existing "All stores" option in place on failure.
            }
        }

        async function loadCustomers() {
            try {
                let url = adminUrl + `?api=customers&limit=${LIST_PAGE_SIZE}&offset=${customersState.offset}`;
                const store = getCustomerStoreFilter();
                if (store && store !== '__all__') {
                    url += `&store_id=${encodeURIComponent(store)}`;
                }
                const sub = getCustomerSubscriptionFilter();
                if (sub) {
                    url += `&subscription=${encodeURIComponent(sub)}`;
                }
                const response = await fetch(url);
                if (!response.ok) { showToast('Failed to load customers', 'error'); return; }
                const data = await response.json();
                customersState.total = data.total || 0;
                renderCustomersTable('all-customers', data.customers || []);
                renderListPagination('customers-pagination', customersState, (off) => {
                    customersState.offset = off;
                    loadCustomers();
                });
            } catch (e) {
                showToast('Failed to load customers', 'error');
            }
        }

        function renderCustomersTable(containerId, customers) {
            const container = document.getElementById(containerId);
            if (!container) return;

            if (!customers || customers.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">👥</div>
                        <p>No customers match this filter</p>
                    </div>
                `;
                return;
            }

            const rows = customers.map(c => {
                const sub = c.newsletterOptIn
                    ? '<span title="Subscribed to newsletter" style="color: var(--success, #48bb78);">✓</span>'
                    : '<span title="Not subscribed" style="color: var(--text-secondary);">—</span>';
                const invShort = c.invoiceId && c.invoiceId.length > 4 ? '..' + c.invoiceId.slice(-4) : (c.invoiceId || '');
                const invLink = c.checkoutLink
                    ? `<a href="${escapeHtml(c.checkoutLink)}" target="_blank" rel="noopener" class="inv-mono" title="${escapeHtml(c.invoiceId || '')}" style="color: var(--accent, #60a5fa);">${escapeHtml(invShort)}</a>`
                    : '<span style="color: var(--text-secondary);">—</span>';
                return `
                    <tr>
                        <td>${escapeHtml(c.email || '')}</td>
                        <td style="text-align: center;">${sub}</td>
                        <td>${escapeHtml(c.storeName || '')}</td>
                        <td>${invLink}</td>
                        <td>${formatPaidTime(c.paidTime)}</td>
                    </tr>
                `;
            }).join('');

            container.innerHTML = `
                <div style="overflow-x: auto;">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th style="text-align: center;">Newsletter</th>
                                <th>Store</th>
                                <th>Most recent invoice</th>
                                <th>Paid</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
        }

        // ===== Stats Dashboard =====
        const statsState = {
            storeId: '__all__',
            range: 'all',
            unit: 'sat',
            financialChartType: 'revenue',
            ccRate: 5,
            payoutsPage: 1,
            feePaymentsPage: 1,
            summary: null,
            charts: { financial: null, fee: null },
            // Bumped on every filter change so in-flight responses from a
            // prior filter selection can be discarded when they resolve out
            // of order.
            loadToken: 0,
        };

        // Sats >= 1,000,000 shown as e.g. "1.25M" with the exact number in the
        // tooltip. Uses an underlined span (.stats-amount-abbr) for affordance.
        function formatSatsAbbr(sats) {
            const n = Math.floor(Number(sats) || 0);
            if (n >= 1_000_000) {
                const m = (n / 1_000_000).toFixed(2);
                const exact = n.toLocaleString();
                return `<span class="stats-amount-abbr" title="${exact} sats">${m}M</span>`;
            }
            return n.toLocaleString();
        }

        // 1 BTC = 100,000,000 sats. Trim trailing zeros, but keep at least one
        // digit after the decimal so it's visually obvious that it's BTC.
        function formatBtcTrim(sats) {
            const n = Math.floor(Number(sats) || 0);
            if (n === 0) return '0';
            const btc = (n / 100_000_000).toFixed(8);
            const trimmed = btc.replace(/0+$/, '').replace(/\.$/, '');
            return trimmed === '' ? '0' : trimmed;
        }

        // Combined display: "<amount> sats (<fiat>)" or "<amount> BTC (<fiat>)".
        // Returns an HTML string.
        function formatStatsAmount(sats, opts = {}) {
            const unit = statsState.unit;
            const summary = statsState.summary || {};
            const currency = opts.currency || summary.currency || 'USD';
            const btcPrice = (opts.btcPrice !== undefined) ? opts.btcPrice : summary.btc_price;

            let primary;
            if (unit === 'btc') {
                primary = `${formatBtcTrim(sats)} BTC`;
            } else {
                primary = `${formatSatsAbbr(sats)} sats`;
            }

            if (btcPrice && Number(sats) > 0) {
                const fiat = (Number(sats) / 100_000_000) * Number(btcPrice);
                const formattedFiat = fiat.toLocaleString(undefined, {
                    style: 'currency',
                    currency: currency,
                    maximumFractionDigits: fiat >= 100 ? 0 : 2,
                });
                return `${primary} <span class="stats-stat-fiat">(${formattedFiat})</span>`;
            }
            return primary;
        }

        async function loadStats() {
            await loadStatsStores();
            const token = ++statsState.loadToken;
            await Promise.all([
                refreshStatsSummary(token),
                refreshStatsChart('financial', token),
                refreshStatsChart('fee', token),
                refreshPayouts(token),
                refreshFeePayments(token),
                refreshUpgradeBanner(),
            ]);
        }

        async function refreshUpgradeBanner() {
            const banner = document.getElementById('upgrade-banner');
            if (!banner) return;
            try {
                const r = await fetch(adminUrl + '?api=upgrade_banner');
                if (!r.ok) {
                    banner.classList.add('hidden');
                    return;
                }
                const body = await r.json();
                if (body && body.state) {
                    banner.classList.remove('hidden');
                } else {
                    banner.classList.add('hidden');
                }
            } catch (_) {
                banner.classList.add('hidden');
            }
        }

        async function dismissUpgradeBanner() {
            const banner = document.getElementById('upgrade-banner');
            if (banner) banner.classList.add('hidden');
            try {
                await postWithCsrf(adminUrl, 'action=dismiss_upgrade_banner');
            } catch (_) {
                // Best-effort; banner will reappear on next stats load if dismissal didn't persist.
            }
        }

        async function loadStatsStores() {
            try {
                const r = await fetch(adminUrl + '?api=stores');
                const stores = await r.json();
                const sel = document.getElementById('stats-store-selector');
                // Preserve current selection if it still exists in the list.
                const current = sel.value || statsState.storeId;
                sel.innerHTML = '<option value="__all__">All stores</option>'
                    + stores.map(s => `<option value="${s.id}">${escapeHtml(s.name || s.id)}</option>`).join('');
                if ([...sel.options].some(o => o.value === current)) {
                    sel.value = current;
                    statsState.storeId = current;
                }
            } catch (_) {
                // Selector falls back to "All stores"; summary still works.
            }
        }

        function statsQuery(extra = {}) {
            const p = new URLSearchParams({
                store_id: statsState.storeId,
                range: statsState.range,
                ...extra,
            });
            return p.toString();
        }

        async function refreshStatsSummary(token = statsState.loadToken) {
            try {
                const r = await fetch(adminUrl + '?api=stats_summary&' + statsQuery());
                const s = await r.json();
                if (token !== statsState.loadToken) return;
                statsState.summary = s;
                renderStatsSummary(s);
            } catch (e) {
                showToast('Failed to load stats summary', 'error');
            }
        }

        function renderStatsSummary(s) {
            document.getElementById('stats-revenue').innerHTML = formatStatsAmount(s.revenue_sats);
            document.getElementById('stats-invoice-count').textContent = (s.invoice_count || 0).toLocaleString();
            document.getElementById('stats-total-fees').innerHTML = formatStatsAmount(s.fees_paid.total);
            document.getElementById('stats-profit').innerHTML = formatStatsAmount(s.profit_sats);

            document.getElementById('stats-fee-dev-paid').innerHTML = formatStatsAmount(s.fees_paid.dev);
            document.getElementById('stats-fee-hosting-paid').innerHTML = formatStatsAmount(s.fees_paid.hosting);
            document.getElementById('stats-fee-network-paid').innerHTML = formatStatsAmount(s.fees_paid.network);
            const swapFeeEl = document.getElementById('stats-fee-swap-paid');
            if (swapFeeEl) swapFeeEl.innerHTML = formatStatsAmount(s.fees_paid.swap || 0);
            document.getElementById('stats-fees-owed').innerHTML = formatStatsAmount(s.fees_owed.total);
            document.getElementById('stats-fees-paid').innerHTML = formatStatsAmount(s.fees_paid.total);

            const pct = (s.effective_fee_pct || 0).toFixed(2);
            document.getElementById('stats-effective-fee').textContent = `${pct}%`;

            renderFreeTrialBanner(s.free_trial);
            renderCcSaved();
        }

        // Render the free-trial banner above the Fees rows. Three states:
        //   - not configured → hidden
        //   - active → green banner with expiry conditions + progress
        //   - expired → muted banner naming the expiry reason + date
        // Stats are deployment-wide; the trial doesn't filter by store.
        function renderFreeTrialBanner(ft) {
            const el = document.getElementById('stats-free-trial-banner');
            if (!el || !ft || !ft.configured) {
                if (el) el.className = 'hidden';
                return;
            }

            const fmtSats = (n) => Number(n || 0).toLocaleString() + ' sats';
            const fmtDate = (ts) => {
                if (!ts) return null;
                try { return new Date(ts * 1000).toLocaleDateString(); }
                catch (_) { return null; }
            };

            if (ft.active) {
                const conds = [];
                if (ft.until_ts) {
                    conds.push(`until <strong>${fmtDate(ft.until_ts)}</strong>`);
                }
                if (ft.revenue_cap_sats) {
                    conds.push(
                        `until <strong>${fmtSats(ft.revenue_cap_sats)}</strong> revenue ` +
                        `(${fmtSats(ft.revenue_during_trial_sats)} so far)`
                    );
                }
                const joiner = conds.length > 1 ? ' or ' : '';
                el.style.background = 'rgba(16, 185, 129, 0.12)';
                el.style.border = '1px solid rgba(16, 185, 129, 0.4)';
                el.style.color = 'inherit';
                el.innerHTML = `<strong>Free trial active</strong> — no dev or hosting fees will be charged ${conds.join(joiner)}. Network fees (Lightning routing) still apply.`;
                el.classList.remove('hidden');
            } else if (ft.expired_at) {
                const reasonLabel = ft.expired_reason === 'revenue'
                    ? 'revenue cap reached'
                    : 'end date reached';
                el.style.background = 'rgba(148, 163, 184, 0.12)';
                el.style.border = '1px solid rgba(148, 163, 184, 0.4)';
                el.style.color = 'var(--text-secondary)';
                el.innerHTML = `Free trial ended on <strong>${fmtDate(ft.expired_at)}</strong> (${reasonLabel}). Fees apply to revenue from that point on.`;
                el.classList.remove('hidden');
            } else {
                el.className = 'hidden';
            }
        }

        function renderCcSaved() {
            const s = statsState.summary;
            if (!s) return;
            const cc = (statsState.ccRate / 100) * (s.revenue_sats || 0);
            const actual = s.fees_paid.total || 0;
            if (cc <= 0) {
                document.getElementById('stats-cc-saved').textContent = '—';
                return;
            }
            const savedSats = Math.max(0, cc - actual);
            const pct = (savedSats / cc) * 100;
            document.getElementById('stats-cc-saved').innerHTML =
                `${pct.toFixed(1)}% ` +
                `<span style="font-size: 0.5em; color: var(--text-secondary); font-weight: normal; margin-left: 0.5rem;">${formatStatsAmount(savedSats)}</span>`;
        }

        async function refreshStatsChart(which, token = statsState.loadToken) {
            const type = which === 'financial' ? statsState.financialChartType : 'fees';
            try {
                const r = await fetch(adminUrl + '?api=stats_chart&' + statsQuery({ type }));
                const data = await r.json();
                if (token !== statsState.loadToken) return;
                renderStatsChart(which, data);
            } catch (_) {}
        }

        function renderStatsChart(which, data) {
            const canvasId = which === 'financial' ? 'stats-financial-chart' : 'stats-fee-chart';
            const canvas = document.getElementById(canvasId);
            if (!canvas || typeof Chart === 'undefined') return;

            // Tear down any prior chart so a re-render doesn't leak event
            // listeners / overlapping tooltips on the same canvas.
            if (statsState.charts[which]) {
                statsState.charts[which].destroy();
                statsState.charts[which] = null;
            }

            const palette = ['#f7931a', '#3b82f6', '#10b981', '#a855f7', '#ef4444'];
            const colors = data.labels.map((_, i) => palette[i % palette.length]);
            const totalSats = data.data.reduce((acc, n) => acc + Number(n || 0), 0);

            statsState.charts[which] = new Chart(canvas.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        backgroundColor: colors,
                        borderColor: 'rgba(0,0,0,0.2)',
                        borderWidth: 1,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#fff' } },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const v = Number(ctx.raw || 0);
                                    const isCount = which === 'financial' && statsState.financialChartType === 'count';
                                    const valStr = isCount
                                        ? v.toLocaleString()
                                        : (v >= 1_000_000 ? `${(v / 1_000_000).toFixed(2)}M (${v.toLocaleString()})` : v.toLocaleString());
                                    const pct = totalSats > 0 ? ((v / totalSats) * 100).toFixed(1) : '0';
                                    return `${ctx.label}: ${valStr} (${pct}%)`;
                                },
                            },
                        },
                    },
                },
            });
        }

        async function refreshPayouts(token = statsState.loadToken) {
            try {
                const r = await fetch(adminUrl + '?api=stats_payouts&' + statsQuery({ page: statsState.payoutsPage }));
                const data = await r.json();
                if (token !== statsState.loadToken) return;
                renderMeltTable('stats-payouts-table', 'stats-payouts-pagination', data, 'payouts');
            } catch (_) {}
        }

        async function refreshFeePayments(token = statsState.loadToken) {
            try {
                const r = await fetch(adminUrl + '?api=stats_fee_payments&' + statsQuery({ page: statsState.feePaymentsPage }));
                const data = await r.json();
                if (token !== statsState.loadToken) return;
                renderMeltTable('stats-fee-payments-table', 'stats-fee-payments-pagination', data, 'fees');
            } catch (_) {}
        }

        function renderMeltTable(tableElId, pagerElId, data, kind) {
            const tableEl = document.getElementById(tableElId);
            const pagerEl = document.getElementById(pagerElId);
            if (!data || !data.rows || data.rows.length === 0) {
                tableEl.innerHTML = '<p style="color: var(--text-secondary); padding: 1rem 0;">No records in this date range.</p>';
                pagerEl.innerHTML = '';
                return;
            }

            const showType = kind === 'fees';
            const header = `
                <table class="stats-table">
                    <thead><tr>
                        <th>Date</th>
                        <th>Destination</th>
                        ${showType ? '<th>Type</th>' : ''}
                        <th style="text-align:right">Amount</th>
                        <th style="text-align:right">Network fee</th>
                    </tr></thead>
                    <tbody>
            `;
            const noteLabel = (note) => {
                if (note === 'UPSTREAM_DEV_FEE') return 'Upstream dev';
                if (note === 'DEV_FEE') return 'Dev';
                if (note === 'HOSTING_FEE') return 'Hosting';
                return note || '';
            };
            // Fee paid by routing a customer invoice straight to the payee
            // (via='redirect') vs melted out of the cashu wallet ('wallet').
            const typeCell = (row) => {
                const base = escapeHtml(noteLabel(row.note));
                return row.via === 'redirect'
                    ? `${base} <span class="inv-fee-badge" title="Paid by redirecting a customer invoice straight to the fee destination (no wallet melt).">redirect</span>`
                    : base;
            };
            // Strike cashouts carry the Strike invoice id created for the
            // payout, so the merchant can match the incoming payment in
            // their Strike dashboard.
            const rows = data.rows.map(row => `
                <tr>
                    <td>${new Date(row.created_at * 1000).toLocaleString()}</td>
                    <td class="truncate" title="${escapeHtml(row.destination || '')}">${escapeHtml(row.destination || '')}${row.strike_invoice_id ? ` <span class="inv-mono">${renderCopyMono(row.strike_invoice_id, 'Strike invoice ID')}</span>` : ''}</td>
                    ${showType ? `<td>${typeCell(row)}</td>` : ''}
                    <td style="text-align:right">${formatStatsAmount(row.amount_sats)}</td>
                    <td style="text-align:right">${formatStatsAmount(row.network_fee_sats)}</td>
                </tr>
            `).join('');
            tableEl.innerHTML = header + rows + '</tbody></table>';

            const pageStateKey = (kind === 'fees') ? 'feePaymentsPage' : 'payoutsPage';
            const onChange = (kind === 'fees') ? refreshFeePayments : refreshPayouts;
            const page = data.page;
            const pages = Math.max(1, data.pages);
            pagerEl.innerHTML = `
                <div>Page ${page} of ${pages} (${data.total} total)</div>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-secondary" ${page <= 1 ? 'disabled' : ''} id="${tableElId}-prev">Prev</button>
                    <button class="btn btn-secondary" ${page >= pages ? 'disabled' : ''} id="${tableElId}-next">Next</button>
                </div>
            `;
            const prev = document.getElementById(`${tableElId}-prev`);
            const next = document.getElementById(`${tableElId}-next`);
            if (prev) prev.addEventListener('click', () => { statsState[pageStateKey] = Math.max(1, page - 1); onChange(); });
            if (next) next.addEventListener('click', () => { statsState[pageStateKey] = page + 1; onChange(); });
        }

        function downloadCsv(action) {
            const params = new URLSearchParams({
                api: action,
                store_id: statsState.storeId,
                range: statsState.range,
            });
            window.location.href = adminUrl + '?' + params.toString();
        }

        function setupStatsListeners() {
            const sel = document.getElementById('stats-store-selector');
            if (!sel) return; // page not rendered (non-admin)

            sel.addEventListener('change', () => {
                statsState.storeId = sel.value;
                statsState.payoutsPage = 1;
                statsState.feePaymentsPage = 1;
                loadStats();
            });

            document.querySelectorAll('.stats-range-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.stats-range-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    statsState.range = btn.dataset.range;
                    statsState.payoutsPage = 1;
                    statsState.feePaymentsPage = 1;
                    loadStats();
                });
            });

            document.querySelectorAll('.stats-unit-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.stats-unit-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    statsState.unit = btn.dataset.unit;
                    // Unit change is display-only; re-render from cached summary.
                    if (statsState.summary) renderStatsSummary(statsState.summary);
                    const token = ++statsState.loadToken;
                    refreshPayouts(token);
                    refreshFeePayments(token);
                });
            });

            document.getElementById('stats-financial-chart-type').addEventListener('change', (e) => {
                statsState.financialChartType = e.target.value;
                refreshStatsChart('financial');
            });

            document.getElementById('stats-cc-rate').addEventListener('change', (e) => {
                statsState.ccRate = parseFloat(e.target.value);
                renderCcSaved();
            });

            document.getElementById('btn-export-payouts-csv').addEventListener('click', () => downloadCsv('export_payouts_csv'));
            document.getElementById('btn-export-fee-payments-csv').addEventListener('click', () => downloadCsv('export_fee_payments_csv'));
            document.getElementById('btn-export-combined-csv').addEventListener('click', () => downloadCsv('export_combined_csv'));

            const invoicesExportBtn = document.getElementById('btn-export-invoices-csv');
            if (invoicesExportBtn) {
                invoicesExportBtn.addEventListener('click', () => {
                    const params = new URLSearchParams({ api: 'export_all_invoices_csv' });
                    if (currentStoreId) params.set('store_id', currentStoreId);
                    window.location.href = adminUrl + '?' + params.toString();
                });
            }

            const dismissUpgradeBtn = document.getElementById('btn-dismiss-upgrade-banner');
            if (dismissUpgradeBtn) dismissUpgradeBtn.addEventListener('click', dismissUpgradeBanner);
        }

        // ===== End Stats Dashboard =====

        async function loadStoreSettings() {
            const contentEl = document.getElementById('store-settings-content');
            const emptyEl = document.getElementById('store-settings-empty');

            if (!currentStoreId) {
                contentEl.style.display = 'none';
                emptyEl.style.display = 'block';
                return;
            }

            contentEl.style.display = 'block';
            emptyEl.style.display = 'none';

            try {
                // Load store details
                const response = await fetch(`${adminUrl}?api=stores`);
                const stores = await response.json();
                const store = stores.find(s => s.id === currentStoreId);

                if (!store) {
                    contentEl.style.display = 'none';
                    emptyEl.style.display = 'block';
                    return;
                }

                // Update store info display
                document.getElementById('store-settings-name').textContent = store.name;
                document.getElementById('store-settings-id').textContent = store.id;
                document.getElementById('store-settings-mint').textContent = store.mint_url || '-';

                // Load auto-melt settings from dashboard data
                if (dashboardData && dashboardData.autoMelt) {
                    const mintUnit = dashboardData.mintUnit || 'sat';
                    setLnAddressRowsFromData();
                    document.getElementById('auto-melt-enabled').checked = dashboardData.autoMelt.enabled;
                    const thresholdInput = document.getElementById('auto-melt-threshold');
                    thresholdInput.value = isFiatUnit(mintUnit)
                        ? (dashboardData.autoMelt.threshold / 100).toFixed(2)
                        : dashboardData.autoMelt.threshold;
                    // Update threshold label
                    document.querySelectorAll('.unit-label').forEach(el => {
                        el.textContent = mintUnit.toUpperCase();
                    });
                    // Set input attributes for fiat
                    if (isFiatUnit(mintUnit)) {
                        thresholdInput.step = '0.01';
                        thresholdInput.min = '0.01';
                    } else {
                        thresholdInput.step = '1';
                        thresholdInput.min = '1';
                    }
                    renderAutoMeltMode();
                }

                // Load per-store notification settings
                if (dashboardData && dashboardData.notifications) {
                    const n = dashboardData.notifications;
                    const enabledEl = document.getElementById('store-notifications-enabled');
                    const emailEl = document.getElementById('store-notification-email');
                    if (enabledEl) enabledEl.checked = !!n.enabled;
                    if (emailEl) emailEl.value = n.email || '';
                    // Newsletter checkbox default override ('' inherit | '1' | '0').
                    const nlEl = document.getElementById('store-newsletter-default');
                    if (nlEl) nlEl.value = n.newsletterDefault || '';
                    const nlHelp = document.getElementById('store-newsletter-default-help');
                    if (nlHelp) nlHelp.textContent = 'Initial state of the newsletter opt-in checkbox on this '
                        + 'store’s payment page. Site-wide default is currently '
                        + (n.newsletterSiteDefault ? 'checked' : 'unchecked') + '.';
                    // Per-store SMTP override (password write-only, never returned).
                    const ov = document.getElementById('store-smtp-override-enabled');
                    if (ov) ov.checked = !!n.smtpOverrideEnabled;
                    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
                    set('store-smtp-host', n.smtpHost);
                    set('store-smtp-port', n.smtpPort);
                    set('store-smtp-username', n.smtpUsername);
                    set('store-smtp-encryption', n.smtpEncryption);
                    set('store-smtp-from-address', n.smtpFromAddress);
                    set('store-smtp-from-name', n.smtpFromName);
                    set('store-smtp-password', '');
                    const clearEl = document.getElementById('store-smtp-password-clear');
                    if (clearEl) clearEl.checked = false;
                    const help = document.getElementById('store-smtp-password-help');
                    if (help) help.textContent = n.smtpPasswordSet
                        ? 'A password is saved. Leave blank to keep it.'
                        : 'No password saved.';
                    const prefill = document.getElementById('store-notifications-test-email');
                    if (prefill) prefill.value = n.email || '';
                    if (typeof updateStoreSmtpFieldsVisibility === 'function') updateStoreSmtpFieldsVisibility();
                }

                // Load per-store invoice-privacy defaults.
                if (dashboardData && dashboardData.invoicePrivacy) {
                    const p = dashboardData.invoicePrivacy;
                    const hsn = document.getElementById('store-hide-store-name');
                    if (hsn) hsn.checked = !!p.hideStoreName;
                    const hn = document.getElementById('store-hide-note');
                    if (hn) hn.checked = !!p.hideNote;
                }

                // Load exchange rate settings from store data
                document.getElementById('price-provider-primary').value = store.price_provider_primary || 'coingecko';
                document.getElementById('price-provider-secondary').value = store.price_provider_secondary || 'binance';
                document.getElementById('exchange-fee-percent').value = store.exchange_fee_percent || 0;

                // Hosting fee settings
                document.getElementById('hosting-fee-percent').value = store.hosting_fee_percent || 0;
                document.getElementById('hosting-fee-destination').value = store.hosting_fee_destination || '';

                // Load default currency. If the store's mint_unit isn't in the
                // standard list, inject it as an extra option so it stays selectable.
                const defaultCurrencySelect = document.getElementById('default-currency');
                const storeMintUnit = (store.mint_unit || 'sat');
                if (![...defaultCurrencySelect.options].some(o => o.value.toLowerCase() === storeMintUnit.toLowerCase())) {
                    const opt = document.createElement('option');
                    opt.value = storeMintUnit;
                    opt.textContent = storeMintUnit.toUpperCase();
                    defaultCurrencySelect.appendChild(opt);
                }
                defaultCurrencySelect.value = store.default_currency || storeMintUnit || 'sat';

                // Load API keys
                loadStoreApiKeys();

                // Load offline Cashu acceptance settings
                loadOfflineCashu(currentStoreId);

            } catch (e) {
                console.error(e);
                showToast('Failed to load store settings', 'error');
            }
        }

        async function loadStoreApiKeys() {
            const container = document.getElementById('store-api-keys');
            if (!currentStoreId) {
                container.innerHTML = '<div class="empty-state"><p>No store selected</p></div>';
                return;
            }

            try {
                const response = await fetch(`${adminUrl}?api=api_keys&store_id=${encodeURIComponent(currentStoreId)}`);
                const keys = await response.json();

                if (!keys || keys.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">🔑</div>
                            <p>No API keys yet</p>
                        </div>
                    `;
                    return;
                }

                container.innerHTML = keys.map(key => `
                    <div class="list-item">
                        <div class="list-icon" style="background: rgba(247, 147, 26, 0.2);">🔑</div>
                        <div class="list-content">
                            <div class="list-title">${escapeHtml(key.label || 'API Key')}</div>
                            <div class="list-subtitle">ID: ${escapeHtml(String(key.id).substring(0, 8))}...</div>
                        </div>
                        <button class="btn btn-secondary delete-api-key-from-settings" data-api-key-id="${escapeAttr(key.id)}" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Delete</button>
                    </div>
                `).join('');
                container.querySelectorAll('button.delete-api-key-from-settings').forEach(btn => {
                    btn.addEventListener('click', () => deleteApiKeyFromSettings(btn.dataset.apiKeyId));
                });
            } catch (e) {
                container.innerHTML = '<div class="empty-state"><p>Failed to load API keys</p></div>';
            }
        }

        // Rendering
        // Badge for invoices whose payment was redirected to a fee payee
        // instead of the merchant (see includes/fee_redirect.php). The tooltip
        // names which fee and explains where the money went, so the merchant
        // doesn't wonder why their balance didn't move for this invoice.
        function feeRedirectBadge(fr) {
            const label = (fr && fr.label) ? fr.label : 'fees';
            const dest = fr && fr.destination ? '\nDestination: ' + fr.destination : '';
            // An invoice can be mixed: some rails go to the fee payee, the rest
            // to the merchant. Until it settles we don't know which rail the
            // customer will use, so the badge text depends on what we know.
            let text, title;
            if (fr && fr.settled) {
                if (fr.settledToFee) {
                    text = '⚡ Fee payment';
                    title = 'The customer paid a rail routed to ' + label
                        + ', so this payment covered that fee instead of the merchant.' + dest;
                } else {
                    // Mixed invoice where the customer chose the merchant rail —
                    // no badge needed (the merchant was genuinely paid).
                    return '';
                }
            } else if (fr && fr.mixed) {
                // Mixed invoice not yet settled: the customer can still pay the
                // merchant rail, so the payment hasn't actually been redirected
                // to a fee. Show nothing until settlement decides (only actual
                // redirects get a badge).
                return '';
            } else {
                // Pure fee invoice (every rail routes to the fee): the payment
                // is redirected regardless of which rail the customer picks.
                text = '⚡ Fee payment';
                title = 'This invoice is routed to pay for ' + label + '.' + dest;
            }
            return ` <span class="inv-fee-badge" title="${escapeAttr(title)}">${text}</span>`;
        }

        function renderInvoices(containerId, invoices) {
            const container = document.getElementById(containerId);

            if (!invoices || invoices.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📄</div>
                        <p>No invoices yet</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = invoices.map(inv => {
                const statusClass = inv.status.toLowerCase();
                const icon = inv.status === 'Settled' ? '✓' :
                             inv.status === 'New' ? '⏳' :
                             inv.status === 'Provisional' ? '◷' : '✕';
                const date = new Date(inv.createdTime * 1000).toLocaleDateString();
                const description = inv.metadata?.itemDesc || '';
                const feeBadge = inv.feeRedirect ? feeRedirectBadge(inv.feeRedirect) : '';

                return `
                    <div class="list-item">
                        <div class="list-icon ${statusClass}">${icon}</div>
                        <div class="list-content">
                            <div class="list-title">${description ? escapeHtml(description) : inv.id}${feeBadge}</div>
                            <div class="list-subtitle">${date}${description ? ' · ' + inv.id : ''}</div>
                        </div>
                        <div class="list-amount">
                            <div class="list-amount-value">${inv.amount} ${inv.currency}</div>
                            <div class="list-amount-status ${statusClass}">${inv.status}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ---- Detailed invoice table (All Invoices view) ----

        // Build a mempool.space URL for an address or txid on the invoice's
        // network. Regtest has no public explorer; we still return a mainnet
        // URL so operators get *something* clickable — they know it'll 404.
        function mempoolUrl(kind, value, network) {
            const base = network === 'testnet' ? 'https://mempool.space/testnet'
                       : network === 'signet'  ? 'https://mempool.space/signet'
                       : 'https://mempool.space';
            return `${base}/${kind}/${encodeURIComponent(value)}`;
        }

        // Render an address/txid as "xxx...xxx" linked to the block explorer,
        // with the full value in a hover tooltip. Optional extraTitle adds a
        // second line to the tooltip (used to surface the swap claim txid).
        function renderMonoLink(kind, value, network, extraTitle) {
            if (!value) return '—';
            const safe = escapeHtml(value);
            const head = value.length > 6 ? value.slice(0, 3) : value;
            const tail = value.length > 6 ? value.slice(-3) : '';
            const title = extraTitle ? `${safe}\n${escapeHtml(extraTitle)}` : safe;
            const url = mempoolUrl(kind, value, network);
            return `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" title="${title}">${escapeHtml(head)}...${escapeHtml(tail)}</a>`;
        }

        // Render a value as "xxx...xxx" that copies the full value to the
        // clipboard on click, with the full value in a hover tooltip. Used for
        // Lightning destinations (LN address / LNURL) and bolt11 invoices,
        // which have no block explorer to link to. The full value rides in a
        // data attribute so it survives any character without breaking the
        // inline handler.
        function renderCopyMono(value, hint) {
            if (!value) return '—';
            const head = value.length > 6 ? value.slice(0, 3) : value;
            const tail = value.length > 6 ? value.slice(-3) : '';
            const title = (hint ? hint + '\n' : '') + value + '\n(click to copy)';
            return `<a href="#" class="inv-copy" title="${escapeAttr(title)}" data-copy="${escapeAttr(value)}" onclick="copyMono(event, this); return false;">${escapeHtml(head)}...${escapeHtml(tail)}</a>`;
        }

        // Clipboard handler for renderCopyMono links.
        window.copyMono = function (e, el) {
            e.preventDefault();
            e.stopPropagation();
            const val = (el && el.getAttribute('data-copy')) || '';
            navigator.clipboard.writeText(val).then(() => {
                showToast('Copied to clipboard', 'success');
            }).catch(() => showToast('Copy failed', 'error'));
        };

        // Copy the full invoice id to the clipboard from the tooltip popup.
        // Flashes a "Copied!" toast and bumps the icon stroke briefly so the
        // operator sees feedback without losing their place in the table.
        window.copyInvoiceId = function (e, id, btn) {
            e.preventDefault();
            e.stopPropagation();
            navigator.clipboard.writeText(id).then(() => {
                showToast('Invoice ID copied', 'success');
                if (btn) {
                    const orig = btn.style.color;
                    btn.style.color = 'var(--success, #48bb78)';
                    setTimeout(() => { btn.style.color = orig; }, 900);
                }
            }).catch(() => showToast('Copy failed', 'error'));
        };

        // Hostname of the mint an invoice's funds landed at, for the method
        // chip. Falls back to the raw value for unparseable URLs.
        function mintHost(mintUrl) {
            try { return new URL(mintUrl).host || mintUrl; } catch (e) { return mintUrl; }
        }

        // Map paymentRail → emoji+text chip cell. Lightning shows a bolt,
        // on-chain a chain, swap the recycle arrows. Mint-rail payments
        // arrive over Lightning but land as Cashu ecash at the store's mint,
        // so they carry the mint host; direct ecash-token receipts (rail
        // 'cashu') show the host of the mint the token came from.
        function renderPaymentMethod(rail, mintUrl) {
            const map = {
                mint:    { icon: '⚡', label: 'Lightning (cashu)' },
                strike:  { icon: '⚡', label: 'Lightning (Strike)' },
                onchain: { icon: '🔗', label: 'On-chain' },
                swap:    { icon: '🔄', label: 'Swap' },
                cashu:   { icon: '🥜', label: 'Cashu' },
            };
            const m = map[rail] || { icon: '', label: rail || '—' };
            let label = m.label;
            if ((rail === 'mint' || rail === 'cashu') && mintUrl) {
                label += `(${mintHost(mintUrl)})`;
            }
            return `<span class="inv-chip">${m.icon} ${escapeHtml(label)}</span>`;
        }

        // Swap status badge. invoice.settled is suppressed because the row's
        // own "Settled" status chip already conveys completion; everything
        // else (waiting / confirming / failed) gets a labelled badge so the
        // operator can see in-flight or error state at a glance.
        function renderSwapBadge(swapStatus) {
            if (!swapStatus || swapStatus === 'invoice.settled') return '';
            const settling = ['swap.created', 'minerfee.paid', 'transaction.mempool'];
            const failed   = ['invoice.expired', 'swap.expired', 'transaction.refunded', 'transaction.failed'];
            let cls = 'swap-waiting', label = swapStatus;
            if (swapStatus === 'transaction.confirmed') { cls = 'swap-confirming'; label = 'Confirming'; }
            else if (settling.includes(swapStatus))     { cls = 'swap-waiting';    label = 'Awaiting payment'; }
            else if (failed.includes(swapStatus))       { cls = 'swap-failed';     label = swapStatus.replace(/\./g, ' '); }
            return ` <span class="inv-chip ${cls}">${escapeHtml(label)}</span>`;
        }

        function formatPaidTime(ts) {
            if (!ts) return '<span style="color: var(--text-secondary);">—</span>';
            const d = new Date(ts * 1000);
            return escapeHtml(d.toLocaleString());
        }

        function renderInvoicesTable(containerId, invoices) {
            const container = document.getElementById(containerId);

            if (!invoices || invoices.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📄</div>
                        <p>No invoices match this filter</p>
                    </div>
                `;
                return;
            }

            const rows = invoices.map(inv => {
                const description = inv.metadata?.itemDesc || '';
                const network = inv.network || 'mainnet';
                const swapBadge = renderSwapBadge(inv.swapStatus);
                const idShort = inv.id.length > 4 ? '..' + inv.id.slice(-4) : inv.id;
                const idEsc = escapeHtml(inv.id);
                const idCell = `
                    <span class="inv-id-row">
                        <span>${escapeHtml(idShort)}</span>
                        <span class="inv-id-tip" tabindex="0">ⓘ
                            <span class="inv-id-pop" role="tooltip">
                                <span>${idEsc}</span>
                                <button type="button" class="inv-id-copy" title="Copy invoice ID"
                                        onclick="copyInvoiceId(event, '${idEsc}', this)">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                </button>
                            </span>
                        </span>
                    </span>
                `;
                // Note column: invoice description (metadata.itemDesc) plus a
                // swap-status badge appended when the swap isn't fully settled.
                // Em-dash when there's neither a description nor a badge.
                const feeBadge = inv.feeRedirect ? feeRedirectBadge(inv.feeRedirect) : '';
                // Cart invoices get an inline link to their itemized breakdown.
                const itemsLink = inv.hasItems
                    ? ` <a href="#" onclick="openInvoiceItems('${idEsc}'); return false;" style="font-size:0.78rem; color:var(--accent,#60a5fa); white-space:nowrap;">🛒 items</a>`
                    : '';
                const noteCell = (description || swapBadge || itemsLink || feeBadge)
                    ? `<div>${description ? escapeHtml(description) : ''}${swapBadge}${itemsLink}${feeBadge}</div>`
                    : '<span style="color: var(--text-secondary);">—</span>';
                // Customer email captured on the payment screen, with a checkmark
                // when they opted into the newsletter.
                const emailCell = inv.customerEmail
                    ? `<span class="inv-mono">${escapeHtml(inv.customerEmail)}</span>${inv.newsletterOptIn ? ' <span title="Subscribed to newsletter" style="color: var(--success, #48bb78);">✓</span>' : ''}`
                    : '<span style="color: var(--text-secondary);">—</span>';
                // Strike receives carry Strike's own invoice id next to the
                // masked destination label — the id the operator can look up
                // in their Strike dashboard to reconcile the payment.
                const strikeIdPart = inv.strikeInvoiceId
                    ? ` ${renderCopyMono(inv.strikeInvoiceId, 'Strike invoice ID')}`
                    : '';
                // On-chain addresses minted via a Strike receive request carry
                // that request's id the same way (reconciles in the Strike
                // dashboard under Receive requests).
                const strikeRrPart = inv.strikeReceiveRequestId
                    ? ` ${renderCopyMono(inv.strikeReceiveRequestId, 'Strike receive request ID')}`
                    : '';
                const destCell = inv.destination
                    ? `<span class="inv-mono">${inv.destinationIsLightning
                        ? renderCopyMono(inv.destination, 'Lightning destination')
                        : renderMonoLink('address', inv.destination, network)}${strikeIdPart}${strikeRrPart}</span>`
                    : (inv.strikeInvoiceId
                        ? `<span class="inv-mono">${renderCopyMono(inv.strikeInvoiceId, 'Strike invoice ID')}</span>`
                        : '<span style="color: var(--text-secondary);">—</span>');
                const txidCell = inv.txid
                    ? `<span class="inv-mono">${inv.txidIsLightning
                        ? renderCopyMono(inv.txid, 'Lightning invoice (bolt11)')
                        : renderMonoLink('tx', inv.txid, network, inv.claimTxid ? 'Claim: ' + inv.claimTxid : null)}</span>`
                    : '<span style="color: var(--text-secondary);">—</span>';

                // Display label for the status chip: "Settled" reads as
                // "Paid" in the operator UI. The CSS class still uses the
                // raw status so colour styling stays keyed on the internal
                // state machine.
                const statusLabel = inv.status === 'Settled' ? 'Paid' : inv.status;
                return `
                    <tr>
                        <td><span class="inv-chip status-${escapeHtml(inv.status)}">${escapeHtml(statusLabel)}</span></td>
                        <td>${renderPaymentMethod(inv.paymentRail, inv.mintUrl)}</td>
                        <td>${idCell}</td>
                        <td>${noteCell}</td>
                        <td>${emailCell}</td>
                        <td>${formatPaidTime(inv.paidTime)}</td>
                        <td>${destCell}</td>
                        <td>${txidCell}</td>
                        <td class="inv-amount">${escapeHtml(String(inv.amount))} ${escapeHtml(String(inv.currency))}</td>
                    </tr>
                `;
            }).join('');

            container.innerHTML = `
                <div style="overflow-x: auto;">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Invoice</th>
                                <th>Note</th>
                                <th>Customer</th>
                                <th>Paid</th>
                                <th>Destination</th>
                                <th>TxID</th>
                                <th style="text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
        }

        // Actions
        async function handleWithdraw() {
            const address = document.getElementById('withdraw-address').value;
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const isFiatMint = isFiatUnit(mintUnit);
            const isBolt11 = /^ln(bc|tb|tbs|bcrt)[0-9]/i.test(address);
            const isLnAddress = !isBolt11 && address.includes('@');
            const isLightningDestination = isBolt11 || isLnAddress;

            // For ALL Lightning destinations with fiat mints, amount is in SATS
            // For sat mints or non-Lightning destinations, amount is in mint units
            let amount;
            let amountIsSats = '0';

            if (isLightningDestination && isFiatMint) {
                // Amount is in sats (integer) for all Lightning destinations
                amount = parseInt(document.getElementById('withdraw-amount').value, 10) || 0;
                amountIsSats = '1';
            } else {
                // Amount is in mint units (parse normally)
                amount = parseAmount(document.getElementById('withdraw-amount').value, mintUnit);
            }

            if (!address || !amount) {
                showToast('Please fill all fields', 'error');
                return;
            }

            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }

            const withdrawBtn = document.getElementById('btn-confirm-withdraw');
            const withdrawBtnOrigText = withdrawBtn.textContent;
            withdrawBtn.textContent = 'Processing, please wait...';
            withdrawBtn.disabled = true;

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=manual_melt&store_id=${encodeURIComponent(currentStoreId)}&address=${encodeURIComponent(address)}&amount=${amount}&amount_is_sats=${amountIsSats}`
                );

                const result = await response.json();
                const unitLabel = mintUnit.toUpperCase();

                if (response.ok) {
                    // For all Lightning destinations with fiat mint, show amount in sats
                    let msg;
                    if (isLightningDestination && isFiatMint) {
                        msg = `Sent ${amount} SAT!`;
                    } else {
                        msg = `Sent ${formatAmount(result.amountPaid, mintUnit)} ${unitLabel}!`;
                    }

                    showToast(msg, 'success');
                    closeModal('modal-withdraw');
                    loadDashboard();
                } else {
                    showToast(result.error || 'Withdrawal failed', 'error');
                    withdrawBtn.textContent = withdrawBtnOrigText;
                    withdrawBtn.disabled = false;
                }
            } catch (e) {
                showToast('Withdrawal failed', 'error');
                withdrawBtn.textContent = withdrawBtnOrigText;
                withdrawBtn.disabled = false;
            }
        }

        // API base URL for Greenfield API calls
        let API_BASE_URL = <?= json_encode(Urls::api()) ?>;
        const SUPPORTED_CURRENCIES = <?= json_encode(Config::getSupportedDisplayCurrencies()) ?>;

        // Lightning routing fee buffer (same as auto-melt uses)
        const LN_FEE_BUFFER_PERCENT = 2; // 2%
        const LN_FEE_BUFFER_MIN = 10;    // minimum 10 sats

        // Track if bolt-11 has fixed amount
        let bolt11FixedAmount = null;
        let bolt11FeeEstimate = null;
        let bolt11MeltError = false;
        let bolt11FetchTimeout = null;

        // Handle destination input (detect bolt-11 and auto-fill amount)
        function handleDestinationInput() {
            const input = document.getElementById('withdraw-address').value.trim();
            const helpText = document.getElementById('withdraw-destination-help');
            const amountInput = document.getElementById('withdraw-amount');

            // Clear any pending timeout
            if (bolt11FetchTimeout) {
                clearTimeout(bolt11FetchTimeout);
                bolt11FetchTimeout = null;
            }

            // Check if it looks like a bolt-11 invoice
            const isBolt11 = /^ln(bc|tb|tbs|bcrt)[0-9]/i.test(input);
            const isLightningAddress = !isBolt11 && input.includes('@');

            if (isBolt11 && input.length > 20) {
                helpText.textContent = 'Checking invoice amount...';
                helpText.style.color = 'var(--accent)';

                // Debounce the fetch
                bolt11FetchTimeout = setTimeout(async () => {
                    try {
                        const response = await postWithCsrf(adminUrl,
                            `action=get_bolt11_amount&bolt11=${encodeURIComponent(input)}&store_id=${encodeURIComponent(currentStoreId || '')}`
                        );
                        const result = await response.json();

                        if (result.meltError) {
                            // Mint rejected the invoice - withdrawal won't work
                            bolt11FixedAmount = null;
                            bolt11FeeEstimate = null;
                            bolt11MeltError = true;
                            amountInput.disabled = true;
                            document.getElementById('btn-withdraw-max').disabled = true;
                            const amountInfo = result.amountSats > 0 ? ` (${result.amountSats.toLocaleString()} SAT)` : '';
                            helpText.textContent = `Mint error${amountInfo}: ${result.meltError}`;
                            helpText.style.color = 'var(--error)';
                            updateWithdrawInfo();
                        } else if (result.hasAmount && result.amountSats > 0) {
                            // Invoice has encoded amount - auto-fill and disable amount editing
                            bolt11FixedAmount = result.amountSats;
                            bolt11FeeEstimate = result.feeEstimate;
                            bolt11MeltError = false;
                            amountInput.value = result.amountSats;
                            amountInput.disabled = true;
                            document.getElementById('btn-withdraw-max').disabled = true;
                            helpText.textContent = `Invoice amount: ${result.amountSats.toLocaleString()} SAT (fixed)`;
                            helpText.style.color = 'var(--success)';
                            updateWithdrawInfo();
                        } else {
                            // Amountless invoice - enable amount field
                            bolt11FixedAmount = null;
                            bolt11FeeEstimate = null;
                            bolt11MeltError = false;
                            amountInput.disabled = false;
                            document.getElementById('btn-withdraw-max').disabled = false;
                            helpText.textContent = 'Amountless invoice - enter amount below';
                            helpText.style.color = 'var(--text-secondary)';
                        }
                    } catch (e) {
                        console.error('Failed to check bolt-11:', e);
                        bolt11FixedAmount = null;
                        bolt11FeeEstimate = null;
                        bolt11MeltError = false;
                        amountInput.disabled = false;
                        document.getElementById('btn-withdraw-max').disabled = false;
                        helpText.textContent = 'Could not verify invoice';
                        helpText.style.color = 'var(--error)';
                    }
                }, 300);
            } else if (isLightningAddress) {
                // Lightning address detected
                bolt11FixedAmount = null;
                bolt11FeeEstimate = null;
                bolt11MeltError = false;
                amountInput.disabled = false;
                document.getElementById('btn-withdraw-max').disabled = false;
                helpText.textContent = 'Lightning address detected';
                helpText.style.color = 'var(--accent)';
            } else {
                // Incomplete input or unknown format
                bolt11FixedAmount = null;
                bolt11FeeEstimate = null;
                bolt11MeltError = false;
                amountInput.disabled = false;
                document.getElementById('btn-withdraw-max').disabled = false;
                helpText.textContent = 'Lightning address or BOLT-11 invoice';
                helpText.style.color = 'var(--text-secondary)';
            }

            updateWithdrawInfo();
        }

        // Track last estimate for display
        let lastWithdrawEstimate = null;
        let withdrawEstimateTimeout = null;

        // Update withdraw info (total from wallet)
        function updateWithdrawInfo() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const balance = dashboardData?.balance || 0;
            const isFiatMint = isFiatUnit(mintUnit);

            // Lightning withdrawals ALWAYS use SAT (Lightning Network operates in satoshis)
            const amountSats = parseInt(document.getElementById('withdraw-amount').value, 10) || 0;
            const destination = document.getElementById('withdraw-address').value.trim();

            // Update fiat equivalent display for fiat mints
            const equivEl = document.getElementById('withdraw-amount-fiat-equiv');
            if (isFiatMint && amountSats > 0) {
                // If we have an estimate from the mint, use actual cost
                if (lastWithdrawEstimate && lastWithdrawEstimate.amountSats === amountSats) {
                    const cost = lastWithdrawEstimate.costMintUnit;
                    const fee = lastWithdrawEstimate.feeReserve;
                    equivEl.innerHTML = `Cost: ${formatAmount(cost, mintUnit)} + ${formatAmount(fee, mintUnit)} fee = <strong>${formatAmount(cost + fee, mintUnit)} ${mintUnit.toUpperCase()}</strong>`;
                    equivEl.style.display = 'block';
                } else if (dashboardData?.balanceInSats > 0) {
                    // Fallback to our approximation
                    const rate = dashboardData.balance / dashboardData.balanceInSats;
                    const fiatAmount = amountSats * rate;
                    equivEl.textContent = `≈ ${formatAmount(Math.round(fiatAmount), mintUnit)} ${mintUnit.toUpperCase()} (estimate)`;
                    equivEl.style.display = 'block';
                } else {
                    equivEl.style.display = 'none';
                }
            } else {
                equivEl.style.display = 'none';
            }

            // Validate and update button state
            const withdrawBtn = document.getElementById('btn-confirm-withdraw');

            if (bolt11MeltError) {
                // Mint rejected the invoice - disable withdraw
                withdrawBtn.disabled = true;
            } else if (isFiatMint) {
                // For fiat mint, validate against actual cost if we have estimate
                if (lastWithdrawEstimate && lastWithdrawEstimate.amountSats === amountSats) {
                    withdrawBtn.disabled = amountSats < 1 || !lastWithdrawEstimate.canAfford;
                } else {
                    // Just check that amount > 0 (server will validate)
                    withdrawBtn.disabled = amountSats < 1;
                }
            } else if (bolt11FixedAmount && bolt11FeeEstimate !== null) {
                // We have an actual fee from the melt quote - use it instead of generic buffer
                const totalNeeded = amountSats + bolt11FeeEstimate;
                withdrawBtn.disabled = amountSats < 1 || totalNeeded > balance;
            } else {
                // For sat mint, validate against balance with generic fee buffer
                let maxSats = balance;

                // Reserve buffer for Lightning routing fees
                const feeBuffer = Math.max(LN_FEE_BUFFER_MIN, Math.floor(balance * LN_FEE_BUFFER_PERCENT / 100));
                maxSats = balance - feeBuffer;

                const isValid = amountSats > 0 && amountSats <= maxSats;
                withdrawBtn.disabled = !isValid;
            }

            // For fiat mints, debounce fetch actual cost from mint
            if (isFiatMint && amountSats > 0 && destination) {
                clearTimeout(withdrawEstimateTimeout);
                withdrawEstimateTimeout = setTimeout(async () => {
                    const estimate = await getWithdrawalEstimate(destination, amountSats);
                    if (estimate) {
                        lastWithdrawEstimate = estimate;
                        updateWithdrawInfo(); // Re-render with actual cost
                    }
                }, 500);
            }
        }

        // Get withdrawal estimate from mint (actual cost at mint's exchange rate)
        async function getWithdrawalEstimate(destination, amountSats) {
            if (!currentStoreId || !destination || amountSats < 1) {
                return null;
            }

            try {
                const response = await fetch(adminUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_withdrawal_estimate&store_id=${encodeURIComponent(currentStoreId)}&destination=${encodeURIComponent(destination)}&amount_sats=${amountSats}`
                });
                const data = await response.json();
                return data.success ? data : null;
            } catch (e) {
                console.error('Failed to get withdrawal estimate:', e);
                return null;
            }
        }

        // Withdraw max amount (always in SAT for Lightning withdrawals)
        async function withdrawMax() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const isFiatMint = isFiatUnit(mintUnit);
            const destination = document.getElementById('withdraw-address').value.trim();

            // Get balance in SAT
            let maxSats;
            if (isFiatMint) {
                maxSats = dashboardData?.balanceInSats;
                if (!maxSats || maxSats <= 0) {
                    showToast('Could not calculate max amount (no exchange rate)', 'error');
                    return;
                }

                // For fiat mints with a destination, query mint for actual max
                if (destination) {
                    const estimate = await getWithdrawalEstimate(destination, maxSats);
                    if (estimate && estimate.maxAffordableSats > 0) {
                        maxSats = estimate.maxAffordableSats;
                        // Apply fee buffer to mint's calculated max
                        const feeBuffer = Math.max(LN_FEE_BUFFER_MIN, Math.floor(maxSats * LN_FEE_BUFFER_PERCENT / 100));
                        maxSats = maxSats - feeBuffer;
                        document.getElementById('withdraw-amount').value = Math.max(0, maxSats);
                        updateWithdrawInfo();
                        return;
                    }
                }
            } else {
                maxSats = dashboardData?.balance || 0;
            }

            // Reserve buffer for Lightning routing fees
            const feeBuffer = Math.max(LN_FEE_BUFFER_MIN, Math.floor(maxSats * LN_FEE_BUFFER_PERCENT / 100));
            maxSats = maxSats - feeBuffer;

            document.getElementById('withdraw-amount').value = Math.max(0, maxSats);
            updateWithdrawInfo();
        }

        // Update export button enable state when amount changes
        function updateExportButton() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            // Parse as smallest unit (cents for fiat, sats for bitcoin)
            const amountSmallest = parseAmount(document.getElementById('export-amount').value, mintUnit);

            // Validate and update button state
            const exportBtn = document.getElementById('btn-confirm-export');
            const exportAvailable = dashboardData?.exportAvailable || 0;

            const isValid = amountSmallest > 0 && amountSmallest <= exportAvailable;
            exportBtn.disabled = !isValid;
        }

        // Track export state for claiming detection
        let exportCheckInterval = null;
        let exportSecrets = null;

        async function handleExport(forceAmount = null) {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const amount = forceAmount || parseAmount(document.getElementById('export-amount').value, mintUnit);

            const minAmount = isFiatUnit(mintUnit) ? 1 : 1; // 1 cent or 1 sat minimum
            if (!amount || amount < minAmount) {
                showToast('Please enter an amount', 'error');
                return;
            }

            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }

            const exportBtn = document.getElementById('btn-confirm-export');
            const exportBtnOrigText = exportBtn.textContent;
            exportBtn.textContent = 'Processing, please wait...';
            exportBtn.disabled = true;

            try {
                let postData = `action=export_token&store_id=${encodeURIComponent(currentStoreId)}&amount=${amount}`;
                if (forceAmount !== null) {
                    postData += `&force_amount=${forceAmount}`;
                }

                const response = await postWithCsrf(adminUrl, postData);
                const result = await response.json();

                // Handle mint unreachable - needs user confirmation for different amount
                if (result.error === 'mint_unreachable_change_needed') {
                    const unitLabel = mintUnit.toUpperCase();
                    const requestedDisplay = formatAmount(result.requested, mintUnit);
                    const availableDisplay = formatAmount(result.available, mintUnit);

                    const confirmed = confirm(
                        `Mint Unreachable - Offline Export\n\n` +
                        `The mint is currently unreachable, so exact change cannot be provided.\n\n` +
                        `Requested: ${requestedDisplay} ${unitLabel}\n` +
                        `Available (closest match): ${availableDisplay} ${unitLabel}\n\n` +
                        `Export ${availableDisplay} ${unitLabel} instead?`
                    );

                    if (confirmed) {
                        // Re-submit with force_amount
                        exportBtn.textContent = exportBtnOrigText;
                        exportBtn.disabled = false;
                        handleExport(result.available);
                    } else {
                        showToast('Export cancelled. Try using "Max" for exact match.', 'info');
                        exportBtn.textContent = exportBtnOrigText;
                        exportBtn.disabled = false;
                    }
                    return;
                }

                if (response.ok && result.token) {
                    // Show result
                    document.getElementById('export-form').style.display = 'none';
                    document.getElementById('export-result').style.display = 'block';
                    document.getElementById('export-token').textContent = result.token;

                    // Store secrets for claim detection
                    exportSecrets = result.secrets;

                    // Show offline export notice if mint was unreachable
                    if (result.offlineExport && result.mintUnreachable) {
                        setTimeout(() => {
                            showToast('Token exported offline (mint unreachable). Claim detection disabled.', 'info');
                        }, 500);
                        // Don't start claim detection - mint is unreachable
                    } else if (result.offlineExport) {
                        // Had exact change locally - mint wasn't needed but is reachable
                        // Start claim detection since mint is available
                        if (exportCheckInterval) clearInterval(exportCheckInterval);
                        exportCheckInterval = setInterval(checkIfTokenClaimed, 5000);
                    } else {
                        // Start checking if token has been claimed (every 5 seconds)
                        if (exportCheckInterval) clearInterval(exportCheckInterval);
                        exportCheckInterval = setInterval(checkIfTokenClaimed, 5000);
                    }

                    // Generate QR code (single or animated based on size)
                    const qrContainer = document.getElementById('export-qr');
                    qrContainer.innerHTML = '';

                    try {
                        // Check if we need animated QR based on token size
                        if (AnimatedQR.needsAnimation(result.token)) {
                            // Large token - use NUT-16 animated QR with UR encoding
                            const animatedQr = new AnimatedQR(qrContainer, {
                                frameRate: 200,
                                maxFragmentLen: 200,
                                qrSize: 280,
                                errorCorrection: 'M'
                            });

                            if (!animatedQr.encode(result.token)) {
                                throw new Error('Failed to encode animated QR');
                            }
                        } else {
                            // Small token - use single static QR
                            if (typeof QRious !== 'undefined') {
                                const canvas = document.createElement('canvas');
                                qrContainer.appendChild(canvas);

                                const maxSize = Math.min(280, window.innerWidth - 80);

                                new QRious({
                                    element: canvas,
                                    value: result.token,
                                    size: maxSize,
                                    backgroundAlpha: 1,
                                    foreground: '#000000',
                                    background: '#ffffff',
                                    level: 'L'
                                });
                            } else {
                                throw new Error('QR library not loaded');
                            }
                        }
                    } catch (e) {
                        console.error('QR generation failed:', e);
                        const warning = document.createElement('div');
                        warning.style.cssText = 'color: #888; text-align: center; padding: 2rem; font-size: 0.9rem;';
                        warning.textContent = 'QR code generation failed. Please copy the token below.';
                        qrContainer.appendChild(warning);
                    }

                    loadDashboard();
                } else {
                    showToast(result.error || 'Export failed', 'error');
                    exportBtn.textContent = exportBtnOrigText;
                    exportBtn.disabled = false;

                    // If sync happened, reload dashboard to show updated balance
                    if (result.sync) {
                        loadDashboard();
                    }
                }
            } catch (e) {
                showToast('Export failed', 'error');
                exportBtn.textContent = exportBtnOrigText;
                exportBtn.disabled = false;
            }
        }

        function copyToken() {
            const token = document.getElementById('export-token').textContent;
            navigator.clipboard.writeText(token).then(() => {
                showToast('Token copied!', 'success');
            });
        }

        // Rebuild the Request modal's currency <select> for a given store.
        // Mint unit is always offered (so the merchant can request in the
        // store's native unit), plus all supported display currencies.
        function rebuildRequestCurrencyOptions(mintUnit, defaultCurrency) {
            const sel = document.getElementById('request-currency');
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

        function updateRequestAmountConstraints() {
            const cur = document.getElementById('request-currency').value || 'sat';
            const isFiat = !['SAT', 'SATS', 'MSAT', 'BTC'].includes(cur.toUpperCase());
            const a = document.getElementById('request-amount');
            if (isFiat) { a.placeholder = '1.00'; a.min = '0.01'; a.step = '0.01'; }
            else        { a.placeholder = '100';  a.min = '1';    a.step = '1';    }
        }

        // Configure the Request modal for the currently-selected store.
        function updateAmountInputForStore(store) {
            const mintUnit = (typeof store === 'string') ? store : (store?.mint_unit || 'sat');
            const defaultCurrency = (typeof store === 'string') ? mintUnit : (store?.default_currency || mintUnit);
            document.getElementById('request-amount-label').textContent = 'Amount';
            rebuildRequestCurrencyOptions(mintUnit, defaultCurrency);
            updateRequestAmountConstraints();
        }

        // Open the Request modal, showing the per-transaction "allow any mint"
        // checkbox only when the store has offline acceptance + the per-tx
        // override enabled.
        async function openRequestModal() {
            const row = document.getElementById('request-allow-any-mint-row');
            const box = document.getElementById('request-allow-any-mint');
            if (row) row.style.display = 'none';
            if (box) box.checked = false;
            openModal('modal-request');
            if (!currentStoreId || !row) return;
            try {
                const r = await fetch(`${adminUrl}?api=get_offline_cashu&store_id=${encodeURIComponent(currentStoreId)}`, { credentials: 'same-origin' });
                const oc = await r.json();
                if (oc && oc.enabled && oc.per_tx_override) row.style.display = '';
            } catch (e) { /* leave hidden on failure */ }
        }

        async function handleGenerateRequest() {
            const storeId = currentStoreId;
            const amount = parseFloat(document.getElementById('request-amount').value);
            const memo = document.getElementById('request-memo').value;
            const currency = document.getElementById('request-currency').value || 'sat';
            const allowAnyMintBox = document.getElementById('request-allow-any-mint');
            const allowAnyMint = allowAnyMintBox && allowAnyMintBox.checked;
            const hideStoreNameBox = document.getElementById('request-hide-store-name');
            const hideStoreName = !!(hideStoreNameBox && hideStoreNameBox.checked);
            const hideNoteBox = document.getElementById('request-hide-note');
            const hideNote = !!(hideNoteBox && hideNoteBox.checked);

            if (!storeId) {
                showToast('Please select a store first', 'error');
                return;
            }

            // Get store info from dashboardData
            const store = dashboardData?.stores?.find(s => s.id === storeId);
            const apiKey = store?.internalApiKey;
            const isFiatRequest = !['SAT', 'SATS', 'MSAT', 'BTC'].includes(currency.toUpperCase());
            const minAmount = isFiatRequest ? 0.01 : 1;

            if (!amount || amount < minAmount) {
                showToast('Please enter an amount', 'error');
                return;
            }

            if (!apiKey) {
                showToast('Store API key not available', 'error');
                return;
            }

            try {
                // Use Greenfield API to create invoice. The server converts the
                // requested currency to the mint's unit at quote time.
                const apiUrl = API_BASE_URL + '/api/v1/stores/' + encodeURIComponent(storeId) + '/invoices';

                const invoiceData = {
                    amount: amount,
                    currency: currency,
                    checkout: {
                        // Managed installs: payers land on the shop's front
                        // page — this admin's URL is login-gated and would
                        // only show them the lock screen. Standalone keeps
                        // the return-to-admin convenience (no shop exists).
                        redirectURL: shopHomeUrl || window.location.href.split('?')[0],
                        redirectAutomatically: true
                    }
                };

                // Always carry the privacy overrides (pre-filled from the store
                // defaults) so the per-invoice choice wins; include the note
                // only when one was entered.
                invoiceData.metadata = { hideStoreName: hideStoreName, hideNote: hideNote };
                if (memo) {
                    invoiceData.metadata.itemDesc = memo;
                }

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'token ' + apiKey
                    },
                    body: JSON.stringify(invoiceData)
                });

                const result = await response.json();

                if (response.ok && result.checkoutLink) {
                    // Persist the per-transaction "allow any mint" override on
                    // the new invoice before sending the payer to checkout.
                    if (allowAnyMint && result.id) {
                        try {
                            await postWithCsrf(adminUrl,
                                `action=set_invoice_allow_any_mint&invoice_id=${encodeURIComponent(result.id)}&allow=1`);
                        } catch (e) { /* non-fatal */ }
                    }
                    // Redirect to checkout page
                    window.location.href = result.checkoutLink;
                } else {
                    showToast(result.message || result.error || 'Failed to create invoice', 'error');
                }
            } catch (e) {
                console.error('Invoice creation failed:', e);
                showToast('Failed to create invoice', 'error');
            }
        }

        // SLIP-0132 prefix → address type. xpub/tpub are ambiguous (legacy
        // P2PKH per the spec, but reused for native/wrapped segwit by many
        // wallets), so we leave the dropdown visible in that case.
        function inferOnchainTypeFromXpub(s) {
            const head = (s || '').trim().slice(0, 4).toLowerCase();
            if (head === 'zpub' || head === 'vpub') return {type: 'P2WPKH', prefix: head};
            if (head === 'ypub' || head === 'upub') return {type: 'P2SH-P2WPKH', prefix: head};
            return null; // xpub/tpub or unrecognized → user picks
        }

        function applyOnchainAddressTypeVisibility() {
            const row = document.getElementById('onchain-address-type-row');
            const inferredLine = document.getElementById('onchain-address-type-inferred');
            const select = document.getElementById('onchain-address-type');
            const typed = document.getElementById('onchain-xpub').value.trim();
            // Prefer the pasted xpub; if blank, fall back to the configured one.
            let inferred = typed ? inferOnchainTypeFromXpub(typed) : null;
            if (!inferred && !typed && dashboardData?.onchain?.enabled) {
                inferred = inferOnchainTypeFromXpub(dashboardData.onchain.xpub || '');
            }
            if (inferred) {
                select.value = inferred.type;
                row.style.display = 'none';
                inferredLine.style.display = 'block';
                inferredLine.textContent =
                    'Address type: ' + inferred.type +
                    ' (auto-detected from ' + inferred.prefix + ' prefix)';
            } else {
                row.style.display = '';
                inferredLine.style.display = 'none';
            }
        }

        function applyOnchainModeVisibility() {
            const mode = document.getElementById('onchain-mode').value;
            const isStatic = mode === 'static';
            document.getElementById('onchain-static-warning').style.display = isStatic ? 'block' : 'none';
            document.getElementById('onchain-xpub-row').style.display = isStatic ? 'none' : '';
            document.getElementById('onchain-static-address-row').style.display = isStatic ? '' : 'none';
            document.getElementById('onchain-static-tweak-row').style.display = isStatic ? '' : 'none';
            // Address type, validate/test buttons only make sense for xpub mode.
            document.getElementById('onchain-xpub-buttons').style.display = isStatic ? 'none' : '';
            const atRow = document.getElementById('onchain-address-type-row');
            const atInf = document.getElementById('onchain-address-type-inferred');
            if (isStatic) {
                atRow.style.display = 'none';
                atInf.style.display = 'none';
            } else {
                applyOnchainAddressTypeVisibility();
            }
        }

        function renderOnchainDashboard() {
            if (!dashboardData?.onchain) return;
            const oc = dashboardData.onchain;
            document.getElementById('onchain-mode').value = oc.mode || 'xpub';
            document.getElementById('onchain-network').value = oc.network || 'mainnet';
            document.getElementById('onchain-address-type').value = oc.addressType || 'P2WPKH';
            document.getElementById('onchain-min-confs').value = oc.minConfs ?? 1;
            document.getElementById('onchain-confirm-timeout').value = oc.confirmTimeoutSec ?? 86400;
            document.getElementById('onchain-provider-url').value = oc.providerUrl || '';
            document.getElementById('onchain-current-index').textContent = oc.nextIndex ?? 0;
            document.getElementById('onchain-static-address').value = oc.staticAddress || '';
            document.getElementById('onchain-static-tweak-range').value = oc.staticTweakRange ?? 1000;
            const meta = document.getElementById('onchain-xpub-meta');
            const xpubInput = document.getElementById('onchain-xpub');
            if (oc.mode !== 'static' && oc.enabled) {
                meta.innerHTML = 'Currently configured: <code style="word-break:break-all; font-size:0.8rem;">'
                    + escapeHtml(oc.xpub || '(set)')
                    + '</code><br>Paste a new xpub above to replace it.';
                xpubInput.placeholder = '(unchanged — paste new xpub to replace)';
            } else {
                meta.textContent = '';
                xpubInput.placeholder = 'xpub... or zpub... or vpub...';
            }
            const staticMeta = document.getElementById('onchain-static-address-meta');
            if (oc.mode === 'static' && oc.enabled) {
                staticMeta.innerHTML = 'Currently configured: <code style="word-break:break-all; font-size:0.8rem;">'
                    + escapeHtml(oc.staticAddress || '(set)')
                    + '</code>. Edit above to replace, or clear to disable.';
            } else {
                staticMeta.textContent =
                    'Paste a single Bitcoin address you control. The server will reuse it for every invoice. Leave blank to disable on-chain payments.';
            }
            // "Offer on-chain to customers" per-store flag.
            const offerSel = document.getElementById('onchain-offer-override');
            const offerEff = document.getElementById('onchain-offer-effective');
            if (offerSel) offerSel.value = oc.offerEnabled ? '1' : '0';
            if (offerEff) offerEff.textContent = oc.offerEnabled ? 'on' : 'off';
            // "Also accept on-chain payments via Strike" — the same flag backs
            // the toggle here and the checkbox in the Lightning payments card.
            // Without a Strike key the controls stay visible but disabled, so
            // the operator learns where to add one.
            const strikeToggle = document.getElementById('onchain-strike-toggle');
            const strikeCheckbox = document.getElementById('strike-onchain-checkbox');
            const hasStrikeKey = !!oc.strikeKeyConfigured;
            for (const el of [strikeToggle, strikeCheckbox]) {
                if (!el) continue;
                el.checked = !!oc.strikeOnchainEnabled;
                el.disabled = !hasStrikeKey && !oc.strikeOnchainEnabled;
            }
            const strikeHelp = document.getElementById('onchain-strike-help');
            if (strikeHelp && !hasStrikeKey && !oc.strikeOnchainEnabled) {
                strikeHelp.innerHTML = 'Add a Strike API key in the <strong>Lightning payments</strong> '
                    + 'card first — on-chain payments via Strike are minted with that key.';
            }
            updateOnchainOfferWarning();
            applyOnchainModeVisibility();
        }

        // Show the "some wallets can't pay you" warning whenever on-chain is
        // OFF for this store. Computed from the current select value so it
        // reacts before the save round-trips.
        function updateOnchainOfferWarning() {
            const sel = document.getElementById('onchain-offer-override');
            const warn = document.getElementById('onchain-offer-warning');
            if (!sel || !warn) return;
            warn.style.display = sel.value === '0' ? 'block' : 'none';
        }

        function onchainOfferChanged() {
            updateOnchainOfferWarning();
            saveOnchainOffer();
        }

        // On-chain card toggle for "also accept on-chain payments via Strike".
        // Saves instantly; enabling runs the server-side receive-request scope
        // probe, so a failure reverts the toggle and surfaces the reason.
        async function onchainStrikeChanged() {
            const toggle = document.getElementById('onchain-strike-toggle');
            if (!toggle) return;
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                toggle.checked = !toggle.checked;
                return;
            }
            const wanted = toggle.checked ? '1' : '0';
            toggle.disabled = true;
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=save_onchain_strike&store_id=${encodeURIComponent(currentStoreId)}&strike_onchain=${wanted}`);
                const result = await response.json();
                if (response.ok && result.success) {
                    showToast(wanted === '1'
                        ? 'On-chain payments via Strike enabled'
                        : 'On-chain payments via Strike disabled', 'success');
                    const mirror = document.getElementById('strike-onchain-checkbox');
                    if (mirror) mirror.checked = wanted === '1';
                    await loadDashboard();
                    renderOnchainDashboard();
                } else {
                    toggle.checked = wanted !== '1';
                    showToast(result.error || 'Failed to save the Strike on-chain setting', 'error');
                }
            } catch (e) {
                toggle.checked = wanted !== '1';
                showToast('Failed to save the Strike on-chain setting', 'error');
            } finally {
                toggle.disabled = false;
            }
        }

        async function saveOnchainOffer() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            const enabled = document.getElementById('onchain-offer-override').value;
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=save_onchain_offer&store_id=${encodeURIComponent(currentStoreId)}&offer_enabled=${encodeURIComponent(enabled)}`);
                const result = await response.json();
                if (response.ok && result.success) {
                    showToast('On-chain payment setting saved', 'success');
                    await loadDashboard();
                    renderOnchainDashboard();
                } else {
                    showToast(result.error || 'Failed to save on-chain setting', 'error');
                }
            } catch (e) {
                showToast('Failed to save on-chain setting', 'error');
            }
        }

        async function validateOnchainXpub() {
            const xpub = document.getElementById('onchain-xpub').value.trim();
            const box = document.getElementById('onchain-validation-box');
            if (!xpub) {
                box.style.display = 'block';
                box.style.background = 'rgba(245, 101, 101, 0.15)';
                box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                box.textContent = 'Paste an xpub first.';
                return;
            }
            const network = document.getElementById('onchain-network').value;
            const type = document.getElementById('onchain-address-type').value;
            const body = `action=validate_onchain_xpub&xpub=${encodeURIComponent(xpub)}&network=${network}&address_type=${type}`;
            const showInvalid = msg => {
                box.style.display = 'block';
                box.style.background = 'rgba(245, 101, 101, 0.15)';
                box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                box.innerHTML = '';
                const strong = document.createElement('strong');
                strong.textContent = 'Invalid: ';
                box.appendChild(strong);
                box.appendChild(document.createTextNode(msg));
            };
            let res;
            try {
                res = await readJsonResponse(await postWithCsrf(adminUrl, body));
            } catch (e) {
                // Network-level failure (offline, CORS, connection reset). Without
                // this, the button would appear to do nothing at all.
                showInvalid('Could not reach the server. Check your connection and try again.');
                return;
            }
            const data = res.data;
            box.style.display = 'block';
            if (!res.ok || !data.valid) {
                showInvalid(data.error || (res.ok ? 'unknown' : `server error (HTTP ${res.status})`));
                return;
            }
            box.innerHTML = '';
            const validStrong = document.createElement('strong');
            validStrong.textContent = 'Valid. ';
            box.appendChild(validStrong);
            box.appendChild(document.createTextNode('Verify these match your wallet\'s first 3 receive addresses:'));
            box.appendChild(document.createElement('br'));
            const pre = document.createElement('pre');
            pre.style.cssText = 'margin:0.5rem 0 0; font-size:0.85rem;';
            pre.textContent = (data.preview || []).map((a, i) => 'm/0/' + i + ' = ' + a).join('\n');
            box.appendChild(pre);
            (data.warnings || []).forEach(w => {
                const warn = document.createElement('div');
                warn.style.cssText = 'margin-top:0.5rem; color:#f6ad55;';
                warn.textContent = '⚠ ' + w;
                box.appendChild(warn);
            });
            box.style.background = 'rgba(72, 187, 120, 0.1)';
            box.style.border = '1px solid rgba(72, 187, 120, 0.3)';
        }

        async function testOnchainCurrent() {
            if (!currentStoreId) { showToast('No store selected', 'error'); return; }
            const box = document.getElementById('onchain-validation-box');
            const showFail = msg => {
                box.style.display = 'block';
                box.style.background = 'rgba(245, 101, 101, 0.15)';
                box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                box.innerHTML = '<strong>Test failed:</strong> ';
                box.appendChild(document.createTextNode(msg));
            };
            let res;
            try {
                res = await readJsonResponse(await postWithCsrf(adminUrl,
                    `action=test_onchain_xpub&store_id=${encodeURIComponent(currentStoreId)}`));
            } catch (e) {
                showFail('could not reach the server. Check your connection and try again.');
                return;
            }
            const data = res.data;
            if (!res.ok || data.error) {
                showFail(data.error || `server error (HTTP ${res.status})`);
                return;
            }
            box.style.display = 'block';
            box.style.background = 'rgba(72, 187, 120, 0.1)';
            box.style.border = '1px solid rgba(72, 187, 120, 0.3)';
            box.innerHTML = 'Current next address (m/0/' + data.index + '): <code>' + data.address + '</code>';
        }

        // In-page confirm modal — Chrome can suppress native confirm() per-tab
        // if the user once ticked "Prevent additional dialogs", which silently
        // breaks saveOnchain. Returns a Promise that resolves true/false.
        function confirmOnchain(title, body) {
            return new Promise(resolve => {
                document.getElementById('modal-onchain-confirm-title').textContent = title;
                document.getElementById('modal-onchain-confirm-body').textContent = body;
                const yes = document.getElementById('btn-onchain-confirm-yes');
                const no = document.getElementById('btn-onchain-confirm-no');
                const cleanup = answer => {
                    yes.onclick = null;
                    no.onclick = null;
                    closeModal('modal-onchain-confirm');
                    resolve(answer);
                };
                yes.onclick = () => cleanup(true);
                no.onclick = () => cleanup(false);
                openModal('modal-onchain-confirm');
            });
        }

        async function saveOnchain() {
            const box = document.getElementById('onchain-validation-box');
            const showInline = (text, ok) => {
                box.style.display = 'block';
                box.style.background = ok ? 'rgba(72, 187, 120, 0.1)' : 'rgba(245, 101, 101, 0.15)';
                box.style.border = '1px solid ' + (ok ? 'rgba(72, 187, 120, 0.3)' : 'rgba(245, 101, 101, 0.3)');
                box.innerHTML = text;
            };
            if (!currentStoreId) {
                showInline('<strong>No store selected.</strong> Pick a store from the dropdown at the top of the page first.', false);
                showToast('No store selected', 'error');
                return;
            }
            const mode = document.getElementById('onchain-mode').value;
            const network = document.getElementById('onchain-network').value;
            const minConfs = document.getElementById('onchain-min-confs').value;
            const timeout = document.getElementById('onchain-confirm-timeout').value;
            const providerUrl = document.getElementById('onchain-provider-url').value.trim();
            const prevMode = dashboardData?.onchain?.mode || 'xpub';
            const prevEnabled = !!dashboardData?.onchain?.enabled;

            let body;
            if (mode === 'static') {
                const staticAddress = document.getElementById('onchain-static-address').value.trim();
                const tweakRange = document.getElementById('onchain-static-tweak-range').value;
                if (staticAddress === '' && prevEnabled) {
                    const ok = await confirmOnchain(
                        'Disable on-chain payments?',
                        'Saving with an empty static address will disable on-chain payments for this store.'
                    );
                    if (!ok) { showInline('<strong>Cancelled.</strong> No changes saved.', false); return; }
                } else if (prevMode === 'xpub' && prevEnabled) {
                    const ok = await confirmOnchain(
                        'Switch to static address mode?',
                        'This will clear the currently configured xpub. Static address re-use reduces privacy and prevents detecting multi-transaction payments.'
                    );
                    if (!ok) { showInline('<strong>Cancelled.</strong> No changes saved.', false); return; }
                }
                body = `action=save_onchain&store_id=${encodeURIComponent(currentStoreId)}&mode=static&static_address=${encodeURIComponent(staticAddress)}&static_tweak_range=${encodeURIComponent(tweakRange)}&network=${network}&min_confs=${minConfs}&confirm_timeout_sec=${timeout}&provider_url=${encodeURIComponent(providerUrl)}`;
            } else {
                const xpub = document.getElementById('onchain-xpub').value.trim();
                const type = document.getElementById('onchain-address-type').value;
                if (xpub === '' && prevEnabled && prevMode === 'xpub') {
                    const ok = await confirmOnchain(
                        'Disable on-chain payments?',
                        'Saving with an empty xpub will disable on-chain payments for this store.'
                    );
                    if (!ok) { showInline('<strong>Cancelled.</strong> No changes saved.', false); return; }
                } else if (xpub !== '' && prevEnabled && prevMode === 'xpub') {
                    const ok = await confirmOnchain(
                        'Switch to a different xpub?',
                        'Replace the currently configured xpub with the one you just pasted.'
                    );
                    if (!ok) { showInline('<strong>Cancelled.</strong> No changes saved.', false); return; }
                } else if (prevMode === 'static' && prevEnabled) {
                    const ok = await confirmOnchain(
                        'Switch from static address to xpub?',
                        'This will clear the currently configured static address.'
                    );
                    if (!ok) { showInline('<strong>Cancelled.</strong> No changes saved.', false); return; }
                }
                body = `action=save_onchain&store_id=${encodeURIComponent(currentStoreId)}&mode=xpub&xpub=${encodeURIComponent(xpub)}&network=${network}&address_type=${type}&min_confs=${minConfs}&confirm_timeout_sec=${timeout}&provider_url=${encodeURIComponent(providerUrl)}`;
            }
            // Mirror the result inline next to the Save button — the global
            // toast sits above the mobile bottom-nav and is easy to miss.
            let res;
            try {
                res = await readJsonResponse(await postWithCsrf(adminUrl, body));
            } catch (e) {
                // Network-level failure. Surface it instead of leaving the Save
                // button apparently dead (the original silent-failure report).
                showInline('<strong>Save failed:</strong> could not reach the server. Check your connection and try again.', false);
                showToast('Could not reach the server', 'error');
                return;
            }
            const data = res.data;
            if (res.ok) {
                let suffix = '';
                if (data.disabled) {
                    // No address saved. With Strike on-chain active the rail
                    // itself stays up (Strike mints the addresses), so
                    // "disabled" would misread — only the fallback is gone.
                    suffix = data.strikeOnchain
                        ? ' (no fallback address — Strike on-chain stays active)'
                        : ' (on-chain disabled)';
                } else if (data.xpubChanged) {
                    suffix = data.resumedIndex > 0
                        ? ' — resumed at index ' + data.resumedIndex
                        : ' — new xpub starts at index 0';
                }
                showInline('<strong>&#10003; Saved' + suffix + '.</strong>', true);
                showToast('On-chain settings saved' + (data.disabled && !data.strikeOnchain ? ' (disabled)' : ''), 'success');
                loadDashboard();
            } else {
                const err = data.error || `Failed to save (HTTP ${res.status})`;
                showInline('<strong>Save failed:</strong> ' + err, false);
                showToast(err, 'error');
            }
        }

        // Save the "Lightning payments" card: the ordered destination chain
        // (LNURL addresses, NWC connections, CLINK noffers). Used both for
        // invoice receive routing and the Lightning auto-cashout rail.
        async function saveLightningPayments() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            clearAwError('lightning-payments-error');

            // Pull the ordered, non-blank addresses straight from the live rows
            // so what the operator sees is exactly what's saved.
            syncLnAddressesFromInputs();
            syncNoffersFromInputs();
            syncNwcFromInputs();
            syncStrikeFromInputs();
            const lnAddresses = awLnAddresses
                .map(a => (a.address || '').trim())
                .filter(a => a.length > 0);
            const noffers = awNoffers
                .map(a => (a.address || '').trim())
                .filter(a => a.length > 0);
            // NWC entries post as keep:<id> refs (saved rows) or full URIs
            // (new rows); blanks are dropped.
            const nwcEntries = awNwc
                .map(a => a.ref ? a.ref : (a.uri || '').trim())
                .filter(a => a.length > 0);
            // Strike entries post the same way: keep:<id> refs or raw keys.
            const strikeEntries = awStrike
                .map(a => a.ref ? a.ref : (a.key || '').trim())
                .filter(a => a.length > 0);

            // Validate each section against its own type before saving so bad
            // data surfaces inline rather than failing silently on the server.
            for (const s of strikeEntries) {
                if (s.startsWith('keep:')) continue; // saved row reference
                if (!isStrikeKeyValue(s)) {
                    // Don't echo the paste back — even a malformed key paste
                    // may be (most of) the real credential.
                    return awError('lightning-payments-error', 'That Strike API entry doesn\'t look like a valid key (one unbroken block of letters and numbers, copied from the Strike dashboard).');
                }
            }
            for (const a of lnAddresses) {
                if (isStrikeLnAddress(a)) {
                    return awError('lightning-payments-error', `"${a}" is a Strike lightning address — those don't support LUD-21 payment verification. Use the Strike API keys section above instead.`);
                }
                if (isNofferValue(a)) {
                    const nofferGated = !!document.getElementById('auto-melt-noffer-group')?.dataset.envError;
                    return awError('lightning-payments-error', nofferGated
                        ? `"${a}" is a CLINK noffer — those are unavailable on this server (see the CLINK noffers section).`
                        : `"${a}" is a CLINK noffer — add it in the CLINK noffers section, not Lightning Addresses.`);
                }
                if (!isValidLightningAddress(a)) {
                    return awError('lightning-payments-error', `"${a}" doesn't look like a valid lightning address (name@domain.tld).`);
                }
            }
            for (const n of noffers) {
                if (!isNofferValue(n)) {
                    return awError('lightning-payments-error', `"${n}" doesn't look like a valid CLINK noffer (noffer1…).`);
                }
            }
            for (const w of nwcEntries) {
                if (w.startsWith('keep:')) continue; // saved row reference
                if (!isNwcValue(w)) {
                    // Don't echo the paste back — a malformed NWC string may
                    // still contain the wallet secret.
                    return awError('lightning-payments-error', 'That NWC entry doesn\'t look like a valid connection string (nostr+walletconnect://…).');
                }
            }
            // Reject duplicates (case-insensitive) across the lists — the
            // combined chain should never contain the same destination
            // twice. (NWC duplicates are caught server-side, where the
            // stored values behind keep: refs are known.)
            const all = lnAddresses.concat(noffers).map(a => a.toLowerCase());
            const dup = all.find((a, i) => all.indexOf(a) !== i);
            if (dup) {
                return awError('lightning-payments-error', `Duplicate destination: ${dup}`);
            }

            try {
                let body = `action=save_lightning_payments&store_id=${encodeURIComponent(currentStoreId)}`;
                // Send the three ordered lists separately; the server chains
                // them addresses-first, then NWC, noffers last. Empty lists
                // clear the chain.
                for (const a of lnAddresses) {
                    body += `&ln_addresses%5B%5D=${encodeURIComponent(a)}`;
                }
                for (const n of noffers) {
                    body += `&noffers%5B%5D=${encodeURIComponent(n)}`;
                }
                for (const w of nwcEntries) {
                    body += `&nwc%5B%5D=${encodeURIComponent(w)}`;
                }
                for (const s of strikeEntries) {
                    body += `&strike%5B%5D=${encodeURIComponent(s)}`;
                }
                // "Also accept on-chain payments via Strike" rides this save;
                // the server probes the receive-request scope when enabling.
                const strikeOnchainEl = document.getElementById('strike-onchain-checkbox');
                if (strikeOnchainEl) {
                    body += `&strike_onchain=${strikeOnchainEl.checked ? '1' : '0'}`;
                }

                const response = await postWithCsrf(adminUrl, body);

                const result = await response.json();

                if (response.ok) {
                    showToast('Settings saved!', 'success');
                    // Mirror the saved Strike on-chain flag onto the on-chain
                    // card's toggle (both controls back the same store flag).
                    if (typeof result.strikeOnchainEnabled === 'boolean') {
                        const strikeOnchainToggle = document.getElementById('onchain-strike-toggle');
                        if (strikeOnchainToggle) strikeOnchainToggle.checked = result.strikeOnchainEnabled;
                        if (strikeOnchainEl) strikeOnchainEl.checked = result.strikeOnchainEnabled;
                    }
                    // Surface the NWC probe's spend-permission warning (the
                    // connection works but can also pay out of the wallet).
                    const nwcWarnEl = document.getElementById('auto-melt-nwc-warning');
                    const nwcWarnings = Array.isArray(result.addresses)
                        ? result.addresses.filter(r => r.type === 'nwc' && r.warning).map(r => r.warning)
                        : [];
                    if (nwcWarnEl) {
                        if (nwcWarnings.length) {
                            nwcWarnEl.textContent = '⚠ ' + nwcWarnings.join(' ');
                            nwcWarnEl.classList.remove('hidden');
                        } else {
                            nwcWarnEl.classList.add('hidden');
                        }
                    }
                    // Update per-address LUD-21 hints from the save response so
                    // the operator sees probe results without a full reload.
                    // (nwc rows are excluded here: the save response carries
                    // their masked label but not the keep-ref, so the reliable
                    // masked+ref state comes from the loadDashboard() below.)
                    if (Array.isArray(result.addresses)) {
                        const mapped = result.addresses.map(r => ({
                            address: r.address,
                            type: r.type || (isNofferValue(r.address) ? 'noffer' : 'lnaddress'),
                            lud21Support: (r.lud21Support === null || r.lud21Support === undefined)
                                ? null : Number(r.lud21Support),
                        }));
                        awLnAddresses = mapped.filter(a => a.type !== 'noffer' && a.type !== 'nwc' && a.type !== 'strike');
                        awNoffers = mapped.filter(a => a.type === 'noffer');
                        if (dashboardData && dashboardData.autoMelt) {
                            // nwc/strike rows are cached from loadDashboard()
                            // only — the save response has their masked label
                            // but not the keep-ref, and a ref-less row would
                            // rerender as an editable (bogus) secret input.
                            dashboardData.autoMelt.addresses = mapped
                                .filter(a => a.type !== 'nwc' && a.type !== 'strike')
                                .map(a => ({ ...a }));
                        }
                        renderLnAddressRows();
                        renderNofferRows();
                    }
                    // Reload dashboard so the effective-mode badge reflects the
                    // save (and re-renders saved NWC rows masked, with refs).
                    if (typeof loadDashboard === 'function') loadDashboard();
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save settings', 'error');
            }
        }

        // Save the "Cashu automatic cashout" card: withdrawal mode, enable
        // toggle and threshold. The destination chain itself is saved by
        // saveLightningPayments above.
        async function saveAutoMelt() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            clearAwError('aw-store-error');
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const enabled = document.getElementById('auto-melt-enabled').checked ? '1' : '0';
            const modeOverride = document.getElementById('auto-melt-mode-override').value;

            if (modeOverride === '0') {
                // Lightning cashout needs at least one destination in the
                // Lightning payments section (saved rows or unsaved edits).
                syncLnAddressesFromInputs();
                syncNoffersFromInputs();
                syncNwcFromInputs();
                syncStrikeFromInputs();
                const haveDestinations =
                    awLnAddresses.some(a => (a.address || '').trim().length > 0)
                    || awNoffers.some(a => (a.address || '').trim().length > 0)
                    || awNwc.some(a => a.ref || (a.uri || '').trim().length > 0)
                    || awStrike.some(a => a.ref || (a.key || '').trim().length > 0);
                if (enabled === '1' && !haveDestinations) {
                    return awError('aw-store-error', 'Add at least one Strike API key, lightning address, NWC connection, or noffer in the Lightning payments section to withdraw to.');
                }
            } else if (modeOverride === '1') {
                if (!storeHasOnchain()) {
                    return awError('aw-store-error', 'On-chain withdrawal needs an on-chain xpub or address. Add one in the On-chain Bitcoin payments section first.');
                }
            }

            // Convert threshold to smallest unit (cents for fiat)
            const threshold = parseAmount(document.getElementById('auto-melt-threshold').value, mintUnit);

            try {
                const body = `action=save_auto_melt&store_id=${encodeURIComponent(currentStoreId)}`
                    + `&enabled=${enabled}`
                    + `&threshold=${threshold}`
                    + `&mode_override=${encodeURIComponent(modeOverride)}`;

                const response = await postWithCsrf(adminUrl, body);
                const result = await response.json();

                if (response.ok) {
                    showToast('Settings saved!', 'success');
                    // Reload dashboard so the effective-mode badge reflects the
                    // save.
                    if (typeof loadDashboard === 'function') loadDashboard();
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save settings', 'error');
            }
        }

        // ---- Auto-cashout column selector + collapsible sections ----

        function isValidLightningAddress(addr) {
            // LUD-16 lightning address: local-part@domain.tld (basic shape check).
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(addr || '').trim());
        }

        // Whether the current store has an on-chain xpub / static address set.
        function storeHasOnchain() {
            return !!(dashboardData && dashboardData.onchain && dashboardData.onchain.enabled);
        }

        function awError(elId, msg) {
            const el = document.getElementById(elId);
            if (el) { el.textContent = msg; el.classList.remove('hidden'); }
            showToast(msg, 'error');
            return false;
        }
        function clearAwError(elId) {
            const el = document.getElementById(elId);
            if (el) { el.textContent = ''; el.classList.add('hidden'); }
        }

        function awContainer(scope) {
            return document.querySelector(`[data-aw][data-aw-scope="${scope}"]`);
        }

        // Highlight the column matching `mode` ("-1" | "0" | "1") within a container.
        function highlightAwColumn(container, mode) {
            if (!container) return;
            container.querySelectorAll('.aw-col').forEach(col => {
                const on = col.getAttribute('data-aw-mode') === String(mode);
                col.classList.toggle('selected', on);
                col.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        // User picked a column. Reflect it into the hidden control that the
        // existing save flow reads, and fire the live-preview 'change'
        // listener so the effective-mode hint updates immediately.
        function selectAwColumn(container, mode) {
            const scope = container.getAttribute('data-aw-scope');
            highlightAwColumn(container, mode);
            if (scope === 'store') {
                clearAwError('aw-store-error');
                const hidden = document.getElementById('auto-melt-mode-override');
                if (hidden) {
                    hidden.value = String(mode);
                    hidden.dispatchEvent(new Event('change'));
                }
            }
        }

        function wireAwSelectors() {
            document.querySelectorAll('[data-aw]').forEach(container => {
                if (container.dataset.awWired) return;
                container.dataset.awWired = '1';
                container.querySelectorAll('.aw-col').forEach(col => {
                    const pick = () => selectAwColumn(container, col.getAttribute('data-aw-mode'));
                    col.addEventListener('click', pick);
                    col.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
                    });
                });
            });
            // Point the "Strike" links at the configured STRIKE_URL.
            if (typeof strikeUrl === 'string' && strikeUrl) {
                document.querySelectorAll('.aw-strike-link').forEach(a => { a.href = strikeUrl; });
            }
        }

        // Wire collapsible cards (.card.collapsible) and subsections (.subsection).
        // Default state is open; a card/subsection starts collapsed only if it
        // carries the .collapsed class in markup.
        function wireCollapsibles() {
            document.querySelectorAll('.card.collapsible > .card-header').forEach(header => {
                if (header.dataset.collapsibleWired) return;
                header.dataset.collapsibleWired = '1';
                header.addEventListener('click', (e) => {
                    // Ignore clicks on interactive controls inside the header.
                    if (e.target.closest('button, a, input, select, textarea, label')) return;
                    header.parentElement.classList.toggle('collapsed');
                });
            });
            document.querySelectorAll('.subsection > .subsection-toggle').forEach(tog => {
                if (tog.dataset.collapsibleWired) return;
                tog.dataset.collapsibleWired = '1';
                tog.addEventListener('click', () => tog.parentElement.classList.toggle('collapsed'));
            });
        }

        // Site-wide email state, fetched lazily so the store settings page can
        // warn when per-store notifications are enabled but site email is off.
        let _siteNotifState = null;
        async function getSiteNotifState(force = false) {
            if (_siteNotifState && !force) return _siteNotifState;
            try {
                const r = await postWithCsrf(adminUrl, 'action=get_notifications_settings');
                if (r.ok) _siteNotifState = await r.json();
            } catch (e) { /* leave null; warning just won't show */ }
            return _siteNotifState;
        }
        async function warnIfSiteEmailUnavailable() {
            const st = await getSiteNotifState(true);
            if (!st) return;
            if (!st.enabled || !st.smtpConfigured) {
                const why = !st.enabled
                    ? 'site-wide email notifications are turned off'
                    : 'no SMTP server is configured';
                showToast(`Heads up: ${why} in site Settings, so this store's emails won't be delivered until that's fixed.`, 'info');
            }
        }

        /**
         * Show the LUD-21 hint underneath the LN-address field, reflecting
         * the most recent probe stored on the store. Three states:
         *   null  — not probed (or host unreachable at save time): hide both
         *   0     — probed and unsupported: show the warning
         *   1     — probed and supported: show the ok hint
         */
        // ---- Ordered Lightning-address fallback chain ----
        // Working copy of the chain: [{address, lud21Support}]. Priority is the
        // array order; index 0 is the primary, the rest are fallbacks tried in
        // turn when an earlier host is down / can't produce an invoice.
        let awLnAddresses = [];
        // CLINK noffers are kept in their own list, rendered in a dedicated
        // section below the lightning addresses. On save they are appended
        // after the addresses (LN first, noffers as fallback).
        let awNoffers = [];
        // NWC connections, own section between addresses and noffers (their
        // priority position in the chain). Entries are either kept rows
        // ({label, ref:'keep:<id>'} — the server never sends the raw URI,
        // which embeds the wallet secret; shown read-only) or new rows
        // ({uri, ref:null} — an editable input the operator pastes into).
        let awNwc = [];
        // Strike API keys, own section above the lightning addresses (they
        // lead the chain at invoice time). Same kept/new row shapes as awNwc:
        // {label, ref:'keep:<id>'} for saved keys (the server never sends the
        // raw key) or {key, ref:null} for a freshly pasted one.
        let awStrike = [];

        // A destination is a CLINK noffer if it's a bech32 'noffer1…' string
        // (optionally lightning:-prefixed), otherwise it's a Lightning address.
        // Mirrors the server-side auto-detection in save_auto_melt.
        function isNofferValue(v) {
            return /^(lightning:)?noffer1[02-9ac-hj-np-z]+$/i.test(String(v || '').trim());
        }

        // Cheap client-side shape check for an NWC connection URI; the server
        // fully re-validates (and probes) on save.
        function isNwcValue(v) {
            return /^nostr\+walletconnect:(\/\/)?[0-9a-f]{64}\?/i.test(String(v || '').trim());
        }

        // Cheap client-side shape check for a Strike API key (one unbroken
        // alphanumeric token); the server fully re-validates (and probes with
        // a 1-sat test invoice) on save.
        function isStrikeKeyValue(v) {
            return /^[A-Za-z0-9]{16,256}$/.test(String(v || '').trim());
        }

        // A @strike.me lightning address can never work as an LNURL
        // destination (no LUD-21 verify) — the Strike API section is the
        // supported path.
        function isStrikeLnAddress(v) {
            return /@strike\.me$/i.test(String(v || '').trim());
        }

        function setLnAddressRowsFromData() {
            const am = dashboardData && dashboardData.autoMelt;
            const src = (am && Array.isArray(am.addresses)) ? am.addresses : [];
            // The stored chain mixes both types in priority order. Split it back
            // into the two UI lists by type, preserving each type's relative
            // order, so addresses and noffers render in their own sections.
            const mapped = src.map(a => ({
                address: a.address || '',
                type: a.type || (isNofferValue(a.address) ? 'noffer' : 'lnaddress'),
                lud21Support: (a.lud21Support === null || a.lud21Support === undefined)
                    ? null : Number(a.lud21Support),
                ref: a.ref || null,
            }));
            awLnAddresses = mapped.filter(a => a.type !== 'noffer' && a.type !== 'nwc' && a.type !== 'strike');
            awNoffers = mapped.filter(a => a.type === 'noffer');
            // Stored nwc/strike rows arrive masked: address is the display
            // label and ref the opaque keep:<id> the save posts back.
            awNwc = mapped.filter(a => a.type === 'nwc')
                .map(a => ({ label: a.address, uri: '', ref: a.ref }));
            awStrike = mapped.filter(a => a.type === 'strike')
                .map(a => ({ label: a.address, key: '', ref: a.ref }));
            renderLnAddressRows();
            renderNofferRows();
            renderNwcRows();
            renderStrikeRows();
        }

        // Read the current input values back into awLnAddresses (preserving the
        // per-row probe result) so structural ops and save use live edits.
        function syncLnAddressesFromInputs() {
            const list = document.getElementById('auto-melt-address-list');
            if (!list) return;
            const inputs = list.querySelectorAll('input.ln-address-input');
            const next = [];
            inputs.forEach((inp, i) => {
                const noffer = isNofferValue(inp.value);
                next.push({
                    address: inp.value,
                    type: noffer ? 'noffer' : 'lnaddress',
                    lud21Support: (!noffer && awLnAddresses[i]) ? awLnAddresses[i].lud21Support : null,
                });
            });
            awLnAddresses = next;
        }

        function addLnAddressRow() {
            syncLnAddressesFromInputs();
            awLnAddresses.push({ address: '', type: 'lnaddress', lud21Support: null });
            renderLnAddressRows();
            // Focus the freshly added input.
            const list = document.getElementById('auto-melt-address-list');
            if (list) {
                const inputs = list.querySelectorAll('input.ln-address-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }
        }

        function removeLnAddressRow(index) {
            syncLnAddressesFromInputs();
            awLnAddresses.splice(index, 1);
            renderLnAddressRows();
        }

        function moveLnAddressRow(index, delta) {
            syncLnAddressesFromInputs();
            const target = index + delta;
            if (target < 0 || target >= awLnAddresses.length) return;
            const tmp = awLnAddresses[index];
            awLnAddresses[index] = awLnAddresses[target];
            awLnAddresses[target] = tmp;
            renderLnAddressRows();
        }

        // LUD-21 hint text for a single row (null hides it).
        function lud21HintFor(support) {
            if (support === 0) {
                return { cls: 'warn', text: 'Host does not advertise a LUD-21 verify URL. '
                    + 'Payments route through the mint, then auto-cashout here (two-hop) '
                    + 'instead of going directly to this address.' };
            }
            if (support === 1) {
                return { cls: 'ok', text: 'Direct-receive supported (LUD-21 verify URL present). '
                    + 'Payments go straight to this address, except when an invoice is smaller '
                    + 'than the fees the store owes.' };
            }
            return null;
        }

        function renderLnAddressRows() {
            const list = document.getElementById('auto-melt-address-list');
            if (!list) return;
            list.innerHTML = '';
            awLnAddresses.forEach((entry, i) => {
                const row = document.createElement('div');
                row.className = 'ln-address-row';
                row.style.cssText = 'display:flex; align-items:center; gap:0.4rem; margin-bottom:0.4rem;';

                const prio = document.createElement('span');
                prio.textContent = (i + 1) + '.';
                prio.style.cssText = 'min-width:1.4rem; text-align:right; opacity:0.7; font-size:0.85rem;';
                row.appendChild(prio);

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-input ln-address-input';
                input.placeholder = 'user@wallet.com';
                input.value = entry.address || '';
                input.style.flex = '1';
                // Editing invalidates the cached probe result for that row and
                // re-detects the type so the hint stays accurate while typing.
                input.addEventListener('input', () => {
                    if (awLnAddresses[i]) {
                        awLnAddresses[i].lud21Support = null;
                        awLnAddresses[i].type = isNofferValue(input.value) ? 'noffer' : 'lnaddress';
                    }
                    const hintEl = row.nextElementSibling;
                    if (!hintEl || !hintEl.classList.contains('ln-address-hint')) return;
                    // Live warning for @strike.me — those addresses can never
                    // pass the LUD-21 save gate; the Strike API section is the
                    // supported path.
                    if (isStrikeLnAddress(input.value)) {
                        hintEl.textContent = 'Strike lightning addresses don\'t support LUD-21 '
                            + 'payment verification and can\'t be used here — use the '
                            + 'Strike API keys section above instead.';
                        hintEl.style.color = 'var(--warning, #b07b00)';
                        hintEl.style.display = '';
                    } else {
                        hintEl.style.display = 'none';
                    }
                });
                row.appendChild(input);

                const mkBtn = (label, title, handler, disabled) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn btn-secondary';
                    b.textContent = label;
                    b.title = title;
                    b.style.cssText = 'padding:0.3rem 0.55rem; line-height:1;';
                    if (disabled) { b.disabled = true; b.style.opacity = '0.4'; }
                    else b.addEventListener('click', handler);
                    return b;
                };
                row.appendChild(mkBtn('↑', 'Move up', () => moveLnAddressRow(i, -1), i === 0));
                row.appendChild(mkBtn('↓', 'Move down', () => moveLnAddressRow(i, 1), i === awLnAddresses.length - 1));
                row.appendChild(mkBtn('✕', 'Remove', () => removeLnAddressRow(i), false));

                list.appendChild(row);

                // Per-row hint, shown beneath the input. noffers settle via a
                // Nostr payment receipt (no LUD-21), so they get their own note.
                const hintEl = document.createElement('p');
                hintEl.className = 'form-help ln-address-hint';
                hintEl.style.cssText = 'margin:0 0 0.5rem 1.8rem;';
                if (entry.type === 'noffer') {
                    // Static markup only — no user data goes through innerHTML here.
                    hintEl.innerHTML = 'CLINK noffer — payments are fetched over Nostr and '
                        + 'confirmed by the merchant’s kind-21001 payment receipt. '
                        + '⚠️ Using <a href="https://electrum.org" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">Electrum</a> '
                        + 'with the <a href="https://github.com/BareBits/electrum_clink" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">CLINK plugin</a> '
                        + 'is STRONGLY recommended as opposed to other wallets with CLINK '
                        + 'support. CLINK payment receipts are temporary when issued, which '
                        + 'means they can be lost during small network outages and report '
                        + 'invoices as “unpaid”. There is no risk to funds from this, but it '
                        + 'does mean an order may show unpaid when it was, in fact, paid.';
                    hintEl.style.color = 'var(--success, #2d7a3a)';
                } else if (isStrikeLnAddress(entry.address)) {
                    hintEl.textContent = 'Strike lightning addresses don\'t support LUD-21 '
                        + 'payment verification and can\'t be used here — use the '
                        + 'Strike API keys section above instead.';
                    hintEl.style.color = 'var(--warning, #b07b00)';
                } else {
                    const hint = lud21HintFor(entry.lud21Support);
                    if (hint) {
                        hintEl.textContent = hint.text;
                        hintEl.style.color = hint.cls === 'warn'
                            ? 'var(--warning, #b07b00)' : 'var(--success, #2d7a3a)';
                    } else {
                        hintEl.style.display = 'none';
                    }
                }
                list.appendChild(hintEl);
            });
        }

        // ---- CLINK noffer list (own section, mirrors the LN address list) ----

        function syncNoffersFromInputs() {
            const list = document.getElementById('auto-melt-noffer-list');
            if (!list) return;
            const inputs = list.querySelectorAll('input.noffer-input');
            const next = [];
            inputs.forEach((inp) => {
                next.push({ address: inp.value, type: 'noffer', lud21Support: null });
            });
            awNoffers = next;
        }

        function addNofferRow() {
            syncNoffersFromInputs();
            awNoffers.push({ address: '', type: 'noffer', lud21Support: null });
            renderNofferRows();
            const list = document.getElementById('auto-melt-noffer-list');
            if (list) {
                const inputs = list.querySelectorAll('input.noffer-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }
        }

        function removeNofferRow(index) {
            syncNoffersFromInputs();
            awNoffers.splice(index, 1);
            renderNofferRows();
        }

        function moveNofferRow(index, delta) {
            syncNoffersFromInputs();
            const target = index + delta;
            if (target < 0 || target >= awNoffers.length) return;
            const tmp = awNoffers[index];
            awNoffers[index] = awNoffers[target];
            awNoffers[target] = tmp;
            renderNofferRows();
        }

        function renderNofferRows() {
            const list = document.getElementById('auto-melt-noffer-list');
            if (!list) return;
            // Environment gate (host can't run the CLINK client, e.g. no GMP):
            // existing entries stay visible and removable, but read-only — the
            // server rejects new noffers on such a host anyway.
            const envGated = !!document.getElementById('auto-melt-noffer-group')?.dataset.envError;
            list.innerHTML = '';
            awNoffers.forEach((entry, i) => {
                const row = document.createElement('div');
                row.className = 'noffer-row';
                row.style.cssText = 'display:flex; align-items:center; gap:0.4rem; margin-bottom:0.4rem;';

                const prio = document.createElement('span');
                prio.textContent = (i + 1) + '.';
                prio.style.cssText = 'min-width:1.4rem; text-align:right; opacity:0.7; font-size:0.85rem;';
                row.appendChild(prio);

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-input noffer-input';
                input.placeholder = 'noffer1…';
                input.value = entry.address || '';
                input.style.flex = '1';
                if (envGated) { input.disabled = true; input.style.opacity = '0.5'; }
                row.appendChild(input);

                const mkBtn = (label, title, handler, disabled) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn btn-secondary';
                    b.textContent = label;
                    b.title = title;
                    b.style.cssText = 'padding:0.3rem 0.55rem; line-height:1;';
                    if (disabled) { b.disabled = true; b.style.opacity = '0.4'; }
                    else b.addEventListener('click', handler);
                    return b;
                };
                row.appendChild(mkBtn('↑', 'Move up', () => moveNofferRow(i, -1), envGated || i === 0));
                row.appendChild(mkBtn('↓', 'Move down', () => moveNofferRow(i, 1), envGated || i === awNoffers.length - 1));
                row.appendChild(mkBtn('✕', 'Remove', () => removeNofferRow(i), false));

                list.appendChild(row);
            });
        }

        // ---- NWC connection list (own section, between addresses and noffers) ----
        //
        // Saved rows are shown as a read-only masked label (wallet + relay) —
        // the raw URI embeds the connection secret and never reaches the
        // browser, so "editing" a saved connection means removing it and
        // adding the replacement. New rows are normal paste inputs.

        function syncNwcFromInputs() {
            const list = document.getElementById('auto-melt-nwc-list');
            if (!list) return;
            const next = [];
            list.querySelectorAll('[data-nwc-entry]').forEach((el) => {
                if (el.dataset.nwcRef) {
                    next.push({ label: el.dataset.nwcLabel || '', uri: '', ref: el.dataset.nwcRef });
                } else {
                    const inp = el.querySelector('input.nwc-input');
                    next.push({ label: '', uri: inp ? inp.value : '', ref: null });
                }
            });
            awNwc = next;
        }

        function addNwcRow() {
            syncNwcFromInputs();
            awNwc.push({ label: '', uri: '', ref: null });
            renderNwcRows();
            const list = document.getElementById('auto-melt-nwc-list');
            if (list) {
                const inputs = list.querySelectorAll('input.nwc-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }
        }

        function removeNwcRow(index) {
            syncNwcFromInputs();
            awNwc.splice(index, 1);
            renderNwcRows();
        }

        function moveNwcRow(index, delta) {
            syncNwcFromInputs();
            const target = index + delta;
            if (target < 0 || target >= awNwc.length) return;
            const tmp = awNwc[index];
            awNwc[index] = awNwc[target];
            awNwc[target] = tmp;
            renderNwcRows();
        }

        function renderNwcRows() {
            const list = document.getElementById('auto-melt-nwc-list');
            if (!list) return;
            const envGated = !!document.getElementById('auto-melt-nwc-group')?.dataset.envError;
            list.innerHTML = '';
            awNwc.forEach((entry, i) => {
                const row = document.createElement('div');
                row.className = 'nwc-row';
                row.setAttribute('data-nwc-entry', '1');
                if (entry.ref) {
                    row.dataset.nwcRef = entry.ref;
                    row.dataset.nwcLabel = entry.label || '';
                }
                row.style.cssText = 'display:flex; align-items:center; gap:0.4rem; margin-bottom:0.4rem;';

                const prio = document.createElement('span');
                prio.textContent = (i + 1) + '.';
                prio.style.cssText = 'min-width:1.4rem; text-align:right; opacity:0.7; font-size:0.85rem;';
                row.appendChild(prio);

                if (entry.ref) {
                    const label = document.createElement('span');
                    label.className = 'nwc-saved-label';
                    label.textContent = entry.label || 'NWC connection';
                    label.title = 'Saved NWC connection — the secret stays on the server. Remove and re-add to change it.';
                    label.style.cssText = 'flex:1; padding:0.45rem 0.6rem; border:1px dashed var(--border, #ccc); border-radius:6px; opacity:0.85; font-size:0.9rem;';
                    row.appendChild(label);
                } else {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-input nwc-input';
                    input.placeholder = 'nostr+walletconnect://…';
                    input.value = entry.uri || '';
                    input.style.flex = '1';
                    if (envGated) { input.disabled = true; input.style.opacity = '0.5'; }
                    row.appendChild(input);
                }

                const mkBtn = (label, title, handler, disabled) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn btn-secondary';
                    b.textContent = label;
                    b.title = title;
                    b.style.cssText = 'padding:0.3rem 0.55rem; line-height:1;';
                    if (disabled) { b.disabled = true; b.style.opacity = '0.4'; }
                    else b.addEventListener('click', handler);
                    return b;
                };
                row.appendChild(mkBtn('↑', 'Move up', () => moveNwcRow(i, -1), envGated || i === 0));
                row.appendChild(mkBtn('↓', 'Move down', () => moveNwcRow(i, 1), envGated || i === awNwc.length - 1));
                row.appendChild(mkBtn('✕', 'Remove', () => removeNwcRow(i), false));

                list.appendChild(row);
            });
        }

        function syncStrikeFromInputs() {
            const list = document.getElementById('auto-melt-strike-list');
            if (!list) return;
            const next = [];
            list.querySelectorAll('[data-strike-entry]').forEach((el) => {
                if (el.dataset.strikeRef) {
                    next.push({ label: el.dataset.strikeLabel || '', key: '', ref: el.dataset.strikeRef });
                } else {
                    const inp = el.querySelector('input.strike-input');
                    next.push({ label: '', key: inp ? inp.value : '', ref: null });
                }
            });
            awStrike = next;
        }

        function addStrikeRow() {
            syncStrikeFromInputs();
            awStrike.push({ label: '', key: '', ref: null });
            renderStrikeRows();
            const list = document.getElementById('auto-melt-strike-list');
            if (list) {
                const inputs = list.querySelectorAll('input.strike-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }
        }

        function removeStrikeRow(index) {
            syncStrikeFromInputs();
            awStrike.splice(index, 1);
            renderStrikeRows();
        }

        function moveStrikeRow(index, delta) {
            syncStrikeFromInputs();
            const target = index + delta;
            if (target < 0 || target >= awStrike.length) return;
            const tmp = awStrike[index];
            awStrike[index] = awStrike[target];
            awStrike[target] = tmp;
            renderStrikeRows();
        }

        function renderStrikeRows() {
            const list = document.getElementById('auto-melt-strike-list');
            if (!list) return;
            list.innerHTML = '';
            awStrike.forEach((entry, i) => {
                const row = document.createElement('div');
                row.className = 'strike-row';
                row.setAttribute('data-strike-entry', '1');
                if (entry.ref) {
                    row.dataset.strikeRef = entry.ref;
                    row.dataset.strikeLabel = entry.label || '';
                }
                row.style.cssText = 'display:flex; align-items:center; gap:0.4rem; margin-bottom:0.4rem;';

                const prio = document.createElement('span');
                prio.textContent = (i + 1) + '.';
                prio.style.cssText = 'min-width:1.4rem; text-align:right; opacity:0.7; font-size:0.85rem;';
                row.appendChild(prio);

                if (entry.ref) {
                    const label = document.createElement('span');
                    label.className = 'strike-saved-label';
                    label.textContent = entry.label || 'Strike API key';
                    label.title = 'Saved Strike API key — the key stays on the server. Remove and re-add to change it.';
                    label.style.cssText = 'flex:1; padding:0.45rem 0.6rem; border:1px dashed var(--border, #ccc); border-radius:6px; opacity:0.85; font-size:0.9rem;';
                    row.appendChild(label);
                } else {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-input strike-input';
                    input.placeholder = 'paste your Strike API key';
                    input.autocomplete = 'off';
                    input.spellcheck = false;
                    input.value = entry.key || '';
                    input.style.flex = '1';
                    row.appendChild(input);
                }

                const mkBtn = (label, title, handler, disabled) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn btn-secondary';
                    b.textContent = label;
                    b.title = title;
                    b.style.cssText = 'padding:0.3rem 0.55rem; line-height:1;';
                    if (disabled) { b.disabled = true; b.style.opacity = '0.4'; }
                    else b.addEventListener('click', handler);
                    return b;
                };
                row.appendChild(mkBtn('↑', 'Move up', () => moveStrikeRow(i, -1), i === 0));
                row.appendChild(mkBtn('↓', 'Move down', () => moveStrikeRow(i, 1), i === awStrike.length - 1));
                row.appendChild(mkBtn('✕', 'Remove', () => removeStrikeRow(i), false));

                list.appendChild(row);
            });
        }

        // Kept for callers that refresh the chain display after a mode change.
        // Preserve any unsaved edits already typed into the rows before redrawing.
        function renderLud21Warning() {
            const list = document.getElementById('auto-melt-address-list');
            if (list && list.querySelectorAll('input.ln-address-input').length === awLnAddresses.length) {
                syncLnAddressesFromInputs();
            }
            renderLnAddressRows();
        }

        /**
         * Populate the cashout card from dashboardData.autoMelt: mode
         * selection, effective-mode hint, swap-mode blurb, and the
         * greyed-out state when the store has no Cashu mint wallet.
         */
        function renderAutoMeltMode() {
            const am = dashboardData && dashboardData.autoMelt;
            if (!am) return;
            const modeEl = document.getElementById('auto-melt-mode-override');
            if (modeEl) modeEl.value = String(am.modeOverride);
            const effLabel = document.getElementById('auto-melt-mode-effective');
            if (effLabel) effLabel.textContent = (am.mode === 'swap') ? 'On-chain via submarine swap' : 'Lightning address';
            const swapInfo = document.getElementById('auto-melt-swap-info');
            if (swapInfo) swapInfo.style.display = (am.mode === 'swap') ? 'block' : 'none';

            // Grey the whole card out when Cashu is disabled for this store
            // (no mint wallet). The server-rendered env gate (no GMP/BCMath)
            // wins — it already made the body inert, so don't fight it.
            const cashuBody = document.getElementById('cashu-cashout-body');
            const mintWarn = document.getElementById('cashu-cashout-mint-warning');
            if (cashuBody && !cashuBody.dataset.envError) {
                const hasMint = !!(dashboardData && dashboardData.storeConfigured);
                cashuBody.style.opacity = hasMint ? '' : '0.5';
                cashuBody.style.pointerEvents = hasMint ? '' : 'none';
                if (mintWarn) mintWarn.classList.toggle('hidden', hasMint);
            }

            const minSatsEl = document.getElementById('auto-melt-mode-min-sats');
            if (minSatsEl && am.swapMinSats != null) minSatsEl.textContent = Number(am.swapMinSats).toLocaleString();
            const maxPctEl = document.getElementById('auto-melt-mode-max-fee-pct');
            if (maxPctEl && am.swapMaxFeePct != null) maxPctEl.textContent = am.swapMaxFeePct + '%';

            // Reflect the override into the column selector + show the on-chain
            // warning when On-chain is picked but no xpub/address is configured.
            const storeAw = awContainer('store');
            highlightAwColumn(storeAw, am.modeOverride);
            const warnBox = document.getElementById('aw-store-warning');
            if (warnBox) {
                const needsAddr = String(am.modeOverride) === '1' && !storeHasOnchain();
                warnBox.classList.toggle('hidden', !needsAddr);
            }

            renderLud21Warning();
        }

        async function saveStoreNotifications() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            const enabled = document.getElementById('store-notifications-enabled').checked ? '1' : '0';
            const email = document.getElementById('store-notification-email').value.trim();
            const passwordCleared = document.getElementById('store-smtp-password-clear').checked;
            const passwordTyped = document.getElementById('store-smtp-password').value !== '';
            const storeNewsletterEl = document.getElementById('store-newsletter-default');
            const params = new URLSearchParams({
                action: 'save_store_notifications',
                store_id: currentStoreId,
                enabled, email,
                newsletter_default_checked: storeNewsletterEl ? storeNewsletterEl.value : '',
                smtp_override_enabled: document.getElementById('store-smtp-override-enabled').checked ? '1' : '0',
                smtp_host: document.getElementById('store-smtp-host').value.trim(),
                smtp_port: document.getElementById('store-smtp-port').value.trim(),
                smtp_username: document.getElementById('store-smtp-username').value.trim(),
                smtp_encryption: document.getElementById('store-smtp-encryption').value,
                smtp_from_address: document.getElementById('store-smtp-from-address').value.trim(),
                smtp_from_name: document.getElementById('store-smtp-from-name').value.trim(),
                smtp_password: document.getElementById('store-smtp-password').value,
                smtp_password_clear: passwordCleared ? '1' : '0',
            });
            try {
                const response = await postWithCsrf(adminUrl, params.toString());
                const result = await response.json();
                if (response.ok) {
                    showToast('Notification settings saved!', 'success');
                    if (enabled === '1') warnIfSiteEmailUnavailable();
                    // Reset the write-only password UI to reflect the new state.
                    document.getElementById('store-smtp-password').value = '';
                    document.getElementById('store-smtp-password-clear').checked = false;
                    const help = document.getElementById('store-smtp-password-help');
                    if (help) help.textContent = (passwordCleared || (!passwordTyped && help.textContent.indexOf('No password') === 0))
                        ? 'No password saved.'
                        : 'A password is saved. Leave blank to keep it.';
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save notification settings', 'error');
            }
        }

        async function saveStorePrivacy() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            const hideStoreName = document.getElementById('store-hide-store-name').checked ? '1' : '0';
            const hideNote = document.getElementById('store-hide-note').checked ? '1' : '0';
            const params = new URLSearchParams({
                action: 'save_store_privacy',
                store_id: currentStoreId,
                hide_store_name_on_invoice: hideStoreName,
                hide_note_on_invoice: hideNote,
            });
            try {
                const response = await postWithCsrf(adminUrl, params.toString());
                const result = await response.json();
                if (response.ok) {
                    showToast('Privacy settings saved!', 'success');
                    // Keep the in-memory copy fresh so the Request/Cart modals
                    // pre-fill their override checkboxes from the new defaults
                    // without a full dashboard reload.
                    if (dashboardData) {
                        dashboardData.invoicePrivacy = {
                            hideStoreName: hideStoreName === '1',
                            hideNote: hideNote === '1',
                        };
                    }
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save privacy settings', 'error');
            }
        }

        // Show/hide the per-store SMTP override fields based on the toggle.
        function updateStoreSmtpFieldsVisibility() {
            const ov = document.getElementById('store-smtp-override-enabled');
            const fields = document.getElementById('store-smtp-fields');
            if (ov && fields) fields.classList.toggle('hidden', !ov.checked);
        }

        async function sendStoreTestNotification() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            const to = document.getElementById('store-notifications-test-email').value.trim()
                || document.getElementById('store-notification-email').value.trim();
            if (!to) {
                showToast('Enter a recipient email', 'error');
                return;
            }
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=send_test_notification&store_id=${encodeURIComponent(currentStoreId)}&to=${encodeURIComponent(to)}`
                );
                const result = await response.json();
                if (response.ok) {
                    showToast('Test email sent', 'success');
                } else {
                    showToast(result.error || 'Failed to send', 'error');
                }
            } catch (e) {
                showToast('Failed to send test email', 'error');
            }
        }

        async function loadNotificationSettings() {
            try {
                // POST (not GET ?action=) — with path-based admin routing a GET to
                // a sub-path URL is served the SPA page, not the JSON action.
                const response = await postWithCsrf(adminUrl, 'action=get_notifications_settings');
                if (!response.ok) return;
                const data = await response.json();
                document.getElementById('notifications-enabled').checked = !!data.enabled;
                document.getElementById('notifications-invoice-paid').checked = !!data.invoicePaidEnabled;
                document.getElementById('notifications-auto-cashout').checked = !!data.autoCashoutEnabled;
                // Effective server-side value (explicit config, or the
                // managed/standalone default when never saved).
                const payerCaptureEl = document.getElementById('notifications-payer-email-capture');
                if (payerCaptureEl) payerCaptureEl.checked = !!data.payerEmailCaptureEnabled;
                const payerReceiptEl = document.getElementById('notifications-payer-receipt');
                if (payerReceiptEl) payerReceiptEl.checked = !!data.payerReceiptEnabled;
                const newsletterDefaultEl = document.getElementById('notifications-newsletter-default');
                if (newsletterDefaultEl) newsletterDefaultEl.checked = !!data.newsletterDefaultChecked;
                updatePayerCaptureDependents();
                document.getElementById('notifications-to-email').value = data.toEmail || '';
                document.getElementById('notifications-smtp-warning').classList.toggle('hidden', !!data.smtpConfigured);
                // Global SMTP server fields. Password is write-only: it's never
                // returned, so we only reflect whether one is stored.
                document.getElementById('smtp-host').value = data.smtpHost || '';
                document.getElementById('smtp-port').value = data.smtpPort || '';
                document.getElementById('smtp-username').value = data.smtpUsername || '';
                document.getElementById('smtp-encryption').value = data.smtpEncryption || '';
                document.getElementById('smtp-from-address').value = data.smtpFromAddress || '';
                document.getElementById('smtp-from-name').value = data.smtpFromName || '';
                document.getElementById('smtp-password').value = '';
                document.getElementById('smtp-password-clear').checked = false;
                document.getElementById('smtp-password-help').textContent = data.smtpPasswordSet
                    ? 'A password is saved. Leave blank to keep it.'
                    : 'No password saved.';
                const pending = document.getElementById('notifications-pending');
                if (pending) {
                    pending.textContent = data.pendingQueueCount > 0
                        ? `${data.pendingQueueCount} email(s) waiting to be sent on the next cron tick.`
                        : '';
                }
            } catch (e) {
                console.error('Failed to load notification settings', e);
            }
        }

        // Receipt + newsletter toggles are dead while payer email capture is
        // off (the payment page never renders the form) — grey them out so
        // the dependency is visible, but keep their stored values intact.
        function updatePayerCaptureDependents() {
            const captureEl = document.getElementById('notifications-payer-email-capture');
            const depEl = document.getElementById('payer-email-capture-dependent');
            if (!captureEl || !depEl) return;
            const on = captureEl.checked;
            depEl.style.opacity = on ? '' : '0.5';
            depEl.style.pointerEvents = on ? '' : 'none';
        }

        async function saveNotificationSettings() {
            const enabled = document.getElementById('notifications-enabled').checked ? '1' : '0';
            const invoicePaid = document.getElementById('notifications-invoice-paid').checked ? '1' : '0';
            const autoCashout = document.getElementById('notifications-auto-cashout').checked ? '1' : '0';
            const payerCaptureEl = document.getElementById('notifications-payer-email-capture');
            const payerEmailCapture = payerCaptureEl && payerCaptureEl.checked ? '1' : '0';
            const payerReceiptEl = document.getElementById('notifications-payer-receipt');
            const payerReceipt = payerReceiptEl && payerReceiptEl.checked ? '1' : '0';
            const newsletterDefaultEl = document.getElementById('notifications-newsletter-default');
            const newsletterDefault = newsletterDefaultEl && newsletterDefaultEl.checked ? '1' : '0';
            const toEmail = document.getElementById('notifications-to-email').value.trim();
            const params = new URLSearchParams({
                action: 'save_notifications_settings',
                enabled, invoice_paid_enabled: invoicePaid,
                auto_cashout_enabled: autoCashout,
                payer_email_capture_enabled: payerEmailCapture,
                payer_receipt_enabled: payerReceipt,
                newsletter_default_checked: newsletterDefault,
                to_email: toEmail,
                smtp_host: document.getElementById('smtp-host').value.trim(),
                smtp_port: document.getElementById('smtp-port').value.trim(),
                smtp_username: document.getElementById('smtp-username').value.trim(),
                smtp_encryption: document.getElementById('smtp-encryption').value,
                smtp_from_address: document.getElementById('smtp-from-address').value.trim(),
                smtp_from_name: document.getElementById('smtp-from-name').value.trim(),
                smtp_password: document.getElementById('smtp-password').value,
                smtp_password_clear: document.getElementById('smtp-password-clear').checked ? '1' : '0',
            });
            try {
                const response = await postWithCsrf(adminUrl, params.toString());
                const result = await response.json();
                if (response.ok) {
                    showToast('Notification settings saved!', 'success');
                    loadNotificationSettings();
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save notification settings', 'error');
            }
        }

        // -------- Developer / debug settings --------

        async function loadDeveloperSettings() {
            try {
                // POST (not GET ?action=) — with path-based admin routing a GET to
                // a sub-path URL is served the SPA page, not the JSON action.
                const response = await postWithCsrf(adminUrl, 'action=get_developer_settings');
                if (!response.ok) return;
                const data = await response.json();
                document.getElementById('developer-payment-path-debug').checked = !!data.paymentPathDebug;
            } catch (e) {
                console.error('Failed to load developer settings', e);
            }
        }

        async function saveDeveloperSettings() {
            const params = new URLSearchParams({
                action: 'save_developer_settings',
                payment_path_debug: document.getElementById('developer-payment-path-debug').checked ? '1' : '0',
            });
            try {
                const response = await postWithCsrf(adminUrl, params.toString());
                const result = await response.json();
                if (response.ok) {
                    showToast('Developer settings saved!', 'success');
                    loadDeveloperSettings();
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save developer settings', 'error');
            }
        }

        // -------- Invoice page error settings (site-wide suppress switch) --------

        async function loadInvoiceErrorSettings() {
            try {
                const response = await postWithCsrf(adminUrl, 'action=get_invoice_error_settings');
                if (!response.ok) return;
                const data = await response.json();
                document.getElementById('suppress-invoice-errors').checked = !!data.suppressErrors;
            } catch (e) {
                console.error('Failed to load invoice error settings', e);
            }
        }

        async function saveInvoiceErrorSettings() {
            const params = new URLSearchParams({
                action: 'save_invoice_error_settings',
                suppress_errors: document.getElementById('suppress-invoice-errors').checked ? '1' : '0',
            });
            try {
                const response = await postWithCsrf(adminUrl, params.toString());
                const result = await response.json();
                if (response.ok) {
                    showToast('Invoice error settings saved!', 'success');
                    loadInvoiceErrorSettings();
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save invoice error settings', 'error');
            }
        }

        // -------- Log view (unified recent-issues feed) --------

        const adminLogState = { offset: 0, total: 0 };
        // store_id -> display name, filled by populateLogStoreFilter so the
        // table can show names instead of opaque ids.
        let logStoreNames = {};

        const LOG_CATEGORY_LABELS = {
            nwc: 'NWC wallet',
            noffer: 'Noffer',
            lnurl: 'Lightning address',
            mint: 'Mint',
            webhook: 'Webhook',
            email: 'Email',
            swap: 'Swap',
            sweep: 'Sweep',
        };

        async function populateLogStoreFilter() {
            const sel = document.getElementById('log-store-filter');
            if (!sel) return;
            try {
                const r = await fetch(adminUrl + '?api=stores');
                const stores = await r.json();
                logStoreNames = {};
                stores.forEach(s => { logStoreNames[s.id] = s.name || s.id; });
                const prev = sel.value;
                sel.innerHTML = '<option value="__all__">All stores</option>'
                    + stores.map(s => `<option value="${s.id}">${escapeHtml(s.name || s.id)}</option>`).join('');
                sel.value = [...sel.options].some(o => o.value === prev) ? prev : '__all__';
            } catch (e) {
                // Leave the existing "All stores" option in place on failure.
            }
        }

        async function loadAdminLog() {
            const listEl = document.getElementById('admin-log-list');
            if (!listEl) return;
            try {
                const params = new URLSearchParams({
                    action: 'get_admin_log',
                    limit: String(LIST_PAGE_SIZE),
                    offset: String(adminLogState.offset),
                });
                const cat = document.getElementById('log-category-filter').value;
                if (cat) params.set('category', cat);
                const store = document.getElementById('log-store-filter').value;
                if (store && store !== '__all__') params.set('store_id', store);
                const response = await postWithCsrf(adminUrl, params.toString());
                const data = await response.json();
                if (!response.ok) {
                    showToast(data.error || 'Failed to load log', 'error');
                    return;
                }
                adminLogState.total = data.total || 0;
                renderAdminLog(data.entries || []);
                renderListPagination('admin-log-pagination', adminLogState, (off) => {
                    adminLogState.offset = off;
                    loadAdminLog();
                });
            } catch (e) {
                showToast('Failed to load log', 'error');
            }
        }

        function renderAdminLog(entries) {
            const listEl = document.getElementById('admin-log-list');
            if (!listEl) return;
            const rows = entries.map(e => {
                const cat = LOG_CATEGORY_LABELS[e.category] || e.category;
                const store = e.storeId ? (logStoreNames[e.storeId] || e.storeId) : '';
                // Deep-link the invoice id into the invoices view is overkill
                // here; show a short prefix so the operator can find it.
                const inv = e.invoiceId ? e.invoiceId.substring(0, 12) : '';
                return `<tr>
                    <td style="white-space: nowrap; vertical-align: top;">${escapeHtml(fmtTimestamp(e.timestamp))}</td>
                    <td style="vertical-align: top;"><code style="font-size: 0.75rem;">${escapeHtml(cat)}</code></td>
                    <td style="vertical-align: top;">${escapeHtml(store)}</td>
                    <td style="vertical-align: top; word-break: break-all; font-size: 0.75rem;">${escapeHtml(e.ref || '')}${inv ? '<br><span style="color: var(--text-secondary);">inv ' + escapeHtml(inv) + '&hellip;</span>' : ''}</td>
                    <td style="vertical-align: top; word-break: break-word; font-size: 0.75rem;">${escapeHtml(e.message)}</td>
                </tr>`;
            }).join('');
            listEl.innerHTML = `
                <div style="overflow: auto; border: 1px solid var(--border); border-radius: 6px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.3); position: sticky; top: 0;">
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Time</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Category</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Store</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Destination</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Details</th>
                            </tr>
                        </thead>
                        <tbody>${rows || '<tr><td colspan="5" style="padding: 1rem; text-align: center; color: var(--text-secondary);">No issues recorded.</td></tr>'}</tbody>
                    </table>
                </div>`;
        }

        // -------- Submarine swap settings --------

        function refreshStoreSwapsCard() {
            // Driven by dashboardData.swaps once loaded.
            if (!dashboardData || !dashboardData.swaps) return;
            const s = dashboardData.swaps;
            const enabledEl = document.getElementById('store-swaps-enabled');
            const eff = document.getElementById('store-swaps-effective');
            if (enabledEl) enabledEl.checked = !!s.enabled;
            if (eff) eff.textContent = s.effective ? 'on' : 'off';

            // Provider checkboxes. Order: providerOrder (enabled, in
            // preference order) first, then the rest of knownProviders
            // disabled-by-default.
            const known = (s.knownProviders || []);
            const order = (s.providerOrder || []);
            const enabledSet = new Set(order);
            const renderedOrder = [...order, ...known.filter(p => !enabledSet.has(p))];
            const container = document.getElementById('store-swaps-provider-checkboxes');
            if (container) {
                container.innerHTML = '';
                renderedOrder.forEach(name => {
                    const label = document.createElement('label');
                    label.style.display = 'flex';
                    label.style.alignItems = 'center';
                    label.style.gap = '0.5rem';
                    label.style.cursor = 'pointer';
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = name;
                    cb.checked = enabledSet.has(name);
                    cb.dataset.providerName = name;
                    label.appendChild(cb);
                    label.appendChild(document.createTextNode(' ' + name));
                    container.appendChild(label);
                });
            }

            const autoEl = document.getElementById('store-swaps-auto-select');
            const thresholdEl = document.getElementById('store-swaps-auto-threshold');
            if (autoEl) autoEl.checked = s.autoSelectCheapest !== false; // default true
            if (thresholdEl) thresholdEl.value = s.autoSelectThresholdPct ?? 10;
            if (autoEl && thresholdEl) {
                const sync = () => { thresholdEl.disabled = !autoEl.checked; };
                sync();
                autoEl.onchange = sync;
            }
            const minEl = document.getElementById('store-swaps-min-sats');
            if (minEl) minEl.value = s.minimumTargetSats ?? '';

            // Fee-too-high → mint fallback per-store values. Blank inherits
            // the config-file constant, reflected in the placeholder.
            const fpct = document.getElementById('store-swaps-fee-pct');
            const fsats = document.getElementById('store-swaps-fee-sats');
            const feff = document.getElementById('store-swaps-fee-effective');
            if (fpct) {
                fpct.value = (s.feeFallbackMaxPct ?? null) === null ? '' : s.feeFallbackMaxPct;
                fpct.placeholder = s.feeFallbackDefaultPct > 0 ? `default (${s.feeFallbackDefaultPct})` : 'off';
            }
            if (fsats) {
                fsats.value = (s.feeFallbackMaxSats ?? null) === null ? '' : s.feeFallbackMaxSats;
                fsats.placeholder = s.feeFallbackDefaultSats > 0 ? `default (${s.feeFallbackDefaultSats})` : 'off';
            }
            if (feff) {
                const pctTxt = s.feeFallbackEffectivePct > 0 ? (s.feeFallbackEffectivePct + '%') : 'off';
                const satsTxt = s.feeFallbackEffectiveSats > 0 ? (Number(s.feeFallbackEffectiveSats).toLocaleString() + ' sat') : 'off';
                feff.textContent = `${pctTxt} / ${satsTxt}`;
            }
            // Per-store strict-no-mint-fallback flag.
            const strictSel = document.getElementById('store-swaps-strict');
            if (strictSel) strictSel.value = s.strict ? '1' : '0';
            // A revealed phrase belongs to the store it was revealed for, so
            // clear it whenever the settings panel re-binds to a store.
            resetStoreSeedCard();
        }

        function resetStoreSeedCard() {
            const output = document.getElementById('store-seed-output');
            const valueEl = document.getElementById('store-seed-value');
            const pwEl = document.getElementById('store-seed-password');
            if (output) output.classList.add('hidden');
            if (valueEl) valueEl.textContent = '';
            if (pwEl) pwEl.value = '';
            clearAwError('store-seed-error');
        }

        // -------- Store recovery phrase --------

        async function revealStoreSeed() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            clearAwError('store-seed-error');
            const output = document.getElementById('store-seed-output');
            const valueEl = document.getElementById('store-seed-value');
            const pwEl = document.getElementById('store-seed-password');
            output.classList.add('hidden');

            const password = pwEl.value;
            if (!password) {
                return awError('store-seed-error', 'Enter your password to show the recovery phrase.');
            }
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=reveal_store_seed&store_id=${encodeURIComponent(currentStoreId)}`
                    + `&password=${encodeURIComponent(password)}`
                );
                const result = await response.json();
                if (!response.ok) {
                    return awError('store-seed-error', result.error || 'Could not show the recovery phrase');
                }
                // Don't leave the password sitting in the DOM once it's spent.
                pwEl.value = '';
                if (!result.seedPhrase) {
                    return awError('store-seed-error',
                        'This store has no Cashu mint wallet, so there is no recovery phrase to show.');
                }
                valueEl.textContent = result.seedPhrase;
                output.classList.remove('hidden');
            } catch (e) {
                awError('store-seed-error', 'Could not show the recovery phrase');
            }
        }

        async function saveStoreSwaps() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            clearAwError('store-swaps-error');
            const enabled = document.getElementById('store-swaps-enabled').checked ? '1' : '0';
            // Turning swaps on requires an on-chain address; warn + don't save.
            if (enabled === '1' && !storeHasOnchain()) {
                return awError('store-swaps-error', 'Submarine swaps require an on-chain xpub or address on the Bitcoin tab.');
            }
            // Provider list from checked checkboxes, preserving the DOM order
            // (the checkboxes were rendered with the saved order first).
            const order = Array.from(document.querySelectorAll(
                '#store-swaps-provider-checkboxes input[type=checkbox]:checked'))
                .map(cb => cb.value)
                .join(',');
            if (enabled === '1' && !order) {
                return awError('store-swaps-error', 'Select at least one swap provider.');
            }
            const autoSelect = document.getElementById('store-swaps-auto-select').checked ? '1' : '0';
            const threshold = document.getElementById('store-swaps-auto-threshold').value.trim();
            const minSats = document.getElementById('store-swaps-min-sats').value.trim();
            const feePct = document.getElementById('store-swaps-fee-pct').value.trim();
            const feeSats = document.getElementById('store-swaps-fee-sats').value.trim();
            const strict = document.getElementById('store-swaps-strict').value;
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=save_store_swaps&store_id=${encodeURIComponent(currentStoreId)}&enabled=${encodeURIComponent(enabled)}`
                    + `&provider_order=${encodeURIComponent(order)}`
                    + `&auto_select_cheapest=${autoSelect}`
                    + `&auto_select_threshold_pct=${encodeURIComponent(threshold)}`
                    + `&minimum_target_sats=${encodeURIComponent(minSats)}`
                    + `&fee_fallback_max_pct=${encodeURIComponent(feePct)}`
                    + `&fee_fallback_max_sats=${encodeURIComponent(feeSats)}`
                    + `&strict_no_mint_fallback=${encodeURIComponent(strict)}`
                );
                const result = await response.json();
                if (response.ok) {
                    showToast('Store swap setting saved', 'success');
                    // Re-fetch dashboard data to refresh "effective" indicator.
                    await loadDashboard();
                    refreshStoreSwapsCard();
                } else {
                    awError('store-swaps-error', result.error || 'Failed to save');
                }
            } catch (e) {
                showToast('Failed to save store swap setting', 'error');
            }
        }

        // -------- Self-serve invoice settings --------

        function refreshStoreSelfServeCard() {
            // Driven by dashboardData.selfserve once loaded.
            if (!dashboardData || !dashboardData.selfserve) return;
            const s = dashboardData.selfserve;
            const sel = document.getElementById('store-selfserve-override');
            const eff = document.getElementById('store-selfserve-effective');
            const maxEl = document.getElementById('store-selfserve-max');
            const maxEff = document.getElementById('store-selfserve-max-effective');
            const linkRow = document.getElementById('store-selfserve-link-row');
            const linkEl = document.getElementById('store-selfserve-link');
            if (sel) sel.value = s.enabled ? '1' : '0';
            if (eff) eff.textContent = s.effective ? 'on' : 'off';
            if (maxEl) maxEl.value = (s.maxSatsOverride ?? null) === null ? '' : s.maxSatsOverride;
            if (maxEl) maxEl.placeholder = 'default (' + Number(s.maxSatsDefault ?? 500000).toLocaleString() + ')';
            if (maxEff) maxEff.textContent = Number(s.effectiveMaxSats).toLocaleString();
            // Show the shareable public link only when self-serve is effectively on.
            if (linkRow && linkEl) {
                if (s.effective && s.payUrl) {
                    linkEl.value = s.payUrl;
                    linkRow.style.display = '';
                } else {
                    linkRow.style.display = 'none';
                }
            }

            // Mirror the link into the store-info grid (next to Mint URL), again
            // only when self-serve is effectively on.
            const infoRow = document.getElementById('store-info-selfserve-row');
            const infoLink = document.getElementById('store-info-selfserve-link');
            if (infoRow && infoLink) {
                if (s.effective && s.payUrl) {
                    infoLink.value = s.payUrl;
                    infoRow.style.display = '';
                } else {
                    infoRow.style.display = 'none';
                }
            }

            // Mirror the link onto the Invoices view banner so the feature is
            // discoverable from where operators manage invoices.
            const banner = document.getElementById('card-selfserve-link');
            const bannerLink = document.getElementById('invoices-selfserve-link');
            const bannerOpen = document.getElementById('invoices-selfserve-open');
            if (banner && bannerLink) {
                if (s.effective && s.payUrl) {
                    bannerLink.value = s.payUrl;
                    if (bannerOpen) bannerOpen.href = s.payUrl;
                    banner.classList.remove('hidden');
                } else {
                    banner.classList.add('hidden');
                }
            }
        }

        function copyLinkFromEl(elId) {
            const linkEl = document.getElementById(elId);
            if (!linkEl || !linkEl.value) return;
            navigator.clipboard.writeText(linkEl.value).then(() => {
                showToast('Link copied!', 'success');
            }).catch(() => showToast('Could not copy link', 'error'));
        }

        function copySelfServeLink() {
            copyLinkFromEl('store-selfserve-link');
        }

        async function saveStoreSelfServe() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            clearAwError('store-selfserve-error');
            const enabled = document.getElementById('store-selfserve-override').value;
            const maxSats = document.getElementById('store-selfserve-max').value.trim();
            // Turning self-serve on requires a payment method on the store.
            if (enabled === '1' && dashboardData?.selfserve && !dashboardData.selfserve.paymentCapable) {
                return awError('store-selfserve-error', 'This store has no payment method configured (add a Cashu mint or on-chain address first).');
            }
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=save_store_selfserve&store_id=${encodeURIComponent(currentStoreId)}&enabled=${encodeURIComponent(enabled)}`
                    + `&max_sats=${encodeURIComponent(maxSats)}`
                );
                const result = await response.json();
                if (response.ok) {
                    showToast('Store self-serve setting saved', 'success');
                    await loadDashboard();
                    refreshStoreSelfServeCard();
                } else {
                    awError('store-selfserve-error', result.error || 'Failed to save');
                }
            } catch (e) {
                showToast('Failed to save store self-serve setting', 'error');
            }
        }

        async function sendTestNotification() {
            const to = document.getElementById('notifications-test-email').value.trim()
                || document.getElementById('notifications-to-email').value.trim();
            if (!to) {
                showToast('Enter a recipient email', 'error');
                return;
            }
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=send_test_notification&to=${encodeURIComponent(to)}`
                );
                const result = await response.json();
                if (response.ok) {
                    showToast('Test email sent', 'success');
                } else {
                    showToast(result.error || 'Failed to send', 'error');
                }
            } catch (e) {
                showToast('Failed to send test email', 'error');
            }
        }

        async function saveHostingFee() {
            const pct = document.getElementById('hosting-fee-percent').value;
            const dest = document.getElementById('hosting-fee-destination').value.trim();
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            try {
                const response = await postWithCsrf(adminUrl,
                    `action=update_store&store_id=${encodeURIComponent(currentStoreId)}&hosting_fee_percent=${encodeURIComponent(pct)}&hosting_fee_destination=${encodeURIComponent(dest)}`
                );
                const result = await response.json();
                if (response.ok) {
                    showToast('Hosting fee saved!', 'success');
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save hosting fee', 'error');
            }
        }

        async function saveExchangeSettings() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }

            const primaryProvider = document.getElementById('price-provider-primary').value;
            const secondaryProvider = document.getElementById('price-provider-secondary').value;
            const exchangeFee = document.getElementById('exchange-fee-percent').value;
            const defaultCurrency = document.getElementById('default-currency').value;

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=update_store&store_id=${encodeURIComponent(currentStoreId)}&price_provider_primary=${encodeURIComponent(primaryProvider)}&price_provider_secondary=${encodeURIComponent(secondaryProvider)}&exchange_fee_percent=${encodeURIComponent(exchangeFee)}&default_currency=${encodeURIComponent(defaultCurrency)}`
                );

                const result = await response.json();

                if (response.ok) {
                    showToast('Exchange settings saved!', 'success');
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save exchange settings', 'error');
            }
        }

        // URL Mode re-detect (standalone deployments only). The setup wizard
        // already auto-detected once; this is the same probe + auto-save flow,
        // run manually from the Settings page when hosting changes.
        // Mirror of Urls::urlModeLabel() for client-side status text.
        function urlModeLabel(mode) {
            if (mode === 'clean') return 'Clean URLs';
            if (mode === 'direct') return 'Direct URLs';
            return 'Router.php URLs';
        }

        async function testUrlEndpoint(url) {
            try {
                const response = await fetch(url, { method: 'GET', mode: 'same-origin' });
                // 200 from a healthy server, 503 from a half-set-up one — both
                // confirm the route resolves (mirrors setup.php's logic).
                return response.status === 200 || response.status === 503;
            } catch (e) {
                return false;
            }
        }

        // Probe an extension-less, non-/api/v1 route to tell "clean URLs" apart
        // from "direct" (both answer /api/v1/... via .htaccess, so that probe
        // can't distinguish them). /health is the target: it is cron-key gated,
        // so when the front-controller rewrite is active it resolves to
        // health.php and returns 403 (unauthenticated). A 404 means the pretty
        // path did NOT route — clean URLs are unavailable. 200/503 also count
        // (e.g. before the key exists) as "the route resolved".
        async function testCleanUrl(url) {
            try {
                const response = await fetch(url, { method: 'GET', mode: 'same-origin' });
                return response.status === 200 || response.status === 403 || response.status === 503;
            } catch (e) {
                return false;
            }
        }

        async function detectAndSaveUrlMode() {
            const statusEl = document.getElementById('url-mode-detect-status');
            const labelEl = document.getElementById('url-mode-current-label');
            if (statusEl) {
                statusEl.textContent = 'Detecting…';
                statusEl.style.color = 'var(--text-secondary)';
            }

            const baseUrl = urlModeConfig.baseUrl;
            // Prefer the nicest routing the host actually supports:
            // clean (pretty URLs) > direct (.php files) > router (front-controller).
            const tests = await Promise.all([
                testCleanUrl(baseUrl + '/health'),
                testUrlEndpoint(baseUrl + '/api/v1/server/info'),
                testUrlEndpoint(baseUrl + '/router.php/api/v1/server/info')
            ]);

            let selectedMode = null;
            if (tests[0]) selectedMode = 'clean';
            else if (tests[1]) selectedMode = 'direct';
            else if (tests[2]) selectedMode = 'router';

            if (!selectedMode) {
                if (statusEl) {
                    statusEl.textContent = 'No routing detected';
                    statusEl.style.color = 'var(--warning, #b07b00)';
                }
                showToast('Could not detect a working URL routing mode', 'error');
                return;
            }

            try {
                const response = await postWithCsrf(adminUrl, `action=save_url_mode&mode=${encodeURIComponent(selectedMode)}`);
                const result = await response.json();

                if (response.ok && result.success) {
                    urlModeConfig.currentMode = selectedMode;
                    if (labelEl) {
                        labelEl.textContent = urlModeLabel(selectedMode);
                    }
                    const urlEl = document.getElementById('current-server-url');
                    if (urlEl && result.serverUrl) urlEl.textContent = result.serverUrl;

                    if (result.serverUrl) {
                        serverUrl = result.serverUrl;
                        API_BASE_URL = result.serverUrl.replace(/\/$/, '');
                    }

                    if (statusEl) {
                        statusEl.textContent = 'Updated';
                        statusEl.style.color = 'var(--success)';
                    }
                    showToast('URL routing updated to ' + urlModeLabel(selectedMode), 'success');
                } else {
                    if (statusEl) {
                        statusEl.textContent = 'Save failed';
                        statusEl.style.color = 'var(--warning, #b07b00)';
                    }
                    showToast(result.error || 'Failed to save URL routing', 'error');
                }
            } catch (e) {
                if (statusEl) {
                    statusEl.textContent = 'Save failed';
                    statusEl.style.color = 'var(--warning, #b07b00)';
                }
                showToast('Failed to save URL routing', 'error');
            }
        }

        async function logout() {
            await postWithCsrf(adminUrl, 'action=logout');

            localStorage.removeItem(STORAGE_AUTH);
            location.reload();
        }

        // ===============================
        // Mint Discovery Functions
        // ===============================
        let mintDiscoveryInstance = null;
        let discoveredMints = [];
        let discoveryCallback = null;
        let discoveryContext = null;
        // Trusted-list URLs that should show the "Suggested by BareBits"
        // badge and float to the top of the list.
        let suggestedMintUrls = [];
        // Mint URL -> ISO country code (uppercase), populated lazily as
        // cards render.
        const mintCountryCache = {};
        const FLAG_BASE = <?= json_encode(Urls::assets('img/flags/')) ?>;

        function normalizeMintUrl(u) {
            return String(u || '').replace(/\/+$/, '');
        }

        function openBackupMintDiscovery(storeId, storeName) {
            discoveryContext = 'backup';
            discoveryCallback = (url) => {
                document.getElementById('backup-mint-url').value = url;
            };
            openMintDiscoveryModal();
        }

        let mintDisclaimerAcknowledged = false;

        function openMintDiscoveryModal() {
            document.getElementById('mint-discovery-modal').style.display = 'flex';
            // Reset disclaimer checkbox state when opening
            const checkbox = document.getElementById('mint-disclaimer-checkbox');
            if (checkbox) {
                checkbox.checked = false;
                mintDisclaimerAcknowledged = false;
            }
            // Suggested-mints list is fetched in parallel with Nostr discovery;
            // both populate `discoveredMints` and sort happens on each render.
            fetchSuggestedMintsAdmin();
            // Auto-start discovery
            startMintDiscovery();
        }

        function fetchSuggestedMintsAdmin() {
            return fetch(adminUrl + '?api=get_suggested_mints', { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : { mints: [] })
                .then(data => {
                    const urls = Array.isArray(data && data.mints) ? data.mints : [];
                    suggestedMintUrls = urls.map(normalizeMintUrl);
                    suggestedMintUrls.forEach(url => {
                        const present = discoveredMints.some(m => normalizeMintUrl(m.url) === url);
                        if (present) return;
                        fetch(url + '/v1/info', { credentials: 'omit' })
                            .then(r => r.ok ? r.json() : null)
                            .then(info => {
                                const entry = {
                                    url,
                                    info,
                                    error: !info,
                                    averageRating: null,
                                    reviewsCount: 0,
                                };
                                const existing = discoveredMints.findIndex(m => normalizeMintUrl(m.url) === url);
                                if (existing >= 0) discoveredMints[existing] = entry;
                                else discoveredMints.push(entry);
                                renderDiscoveredMints();
                            })
                            .catch(() => {
                                const existing = discoveredMints.findIndex(m => normalizeMintUrl(m.url) === url);
                                if (existing < 0) {
                                    discoveredMints.push({ url, info: null, error: true, averageRating: null, reviewsCount: 0 });
                                    renderDiscoveredMints();
                                }
                            });
                    });
                    renderDiscoveredMints();
                })
                .catch(() => { suggestedMintUrls = []; });
        }

        function isSuggestedMint(url) {
            return suggestedMintUrls.indexOf(normalizeMintUrl(url)) !== -1;
        }

        // Debounced batch country lookup: collect pending URLs for one tick,
        // then issue a single ?api=mint_country_batch request.
        let countryFetchQueue = [];
        let countryFetchTimer = null;

        function fetchCountryForCard(url, cardEl) {
            const normalized = normalizeMintUrl(url);
            if (mintCountryCache[normalized] !== undefined) {
                applyCountryToCard(cardEl, mintCountryCache[normalized]);
                return;
            }
            countryFetchQueue.push({ url, cardEl });
            if (countryFetchTimer) return;
            countryFetchTimer = setTimeout(flushCountryFetchQueueAdmin, 50);
        }

        function flushCountryFetchQueueAdmin() {
            countryFetchTimer = null;
            if (!countryFetchQueue.length) return;
            const pending = countryFetchQueue.splice(0, countryFetchQueue.length);
            const uniqueUrls = [];
            const seen = {};
            pending.forEach(p => {
                const n = normalizeMintUrl(p.url);
                if (!seen[n]) { seen[n] = true; uniqueUrls.push(p.url); }
            });
            const qs = uniqueUrls.map(encodeURIComponent).join(',');
            fetch(adminUrl + '?api=mint_country_batch&urls=' + qs, { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : { countries: {} })
                .then(data => {
                    const byUrl = (data && data.countries) || {};
                    pending.forEach(p => {
                        const raw = byUrl[p.url];
                        const cc = raw ? String(raw).toUpperCase() : null;
                        mintCountryCache[normalizeMintUrl(p.url)] = cc;
                        applyCountryToCard(p.cardEl, cc);
                    });
                })
                .catch(() => {
                    pending.forEach(p => {
                        mintCountryCache[normalizeMintUrl(p.url)] = null;
                    });
                });
        }

        function applyCountryToCard(cardEl, cc) {
            if (!cardEl || !cc) return;
            const holder = cardEl.querySelector('.mint-country-slot');
            if (!holder) return;
            const safe = cc.toLowerCase().replace(/[^a-z]/g, '');
            if (safe.length !== 2) return;
            holder.innerHTML = '<img src="' + FLAG_BASE + safe + '.svg" alt="' + cc +
                '" style="width: 16px; height: 12px; vertical-align: middle; border-radius: 2px; margin-right: 0.25rem; box-shadow: 0 0 0 1px rgba(0,0,0,0.2);"> ' +
                '<span style="font-size: 0.75rem; color: var(--text-secondary); letter-spacing: 0.5px;">' + cc + '</span>';
        }

        function onMintDisclaimerChange(checkbox) {
            mintDisclaimerAcknowledged = checkbox.checked;
            updateMintSelectButtons();
        }

        function updateMintSelectButtons() {
            const buttons = document.querySelectorAll('#mint-discovery-list button');
            buttons.forEach(btn => {
                btn.disabled = !mintDisclaimerAcknowledged;
                btn.style.opacity = mintDisclaimerAcknowledged ? '1' : '0.5';
                btn.style.cursor = mintDisclaimerAcknowledged ? 'pointer' : 'not-allowed';
            });
        }

        function closeMintDiscoveryModal() {
            document.getElementById('mint-discovery-modal').style.display = 'none';
            if (mintDiscoveryInstance) {
                mintDiscoveryInstance.close();
                mintDiscoveryInstance = null;
            }
        }

        async function startMintDiscovery() {
            const listEl = document.getElementById('mint-discovery-list');
            const loadingEl = document.getElementById('mint-discovery-loading');
            const statusEl = document.getElementById('mint-discovery-status');

            loadingEl.style.display = 'block';
            listEl.innerHTML = '';
            statusEl.textContent = 'Connecting to Nostr relays...';

            if (typeof MintDiscovery === 'undefined') {
                statusEl.textContent = 'Error: MintDiscovery library not loaded';
                loadingEl.style.display = 'none';
                return;
            }

            try {
                mintDiscoveryInstance = MintDiscovery.create({
                    httpTimeout: 8000,
                    nostrTimeout: 15000
                });

                discoveredMints = await mintDiscoveryInstance.discover({
                    onProgress: (progress) => {
                        if (progress.phase === 'nostr' && progress.step === 'mint-info') {
                            statusEl.textContent = 'Fetching mint announcements...';
                        } else if (progress.phase === 'nostr' && progress.step === 'reviews') {
                            statusEl.textContent = 'Fetching reviews...';
                        } else if (progress.phase === 'http') {
                            statusEl.textContent = 'Checking mint status...';
                        }
                    }
                });

                loadingEl.style.display = 'none';
                statusEl.textContent = `Found ${discoveredMints.length} mints`;
                renderDiscoveredMints();
            } catch (error) {
                loadingEl.style.display = 'none';
                statusEl.textContent = 'Error: ' + error.message;
            }
        }

        function getUnitsFromMintInfo(info) {
            if (!info?.nuts?.[4]?.methods) return [];
            return [...new Set(info.nuts[4].methods.map(m => m.unit).filter(Boolean))];
        }

        // Whether the mint advertises a given NUT as supported in /v1/info.
        // NUTs are keyed by number; optional NUTs expose {"supported": bool}.
        function mintSupportsNut(info, nut) {
            const n = info?.nuts?.[nut];
            if (n === undefined || n === null) return false;
            if (typeof n === 'object') return n.supported !== false;
            return !!n;
        }

        // DLEQ (NUT-12) capability badge — required for offline acceptance —
        // plus a P2PK (NUT-11) indicator. Pulled straight from /v1/info so the
        // operator can see at a glance which mints are offline-capable.
        function renderMintCapabilityBadges(info) {
            if (!info) return '';
            const dleq = mintSupportsNut(info, 12);
            const p2pk = mintSupportsNut(info, 11);
            const chip = (ok, label, title) =>
                `<span title="${escapeAttr(title)}" style="display:inline-block; padding:0.12rem 0.4rem; border-radius:4px; font-size:0.68rem; font-weight:600; margin-right:0.3rem; `
                + (ok
                    ? 'background:rgba(72,187,120,0.18); color:#48bb78; border:1px solid rgba(72,187,120,0.4);">✓ '
                    : 'background:rgba(160,160,160,0.15); color:var(--text-secondary); border:1px solid var(--border);">– ')
                + escapeHtml(label) + '</span>';
            return chip(dleq, 'DLEQ (offline-capable)', dleq
                        ? 'Supports NUT-12 DLEQ — can be accepted offline'
                        : 'No NUT-12 DLEQ — cannot be accepted offline')
                 + chip(p2pk, 'P2PK', p2pk ? 'Supports NUT-11 P2PK' : 'No NUT-11 P2PK');
        }

        function renderDiscoveryStars(rating) {
            if (rating === null || rating === undefined) return '---';
            const full = Math.floor(rating);
            let html = '<span style="color: #FFC107;">';
            for (let i = 0; i < full; i++) html += '\u2605';
            for (let i = full; i < 5; i++) html += '\u2606';
            html += '</span> ' + rating.toFixed(1);
            return html;
        }

        function filterDiscoveredMints() {
            renderDiscoveredMints();
        }

        function renderDiscoveredMints() {
            const listEl = document.getElementById('mint-discovery-list');
            const filterUnit = document.getElementById('mint-discovery-unit-filter').value;
            const searchText = document.getElementById('mint-discovery-search').value.toLowerCase().trim();

            let filtered = discoveredMints.filter(m => {
                if (filterUnit) {
                    const units = getUnitsFromMintInfo(m.info);
                    if (!units.includes(filterUnit)) return false;
                }
                if (searchText) {
                    const name = (m.info?.name || '').toLowerCase();
                    const url = m.url.toLowerCase();
                    if (!name.includes(searchText) && !url.includes(searchText)) return false;
                }
                return true;
            });

            // Suggested mints float to the top (in their declared order);
            // everything else keeps the upstream review-count sort.
            const pinned = filtered.filter(m => isSuggestedMint(m.url));
            const rest = filtered.filter(m => !isSuggestedMint(m.url));
            pinned.sort((a, b) =>
                suggestedMintUrls.indexOf(normalizeMintUrl(a.url)) -
                suggestedMintUrls.indexOf(normalizeMintUrl(b.url))
            );
            const ordered = pinned.concat(rest);

            if (ordered.length === 0) {
                listEl.innerHTML = '<p style="color: var(--text-secondary); text-align: center; padding: 2rem;">No mints found</p>';
                return;
            }

            listEl.innerHTML = ordered.map(m => {
                const name = m.info?.name || 'Unknown Mint';
                const isOnline = !m.error && m.info;
                const units = getUnitsFromMintInfo(m.info);
                const suggested = isSuggestedMint(m.url);
                const border = suggested
                    ? '1px solid rgba(247, 147, 26, 0.5)'
                    : '1px solid var(--border)';
                const bg = suggested
                    ? 'rgba(247, 147, 26, 0.08)'
                    : 'var(--card-bg)';
                const badge = suggested
                    ? '<span style="display: inline-block; background: rgba(247, 147, 26, 0.2); color: #f7931a; border: 1px solid rgba(247, 147, 26, 0.4); padding: 0.15rem 0.45rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; margin-right: 0.4rem;">\u2605 Suggested by BareBits</span>'
                    : '';

                return `
                    <div class="mint-discovery-card" data-mint-url="${escapeAttr(m.url)}" style="background: ${bg}; border: ${border}; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; gap: 0.5rem;">
                            <div style="font-size: 0.9rem;">
                                ${renderDiscoveryStars(m.averageRating)}
                                <span style="color: var(--text-secondary); font-size: 0.8rem; margin-left: 0.25rem;">(${m.reviewsCount || 0})</span>
                            </div>
                            <span style="font-size: 0.8rem; color: ${isOnline ? 'var(--accent)' : 'var(--danger)'};">
                                ${isOnline ? '\u25CF Online' : '\u25CB Offline'}
                            </span>
                        </div>
                        <div style="margin-bottom: 0.35rem;">${badge}<span class="mint-country-slot" data-mint-url="${escapeAttr(m.url)}"></span></div>
                        <h4 style="margin: 0 0 0.25rem 0; font-size: 1rem;">${escapeHtml(name)}</h4>
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0 0 0.5rem 0; word-break: break-all;">${escapeHtml(m.url)}</p>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            ${units.length > 0 ? units.map(u => escapeHtml(u.toUpperCase())).join(' \u2022 ') : 'Unknown units'}
                        </div>
                        <div style="margin-bottom: 0.75rem;">${renderMintCapabilityBadges(m.info)}</div>
                        <button type="button" class="btn btn-full select-discovered-mint" data-mint-url="${escapeAttr(m.url)}" style="font-size: 0.85rem;">Select</button>
                    </div>
                `;
            }).join('');
            listEl.querySelectorAll('button.select-discovered-mint').forEach(btn => {
                btn.addEventListener('click', () => selectDiscoveredMint(btn.dataset.mintUrl));
            });
            listEl.querySelectorAll('.mint-discovery-card').forEach(card => {
                const url = card.getAttribute('data-mint-url');
                if (url) fetchCountryForCard(url, card);
            });

            const statusEl = document.getElementById('mint-discovery-status');
            statusEl.textContent = `Showing ${filtered.length} of ${discoveredMints.length} mints`;

            // Update button states based on disclaimer checkbox
            updateMintSelectButtons();
        }

        function selectDiscoveredMint(url) {
            if (discoveryCallback) {
                discoveryCallback(url);
            }
            closeMintDiscoveryModal();
        }

        async function showStoreDetails(storeId, storeName) {
            document.getElementById('store-modal-title').textContent = storeName;

            // Load API keys and backup mints in parallel
            const [keysRes, mintsRes] = await Promise.all([
                fetch(`${adminUrl}?api=api_keys&store_id=${storeId}`),
                fetch(`${adminUrl}?api=get_backup_mints&store_id=${storeId}`)
            ]);
            const keys = await keysRes.json();
            const backupMintsRes = await mintsRes.json();
            const backupMints = Array.isArray(backupMintsRes) ? backupMintsRes : [];

            // Get store info from dashboardData
            const store = dashboardData?.stores?.find(s => s.id === storeId);
            const mintUrl = store?.mint_url || 'Not configured';
            const mintUnit = (store?.mint_unit || 'sat').toUpperCase();

            // Build pairing URL for testing
            const pairingParams = new URLSearchParams({
                applicationName: 'Test Connection',
                'permissions[]': 'btcpay.store.cancreateinvoice',
                strict: 'true'
            });
            // The real file, not the pretty /api-keys/authorize rewrite:
            // rewrite-hostile hosts 404 the extension-less spelling, and
            // authorize.php executes anywhere this admin itself is served.
            const pairingUrl = serverUrl + '/api-keys/authorize.php?' + pairingParams.toString();

            // All references to the store-scoped values (id/name) are placed
            // into data-* attributes; click handlers are wired up below. That
            // keeps untrusted strings out of inline JS contexts.
            const sId = escapeAttr(storeId);
            const sName = escapeAttr(storeName);
            const content = `
                <div class="card-body" style="max-height: 70vh; overflow-y: auto;">
                    <p style="color: var(--text-secondary); margin-bottom: 1rem;">Store ID: ${escapeHtml(storeId)}</p>

                    <!-- Mint Configuration -->
                    <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.75rem;">Mint Configuration</h4>
                        <div style="margin-bottom: 0.5rem;">
                            <span style="color: var(--text-secondary); font-size: 0.85rem;">Primary Mint:</span>
                            <code style="display: block; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.75rem; word-break: break-all; margin-top: 0.25rem;">
                                ${escapeHtml(mintUrl)}
                                ${mintUrl ? mintDiagnosticIcon(mintUrl) : ''}
                            </code>
                        </div>
                        <div>
                            <span style="color: var(--text-secondary); font-size: 0.85rem;">Unit:</span>
                            <span style="font-weight: 500; margin-left: 0.5rem;">${escapeHtml(mintUnit)}</span>
                        </div>
                    </div>

                    <!-- Backup Mints -->
                    <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem;">Backup Mints</h4>
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Fallback mints used if primary is unavailable
                        </p>
                        <div id="backup-mints-list">
                            ${backupMints.length === 0 ? '<p style="color: var(--text-secondary); font-size: 0.85rem;">No backup mints configured</p>' :
                                backupMints.map(m => `
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; background: rgba(0,0,0,0.2); border-radius: 4px; margin-bottom: 0.5rem;">
                                        <div style="flex: 1; overflow: hidden;">
                                            <code style="font-size: 0.7rem; word-break: break-all;">${escapeHtml(m.mint_url)}</code>
                                            ${mintDiagnosticIcon(m.mint_url)}
                                            <span style="opacity: 0.6; font-size: 0.75rem; margin-left: 0.5rem;">(${escapeHtml(String(m.unit).toUpperCase())})</span>
                                        </div>
                                        <button class="btn btn-danger store-action" data-store-action="remove-backup-mint" data-mint-id="${escapeAttr(m.id)}" data-store-id="${sId}" data-store-name="${sName}" style="padding: 0.2rem 0.4rem; font-size: 0.7rem; margin-left: 0.5rem;">Remove</button>
                                    </div>
                                `).join('')
                            }
                        </div>
                        <div id="add-backup-mint-form" style="display: none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border);">
                            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="url" id="backup-mint-url" class="form-input" placeholder="https://mint.example.com" style="flex: 1; font-size: 0.85rem;">
                                <button class="btn btn-secondary store-action" data-store-action="discover-backup-mint" data-store-id="${sId}" data-store-name="${sName}" style="font-size: 0.8rem; white-space: nowrap;">Discover</button>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn btn-full store-action" data-store-action="add-backup-mint" data-store-id="${sId}" data-store-name="${sName}" style="font-size: 0.8rem;">Add</button>
                                <button class="btn btn-secondary store-action" data-store-action="cancel-add-backup-mint" style="font-size: 0.8rem;">Cancel</button>
                            </div>
                        </div>
                        <button class="btn btn-secondary btn-full store-action" data-store-action="show-add-backup-mint" style="margin-top: 0.5rem; font-size: 0.85rem;">
                            + Add Backup Mint
                        </button>
                    </div>

                    <!-- E-commerce Integration -->
                    <div style="background: var(--card-bg); border: 1px solid var(--primary); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem; color: var(--primary);">E-commerce Integration</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Enter this URL in your BTCPay plugin settings:
                        </p>
                        <code style="display: block; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.8rem; word-break: break-all; user-select: all; margin-bottom: 0.75rem;">
                            ${escapeHtml(serverUrl)}
                        </code>
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            Most plugins (WooCommerce, etc.) will redirect here to pair automatically.
                        </p>
                        <a href="${escapeAttr(pairingUrl)}" class="btn" style="display: inline-block; font-size: 0.8rem; padding: 0.4rem 0.75rem;">
                            Test Pairing Flow
                        </a>
                    </div>

                    <h4 style="margin-bottom: 0.5rem;">API Keys</h4>
                    ${keys.length === 0 ? '<p style="color: var(--text-secondary);">No API keys yet</p>' :
                        keys.map(k => `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                                <span>${escapeHtml(k.label) || 'API Key'}</span>
                                ${k.label === 'Internal (Dashboard)' ? '' : `<button class="btn btn-danger store-action" data-store-action="delete-api-key" data-api-key-id="${escapeAttr(k.id)}" data-store-id="${sId}" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Delete</button>`}
                            </div>
                        `).join('')
                    }

                    <button class="btn btn-full store-action" data-store-action="create-api-key" data-store-id="${sId}" style="margin-top: 1rem;">
                        + Create API Key Manually
                    </button>

                    <button class="btn btn-danger btn-full store-action" data-store-action="delete-store" data-store-id="${sId}" style="margin-top: 0.5rem;">
                        Delete Store
                    </button>
                </div>
            `;

            const modalContent = document.getElementById('store-modal-content');
            modalContent.innerHTML = content;
            modalContent.querySelectorAll('.store-action').forEach(btn => {
                btn.addEventListener('click', () => {
                    const action = btn.dataset.storeAction;
                    const sid = btn.dataset.storeId;
                    const sname = btn.dataset.storeName;
                    switch (action) {
                        case 'remove-backup-mint':
                            removeBackupMint(Number(btn.dataset.mintId), sid, sname);
                            break;
                        case 'discover-backup-mint':
                            openBackupMintDiscovery(sid, sname);
                            break;
                        case 'add-backup-mint':
                            addBackupMint(sid, sname);
                            break;
                        case 'cancel-add-backup-mint':
                            document.getElementById('add-backup-mint-form').style.display = 'none';
                            break;
                        case 'show-add-backup-mint':
                            document.getElementById('add-backup-mint-form').style.display = 'block';
                            break;
                        case 'delete-api-key':
                            deleteApiKey(btn.dataset.apiKeyId, sid);
                            break;
                        case 'create-api-key':
                            createApiKey(sid);
                            break;
                        case 'delete-store':
                            deleteStore(sid);
                            break;
                    }
                });
            });
            openModal('modal-store');
        }

        async function addBackupMint(storeId, storeName) {
            const mintUrl = document.getElementById('backup-mint-url').value.trim();
            if (!mintUrl) {
                showToast('Please enter a mint URL', 'error');
                return;
            }

            // Get the store's unit
            const store = dashboardData?.stores?.find(s => s.id === storeId);
            const unit = store?.mint_unit || 'sat';

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=add_backup_mint&store_id=${encodeURIComponent(storeId)}&mint_url=${encodeURIComponent(mintUrl)}&unit=${encodeURIComponent(unit)}`
                );

                const result = await response.json();

                if (response.ok) {
                    showToast('Backup mint added!', 'success');
                    showStoreDetails(storeId, storeName);
                } else {
                    showToast(result.error || 'Failed to add backup mint', 'error');
                }
            } catch (e) {
                showToast('Failed to add backup mint', 'error');
            }
        }

        async function removeBackupMint(mintId, storeId, storeName) {
            if (!confirm('Remove this backup mint?')) return;

            try {
                await postWithCsrf(adminUrl, `action=remove_backup_mint&id=${mintId}`);
                showToast('Backup mint removed', 'success');
                showStoreDetails(storeId, storeName);
            } catch (e) {
                showToast('Failed to remove backup mint', 'error');
            }
        }

        // -------- Offline Cashu acceptance UI --------

        async function loadOfflineCashu(storeId) {
            const body = document.getElementById('offline-cashu-body');
            if (!body) return;
            let data;
            try {
                const r = await fetch(`${adminUrl}?api=get_offline_cashu&store_id=${encodeURIComponent(storeId)}`, { credentials: 'same-origin' });
                data = await r.json();
                if (!r.ok) throw new Error(data.error || 'Failed to load');
            } catch (e) {
                body.innerHTML = `<p style="color: var(--danger); font-size: 0.85rem;">Failed to load: ${escapeHtml(e.message)}</p>`;
                return;
            }

            const unit = (data.unit || 'sat').toUpperCase();
            const mintsHtml = (data.mints || []).length === 0
                ? '<p style="color: var(--text-secondary); font-size: 0.8rem;">No mints on the offline allowlist yet.</p>'
                : data.mints.map(m => `
                    <div style="display:flex; align-items:center; gap:0.5rem; padding:0.4rem; background:rgba(0,0,0,0.2); border-radius:4px; margin-bottom:0.4rem;">
                        <label style="display:flex; align-items:center; gap:0.35rem; flex:1; overflow:hidden; cursor:pointer;">
                            <input type="checkbox" class="oc-mint-toggle" data-mint="${escapeAttr(m.mint_url)}" ${Number(m.enabled) ? 'checked' : ''}>
                            <code style="font-size:0.7rem; word-break:break-all;">${escapeHtml(m.mint_url)}</code>
                        </label>
                        <button class="btn btn-danger oc-mint-remove" data-mint="${escapeAttr(m.mint_url)}" style="padding:0.2rem 0.4rem; font-size:0.7rem;">Remove</button>
                    </div>`).join('');

            body.innerHTML = `
                <div style="background: rgba(237,137,54,0.12); border:1px solid rgba(237,137,54,0.5); border-radius:8px; padding:0.75rem; margin-bottom:0.85rem; font-size:0.8rem; color:#fbd38d;">
                    <strong>⚠ Please read — this carries a small risk.</strong><br>
                    Offline acceptance trusts a token's signature without checking the mint at the moment of
                    payment. The signature proves the ecash is genuine, but it <b>cannot</b> guarantee the
                    customer hasn't already spent it elsewhere. That can only be confirmed once the mint is
                    reachable again. In the rare case a customer double-spends during an outage, that payment
                    will fail to settle and is a loss. Use the limits below to cap your exposure.
                </div>

                <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.85rem; cursor:pointer;">
                    <input type="checkbox" id="oc-enabled" ${data.enabled ? 'checked' : ''}>
                    <span><strong>Enable offline acceptance</strong> for this store</span>
                </label>

                <div style="margin-bottom:0.75rem;">
                    <label style="display:block; font-size:0.8rem; color:var(--text-secondary); margin-bottom:0.25rem;">Acceptance policy</label>
                    <select id="oc-policy" class="form-input" style="font-size:0.85rem;">
                        <option value="dleq" selected>Verified signature + trusted mints + limits (recommended)</option>
                        <option value="p2pk" disabled>Require P2PK lock (coming soon — not yet available)</option>
                    </select>
                </div>

                <label style="display:flex; align-items:flex-start; gap:0.5rem; margin-bottom:0.85rem; cursor:pointer;">
                    <input type="checkbox" id="oc-accept-all" ${data.accept_all_mints ? 'checked' : ''} style="margin-top:0.15rem;">
                    <span style="font-size:0.85rem;"><strong>Accept tokens from any mint</strong><br>
                        <span style="font-size:0.74rem; color:var(--text-secondary);">Ignores the allowlist below and accepts offline payments from any mint whose signature can be verified. Higher risk — you may end up holding ecash from mints you don't know or trust.</span>
                    </span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:0.5rem; margin-bottom:0.85rem; cursor:pointer;">
                    <input type="checkbox" id="oc-per-tx-override" ${data.per_tx_override ? 'checked' : ''} style="margin-top:0.15rem;">
                    <span style="font-size:0.85rem;"><strong>Enable per-transaction override to allow all mints</strong><br>
                        <span style="font-size:0.74rem; color:var(--text-secondary);">Adds an "allow payment from any mint" checkbox when creating a payment request, so you can lift the allowlist for a single payment instead of all of them.</span>
                    </span>
                </label>

                <div style="display:flex; gap:0.5rem; margin-bottom:0.85rem;">
                    <div style="flex:1;">
                        <label style="display:block; font-size:0.8rem; color:var(--text-secondary); margin-bottom:0.25rem;">Max per payment (${escapeHtml(unit)})</label>
                        <input type="number" id="oc-max-per-tx" class="form-input" min="0" step="1" value="${Number(data.max_per_tx) || 0}" style="font-size:0.85rem;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block; font-size:0.8rem; color:var(--text-secondary); margin-bottom:0.25rem;">Max total outstanding (${escapeHtml(unit)})</label>
                        <input type="number" id="oc-max-outstanding" class="form-input" min="0" step="1" value="${Number(data.max_outstanding) || 0}" style="font-size:0.85rem;">
                    </div>
                </div>
                <p style="font-size:0.72rem; color:var(--text-secondary); margin:-0.5rem 0 0.85rem;">0 = no limit. Outstanding now: <strong>${Number(data.outstanding) || 0} ${escapeHtml(unit)}</strong>.</p>

                <h5 style="margin:0 0 0.4rem;">Trusted mints for offline acceptance</h5>
                <p id="oc-allowlist-note" style="font-size:0.72rem; color:var(--text-secondary); margin:0 0 0.5rem;">${data.accept_all_mints
                    ? '<span style="color:#fbd38d;">Not enforced while “Accept tokens from any mint” is on.</span>'
                    : 'Only tokens from these mints are accepted offline (a higher trust bar than your backup mints).'}</p>
                <div id="oc-mints-list">${mintsHtml}</div>
                <div style="display:flex; gap:0.5rem; margin:0.5rem 0;">
                    <input type="url" id="oc-mint-url" class="form-input" placeholder="https://mint.example.com" style="flex:1; font-size:0.82rem;">
                    <button class="btn btn-secondary" id="oc-add-mint" style="font-size:0.8rem; white-space:nowrap;">Add</button>
                </div>
                <button class="btn btn-secondary btn-full" id="oc-seed" style="font-size:0.8rem; margin-bottom:0.75rem;">Seed from my configured mints</button>

                <button class="btn btn-full" id="oc-save" style="font-size:0.9rem;">Save offline settings</button>
            `;

            // Wire controls (self-contained; re-attached on every render).
            body.querySelector('#oc-save').addEventListener('click', () => saveOfflineCashu(storeId));
            const accAll = body.querySelector('#oc-accept-all');
            if (accAll) accAll.addEventListener('change', () => {
                const note = body.querySelector('#oc-allowlist-note');
                if (note) note.innerHTML = accAll.checked
                    ? '<span style="color:#fbd38d;">Not enforced while “Accept tokens from any mint” is on.</span>'
                    : 'Only tokens from these mints are accepted offline (a higher trust bar than your backup mints).';
            });
            body.querySelector('#oc-add-mint').addEventListener('click', () => addOfflineMint(storeId));
            body.querySelector('#oc-seed').addEventListener('click', () => seedOfflineMints(storeId));
            body.querySelectorAll('.oc-mint-remove').forEach(b =>
                b.addEventListener('click', () => removeOfflineMint(storeId, b.dataset.mint)));
            body.querySelectorAll('.oc-mint-toggle').forEach(c =>
                c.addEventListener('change', () => toggleOfflineMint(storeId, c.dataset.mint, c.checked)));
        }

        async function saveOfflineCashu(storeId) {
            const enabled = document.getElementById('oc-enabled').checked ? 1 : 0;
            const acceptAll = document.getElementById('oc-accept-all').checked ? 1 : 0;
            const perTxOverride = document.getElementById('oc-per-tx-override').checked ? 1 : 0;
            const maxPerTx = Math.max(0, parseInt(document.getElementById('oc-max-per-tx').value || '0', 10));
            const maxOut = Math.max(0, parseInt(document.getElementById('oc-max-outstanding').value || '0', 10));
            try {
                const res = await postWithCsrf(adminUrl,
                    `action=save_offline_cashu&store_id=${encodeURIComponent(storeId)}&enabled=${enabled}&accept_all_mints=${acceptAll}&per_tx_override=${perTxOverride}&max_per_tx=${maxPerTx}&max_outstanding=${maxOut}`);
                const result = await res.json();
                if (!res.ok) throw new Error(result.error || 'Save failed');
                showToast(result.seeded > 0 ? `Saved. Seeded ${result.seeded} mint(s) into the allowlist.` : 'Offline settings saved', 'success');
                loadOfflineCashu(storeId);
            } catch (e) {
                showToast(e.message || 'Save failed', 'error');
            }
        }

        async function addOfflineMint(storeId) {
            const url = document.getElementById('oc-mint-url').value.trim();
            if (!url) { showToast('Enter a mint URL', 'error'); return; }
            try {
                const res = await postWithCsrf(adminUrl,
                    `action=add_offline_mint&store_id=${encodeURIComponent(storeId)}&mint_url=${encodeURIComponent(url)}`);
                if (!res.ok) throw new Error((await res.json()).error || 'Failed');
                showToast('Mint added to offline allowlist', 'success');
                loadOfflineCashu(storeId);
            } catch (e) { showToast(e.message || 'Failed to add mint', 'error'); }
        }

        async function removeOfflineMint(storeId, mintUrl) {
            try {
                await postWithCsrf(adminUrl,
                    `action=remove_offline_mint&store_id=${encodeURIComponent(storeId)}&mint_url=${encodeURIComponent(mintUrl)}`);
                showToast('Mint removed', 'success');
                loadOfflineCashu(storeId);
            } catch (e) { showToast('Failed to remove mint', 'error'); }
        }

        async function toggleOfflineMint(storeId, mintUrl, enabled) {
            try {
                await postWithCsrf(adminUrl,
                    `action=toggle_offline_mint&store_id=${encodeURIComponent(storeId)}&mint_url=${encodeURIComponent(mintUrl)}&enabled=${enabled ? 1 : 0}`);
            } catch (e) { showToast('Failed to update mint', 'error'); loadOfflineCashu(storeId); }
        }

        async function seedOfflineMints(storeId) {
            try {
                // Seeding is a no-op save that triggers the server-side seed when empty,
                // but we also expose it explicitly by adding each configured mint.
                const r = await fetch(`${adminUrl}?api=get_backup_mints&store_id=${encodeURIComponent(storeId)}`, { credentials: 'same-origin' });
                const backups = await r.json();
                const store = dashboardData?.stores?.find(s => s.id === storeId);
                const urls = [];
                if (store?.mint_url) urls.push(store.mint_url);
                (backups || []).forEach(b => urls.push(b.mint_url));
                for (const u of [...new Set(urls)]) {
                    await postWithCsrf(adminUrl,
                        `action=add_offline_mint&store_id=${encodeURIComponent(storeId)}&mint_url=${encodeURIComponent(u)}`);
                }
                showToast('Seeded offline allowlist from configured mints', 'success');
                loadOfflineCashu(storeId);
            } catch (e) { showToast('Failed to seed mints', 'error'); }
        }

        // -------- Mint reliability + trusted-mints UI --------

        function mintDiagnosticIcon(mintUrl) {
            if (!mintUrl) return '';
            return `<button type="button" title="Show mint diagnostic"
                data-mint-action="diagnostic" data-mint-url="${escapeAttr(mintUrl)}"
                style="background: none; border: none; cursor: pointer; padding: 0 0.35rem; color: var(--text-secondary); font-size: 0.9rem; line-height: 1;">&#9432;</button>`;
        }

        function fmtTimestamp(ts) {
            if (!ts) return 'never';
            try {
                return new Date(ts * 1000).toLocaleString();
            } catch (e) {
                return String(ts);
            }
        }

        async function openMintDiagnostic(mintUrl) {
            document.getElementById('mint-diagnostic-title').textContent = 'Mint Diagnostic';
            document.getElementById('mint-diagnostic-content').innerHTML =
                '<div class="loading"><div class="spinner"></div></div>';
            openModal('modal-mint-diagnostic');
            try {
                const r = await fetch(adminUrl + '?api=get_mint_diagnostic&mint_url=' +
                    encodeURIComponent(mintUrl), { credentials: 'same-origin' });
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                const data = await r.json();
                renderMintDiagnostic(data);
            } catch (e) {
                document.getElementById('mint-diagnostic-content').innerHTML =
                    '<p style="color: var(--danger);">Failed to load diagnostic: ' + escapeHtml(e.message) + '</p>';
            }
        }

        function renderMintDiagnostic(data) {
            const s = data.status || {};
            const states = [];
            if (s.permanentlyDisabled) states.push('permanently disabled');
            if (s.disabledPendingSuccess) states.push('disabled until next success');
            if (s.trustedListDisabled) states.push('disabled by trusted list' + (s.trustedListDisabledReason ? ': ' + s.trustedListDisabledReason : ''));
            const stateLabel = states.length ? states.join(', ') : 'available';

            const eventsHtml = (data.events || []).map(e => {
                const detailsRaw = e.details || '';
                return `<tr>
                    <td style="white-space: nowrap; vertical-align: top;">${escapeHtml(fmtTimestamp(e.timestamp))}</td>
                    <td style="vertical-align: top;"><code style="font-size: 0.75rem;">${escapeHtml(e.eventType)}</code></td>
                    <td style="vertical-align: top;">${e.failureType ? '<code style="font-size: 0.75rem;">' + escapeHtml(e.failureType) + '</code>' : ''}</td>
                    <td style="vertical-align: top;">${escapeHtml(e.address || '')}</td>
                    <td style="vertical-align: top; word-break: break-word; font-size: 0.75rem;">${escapeHtml(detailsRaw)}</td>
                </tr>`;
            }).join('');

            const body = `
                <div style="margin-bottom: 1rem;">
                    <code style="display: block; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.75rem; word-break: break-all;">${escapeHtml(data.mintUrl)}</code>
                </div>
                <div style="display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; font-size: 0.85rem; margin-bottom: 1rem;">
                    <span style="color: var(--text-secondary);">Last success</span><span>${escapeHtml(fmtTimestamp(s.lastSuccessAt))}</span>
                    <span style="color: var(--text-secondary);">Last failure</span><span>${escapeHtml(fmtTimestamp(s.lastFailureAt))}${s.lastFailureKind ? ' &mdash; <code style="font-size: 0.75rem;">' + escapeHtml(s.lastFailureKind) + '</code>' : ''}</span>
                    <span style="color: var(--text-secondary);">Lifetime failures</span><span>${s.totalFailures || 0}</span>
                    <span style="color: var(--text-secondary);">Consecutive</span><span>${s.consecutiveFailures || 0}</span>
                    <span style="color: var(--text-secondary);">Status</span><span>${escapeHtml(stateLabel)}</span>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
                    <button class="btn btn-secondary" data-mint-action="reenable" data-mint-url="${escapeAttr(data.mintUrl)}" style="font-size: 0.8rem;">Re-enable</button>
                    <button class="btn btn-danger" data-mint-action="mark-bad" data-mint-url="${escapeAttr(data.mintUrl)}" style="font-size: 0.8rem;">Mark confirmed bad</button>
                    <button class="btn btn-secondary" data-mint-action="reset" data-mint-url="${escapeAttr(data.mintUrl)}" style="font-size: 0.8rem;">Reset counters</button>
                </div>
                <div style="max-height: 360px; overflow: auto; border: 1px solid var(--border); border-radius: 6px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.3); position: sticky; top: 0;">
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Time</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Event</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Kind</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Address</th>
                                <th style="text-align: left; padding: 0.4rem 0.6rem;">Details</th>
                            </tr>
                        </thead>
                        <tbody>${eventsHtml || '<tr><td colspan="5" style="padding: 1rem; text-align: center; color: var(--text-secondary);">No events recorded.</td></tr>'}</tbody>
                    </table>
                </div>
            `;
            document.getElementById('mint-diagnostic-content').innerHTML = body;
        }

        async function adminReenableMint(mintUrl) {
            if (!confirm('Re-enable this mint and reset its counters?')) return;
            try {
                await postWithCsrf(adminUrl, 'action=admin_reenable_mint&mint_url=' + encodeURIComponent(mintUrl));
                showToast('Mint re-enabled', 'success');
                openMintDiagnostic(mintUrl);
                loadReliabilityCard();
            } catch (e) {
                showToast('Failed to re-enable mint', 'error');
            }
        }

        async function adminMarkMintBad(mintUrl) {
            if (!confirm('Mark this mint as confirmed bad? This counts as one strike and may trigger permanent disable.')) return;
            try {
                await postWithCsrf(adminUrl, 'action=admin_mark_mint_bad&mint_url=' + encodeURIComponent(mintUrl));
                showToast('Mint marked as confirmed bad', 'success');
                openMintDiagnostic(mintUrl);
                loadReliabilityCard();
            } catch (e) {
                showToast('Failed to mark mint as bad', 'error');
            }
        }

        async function resetMintCounters(mintUrl) {
            if (!confirm('Reset all counters for this mint?')) return;
            try {
                await postWithCsrf(adminUrl, 'action=reset_mint_counters&mint_url=' + encodeURIComponent(mintUrl));
                showToast('Counters reset', 'success');
                openMintDiagnostic(mintUrl);
                loadReliabilityCard();
            } catch (e) {
                showToast('Failed to reset counters', 'error');
            }
        }

        async function loadStuckFundsCard() {
            const card = document.getElementById('card-stuck-funds');
            if (!card) return;
            try {
                const r = await fetch(adminUrl + '?api=stuck_funds_summary', { credentials: 'same-origin' });
                if (!r.ok) { card.classList.add('hidden'); return; }
                const data = await r.json();
                const stores = Array.isArray(data.stores) ? data.stores : [];
                if (stores.length === 0) {
                    // No store has any stuck balance — hide the card entirely.
                    card.classList.add('hidden');
                    return;
                }
                const totals = data.totals || {};
                document.getElementById('stuck-funds-totals').innerHTML =
                    '<strong>' + Number(totals.stuckSats || 0).toLocaleString() + '</strong> sats stuck across all stores &middot; ' +
                    '<strong>' + Number(totals.absorbedSats || 0).toLocaleString() + '</strong> sats absorbed against owed fees';
                const list = document.getElementById('stuck-funds-list');
                list.innerHTML = stores.map(s => {
                    const mintsHtml = (s.mints || []).map(m => {
                        return '<div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.2rem;">'
                            + '<code style="font-size: 0.7rem; word-break: break-all;">' + escapeHtml(m.mintUrl) + '</code>'
                            + ' &middot; ' + Number(m.stuckSats || 0).toLocaleString() + ' sats stuck'
                            + '</div>';
                    }).join('');
                    const uncovered = Number(s.uncoveredSats || 0);
                    const uncoveredNote = uncovered > 0
                        ? ' &middot; <span style="color: var(--text-secondary);">' + uncovered.toLocaleString() + ' sats exceeds owed fees</span>'
                        : '';
                    return `
                        <div style="padding: 0.6rem; background: rgba(0,0,0,0.2); border-radius: 6px; margin-bottom: 0.5rem;">
                            <div style="font-weight: 600; font-size: 0.9rem;">${escapeHtml(s.storeName || s.storeId)}</div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;">
                                ${Number(s.stuckTotalSats || 0).toLocaleString()} sats stuck &middot;
                                ${Number(s.absorbedTotalSats || 0).toLocaleString()} sats absorbed
                                (${Number(s.absorbedDevSats || 0).toLocaleString()} dev,
                                ${Number(s.absorbedHostingSats || 0).toLocaleString()} hosting)${uncoveredNote}
                            </div>
                            ${mintsHtml}
                        </div>
                    `;
                }).join('');
                card.classList.remove('hidden');
            } catch (e) {
                card.classList.add('hidden');
            }
        }

        async function loadReliabilityCard() {
            const card = document.getElementById('card-mint-reliability');
            if (!card) return;
            try {
                const r = await fetch(adminUrl + '?api=list_disabled_mints', { credentials: 'same-origin' });
                if (!r.ok) { card.classList.add('hidden'); return; }
                const data = await r.json();
                const container = document.getElementById('disabled-mints-list');
                if (!data.mints || data.mints.length === 0) {
                    container.innerHTML = '<p style="color: var(--text-secondary); font-size: 0.85rem;">No mints currently disabled.</p>';
                } else {
                    container.innerHTML = data.mints.map(m => {
                        const flags = [];
                        if (m.permanentlyDisabled) flags.push('permanent');
                        if (m.disabledPendingSuccess) flags.push('pending success');
                        if (m.trustedListDisabled) flags.push('trusted-list' + (m.trustedListDisabledReason ? ': ' + m.trustedListDisabledReason : ''));
                        const urlAttr = escapeAttr(m.mintUrl);
                        return `
                            <div style="padding: 0.6rem; background: rgba(0,0,0,0.2); border-radius: 6px; margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                                    <code style="font-size: 0.75rem; word-break: break-all; flex: 1;">${escapeHtml(m.mintUrl)}</code>
                                    ${mintDiagnosticIcon(m.mintUrl)}
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                    ${escapeHtml(flags.join(' • '))} &middot; lifetime ${Number(m.totalFailures) || 0}${m.lastFailureKind ? ' &middot; last: ' + escapeHtml(m.lastFailureKind) : ''}
                                </div>
                                <div style="display: flex; gap: 0.4rem; margin-top: 0.4rem; flex-wrap: wrap;">
                                    <button class="btn btn-secondary" data-mint-action="reenable" data-mint-url="${urlAttr}" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">Re-enable</button>
                                    <button class="btn btn-danger" data-mint-action="mark-bad" data-mint-url="${urlAttr}" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">Confirmed bad</button>
                                    <button class="btn btn-secondary" data-mint-action="diagnostic" data-mint-url="${urlAttr}" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">Diagnostic</button>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
                card.classList.remove('hidden');
            } catch (e) {
                // Non-admin or transient — leave card hidden.
                card.classList.add('hidden');
            }
        }

        async function resetAllMintCounters() {
            if (!confirm('Reset counters for ALL mints?')) return;
            try {
                await postWithCsrf(adminUrl, 'action=reset_all_mint_counters');
                showToast('All counters reset', 'success');
                loadReliabilityCard();
            } catch (e) {
                showToast('Failed to reset counters', 'error');
            }
        }

        async function loadTrustedMintsCard() {
            const card = document.getElementById('card-trusted-mints');
            if (!card) return;
            try {
                const r = await fetch(adminUrl + '?api=get_trusted_mints_settings', { credentials: 'same-origin' });
                if (!r.ok) { card.classList.add('hidden'); return; }
                const data = await r.json();
                const urlEl = document.getElementById('trusted-mints-url');
                const intEl = document.getElementById('trusted-mints-refresh');
                urlEl.value = data.url || '';
                urlEl.disabled = !!data.urlFromEnv;
                document.getElementById('trusted-mints-url-help').textContent =
                    data.urlFromEnv ? 'Set by CASHUPAY_TRUSTED_MINTS_URL — value is read-only here.' : '';
                intEl.value = data.refreshMinutes || '';
                intEl.disabled = !!data.refreshFromEnv;
                document.getElementById('trusted-mints-refresh-help').textContent =
                    data.refreshFromEnv ? 'Set by CASHUPAY_TRUSTED_MINTS_REFRESH_MINUTES.' : 'Default 1440 (24h).';

                const status = document.getElementById('trusted-mints-status');
                if (data.lastError) {
                    status.innerHTML = '<span style="color: var(--danger);">Last refresh failed: ' + escapeHtml(data.lastError) + '</span>';
                } else if (data.lastOkAt) {
                    status.textContent = 'Last successful fetch: ' + fmtTimestamp(data.lastOkAt);
                } else if (data.url) {
                    status.textContent = 'Not fetched yet.';
                } else {
                    status.textContent = '';
                }

                const cached = document.getElementById('trusted-mints-cached');
                if (data.cached && data.cached.mints) {
                    cached.innerHTML = '<details><summary style="cursor: pointer; font-size: 0.85rem; color: var(--text-secondary);">Cached list (' + data.cached.mints.length + ' entries)</summary>' +
                        '<pre style="background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.75rem; max-height: 240px; overflow: auto;">' +
                        escapeHtml(JSON.stringify(data.cached, null, 2)) + '</pre></details>';
                } else {
                    cached.innerHTML = '';
                }
                card.classList.remove('hidden');
            } catch (e) {
                card.classList.add('hidden');
            }
        }

        async function saveTrustedMintsSettings() {
            const url = document.getElementById('trusted-mints-url').value.trim();
            const minutes = document.getElementById('trusted-mints-refresh').value;
            try {
                await postWithCsrf(adminUrl,
                    'action=save_trusted_mints_settings&url=' + encodeURIComponent(url) +
                    '&refresh_minutes=' + encodeURIComponent(minutes));
                showToast('Trusted mints settings saved', 'success');
                loadTrustedMintsCard();
            } catch (e) {
                showToast('Failed to save: ' + e.message, 'error');
            }
        }

        async function refreshTrustedMintsNow() {
            try {
                const r = await postWithCsrf(adminUrl, 'action=refresh_trusted_mints');
                const data = await r.json();
                if (data.lastError) {
                    showToast('Refresh failed: ' + data.lastError, 'error');
                } else {
                    showToast('Trusted list refreshed', 'success');
                }
                loadTrustedMintsCard();
                loadReliabilityCard();
            } catch (e) {
                showToast('Refresh request failed', 'error');
            }
        }

        function updateCronStaleBanner(state) {
            const banner = document.getElementById('cron-stale-banner');
            if (!banner) return;
            if (state) {
                banner.classList.remove('hidden');
            } else {
                banner.classList.add('hidden');
            }
        }

        async function dismissCronStaleBanner() {
            const banner = document.getElementById('cron-stale-banner');
            if (banner) banner.classList.add('hidden');
            try {
                await postWithCsrf(adminUrl, 'action=dismiss_cron_warning');
            } catch (e) {
                // No-op; banner will reappear on next dashboard load if dismissal didn't persist.
            }
        }

        // Settings page: fetch the suggested crontab entries and render them.
        // Lazily generates a cron_key server-side on first call. Surfaces
        // last-seen timestamps for both modes so the admin can confirm cron
        // is actually hitting them at the expected cadence — there's no way
        // for a PHP script to read the operator's crontab directly, this is
        // the best we can do.
        async function loadCronUrl() {
            const code = document.getElementById('cron-url-display');
            const codeSwaps = document.getElementById('cron-swaps-url-display');
            const status = document.getElementById('cron-url-status');
            const statusSwaps = document.getElementById('cron-swaps-url-status');
            if (!code) return;
            try {
                const response = await fetch(adminUrl + '?api=cron_url');
                const data = await response.json();
                if (data && data.crontab) {
                    code.textContent = data.crontab;
                } else {
                    code.textContent = 'Unable to load cron URL';
                }
                if (codeSwaps) {
                    codeSwaps.textContent = data.crontab_swaps || 'Unable to load swaps cron URL';
                }
                if (status) status.innerHTML = formatCronSeenAgo(data.last_full_seen_ago_sec, 90);
                if (statusSwaps) statusSwaps.innerHTML = formatCronSeenAgo(data.last_swaps_seen_ago_sec, 30);
            } catch (e) {
                code.textContent = 'Unable to load cron URL';
            }
        }

        // Return an HTML fragment showing how long ago this cron mode was
        // last seen, color-coded green/yellow/red depending on how recent.
        // `expectedMaxSec` is the threshold beyond which we render red.
        function formatCronSeenAgo(secsAgo, expectedMaxSec) {
            if (secsAgo === null || secsAgo === undefined) {
                return '<span style="color:#dc4646">Last run: never.</span> Add this to your cron settings on your server.';
            }
            const ago = Math.max(0, parseInt(secsAgo, 10));
            const human = ago < 60 ? ago + 's' :
                          ago < 3600 ? Math.floor(ago/60) + 'm' :
                          Math.floor(ago/3600) + 'h';
            let color = '#5ec07f';   // green
            let note = 'Cron is hitting this endpoint.';
            if (ago > expectedMaxSec * 3) { color = '#dc4646'; note = 'Stale — cron may not be configured.'; }
            else if (ago > expectedMaxSec * 1.5) { color = '#f7931a'; note = 'Slower than expected.'; }
            return `Last seen <strong style="color:${color}">${human} ago</strong>. ${note}`;
        }

        async function copyCronUrl() {
            const code = document.getElementById('cron-url-display');
            if (!code) return;
            try {
                await navigator.clipboard.writeText(code.textContent);
                showToast('Cron entry copied to clipboard', 'success');
            } catch (e) {
                showToast('Copy failed — select the text manually', 'error');
            }
        }

        async function copyCronSwapsUrl() {
            const code = document.getElementById('cron-swaps-url-display');
            if (!code) return;
            try {
                await navigator.clipboard.writeText(code.textContent);
                showToast('Swap fast-lane cron entry copied to clipboard', 'success');
            } catch (e) {
                showToast('Copy failed — select the text manually', 'error');
            }
        }

        // ---- Manual ("Update now") + update-available banner ----------------
        //
        // update_status returns `available` (cached daily-by-cron verdict) and
        // `manual_run` (the in-flight / last "Update now" run). These helpers
        // render both the dashboard banner and the Auto-update card from that
        // payload, and drive the click → poll-until-done flow.

        let manualUpdatePollTimer = null;
        let manualUpdatePollDeadline = 0;

        // A manual_run record is only "active" (worth surfacing) for a few
        // minutes, so a stale record from an old session doesn't pin the
        // banner open or show a phantom "Updating…".
        function manualRunRecent(run) {
            if (!run || !run.state) return false;
            const stamp = run.state === 'running' ? run.started_at : run.finished_at;
            if (!stamp) return run.state === 'running';
            return (Math.floor(Date.now() / 1000) - parseInt(stamp, 10)) < 600;
        }

        // Map a manual_run state to a user-facing {text, color, busy, done}.
        function manualRunView(run) {
            if (!run || !manualRunRecent(run)) return null;
            switch (run.state) {
                case 'running':
                    return { busy: true, text: 'Updating… downloading and verifying the new build. This can take a minute — keep this page open.', color: 'var(--text-secondary)' };
                case 'success': {
                    const v = (run.from_version && run.to_version) ? (' (' + run.from_version + ' → ' + run.to_version + ')') : '';
                    return { busy: false, done: 'success', text: 'Update applied' + v + '. Reload the page to use the new version.', color: '#22c55e' };
                }
                case 'applied_unverified':
                    return { busy: false, done: 'success', text: 'Update applied. Reload the page; the new build is being health-checked automatically.', color: '#22c55e' };
                case 'up_to_date':
                    return { busy: false, done: 'noop', text: 'You’re already on the latest version.', color: 'var(--text-secondary)' };
                case 'failed': {
                    const rb = run.rolled_back === false
                        ? ' The automatic rollback FAILED — restore manually via recover.php.'
                        : ' It was rolled back, so you’re still on the previous version.';
                    return { busy: false, done: 'failed', text: 'The update failed its health check.' + rb, color: '#ef4444' };
                }
                case 'error':
                    return { busy: false, done: 'failed', text: 'The update could not be applied' + (run.message ? (': ' + run.message) : '.'), color: '#ef4444' };
                case 'blocked':
                    return { busy: false, done: 'blocked', text: 'Updates are disabled in this environment.', color: 'var(--text-secondary)' };
                default:
                    return null;
            }
        }

        // Render both the dashboard banner and the settings-card status line
        // from an update_status payload. Safe to call on any view (it no-ops on
        // elements that aren't present).
        function applyUpdateState(data) {
            const avail = data && data.available && data.available.available === true;
            const run = data ? data.manual_run : null;
            const view = manualRunView(run);
            const busy = !!(view && view.busy);

            // --- Dashboard banner ---
            const banner = document.getElementById('update-available-banner');
            if (banner) {
                const txt = document.getElementById('update-available-text');
                const prog = document.getElementById('update-available-progress');
                const btn = document.getElementById('btn-update-now-banner');
                // Show while an update is available OR while a manual run is
                // active (so the operator sees progress / the outcome).
                if (avail || view) {
                    banner.classList.remove('hidden');
                    if (txt) {
                        const lv = (data.available && data.available.latest_version) ? (' (version ' + data.available.latest_version + ')') : '';
                        txt.textContent = avail ? ('A software update is available' + lv + '.') : 'Software update';
                    }
                    if (prog) {
                        if (view) {
                            prog.classList.remove('hidden');
                            prog.textContent = view.text;
                            prog.style.color = view.color;
                        } else {
                            prog.classList.add('hidden');
                        }
                    }
                    if (btn) {
                        btn.disabled = busy;
                        btn.textContent = busy ? 'Updating…' : 'Update now';
                    }
                } else {
                    banner.classList.add('hidden');
                }
            }

            // --- Settings card ---
            const availLine = document.getElementById('auto-update-availability');
            if (availLine) {
                if (avail) {
                    const lv = (data.available && data.available.latest_version) ? data.available.latest_version : 'a newer build';
                    availLine.textContent = 'Update available: ' + lv + '. Click “Update now” to install it.';
                    availLine.style.color = '#f7931a';
                } else if (data && data.available && data.available.available === false) {
                    availLine.textContent = 'You’re on the latest version for this channel.';
                    availLine.style.color = 'var(--text-secondary)';
                } else {
                    availLine.textContent = 'Update check pending — runs on the next cron tick.';
                    availLine.style.color = 'var(--text-secondary)';
                }
            }
            const cardProg = document.getElementById('auto-update-progress');
            if (cardProg) {
                if (view) {
                    cardProg.classList.remove('hidden');
                    cardProg.textContent = view.text;
                    cardProg.style.color = view.color;
                } else {
                    cardProg.classList.add('hidden');
                }
            }
            const cardBtn = document.getElementById('btn-update-now');
            if (cardBtn) {
                cardBtn.disabled = busy || (data && data.manual_blocked);
                cardBtn.textContent = busy ? 'Updating…' : 'Update now';
                if (data && data.manual_blocked && !view) {
                    if (cardProg) {
                        cardProg.classList.remove('hidden');
                        cardProg.textContent = 'Updates are disabled in this environment (dev/test).';
                        cardProg.style.color = 'var(--text-secondary)';
                    }
                }
            }
        }

        async function fetchAndRenderUpdateState() {
            try {
                const r = await fetch(adminUrl + '?api=update_status', { credentials: 'same-origin' });
                const data = await r.json();
                applyUpdateState(data);
                return data;
            } catch (e) {
                return null;
            }
        }

        // Kick off a manual update: confirm, POST start_manual_update, then poll
        // update_status until the manual_run reaches a terminal state.
        async function startManualUpdate() {
            if (manualUpdatePollTimer) return; // already in flight
            if (!confirm('Download and apply the latest update now? Your data and configuration are preserved, and a failed update is rolled back automatically.')) {
                return;
            }
            // Optimistically reflect the running state immediately.
            applyUpdateState({ manual_run: { state: 'running', started_at: Math.floor(Date.now() / 1000) } });
            try {
                const r = await postWithCsrf(adminUrl, 'action=start_manual_update');
                const data = await r.json();
                if (!data || !data.success) {
                    const reason = data && data.error;
                    const msg = reason === 'disabled' ? 'Updates are disabled in this environment (dev/test).'
                        : reason === 'no_cron_key' ? 'Set up the cron job first — the updater needs the cron key.'
                        : 'Could not start the update.';
                    showToast(msg, 'error');
                    await fetchAndRenderUpdateState();
                    return;
                }
                showToast('Update started…', 'success');
                manualUpdatePollDeadline = Date.now() + 180000; // 3 min cap
                pollManualUpdate();
            } catch (e) {
                showToast('Could not start the update', 'error');
                await fetchAndRenderUpdateState();
            }
        }

        function pollManualUpdate() {
            if (manualUpdatePollTimer) clearTimeout(manualUpdatePollTimer);
            manualUpdatePollTimer = setTimeout(async () => {
                const data = await fetchAndRenderUpdateState();
                const run = data ? data.manual_run : null;
                const stillRunning = run && run.state === 'running';
                if (stillRunning && Date.now() < manualUpdatePollDeadline) {
                    pollManualUpdate();
                    return;
                }
                manualUpdatePollTimer = null;
                if (stillRunning) {
                    // Timed out waiting. The update may still finish server-side.
                    showToast('Still working… reload in a minute to check the result.', 'error');
                    return;
                }
                const view = manualRunView(run);
                if (view && view.done === 'success') {
                    showToast('Update applied — reload the page.', 'success');
                } else if (view && view.done === 'failed') {
                    showToast('Update failed and was rolled back.', 'error');
                } else if (view && view.done === 'noop') {
                    showToast('Already up to date.', 'success');
                }
            }, 3000);
        }

        // Auto-update card: fetch current channel/version/backups and wire
        // up the channel selector + rollback button.
        async function loadAutoUpdateCard() {
            const card = document.getElementById('card-auto-update');
            if (!card) return;
            const cur = document.getElementById('auto-update-current');
            const sel = document.getElementById('auto-update-channel');
            const warn = document.getElementById('auto-update-htaccess-warning');
            const rollbackBtn = document.getElementById('btn-rollback-update');
            const rollbackHelp = document.getElementById('auto-update-rollback-help');
            try {
                const r = await fetch(adminUrl + '?api=update_status', { credentials: 'same-origin' });
                const data = await r.json();
                const sha = data.current_sha ? data.current_sha.slice(0, 12) : 'unknown';
                cur.textContent = (data.current_version || 'unknown') + ' (' + sha + ')';
                if (sel) sel.value = (data.channel === 'testing') ? 'testing' : 'main';
                if (data.htaccess_new_exists) {
                    warn.classList.remove('hidden');
                } else {
                    warn.classList.add('hidden');
                }
                // Auto-rollback alert: a build failed its post-update health
                // check and was reverted. Show until the admin dismisses it.
                const rbWarn = document.getElementById('auto-update-rollback-warning');
                const rbDetail = document.getElementById('auto-update-rollback-detail');
                if (rbWarn && data.last_auto_rollback && !data.auto_rollback_dismissed) {
                    const ar = data.last_auto_rollback;
                    const badSha = ar.bad_sha ? (' ' + ar.bad_sha.slice(0, 12)) : '';
                    const ver = ar.version ? (' ' + ar.version) : '';
                    const failed = ar.rolled_back === false
                        ? ' The automatic rollback FAILED — use recover.php to restore manually.'
                        : '';
                    if (rbDetail) {
                        rbDetail.textContent = ' Build' + ver + badSha + ' crashed the install and was reverted to ' +
                            (ar.backup || 'the previous version') + '.' + failed + ' ';
                    }
                    rbWarn.classList.remove('hidden');
                } else if (rbWarn) {
                    rbWarn.classList.add('hidden');
                }
                const cronLine = document.getElementById('auto-update-cron-line');
                if (cronLine) {
                    cronLine.textContent = data.recommended_cron || 'Unavailable (set up the main cron first).';
                }
                const hasBackup = Array.isArray(data.backups) && data.backups.length > 0;
                if (rollbackBtn) rollbackBtn.disabled = !hasBackup;
                if (rollbackHelp) {
                    rollbackHelp.textContent = hasBackup
                        ? ('Restores backup: ' + data.backups[0])
                        : 'No backups yet. The first update will create one.';
                }
                // Render the "X -> Y applied at ..." banner if there's a
                // recent update the admin hasn't acknowledged.
                if (data.last_update && !data.banner_dismissed) {
                    showAutoUpdateBanner(data.last_update);
                }
                // Availability line + manual-run progress + Update-now button.
                applyUpdateState(data);
            } catch (e) {
                cur.textContent = 'Unable to load update status';
            }
        }

        function showAutoUpdateBanner(last) {
            // Reuse the page-top banner space: render a simple toast for now.
            const from = last.from_version || 'unknown';
            const to = last.to_version || 'unknown';
            showToast('Updated ' + from + ' → ' + to, 'success');
            // Best-effort dismiss so we don't re-toast on every settings open.
            // POST actions carry `action` in the body + the CSRF token (the
            // shared postWithCsrf helper) — the query-string form returns
            // "Unknown action" since the handler reads $_POST['action'].
            try {
                postWithCsrf(adminUrl, 'action=dismiss_update_banner');
            } catch (e) {}
        }

        async function dismissAutoRollback() {
            const warn = document.getElementById('auto-update-rollback-warning');
            if (warn) warn.classList.add('hidden');
            try {
                await postWithCsrf(adminUrl, 'action=dismiss_auto_rollback');
            } catch (e) {}
        }

        async function saveUpdateChannel() {
            const sel = document.getElementById('auto-update-channel');
            if (!sel) return;
            const channel = sel.value === 'testing' ? 'testing' : 'main';
            try {
                const r = await postWithCsrf(adminUrl,
                    'action=save_update_channel&channel=' + encodeURIComponent(channel));
                const data = await r.json();
                if (data && data.success) {
                    showToast('Channel set to ' + data.channel, 'success');
                } else {
                    showToast(data.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save', 'error');
            }
        }

        async function rollbackUpdate() {
            if (!confirm('Roll back to the previous version? Your data/ and user_config.php are preserved either way.')) {
                return;
            }
            try {
                const r = await postWithCsrf(adminUrl, 'action=rollback_update');
                const data = await r.json();
                if (data && data.success) {
                    showToast('Rolled back. Reload the page.', 'success');
                } else {
                    showToast('Rollback failed', 'error');
                }
            } catch (e) {
                showToast('Rollback failed', 'error');
            }
        }

        function updateOnchainManualBanner(onchain) {
            const banner = document.getElementById('onchain-manual-banner');
            const text = document.getElementById('onchain-manual-banner-text');
            if (!banner || !text) return;
            const n = (onchain && onchain.needsManualConfirmation) || 0;
            if (n > 0) {
                text.textContent =
                    n + ' on-chain payment' + (n === 1 ? '' : 's') + ' need' + (n === 1 ? 's' : '') + ' manual confirmation.';
                banner.classList.remove('hidden');
            } else {
                banner.classList.add('hidden');
            }
        }

        async function loadOnchainManualList() {
            const card = document.getElementById('card-onchain-manual');
            const list = document.getElementById('onchain-manual-list');
            if (!card || !list) return;
            if (!currentStoreId) {
                card.classList.add('hidden');
                return;
            }
            try {
                const r = await fetch(adminUrl + '?api=list_onchain_manual&store_id=' + encodeURIComponent(currentStoreId));
                const data = await r.json();
                const invoices = data.invoices || [];
                if (invoices.length === 0) {
                    card.classList.add('hidden');
                    list.innerHTML = '';
                    return;
                }
                card.classList.remove('hidden');
                list.innerHTML = invoices.map(renderManualInvoiceBlock).join('');
                list.querySelectorAll('[data-manual-action="attribute"]').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const invoiceId = btn.dataset.invoiceId;
                        const txid = btn.dataset.txid;
                        const vout = btn.dataset.vout;
                        btn.disabled = true;
                        try {
                            const resp = await postWithCsrf(adminUrl,
                                'action=resolve_onchain_manual&invoice_id=' + encodeURIComponent(invoiceId) +
                                '&txid=' + encodeURIComponent(txid) +
                                '&vout=' + encodeURIComponent(vout));
                            const result = await resp.json();
                            if (resp.ok) {
                                showToast('Attributed and settled', 'success');
                                await loadDashboard();
                            } else {
                                showToast(result.error || 'Failed to attribute', 'error');
                                btn.disabled = false;
                            }
                        } catch (e) {
                            showToast('Network error: ' + e.message, 'error');
                            btn.disabled = false;
                        }
                    });
                });
            } catch (e) {
                list.innerHTML = '<p style="color: var(--text-secondary);">Failed to load: ' + escapeHtml(e.message) + '</p>';
            }
        }

        function renderManualInvoiceBlock(inv) {
            const candidates = (inv.candidates || []).map(c => {
                const conf = (c.confirmations || 0);
                return `
                    <li style="margin-bottom: 0.5rem; padding: 0.5rem; background: rgba(0,0,0,0.08); border-radius: 6px;">
                        <div style="font-family: monospace; font-size: 0.78rem; word-break: break-all;">${escapeHtml(c.txid)}:${c.vout}</div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin: 0.25rem 0;">
                            ${c.amount_sat} sats &middot; ${conf} confirmation${conf === 1 ? '' : 's'}
                        </div>
                        <button class="btn btn-secondary" style="padding: 0.25rem 0.75rem; font-size: 0.8rem;"
                                data-manual-action="attribute"
                                data-invoice-id="${escapeAttr(inv.id)}"
                                data-txid="${escapeAttr(c.txid)}"
                                data-vout="${c.vout}">
                            Attribute to this invoice
                        </button>
                    </li>`;
            }).join('');
            return `
                <div style="margin-bottom: 1rem; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 0.5rem;">
                        <div><strong>${escapeHtml(inv.id)}</strong>
                            <span style="color: var(--text-secondary); font-size: 0.85rem;"> &middot; ${escapeHtml(inv.amount)} ${escapeHtml(inv.currency)} &middot; expected ${inv.onchain_amount_sat} sats</span>
                        </div>
                        <span style="font-size: 0.8rem; color: var(--text-secondary);">${escapeHtml(inv.status)}</span>
                    </div>
                    <ul style="list-style: none; padding: 0; margin: 0.5rem 0 0;">${candidates}</ul>
                </div>`;
        }

        function updateReliabilityBanner(reliability) {
            const banner = document.getElementById('reliability-banner');
            const text = document.getElementById('reliability-banner-text');
            if (!banner || !text || !reliability) return;
            if (reliability.hasStaleSuspect || reliability.disabledCount > 0) {
                const parts = [];
                if (reliability.disabledCount > 0) {
                    parts.push(reliability.disabledCount + ' mint' + (reliability.disabledCount === 1 ? '' : 's') + ' currently disabled');
                }
                if (reliability.hasStaleSuspect) {
                    parts.push('one or more withdraw failures unresolved for over 24h');
                }
                text.textContent = parts.join(' — ') + '. Review in Settings.';
                banner.classList.remove('hidden');
            } else {
                banner.classList.add('hidden');
            }
        }

        function createApiKey(storeId) {
            document.getElementById('modal-apikey-title').textContent = 'Create API Key';
            const modalContent = document.getElementById('modal-apikey-content');
            modalContent.innerHTML = `
                <div class="form-group">
                    <label class="form-label">Label (optional)</label>
                    <input type="text" class="form-input" id="apikey-label" placeholder="My API Key">
                </div>
                <button class="btn btn-full" data-apikey-action="submit-create" data-store-id="${escapeAttr(storeId)}">Create Key</button>
                <button class="btn btn-secondary btn-full" data-apikey-action="cancel" style="margin-top: 0.5rem;">Cancel</button>
            `;
            modalContent.querySelector('[data-apikey-action="submit-create"]').addEventListener('click', (e) => {
                submitCreateApiKey(e.currentTarget.dataset.storeId);
            });
            modalContent.querySelector('[data-apikey-action="cancel"]').addEventListener('click', () => closeModal('modal-apikey'));
            openModal('modal-apikey');
            document.getElementById('apikey-label').focus();
        }

        async function submitCreateApiKey(storeId) {
            const label = document.getElementById('apikey-label').value || 'API Key';

            const response = await postWithCsrf(adminUrl,
                `action=create_api_key&store_id=${encodeURIComponent(storeId)}&label=${encodeURIComponent(label)}`
            );

            const result = await response.json();

            if (response.ok) {
                showApiKeyResult(result.key, storeId);
            } else {
                showToast(result.error || 'Failed to create API key', 'error');
            }
        }

        function showApiKeyResult(key, storeId) {
            document.getElementById('modal-apikey-title').textContent = 'API Key Created';
            const modalContent = document.getElementById('modal-apikey-content');
            modalContent.innerHTML = `
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">Save this key now - it won't be shown again!</p>
                <div class="token-display" style="user-select: all; cursor: text;">${escapeHtml(key)}</div>
                <button class="btn btn-full" data-apikey-action="copy">Copy to Clipboard</button>
                <button class="btn btn-secondary btn-full" data-apikey-action="close" style="margin-top: 0.5rem;">Close</button>
            `;
            modalContent.querySelector('[data-apikey-action="copy"]').addEventListener('click', () => copyApiKey(key));
            modalContent.querySelector('[data-apikey-action="close"]').addEventListener('click', () => closeApiKeyModal(storeId));
        }

        function copyApiKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                showToast('API key copied to clipboard', 'success');
            }).catch(() => {
                showToast('Failed to copy', 'error');
            });
        }

        function closeApiKeyModal(storeId) {
            closeModal('modal-apikey');
            // Refresh store details or store settings view
            if (document.getElementById('view-stores').classList.contains('active')) {
                loadStoreApiKeys();
            } else {
                const store = dashboardData?.stores?.find(s => s.id === storeId);
                if (store) showStoreDetails(storeId, store.name);
            }
        }

        async function deleteApiKey(keyId, storeId) {
            if (!confirm('Delete this API key?')) return;

            await postWithCsrf(adminUrl, `action=delete_api_key&key_id=${encodeURIComponent(keyId)}`);

            const store = dashboardData?.stores?.find(s => s.id === storeId);
            if (store) showStoreDetails(storeId, store.name);
        }

        async function deleteApiKeyFromSettings(keyId) {
            if (!confirm('Delete this API key?')) return;

            // keyId now arrives raw (caller previously double-encoded via
            // encodeURIComponent and the body was sent verbatim).
            await postWithCsrf(adminUrl, `action=delete_api_key&key_id=${encodeURIComponent(keyId)}`);
            loadStoreApiKeys();
        }

        async function deleteStore(storeId) {
            if (!confirm('Delete this store and all its data? This action cannot be undone.')) return;

            await postWithCsrf(adminUrl, `action=delete_store&store_id=${encodeURIComponent(storeId)}`);

            closeModal('modal-store');
            // Clear current store selection
            currentStoreId = null;
            localStorage.removeItem('selectedStoreId');
            // Reload dashboard which will auto-select first store
            loadDashboard();
            // If on store settings view, reload it
            if (document.getElementById('view-stores').classList.contains('active')) {
                loadStoreSettings();
            }
        }

        // Export Max button - uses pre-loaded dashboard data for instant response
        function sendMax() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const exportAvailable = dashboardData?.exportAvailable || 0;

            if (exportAvailable > 0) {
                const maxAmountSmallest = exportAvailable;

                // Convert to display format (divide by 100 for fiat)
                const displayValue = isFiatUnit(mintUnit)
                    ? (Math.max(0, maxAmountSmallest) / 100).toFixed(2)
                    : Math.max(0, maxAmountSmallest);
                document.getElementById('export-amount').value = displayValue;
                updateExportButton();
            } else {
                showToast('No balance available to send', 'error');
            }
        }

        // Modals
        // ============================ Products + cart ============================

        function currentStoreCurrency() {
            const store = dashboardData?.stores?.find(s => s.id === currentStoreId);
            return (store?.default_currency || dashboardData?.mintUnit || 'sat');
        }

        function formatMoney(amount, currency) {
            const cur = String(currency || 'sat').toUpperCase();
            if (cur === 'SAT' || cur === 'SATS') {
                return Math.round(Number(amount)).toLocaleString() + ' sats';
            }
            return Number(amount).toFixed(2) + ' ' + cur;
        }

        // Inline markup for a product/line image: uploaded picture, emoji, or
        // the default box.
        function productImageHtml(imageType, imageValue, imageUrl, sizePx) {
            const s = sizePx || 40;
            const box = `width:${s}px;height:${s}px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.18);overflow:hidden;flex-shrink:0;`;
            if (imageType === 'upload' && imageUrl) {
                return `<div style="${box}"><img src="${escapeHtml(imageUrl)}" alt="" style="width:100%;height:100%;object-fit:cover;"></div>`;
            }
            const glyph = (imageType === 'emoji' && imageValue) ? imageValue : '📦';
            return `<div style="${box};font-size:${Math.round(s*0.55)}px;">${escapeHtml(glyph)}</div>`;
        }

        // ---- Admin Products view ----

        let productsAdminCache = [];

        async function loadProductsView() {
            if (phpUser.role !== 'admin') return;
            const list = document.getElementById('products-admin-list');
            const curLabel = document.getElementById('products-currency-label');
            if (curLabel) curLabel.textContent = String(currentStoreCurrency()).toUpperCase();
            if (!currentStoreId) {
                if (list) list.innerHTML = '<p style="color:var(--text-secondary);">Select a store first.</p>';
                return;
            }
            if (list) list.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            try {
                const r = await fetch(`${adminUrl}?api=products_manage&store_id=${encodeURIComponent(currentStoreId)}`, { credentials: 'same-origin' });
                const data = await r.json();
                productsAdminCache = data.products || [];
                const sortSel = document.getElementById('products-default-sort');
                if (sortSel && data.sort) sortSel.value = data.sort;
                renderProductsAdmin();
            } catch (e) {
                if (list) list.innerHTML = '<p style="color:#ef4444;">Failed to load products.</p>';
            }
        }

        function renderProductsAdmin() {
            const list = document.getElementById('products-admin-list');
            if (!list) return;
            if (!productsAdminCache.length) {
                list.innerHTML = '<p style="color:var(--text-secondary);">No products yet. Click “New product”.</p>';
                return;
            }
            list.innerHTML = productsAdminCache.map(p => `
                <div style="display:flex; align-items:center; gap:0.75rem; padding:0.6rem 0; border-bottom:1px solid var(--border, rgba(255,255,255,0.08));">
                    ${productImageHtml(p.imageType, p.imageValue, p.imageUrl, 44)}
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:600; ${p.enabled ? '' : 'opacity:0.5;'}">${escapeHtml(p.title)}${p.enabled ? '' : ' <span style="font-size:0.7rem;">(disabled)</span>'}</div>
                        <div style="font-size:0.8rem; color:var(--text-secondary);">${escapeHtml(formatMoney(p.price, p.currency))} · ${p.purchaseCount} sold</div>
                    </div>
                    <button class="btn btn-secondary" style="width:auto; padding:0.3rem 0.7rem;" onclick="openProductEditModal('${p.id}')">Edit</button>
                    <button class="btn btn-secondary" style="width:auto; padding:0.3rem 0.7rem;" onclick="toggleProductEnabled('${p.id}')">${p.enabled ? 'Disable' : 'Enable'}</button>
                    <button class="btn btn-secondary" style="width:auto; padding:0.3rem 0.7rem; color:#ef4444;" onclick="deleteProduct('${p.id}')">Delete</button>
                </div>
            `).join('');
        }

        async function saveProductDefaultSort() {
            if (!currentStoreId) return;
            const sort = document.getElementById('products-default-sort').value;
            try {
                const r = await postWithCsrf(adminUrl, `action=save_product_sort&store_id=${encodeURIComponent(currentStoreId)}&sort=${encodeURIComponent(sort)}`);
                const d = await r.json();
                if (d.success) showToast('Default sort saved', 'success');
                else showToast(d.error || 'Failed', 'error');
            } catch (e) { showToast('Failed to save sort', 'error'); }
        }

        async function toggleProductEnabled(id) {
            const p = productsAdminCache.find(x => x.id === id);
            if (!p) return;
            try {
                const r = await postWithCsrf(adminUrl, `action=update_product&store_id=${encodeURIComponent(currentStoreId)}&product_id=${encodeURIComponent(id)}&enabled=${p.enabled ? '0' : '1'}`);
                const d = await r.json();
                if (d.success) loadProductsView();
                else showToast(d.error || 'Failed', 'error');
            } catch (e) { showToast('Failed', 'error'); }
        }

        async function deleteProduct(id) {
            const p = productsAdminCache.find(x => x.id === id);
            if (!confirm('Delete product “' + (p ? p.title : id) + '”? Past invoices keep their line items.')) return;
            try {
                const r = await postWithCsrf(adminUrl, `action=delete_product&store_id=${encodeURIComponent(currentStoreId)}&product_id=${encodeURIComponent(id)}`);
                const d = await r.json();
                if (d.success) { showToast('Product deleted', 'success'); loadProductsView(); }
                else showToast(d.error || 'Failed', 'error');
            } catch (e) { showToast('Failed to delete', 'error'); }
        }

        // ---- Product create/edit modal ----

        let productEditImage = { type: 'none', value: null, url: null };

        function refreshProductImagePreview() {
            const prev = document.getElementById('product-edit-image-preview');
            if (!prev) return;
            if (productEditImage.type === 'upload' && productEditImage.url) {
                prev.innerHTML = `<img src="${escapeHtml(productEditImage.url)}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
            } else if (productEditImage.type === 'emoji' && productEditImage.value) {
                prev.textContent = productEditImage.value;
            } else {
                prev.textContent = '📦';
            }
        }

        function openProductEditModal(id) {
            if (!ensureAdmin('Only admins can manage products.')) return;
            if (!currentStoreId) { showToast('Select a store first', 'error'); return; }
            const p = id ? productsAdminCache.find(x => x.id === id) : null;
            document.getElementById('product-edit-id').value = p ? p.id : '';
            document.getElementById('product-edit-title').textContent = p ? 'Edit product' : 'New product';
            document.getElementById('product-edit-title-input').value = p ? p.title : '';
            document.getElementById('product-edit-price').value = p ? p.price : '';
            document.getElementById('product-edit-currency').textContent = String(p ? p.currency : currentStoreCurrency()).toUpperCase();
            document.getElementById('product-edit-emoji').value = (p && p.imageType === 'emoji') ? p.imageValue : '';
            document.getElementById('product-edit-error').style.display = 'none';
            productEditImage = p
                ? { type: p.imageType || 'none', value: p.imageValue || null, url: p.imageUrl || null }
                : { type: 'none', value: null, url: null };
            refreshProductImagePreview();
            const picker = document.getElementById('product-edit-emoji-picker');
            if (picker) picker.style.display = 'none';
            document.getElementById('modal-product-edit').classList.add('visible');
        }

        function wireProductEditModal() {
            const emoji = document.getElementById('product-edit-emoji');
            if (emoji) emoji.addEventListener('input', () => {
                const v = emoji.value.trim();
                if (v) productEditImage = { type: 'emoji', value: v, url: null };
                else if (productEditImage.type === 'emoji') productEditImage = { type: 'none', value: null, url: null };
                refreshProductImagePreview();
            });
            const uploadBtn = document.getElementById('btn-product-upload');
            const fileInput = document.getElementById('product-edit-file');
            if (uploadBtn && fileInput) {
                uploadBtn.addEventListener('click', () => fileInput.click());
                fileInput.addEventListener('change', handleProductImageUpload);
            }
            const clearBtn = document.getElementById('btn-product-clear-image');
            if (clearBtn) clearBtn.addEventListener('click', () => {
                productEditImage = { type: 'none', value: null, url: null };
                document.getElementById('product-edit-emoji').value = '';
                refreshProductImagePreview();
            });
            // Emoji picker: the "＋" button toggles a small grid of common
            // emojis; clicking one fills the input + preview.
            const pickBtn = document.getElementById('btn-product-emoji-pick');
            const picker = document.getElementById('product-edit-emoji-picker');
            if (pickBtn && picker) {
                const grid = picker.querySelector('div');
                grid.innerHTML = PRODUCT_EMOJIS.map(em =>
                    `<button type="button" class="product-emoji-opt" data-emoji="${em}" style="background:none; border:0; cursor:pointer; font-size:1.4rem; padding:0.2rem; border-radius:6px;">${em}</button>`
                ).join('');
                grid.addEventListener('click', (e) => {
                    const btn = e.target.closest('.product-emoji-opt');
                    if (!btn) return;
                    const em = btn.dataset.emoji;
                    document.getElementById('product-edit-emoji').value = em;
                    productEditImage = { type: 'emoji', value: em, url: null };
                    refreshProductImagePreview();
                    picker.style.display = 'none';
                });
                pickBtn.addEventListener('click', () => {
                    picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
                });
            }
            const saveBtn = document.getElementById('btn-save-product');
            if (saveBtn) saveBtn.addEventListener('click', handleProductSave);
        }

        // Curated emoji set for the product picker (commerce / food / goods).
        const PRODUCT_EMOJIS = [
            '📦','🛍️','🛒','🎁','🏷️','💳','💵','⭐','🔥','✨',
            '☕','🍵','🧋','🍺','🍷','🥤','🍿','🍫','🍪','🍰',
            '🍔','🍕','🌮','🥗','🍜','🍣','🥐','🍞','🧀','🍎',
            '👕','👟','🧢','👜','💍','⌚','🕶️','🧴','💄','🧼',
            '📱','💻','🎧','🎮','📷','🔋','💡','🔌','🖊️','📚',
            '🌱','🌸','🪴','🐶','🐱','🎫','🎟️','🏠','🚲','🔧',
        ];

        async function handleProductImageUpload(e) {
            const file = e.target.files && e.target.files[0];
            e.target.value = '';
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) { showToast('Image must be 2 MB or smaller', 'error'); return; }
            const fd = new FormData();
            fd.append('action', 'upload_product_image');
            fd.append('image', file);
            try {
                const r = await fetch(adminUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': getCsrfToken() }, body: fd });
                const d = await r.json();
                if (d.success) {
                    productEditImage = { type: 'upload', value: d.filename, url: d.url };
                    document.getElementById('product-edit-emoji').value = '';
                    refreshProductImagePreview();
                } else {
                    showToast(d.error || 'Upload failed', 'error');
                }
            } catch (err) { showToast('Upload failed', 'error'); }
        }

        async function handleProductSave() {
            const id = document.getElementById('product-edit-id').value;
            const title = document.getElementById('product-edit-title-input').value.trim();
            const price = document.getElementById('product-edit-price').value.trim();
            const errEl = document.getElementById('product-edit-error');
            errEl.style.display = 'none';
            if (!title) { errEl.textContent = 'Title is required'; errEl.style.display = 'block'; return; }
            if (!price || Number(price) <= 0) { errEl.textContent = 'Enter a price greater than zero'; errEl.style.display = 'block'; return; }

            let body = `store_id=${encodeURIComponent(currentStoreId)}`
                + `&title=${encodeURIComponent(title)}`
                + `&price=${encodeURIComponent(price)}`
                + `&image_type=${encodeURIComponent(productEditImage.type)}`
                + `&image_value=${encodeURIComponent(productEditImage.value || '')}`;
            const action = id ? 'update_product' : 'create_product';
            if (id) body += `&product_id=${encodeURIComponent(id)}`;
            try {
                const r = await postWithCsrf(adminUrl, `action=${action}&` + body);
                const d = await r.json();
                if (d.success) {
                    closeModal('modal-product-edit');
                    showToast(id ? 'Product updated' : 'Product created', 'success');
                    loadProductsView();
                } else {
                    errEl.textContent = d.error || 'Failed to save'; errEl.style.display = 'block';
                }
            } catch (e) {
                errEl.textContent = 'Failed to save'; errEl.style.display = 'block';
            }
        }

        // ---- Cart request modal ----

        let cartCatalog = [];
        let cartItems = [];   // {productId|null, title, price, currency, quantity, image:{type,value,url}}
        let cartPage = 1;
        const CART_PAGE_SIZE = 10;

        async function openCartModal() {
            if (!currentStoreId) { showToast('Please select a store first', 'error'); return; }
            cartItems = [];
            cartPage = 1;
            document.getElementById('cart-search').value = '';
            document.getElementById('cart-memo').value = '';
            // Pre-fill the per-invoice privacy overrides from the store defaults.
            const cartPriv = dashboardData?.invoicePrivacy || {};
            const cartHsn = document.getElementById('cart-hide-store-name');
            if (cartHsn) cartHsn.checked = !!cartPriv.hideStoreName;
            const cartHn = document.getElementById('cart-hide-note');
            if (cartHn) cartHn.checked = !!cartPriv.hideNote;
            const customForm = document.getElementById('cart-custom-form');
            if (customForm) customForm.style.display = 'none';
            renderCart();
            document.getElementById('cart-catalog').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            document.getElementById('modal-cart').classList.add('visible');
            try {
                const r = await fetch(`${adminUrl}?api=products&store_id=${encodeURIComponent(currentStoreId)}`, { credentials: 'same-origin' });
                const data = await r.json();
                cartCatalog = data.products || [];
                const sortSel = document.getElementById('cart-sort');
                if (sortSel && data.sort) sortSel.value = data.sort;
                renderCartCatalog();
            } catch (e) {
                document.getElementById('cart-catalog').innerHTML = '<p style="padding:1rem; color:#ef4444;">Failed to load products.</p>';
            }
        }

        function wireCartModal() {
            const search = document.getElementById('cart-search');
            if (search) search.addEventListener('input', () => { cartPage = 1; renderCartCatalog(); });
            const sort = document.getElementById('cart-sort');
            if (sort) sort.addEventListener('change', () => { cartPage = 1; renderCartCatalog(); });
            const addCustom = document.getElementById('btn-add-custom-line');
            if (addCustom) addCustom.addEventListener('click', () => {
                const f = document.getElementById('cart-custom-form');
                f.style.display = f.style.display === 'none' ? 'flex' : 'none';
            });
            const addCustomConfirm = document.getElementById('btn-add-custom-confirm');
            if (addCustomConfirm) addCustomConfirm.addEventListener('click', addCustomCartLine);
            const checkout = document.getElementById('btn-cart-checkout');
            if (checkout) checkout.addEventListener('click', cartCheckout);
        }

        function sortCatalog(list, key) {
            const arr = list.slice();
            switch (key) {
                case 'newest': arr.sort((a, b) => b.createdAt - a.createdAt); break;
                case 'title_asc': arr.sort((a, b) => a.title.toLowerCase().localeCompare(b.title.toLowerCase())); break;
                case 'price_asc': arr.sort((a, b) => Number(a.price) - Number(b.price)); break;
                case 'price_desc': arr.sort((a, b) => Number(b.price) - Number(a.price)); break;
                default: arr.sort((a, b) => (b.purchaseCount - a.purchaseCount) || (b.createdAt - a.createdAt));
            }
            return arr;
        }

        function renderCartCatalog() {
            const container = document.getElementById('cart-catalog');
            const pager = document.getElementById('cart-pagination');
            const q = (document.getElementById('cart-search').value || '').trim().toLowerCase();
            const sortKey = document.getElementById('cart-sort').value;
            let filtered = cartCatalog.filter(p => p.title.toLowerCase().includes(q));
            filtered = sortCatalog(filtered, sortKey);

            const totalPages = Math.max(1, Math.ceil(filtered.length / CART_PAGE_SIZE));
            if (cartPage > totalPages) cartPage = totalPages;
            const start = (cartPage - 1) * CART_PAGE_SIZE;
            const pageItems = filtered.slice(start, start + CART_PAGE_SIZE);

            if (!filtered.length) {
                container.innerHTML = '<p style="padding:1rem; color:var(--text-secondary); margin:0;">' +
                    (cartCatalog.length ? 'No products match your search.' : 'No products for this store yet.') + '</p>';
                pager.innerHTML = '';
                return;
            }
            container.innerHTML = pageItems.map(p => `
                <div role="button" tabindex="0" onclick="addToCart('${p.id}')" style="display:flex; align-items:center; gap:0.6rem; padding:0.55rem 0.6rem; cursor:pointer; border-bottom:1px solid var(--border, rgba(255,255,255,0.06));">
                    ${productImageHtml(p.imageType, p.imageValue, p.imageUrl, 38)}
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(p.title)}</div>
                        <div style="font-size:0.8rem; color:var(--text-secondary);">${escapeHtml(formatMoney(p.price, p.currency))}</div>
                    </div>
                    <div style="font-size:1.3rem; color:var(--accent, #60a5fa);">＋</div>
                </div>
            `).join('');

            pager.innerHTML = totalPages > 1 ? `
                <button class="btn btn-secondary" style="width:auto; padding:0.25rem 0.7rem;" ${cartPage <= 1 ? 'disabled' : ''} onclick="cartGoPage(${cartPage - 1})">‹</button>
                <span>Page ${cartPage} / ${totalPages}</span>
                <button class="btn btn-secondary" style="width:auto; padding:0.25rem 0.7rem;" ${cartPage >= totalPages ? 'disabled' : ''} onclick="cartGoPage(${cartPage + 1})">›</button>
            ` : '';
        }

        function cartGoPage(n) { cartPage = n; renderCartCatalog(); }

        function addToCart(productId) {
            const p = cartCatalog.find(x => x.id === productId);
            if (!p) return;
            const existing = cartItems.find(i => i.productId === productId);
            if (existing) existing.quantity = Math.min(999, existing.quantity + 1);
            else cartItems.push({ productId: p.id, title: p.title, price: p.price, currency: p.currency, quantity: 1, image: { type: p.imageType, value: p.imageValue, url: p.imageUrl } });
            renderCart();
        }

        function addCustomCartLine() {
            const amount = document.getElementById('cart-custom-amount').value.trim();
            const label = document.getElementById('cart-custom-label').value.trim() || 'Custom amount';
            if (!amount || Number(amount) <= 0) { showToast('Enter a custom amount', 'error'); return; }
            cartItems.push({ productId: null, title: label, price: amount, currency: currentStoreCurrency(), quantity: 1, image: { type: 'none' } });
            document.getElementById('cart-custom-amount').value = '';
            document.getElementById('cart-custom-label').value = '';
            document.getElementById('cart-custom-form').style.display = 'none';
            renderCart();
        }

        function changeCartQty(index, delta) {
            const it = cartItems[index];
            if (!it) return;
            it.quantity += delta;
            if (it.quantity < 1) { cartItems.splice(index, 1); }
            else if (it.quantity > 999) { it.quantity = 999; }
            renderCart();
        }

        function renderCart() {
            const wrap = document.getElementById('cart-items');
            const totalEl = document.getElementById('cart-total');
            const checkoutBtn = document.getElementById('btn-cart-checkout');
            if (!cartItems.length) {
                wrap.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">Cart is empty. Tap a product to add it.</p>';
                totalEl.textContent = '';
                if (checkoutBtn) checkoutBtn.disabled = true;
                return;
            }
            wrap.innerHTML = cartItems.map((it, idx) => `
                <div style="display:flex; align-items:center; gap:0.5rem; padding:0.4rem 0; border-bottom:1px solid var(--border, rgba(255,255,255,0.06));">
                    ${productImageHtml(it.image && it.image.type, it.image && it.image.value, it.image && it.image.url, 32)}
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(it.title)}</div>
                        <div style="font-size:0.78rem; color:var(--text-secondary);">${escapeHtml(formatMoney(it.price, it.currency))} each</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.3rem;">
                        <button class="btn btn-secondary" style="width:28px; padding:0.1rem 0;" onclick="changeCartQty(${idx}, -1)">−</button>
                        <span style="min-width:1.5rem; text-align:center;">${it.quantity}</span>
                        <button class="btn btn-secondary" style="width:28px; padding:0.1rem 0;" onclick="changeCartQty(${idx}, 1)">＋</button>
                    </div>
                </div>
            `).join('');

            const totals = {};
            cartItems.forEach(it => {
                const cur = String(it.currency || 'sat').toUpperCase();
                totals[cur] = (totals[cur] || 0) + Number(it.price) * it.quantity;
            });
            const parts = Object.keys(totals).map(c => formatMoney(totals[c], c));
            totalEl.textContent = 'Total: ' + parts.join(' + ');
            if (checkoutBtn) checkoutBtn.disabled = false;
        }

        async function cartCheckout() {
            if (!cartItems.length) return;
            const btn = document.getElementById('btn-cart-checkout');
            btn.disabled = true;
            const items = cartItems.map(it => it.productId
                ? { product_id: it.productId, quantity: it.quantity }
                : { title: it.title, price: it.price, currency: it.currency, quantity: it.quantity });
            const memo = document.getElementById('cart-memo').value.trim();
            const hideStoreName = document.getElementById('cart-hide-store-name')?.checked ? '1' : '0';
            const hideNote = document.getElementById('cart-hide-note')?.checked ? '1' : '0';
            let body = `store_id=${encodeURIComponent(currentStoreId)}`
                + `&items=${encodeURIComponent(JSON.stringify(items))}`
                + `&redirect=${encodeURIComponent(window.location.href.split('?')[0])}`
                // Always send the privacy overrides (pre-filled from store
                // defaults) so the per-invoice choice wins.
                + `&hide_store_name=${hideStoreName}`
                + `&hide_note=${hideNote}`;
            if (memo) body += `&memo=${encodeURIComponent(memo)}`;
            try {
                const r = await postWithCsrf(adminUrl, `action=cart_checkout&` + body);
                const d = await r.json();
                if (d.success && d.checkoutLink) {
                    window.location.href = d.checkoutLink;
                } else {
                    showToast(d.error || 'Checkout failed', 'error');
                    btn.disabled = false;
                }
            } catch (e) {
                showToast('Checkout failed', 'error');
                btn.disabled = false;
            }
        }

        // ---- Admin invoice line-item detail ----

        async function openInvoiceItems(invoiceId) {
            const content = document.getElementById('invoice-detail-content');
            content.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            document.getElementById('modal-invoice-detail').classList.add('visible');
            try {
                const r = await fetch(`${adminUrl}?api=invoice_items&id=${encodeURIComponent(invoiceId)}`, { credentials: 'same-origin' });
                const d = await r.json();
                const items = d.items || [];
                if (!items.length) {
                    content.innerHTML = '<p style="color:var(--text-secondary);">This invoice has no cart line items.</p>';
                    return;
                }
                let totalSats = 0;
                content.innerHTML = items.map(it => {
                    totalSats += it.amountSats;
                    const paren = (it.displayAmount && it.displayCurrency && String(it.displayCurrency).toUpperCase() !== 'SAT')
                        ? ` <span style="color:var(--text-secondary);">(${escapeHtml(formatMoney(it.displayAmount, it.displayCurrency))})</span>` : '';
                    return `<div style="display:flex; align-items:center; gap:0.6rem; padding:0.45rem 0; border-bottom:1px solid var(--border, rgba(255,255,255,0.06));">
                        ${productImageHtml(it.imageType, it.imageValue, it.imageUrl, 34)}
                        <div style="flex:1;"><div style="font-weight:500;">${escapeHtml(it.title)}</div>
                        <div style="font-size:0.8rem; color:var(--text-secondary);">×${it.quantity}</div></div>
                        <div style="text-align:right;">${it.amountSats.toLocaleString()} sats${paren}</div>
                    </div>`;
                }).join('') + `<div style="text-align:right; font-weight:600; margin-top:0.5rem;">Total: ${totalSats.toLocaleString()} sats</div>`;
            } catch (e) {
                content.innerHTML = '<p style="color:#ef4444;">Failed to load items.</p>';
            }
        }

        function openModal(id) {
            // Configure input attributes based on mint unit
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const isFiat = isFiatUnit(mintUnit);

            // Reset export modal state
            if (id === 'modal-export') {
                // Stop any active claim checking
                if (exportCheckInterval) {
                    clearInterval(exportCheckInterval);
                    exportCheckInterval = null;
                }
                exportSecrets = null;

                // Remove any existing success overlay
                const modal = document.getElementById('modal-export');
                const modalContent = modal.querySelector('.modal');
                const existingOverlay = Array.from(modalContent.children).find(el =>
                    el.style.position === 'absolute' && el.style.zIndex === '1000'
                );
                if (existingOverlay) {
                    existingOverlay.remove();
                }

                document.getElementById('export-form').style.display = 'block';
                document.getElementById('export-result').style.display = 'none';
                document.getElementById('export-amount').value = '';
                document.getElementById('export-qr').innerHTML = '';

                // Set input attributes for fiat vs sats
                const exportInput = document.getElementById('export-amount');
                exportInput.step = isFiat ? '0.01' : '1';
                exportInput.min = isFiat ? '0.01' : '1';
                exportInput.placeholder = isFiat ? '0.00' : '0';

                // Reset export button state
                const exportBtn = document.getElementById('btn-confirm-export');
                exportBtn.textContent = 'Generate Token';
                exportBtn.disabled = true; // Will be enabled by updateExportButton() when amount entered
            }
            if (id === 'modal-withdraw') {
                document.getElementById('withdraw-address').value = '';
                document.getElementById('withdraw-amount').value = '';
                document.getElementById('withdraw-amount').disabled = false;
                document.getElementById('btn-withdraw-max').disabled = false;
                document.getElementById('withdraw-destination-help').textContent = 'Lightning address or BOLT-11 invoice';
                document.getElementById('withdraw-destination-help').style.color = 'var(--text-secondary)';
                document.getElementById('withdraw-amount-fiat-equiv').style.display = 'none';
                bolt11FixedAmount = null;
                bolt11FeeEstimate = null;
                bolt11MeltError = false;

                // Reset withdraw button state
                const withdrawBtn = document.getElementById('btn-confirm-withdraw');
                withdrawBtn.textContent = 'Withdraw';
                withdrawBtn.disabled = true; // Will be enabled by updateWithdrawInfo() when valid input

                // Lightning withdrawals ALWAYS use SAT (Lightning Network operates in satoshis)
                const withdrawInput = document.getElementById('withdraw-amount');
                withdrawInput.step = '1';
                withdrawInput.min = '1';
                withdrawInput.placeholder = '0';

                // Set label to SAT
                const amountLabel = document.querySelector('#withdraw-amount-group .form-label');
                if (amountLabel) {
                    amountLabel.innerHTML = 'Amount (<span class="unit-label">SAT</span>)';
                }

                // For fiat mints, show fiat balance primary with SAT approximation
                // The fiat amount is what they actually have; SAT is an estimate based on our exchange rate
                if (isFiat) {
                    const balanceSats = dashboardData?.balanceInSats || 0;
                    const balanceFiat = dashboardData?.balance || 0;
                    document.getElementById('withdraw-available').innerHTML =
                        `${formatAmount(balanceFiat, mintUnit)} ${mintUnit.toUpperCase()} (~${balanceSats.toLocaleString()} SAT)`;
                } else {
                    document.getElementById('withdraw-available').textContent =
                        formatAmount(dashboardData?.balance ?? 0, mintUnit) + ' SAT';
                }

                updateWithdrawInfo();
            }
            if (id === 'modal-request') {
                // Check that a store is selected
                if (!currentStoreId) {
                    showToast('Please select a store first', 'error');
                    return;
                }

                document.getElementById('request-amount').value = '';
                document.getElementById('request-memo').value = '';

                // Pre-fill the per-invoice privacy overrides from the store
                // defaults; the value at submit wins for this invoice.
                const priv = dashboardData?.invoicePrivacy || {};
                const hsnBox = document.getElementById('request-hide-store-name');
                if (hsnBox) hsnBox.checked = !!priv.hideStoreName;
                const hnBox = document.getElementById('request-hide-note');
                if (hnBox) hnBox.checked = !!priv.hideNote;

                // Configure currency selector + amount constraints from the
                // store's default currency (falls back to mint unit).
                const store = dashboardData?.stores?.find(s => s.id === currentStoreId);
                updateAmountInputForStore(store);
            }

            document.getElementById(id).classList.add('visible');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('visible');

            // Cleanup and refresh when closing export modal
            if (id === 'modal-export') {
                // Stop claim checking
                if (exportCheckInterval) {
                    clearInterval(exportCheckInterval);
                    exportCheckInterval = null;
                }
                exportSecrets = null;

                loadDashboard();
            }
        }

        // Check if exported token has been claimed
        async function checkIfTokenClaimed() {
            if (!exportSecrets || exportSecrets.length === 0) return;
            if (!currentStoreId) return;

            try {
                const secretsJson = JSON.stringify(exportSecrets);
                const response = await postWithCsrf(adminUrl,
                    `action=check_proofs_spent&store_id=${encodeURIComponent(currentStoreId)}&secrets=${encodeURIComponent(secretsJson)}`
                );

                const result = await response.json();

                if (result.spent) {
                    // Token has been claimed!
                    showTokenClaimedSuccess();
                }
            } catch (e) {
                console.error('Failed to check if token claimed:', e);
            }
        }

        // Show success when token is claimed
        function showTokenClaimedSuccess() {
            // Stop checking
            if (exportCheckInterval) {
                clearInterval(exportCheckInterval);
                exportCheckInterval = null;
            }

            // Get export modal
            const modal = document.getElementById('modal-export');
            const modalContent = modal.querySelector('.modal');

            // Create success overlay
            const successOverlay = document.createElement('div');
            successOverlay.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(76, 175, 80, 0.95);
                border-radius: 24px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                cursor: pointer;
                animation: fadeIn 0.3s ease;
            `;

            successOverlay.innerHTML = `
                <div style="font-size: 4rem; margin-bottom: 1rem;">🎉</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: white; margin-bottom: 0.5rem;">Token Claimed!</div>
                <div style="font-size: 0.9rem; color: rgba(255,255,255,0.9);">Recipient received the funds</div>
                <div style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 2rem;">Click anywhere to close</div>
            `;

            // Add fadeIn animation
            const style = document.createElement('style');
            style.textContent = '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }';
            document.head.appendChild(style);

            // Click to close
            successOverlay.addEventListener('click', () => {
                closeModal('modal-export');
            });

            modalContent.style.position = 'relative';
            modalContent.appendChild(successOverlay);

            // Update balance
            loadDashboard();
        }

        // Utility: escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Toast
        function showToast(message, type = '') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast show ${type}`;
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>
