<?php
/**
 * CashuPayServer — User Configuration (TEMPLATE)
 * ===============================================
 *
 * Deployment-time settings, as an alternative to environment variables.
 *
 * USAGE
 * -----
 * This file is a *template*. To customize a deployment:
 *
 *   cp user_config.example.php user_config.php
 *
 * then edit `user_config.php` (which is gitignored — your edits won't be
 * touched by upstream pulls). Both files live at the project root, and the
 * loader looks specifically for `user_config.php`.
 *
 * Each setting below is a PHP `define()` line, commented out by default.
 * To activate a setting in your copy:
 *   1. Uncomment the `define()` line
 *   2. Edit the value
 *   3. Restart PHP-FPM / the web server so the constant is picked up
 *
 * Precedence: any value defined here overrides the equivalent environment
 * variable of the same name. A commented-out line falls back to the env
 * var (and then to the built-in default if neither is set).
 *
 * Some settings are read once on first migration and seeded into the
 * database (the free trial below is one of these). For those, editing
 * this file AFTER first install has no effect — the seeded values are
 * authoritative. To re-seed, delete both the relevant `config` rows AND
 * the `free_trial_seeded` marker row, then restart.
 *
 * For backwards compatibility, `includes/config.local.php` continues to
 * work for the CASHUPAY_DATA_DIR override (the only setting that file
 * historically held); new settings should go here instead.
 */

// =============================================================================
// FREE TRIAL  (seeded once on first migration)
// =============================================================================
// While a free trial is active, the platform fees are waived:
//   - dev fee (1%)
//   - per-store hosting fee
// Network fees (Lightning routing, on-chain miner fees) are real sats spent
// on melts and cannot be waived.
//
// Set EITHER threshold below, or BOTH (whichever fires first ends the trial).
// Both unset → no trial at all.
//
// On expiry, the platform fees apply only to revenue earned AFTER the expiry
// instant — revenue accrued during the trial is never retroactively charged.

// CASHUPAY_FREE_TRIAL_UNTIL — calendar end of the trial.
// Accepts either:
//   - A unix timestamp (integer):    1893456000
//   - Any strtotime()-parseable date string:
//       '2027-01-01'
//       '2026-12-31 23:59:59 UTC'
//       '+90 days'        (evaluated at first-migration time, not per request)
// A date in the past at seed time is silently treated as "no trial".
//
// define('CASHUPAY_FREE_TRIAL_UNTIL', '2027-01-01');

// CASHUPAY_FREE_TRIAL_REVENUE_SATS — revenue cap, in sats, summed across
// all stores in the deployment. When cumulative paid-invoice sats reach
// this value, the trial ends.
// Must be a positive integer; zero or negative is silently treated as
// "no trial".
//
// define('CASHUPAY_FREE_TRIAL_REVENUE_SATS', 500000);

// =============================================================================
// AUTO-UPDATE CHANNEL
// =============================================================================
// Which release channel this install tracks. The auto-updater (run from
// cron.php) fetches the latest build attached to the matching channel-* tag
// on https://github.com/BareBits/cashupayserver and overlays it on this
// install, preserving data/ and user_config.php.
//
// Values:
//   'main'    — stable. Receives commits merged to main.
//   'testing' — pre-release. Receives commits pushed to the testing branch.
//
// This sets the deployment-time default. The admin can override it at
// runtime from the Settings page; once overridden, the database value wins.
//
// define('CASHUPAY_UPDATE_CHANNEL', 'main');

// CASHUPAY_AUTO_UPDATE_ENABLED — explicit opt-in for the auto-updater.
// Defaults to false: fresh installs do NOT auto-update. To enable cron-driven
// updates on this install, set this constant to true (or set the env var of
// the same name to a non-empty, non-"0" string). The updater respects the
// CASHUPAY_UPDATE_CHANNEL setting above when fetching.
//
// Operators who don't want auto-update can leave this alone.
//
// define('CASHUPAY_AUTO_UPDATE_ENABLED', true);

// -----------------------------------------------------------------------------
// RECOMMENDED: dedicated auto-update cron line
// -----------------------------------------------------------------------------
// The auto-updater runs in its own isolated endpoint, update.php, so a bad
// update that crashes the main application code cannot stop the updater from
// running, detecting the breakage, and rolling it back. The normal cron line
// (curl .../cron.php) already nudges update.php as a fallback, but that nudge
// is fired from cron.php — which loads the whole application first. If an
// update breaks that bootstrap, cron.php can't run, and the fallback nudge
// never fires.
//
// For maximum resilience, add a SECOND cron line that hits update.php directly.
// It depends on almost nothing in includes/, so it keeps working even when the
// main code is broken — pulling the next (fixed) build and/or rolling back the
// broken one automatically. Run it more often than daily (e.g. every 15 min):
// the update check itself self-throttles to once a day, but a freshly-applied
// build's health re-check (and any pending rollback) happens on the next tick.
//
//   */15 * * * * curl -fsS -H "X-CRON-KEY: YOUR_CRON_KEY" https://your-domain.com/update.php > /dev/null
//
// Find YOUR_CRON_KEY the same place as the main cron line (Settings → Cron).
// update.php is a no-op unless CASHUPAY_AUTO_UPDATE_ENABLED is set, so it's
// harmless to add even before you opt in.
//
// After applying an update, update.php verifies it by fetching health.php
// (also bundled). If the new build fails to boot, update.php restores the most
// recent backup, blocks the broken build's commit so it is never re-applied,
// and emails the address in the notification settings (Settings → Email).

// =============================================================================
// PROVISIONED INSTALLS (external orchestrators, e.g. the WordPress plugin)
// =============================================================================
// These settings are written by an installer that deploys BareBits on the
// operator's behalf — the GPL WordPress companion plugin's "install BareBits
// alongside WordPress" flow is the canonical example. A hand-managed install
// never needs them.

// CASHUPAY_BASE_URL — pin the public base URL of this install. Normally the
// base URL is auto-detected per request (or set in the database); installers
// that know the served URL up front write it here so it is correct from the
// first request and never trusts the Host header. The pin wins over any
// database-stored base_url — remove this line to hand control back to the
// admin UI / auto-detection.
//
// define('CASHUPAY_BASE_URL', 'https://example.com/barebits');

// CASHUPAY_EXTERNAL_CRON — declare that something outside this install
// already requests cron.php every minute (the WordPress plugin pings it from
// WP-cron). The setup wizard then skips the crontab screen, since there is
// nothing for the operator to wire up. Constant wins over the env var of the
// same name; "0" means off.
//
// define('CASHUPAY_EXTERNAL_CRON', true);

// CASHUPAY_MANAGED_INSTALL — declare this a managed single-shop install: the
// orchestrator's plugin embeds and operates BareBits behind exactly one shop.
// Shapes the product for that case — single-store admin UI (no store selector
// or add-store), the shop-owned sections (Products, Customers) and account
// management hidden (login is automatic via SSO tokens), payer email capture
// defaulted off, payer redirects preferring the shop's front page — and
// implies CASHUPAY_EXTERNAL_CRON's cron-screen skip. Constant wins over the
// env var of the same name; "0" means off.
//
// define('CASHUPAY_MANAGED_INSTALL', true);

// CASHUPAY_SHOP_URL — the shop's public front page, used for payer-facing
// redirects (Return to Shop, admin-created invoices) on managed installs.
//
// define('CASHUPAY_SHOP_URL', 'https://example.com');

// CASHUPAY_ADMIN_PASSWORD_HASH — a PHP password_hash() string for the admin
// account. When set on a fresh install, the setup wizard seeds the `admin`
// user from it and skips its password screen; the plaintext stays with the
// orchestrator (the WordPress plugin can reveal it to the site admin).
//
// define('CASHUPAY_ADMIN_PASSWORD_HASH', '$2y$10$...');

// CASHUPAY_SSO_KEY_HASH — SHA-256 hex hash of the orchestrator's SSO key.
// Arms sso.php: POSTing the plaintext key mints a single-use 60-second admin
// login token, which is how "open BareBits from the shop admin" signs the
// operator in without a password prompt.
//
// define('CASHUPAY_SSO_KEY_HASH', 'hex-sha256-of-key');

// CASHUPAY_RETRY_URL_TEMPLATE — URL of the shop-side "retry an expired
// invoice" endpoint; {invoiceId} is substituted. When set, the payment page's
// expired screen offers "Request a new invoice" for e-commerce invoices
// (metadata orderId), landing the customer where the shop can mint a fresh
// one (the WordPress plugin redirects to WooCommerce's order-pay page).
//
// define('CASHUPAY_RETRY_URL_TEMPLATE', 'https://example.com/?cashupay-retry={invoiceId}');

// CASHUPAY_PROVISION_TOKEN_HASH — SHA-256 hex hash of a one-time provisioning
// token. When set, provision.php lets the holder of the matching plaintext
// token collect this install's integration credentials (store id, a freshly
// minted BTCPay-compatible API key, and the cron key) exactly once after the
// setup wizard completes. The installer generates the token, writes only its
// hash here, and keeps the plaintext on its own side. The exchange
// self-invalidates after first use; remove the line to revoke an unused token.
//
// define('CASHUPAY_PROVISION_TOKEN_HASH', 'hex-sha256-of-token');

// =============================================================================
// EMAIL NOTIFICATIONS — SMTP (optional)
// =============================================================================
// Outbound email is opt-in: notifications stay off until the admin enables
// them in the Settings UI. The SMTP settings below are only consulted if
// notifications are turned on for the deployment.
//
// If CASHUPAY_SMTP_HOST is unset, the sender falls back to PHP's built-in
// mail() function, which relies on a working local MTA (sendmail / postfix /
// msmtp). Many shared hosts do not have one, so configuring SMTP explicitly
// is strongly recommended for reliable delivery.
//
// CASHUPAY_SMTP_HOST          SMTP server hostname (e.g. 'smtp.sendgrid.net').
//                             Unset → use PHP mail().
// CASHUPAY_SMTP_PORT          SMTP server port (typical: 587 for STARTTLS,
//                             465 for implicit TLS, 25 for unauthenticated).
// CASHUPAY_SMTP_USERNAME      Auth username. Unset → no auth.
// CASHUPAY_SMTP_PASSWORD      Auth password. Treat this file as a secret.
// CASHUPAY_SMTP_ENCRYPTION    'tls' (STARTTLS, port 587), 'ssl' (implicit
//                             TLS, port 465), or 'none' (cleartext, dev only).
// CASHUPAY_SMTP_FROM_ADDRESS  Envelope + header From address. REQUIRED to send.
// CASHUPAY_SMTP_FROM_NAME     Display name for the From header (default:
//                             'CashuPayServer').
//
// define('CASHUPAY_SMTP_HOST', 'smtp.example.com');
// define('CASHUPAY_SMTP_PORT', 587);
// define('CASHUPAY_SMTP_USERNAME', 'apikey');
// define('CASHUPAY_SMTP_PASSWORD', 'replace-me');
// define('CASHUPAY_SMTP_ENCRYPTION', 'tls');
// define('CASHUPAY_SMTP_FROM_ADDRESS', 'notifications@example.com');
// define('CASHUPAY_SMTP_FROM_NAME', 'CashuPayServer');

// =============================================================================
// AUTO-MELT VIA SUBMARINE SWAP
// =============================================================================
// When a store opts into "Auto-cashout via submarine swap" (instead of the
// Lightning-address path), the cron sweeps the mint balance through a
// reverse submarine swap to the store's on-chain xpub.
//
// Two cost gates protect against sweeping at unfavourable rates. A sweep
// runs only when BOTH are satisfied:
//   1. Mint balance ≥ CASHUPAY_AUTO_MELT_SWAP_MIN_SATS (cheap pre-flight; we
//      don't even fetch a quote below this).
//   2. Best available swap-provider quote's total cost (percent fee +
//      lockup miner fee + claim miner fee estimate) is ≤
//      CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT % of the amount being swept.
//
// Both are display-only knobs in the admin UI: defaults below drive
// behaviour, the UI just shows the active values so operators can size
// their balances. To change them, edit this file and restart PHP-FPM.

// CASHUPAY_AUTO_MELT_SWAP_MIN_SATS — static floor in satoshis. Defaults to
// 5000 (~$5 at typical rates) which covers the swap-provider minimums and
// is the smallest amount where the percent-fee gate has any realistic
// chance of being satisfied.
//
// define('CASHUPAY_AUTO_MELT_SWAP_MIN_SATS', 5000);

// CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT — percent cap on the swap-provider's
// total fees relative to the amount being swept. Defaults to 1.0 (i.e.
// total swap cost must not exceed 1% of the sweep amount). Accepts a
// float; values < 0.1 will almost never be satisfiable in practice.
//
// define('CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT', 1.0);

// =============================================================================
// SUBMARINE-SWAP FEE → MINT FALLBACK (customer checkout)
// =============================================================================
// At checkout, a customer paying via Lightning while the merchant receives
// on-chain normally goes through a submarine swap. For small payments the swap
// cost can dwarf the payment. When the store has a cashu mint enabled (and
// strict-no-mint-fallback is OFF), a prospective swap whose TOTAL cost (percent
// fee + lockup miner fee + claim miner-fee estimate) exceeds EITHER threshold
// below is skipped, and the customer is shown a mint-issued Lightning invoice
// instead.
//
// These are the lowest-precedence defaults. Each layers: per-store column →
// site-wide setting (admin UI) → these config-file constants → 0 (disabled).
// A value of 0 disables that particular check; with both unset/0 there is no
// fee-based fallback (historical behaviour). Edit this file and restart PHP-FPM
// to change the config-file layer.

// CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT — fall back to the mint when the swap's
// total cost exceeds this percent of the invoice amount. Float; 0/unset off.
//
// define('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT', 5.0);

// CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS — fall back to the mint when the swap's
// total cost exceeds this many sats. Integer; 0/unset off.
//
// define('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS', 1000);

// CASHUPAY_STRIKE_URL — destination for the "get a free lightning address"
// Strike links shown in the auto-cashout settings. Defaults to
// 'http://strike.me'. Override to point merchants at a referral/localized URL.
//
// define('CASHUPAY_STRIKE_URL', 'http://strike.me');

// LNURL DIRECT-RECEIVE
// --------------------
// When a store has an auto-cashout Lightning address configured and the
// host supports LUD-21 verify URLs, incoming Lightning payments route
// directly to that address instead of through the cashu mint. An invoice
// smaller than the accumulated dev/hosting fees the store owes
// routes via the mint instead, so the resulting balance can cover the
// owed fees before the merchant payout.
//
// LNURL_RECEIVE_PROBE_TIMEOUT_SEC (default 5): wall-clock budget for the
// per-invoice LNURL probe (well-known + callback). Slower hosts cause
// the probe to fail and the invoice falls back to mint/swap.
//
// define('LNURL_RECEIVE_PROBE_TIMEOUT_SEC', 5);

// --------------------
// STRIKE API RECEIVE
// --------------------
// When a store has a Strike API key configured (Lightning payments settings
// or the setup wizard), it is the FIRST Lightning method tried: invoices are
// created in the merchant's Strike account (BTC-denominated for the exact
// sat amount) and settlement is confirmed by reading the Strike invoice
// back until it reports PAID. The key only needs the create/quote/read
// invoice scopes — it can never spend funds.
//
// With the optional "also accept on-chain payments via Strike" setting the
// invoice's on-chain address is minted in the Strike account too (one
// receive request per invoice; needs the additional
// partner.receive-request.create scope, mainnet only). Settlement of those
// stays with the normal on-chain chain watcher; the store's xpub/static
// address is the fallback when the Strike call fails. See docs/onchain.md.
//
// STRIKE_TIMEOUT_SEC: checkout-path wall-clock budget (seconds) for the
// create+quote round trips while the customer waits (the on-chain receive
// request shares the same budget). Defaults to the same 5s the other
// direct-receive rails use; settlement polls and the save-time probes use
// a 10s budget.
//
// define('STRIKE_TIMEOUT_SEC', 5);
