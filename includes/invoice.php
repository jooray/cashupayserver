<?php
/**
 * CashuPayServer - Invoice Module
 *
 * Invoice creation, management, and payment detection.
 * Supports per-store wallet configuration.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/webhook_sender.php';
require_once __DIR__ . '/notification_sender.php';
require_once __DIR__ . '/urls.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';
require_once __DIR__ . '/onchain/payments.php';
require_once __DIR__ . '/onchain/config.php';
require_once __DIR__ . '/onchain/address_check.php';
require_once __DIR__ . '/rail_order.php';
require_once __DIR__ . '/swap/factory.php';
require_once __DIR__ . '/swap/config.php';
require_once __DIR__ . '/swap/quote_fetcher.php';
require_once __DIR__ . '/swap/poller.php';
require_once __DIR__ . '/crypto/secp256k1.php';
require_once __DIR__ . '/crypto/taproot.php';
require_once __DIR__ . '/lnurl_receive.php';
require_once __DIR__ . '/store_ln_addresses.php';
require_once __DIR__ . '/clink/client.php';
require_once __DIR__ . '/nwc/client.php';
require_once __DIR__ . '/strike/client.php';
require_once __DIR__ . '/fee_redirect.php';
require_once __DIR__ . '/admin_log.php';
require_once __DIR__ . '/store_safety.php';
require_once __DIR__ . '/transfer.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\Proof;
use Cashu\ProofState;

class Invoice {
    /**
     * Statuses a non-settlement transition may overwrite. Settled is terminal:
     * a slower expiry/cancel path that read the row before another poller
     * settled it must not flip it back (and must not emit InvoiceExpired /
     * InvoiceInvalid after InvoiceSettled). Provisional is the offline-cashu
     * intermediate state its reconcile moves to Settled/Invalid.
     */
    public const MUTABLE_STATUSES = ['New', 'Processing', 'Provisional'];

    /**
     * How long past its expiry a `New` mint-rail invoice may stay open while
     * the mint has not confirmed it unpaid. Expiring on the local clock alone
     * gave a payment made in the last seconds an InvoiceExpired followed by
     * InvoiceSettled; past this, an unreachable mint no longer keeps the order
     * open (late payments are still collected by recoverLatePaidInvoices()).
     */
    public const EXPIRY_CONFIRM_WINDOW = 3600;

    /** Seconds after expiry during which a closed quote is re-checked often. */
    public const EXPIRY_RECOVERY_GRACE = 259200; // 72h

    /** After the frequent window a closed quote is re-checked this often, forever. */
    public const EXPIRY_RECOVERY_SLOW_INTERVAL = 86400; // 24h

    /**
     * How long after the mint's own quote expiry a plain UNPAID answer lets the
     * quote id be dropped. A mint's watcher can lag; dropping the only link to
     * a quote that was actually paid makes the money uncollectable.
     */
    public const UNPAID_WRITE_OFF_DELAY = 7 * 86400;

    /** How many expiries one write transaction handles. */
    private const EXPIRY_BATCH_SIZE = 100;

    /** Processing rows are re-driven at most this often (seconds). */
    private const ORPHAN_RECOVERY_COOLDOWN = 300;
    // Per-destination wall-clock budget (seconds) for fetching a bolt11 from
    // an NWC wallet or CLINK noffer service during invoice creation. Tighter
    // than the clients' 10s default because the customer is staring at the
    // checkout spinner while the destination chain walks; settlement polls,
    // the admin save-time probe, and the cron cash-out keep the default. A
    // NWC_TIMEOUT_SEC / CLINK_NOFFER_TIMEOUT_SEC define in user_config.php
    // still overrides this, same as everywhere else those constants apply.
    private const DIRECT_RECEIVE_TIMEOUT_SEC = 5;

    /** Checkout-path budget for one NWC/noffer destination (see above). */
    private static function directReceiveTimeoutSec(string $overrideConst): int {
        return defined($overrideConst)
            ? (int)constant($overrideConst)
            : self::DIRECT_RECEIVE_TIMEOUT_SEC;
    }

    // ---- Invoice-memo privacy resolution ------------------------------------
    // The store name and the payer-facing note can each be embedded in the memo
    // a payer's wallet records (noffer NIP-69 description + cashu NUT-18 memo).
    // Visibility resolves per field with the per-invoice override winning:
    //   1. invoice metadata flag (hideStoreName / hideNote), when present;
    //   2. else the per-store default column (hide_store_name_on_invoice /
    //      hide_note_on_invoice), when set (1 = hide);
    //   3. else show (the product default).
    // Both layers default to "show", so existing stores/invoices are unchanged.

    /** Coerce a metadata/DB flag (bool, 1/0, "1"/"0", "true"/"false") to bool. */
    private static function flagIsOn($val): bool {
        if (is_bool($val)) { return $val; }
        if (is_int($val))  { return $val === 1; }
        return filter_var((string)$val, FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether the store name should appear in this invoice's memo. */
    public static function showStoreNameOnInvoice(array $store, ?array $metadata = null): bool {
        if (is_array($metadata) && array_key_exists('hideStoreName', $metadata)) {
            return !self::flagIsOn($metadata['hideStoreName']);
        }
        $v = $store['hide_store_name_on_invoice'] ?? null;
        if ($v !== null && $v !== '') {
            return (int)$v !== 1;
        }
        return true;
    }

    /** Whether the payer-facing note should appear in this invoice's memo. */
    public static function showNoteOnInvoice(array $store, ?array $metadata = null): bool {
        if (is_array($metadata) && array_key_exists('hideNote', $metadata)) {
            return !self::flagIsOn($metadata['hideNote']);
        }
        $v = $store['hide_note_on_invoice'] ?? null;
        if ($v !== null && $v !== '') {
            return (int)$v !== 1;
        }
        return true;
    }

    // ---- Payment order on the customer invoice page -------------------------
    // Per-store preference (stores.invoice_first_method) for which method is
    // active, and listed first, when an invoice offers both on-chain and
    // Lightning. Cashu is never preferred: it is only shown when the store has
    // opted into offline acceptance and always sits last.

    public const FIRST_METHOD_ONCHAIN = 'onchain';
    public const FIRST_METHOD_LIGHTNING = 'lightning';
    public const FIRST_METHODS = [self::FIRST_METHOD_ONCHAIN, self::FIRST_METHOD_LIGHTNING];

    /**
     * The store's preferred first method. NULL / '' / any unknown value
     * resolves to on-chain, the historical default, so rows that predate the
     * setting (and any value written by hand) are unchanged.
     */
    public static function preferredFirstMethod(array $store): string {
        $v = strtolower(trim((string)($store['invoice_first_method'] ?? '')));
        return in_array($v, self::FIRST_METHODS, true) ? $v : self::FIRST_METHOD_ONCHAIN;
    }

    /**
     * Which method the payment page shows on load for ONE invoice: the store's
     * preference when that method is offered, else the other of the pair, else
     * Cashu. Also returns 'cashu' when the invoice offers nothing at all —
     * the pre-setting rule did the same, and the page keys its brand row off
     * this value.
     */
    public static function firstPaymentMethod(array $store, bool $hasOnchain, bool $hasLightning, bool $hasCashu): string {
        $order = self::preferredFirstMethod($store) === self::FIRST_METHOD_LIGHTNING
            ? ['lightning', 'onchain']
            : ['onchain', 'lightning'];
        $offered = ['onchain' => $hasOnchain, 'lightning' => $hasLightning];
        foreach ($order as $method) {
            if ($offered[$method]) {
                return $method;
            }
        }
        return 'cashu';
    }

    /**
     * Tab order for the payment page: the preferred method first, then the
     * other of the pair, then Cashu — so the default tab always sits first.
     *
     * @return string[]
     */
    public static function paymentMethodTabOrder(array $store): array {
        return self::preferredFirstMethod($store) === self::FIRST_METHOD_LIGHTNING
            ? ['lightning', 'onchain', 'cashu']
            : ['onchain', 'lightning', 'cashu'];
    }

    /**
     * Build the payer-facing memo embedded in an invoice — the store name, the
     * order reference (metadata.orderId, set by the WooCommerce gateway), and
     * the invoice's note (metadata.itemDesc) joined with " - ". Name and note
     * are each included only when their privacy resolution (see
     * showStoreNameOnInvoice / showNoteOnInvoice) says to show them; the order
     * reference is deliberately NOT privacy-gated — letting the receiving
     * wallet match a payment to its order is the memo's job. mb-safe so
     * multibyte names/notes aren't cut mid-character. Returns '' when there's
     * nothing to say, so callers can omit the memo entirely.
     *
     * $maxLen caps the result (mb-safe); pass <= 0 to leave it uncapped. When
     * capping, the order reference survives: the note is shortened first, then
     * the store name, before the whole string is hard-capped.
     */
    public static function buildInvoiceMemo(array $store, ?array $metadata = null, int $maxLen = 100): string {
        $name = '';
        if (self::showStoreNameOnInvoice($store, $metadata)) {
            $name = trim((string)($store['name'] ?? ''));
        }
        $order = '';
        if (is_array($metadata) && isset($metadata['orderId'])
            && is_scalar($metadata['orderId']) && !is_bool($metadata['orderId'])) {
            $ref = trim((string)$metadata['orderId']);
            if ($ref !== '') { $order = 'Order ' . $ref; }
        }
        $note = '';
        if (self::showNoteOnInvoice($store, $metadata)) {
            // itemDesc is the payer-facing note convention used across the app
            // (see payment.php / pay.php); reuse it so the memo matches.
            $note = is_array($metadata) ? trim((string)($metadata['itemDesc'] ?? '')) : '';
        }

        $compose = static function (string $name, string $order, string $note): string {
            $parts = [];
            foreach ([$name, $order, $note] as $p) {
                if ($p !== '') { $parts[] = $p; }
            }
            return implode(' - ', $parts);
        };

        $memo = $compose($name, $order, $note);
        if ($maxLen > 0 && mb_strlen($memo) > $maxLen) {
            if ($order !== '' && $note !== '') {
                $note = rtrim(mb_substr($note, 0, max(0, mb_strlen($note) - (mb_strlen($memo) - $maxLen))));
                $memo = $compose($name, $order, $note);
            }
            if ($order !== '' && $name !== '' && mb_strlen($memo) > $maxLen) {
                $name = rtrim(mb_substr($name, 0, max(0, mb_strlen($name) - (mb_strlen($memo) - $maxLen))));
                $memo = $compose($name, $order, $note);
            }
            if (mb_strlen($memo) > $maxLen) {
                $memo = rtrim(mb_substr($memo, 0, $maxLen));
            }
        }
        return $memo;
    }

    /**
     * Best-effort, spec-compliant memo for an externally-minted Lightning
     * invoice we request as the payer — currently the CLINK noffer rail's
     * NIP-69 `description`. We surface the store name, the order reference
     * (metadata.orderId) and the invoice's payer-facing note
     * (metadata.itemDesc) when present and not hidden by the store/invoice
     * privacy settings, so the receiving wallet *can* label the payment in its
     * history, mirroring the text the payment page shows.
     *
     * This is only a request: per NIP-69 the receiving service may honor or
     * ignore the description and has the final say on the invoice's actual
     * memo. Capped at 100 chars to stay within the CLINK spec (the reference
     * @shocknet/clink-sdk rejects longer descriptions). Returns '' when there's
     * nothing to say, so callers can omit the field entirely.
     */
    public static function nofferMemo(array $store, ?array $metadata = null): string {
        return self::buildInvoiceMemo($store, $metadata, 100);
    }

    /**
     * Payer-facing reason for a failed NWC make_invoice attempt. Maps the
     * structured NIP-47 code (or the known local failure shapes) onto a fixed
     * vocabulary — never echoes wallet- or relay-provided text, since the
     * result lands on the public payment page and raw NWC errors can leak
     * relay URLs from the (secret-bearing) connection string.
     */
    private static function describeNwcFailure(Throwable $e): string {
        if ($e instanceof NwcException && $e->nwcCode !== '') {
            switch ($e->nwcCode) {
                case 'UNAUTHORIZED':
                case 'RESTRICTED':
                    return 'the wallet rejected the request (check the connection\'s permissions)';
                case 'RATE_LIMITED':
                    return 'the wallet is rate-limiting requests';
                case 'INSUFFICIENT_BALANCE':
                case 'QUOTA_EXCEEDED':
                    return 'the wallet refused to issue an invoice';
                default:
                    return 'the wallet reported an error';
            }
        }
        // '' code = local/transport failure; the timeout throw is the one
        // local shape worth distinguishing for the payer.
        if ($e instanceof NwcException && strpos($e->getMessage(), 'No response from NWC wallet') === 0) {
            return 'no response from the wallet (timed out)';
        }
        return 'the wallet could not be reached';
    }

    /**
     * Payer-facing reason for a failed CLINK noffer invoice request, keyed on
     * the structured NIP-69 error code. Same sanitization contract as
     * describeNwcFailure: fixed phrases only.
     */
    private static function describeNofferFailure(Throwable $e): string {
        if ($e instanceof ClinkException) {
            switch ($e->clinkCode) {
                case 1: return 'the configured offer is invalid';
                case 3: return 'the configured offer has expired';
                case 4: return 'the service does not support this request';
                case 5: return 'the amount is not accepted for this offer';
                case 2: return 'no response or a temporary failure from the service';
            }
        }
        return 'the service could not be reached';
    }

    /**
     * Collapse repeated {type, reason} pairs (e.g. two NWC connections both
     * timing out) and cap the list so the payment-page banner and the JSON
     * column stay bounded no matter how many destinations are configured.
     */
    private static function dedupeReceiveErrors(array $errors): array {
        $seen = [];
        $out = [];
        foreach ($errors as $err) {
            $key = $err['type'] . '|' . $err['reason'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $err;
            if (count($out) >= 10) {
                break;
            }
        }
        return $out;
    }

    /**
     * Create a new invoice
     *
     * Uses per-store mint configuration and supports multi-mint fallback.
     */
    public static function create(string $storeId, array $options): array {
        $amount = $options['amount'];
        $currency = $options['currency'] ?? 'sat';
        $metadata = $options['metadata'] ?? null;
        $checkout = $options['checkout'] ?? null;
        // Long-lived (shareable) invoice: lives for $lifetimeSec instead of the
        // checkout window, Lightning re-sourced by the payment page as the
        // bolt11 lapses, fiat re-quoted on page load (unless fee-redirected:
        // then the sats are locked at creation, see requoteForView). Set by
        // the admin Request modal / self-serve page / API opt-in — see
        // Config::getSharedInvoiceLifetime.
        $longLived = !empty($options['long_lived']);
        $lifetimeSec = isset($options['lifetime_sec'])
            ? max(60, (int)$options['lifetime_sec'])
            : Config::getInvoiceExpiration();

        // Get store configuration
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $cashuConfigured = Config::isStoreConfigured($storeId);
        // A store is "on-chain configured" if it has either an xpub (default
        // mode) or a static receive address (alternative mode for merchants
        // without an xpub). Treating only xpub as configured caused
        // static-mode invoices to be created without an on-chain payment
        // method block.
        $onchainMode = $store['onchain_address_mode'] ?? 'xpub';
        $onchainConfigured = ($onchainMode === 'static')
            ? !empty($store['onchain_static_address'])
            : !empty($store['onchain_xpub']);
        // Ordered direct-receive chain (Lightning address / NWC / noffer),
        // loaded once here because it is a payment rail in its own right: a
        // store whose only rails are Lightning destinations (the wizard's
        // "skip on-chain, skip mints, add NWC/noffer" outcome) fetches its
        // BOLT11 straight from the merchant's wallet, needing neither a mint
        // nor an xpub.
        $destinations = StoreLnAddresses::destinationsForStore($storeId);
        if (!$cashuConfigured && !$onchainConfigured && $destinations === []) {
            throw new Exception(
                'Store has no payment methods configured. Add a Cashu mint, an '
                . 'on-chain xpub, or a Lightning destination (address, NWC, or noffer).'
            );
        }
        // Strike on-chain: the operator opted to mint the invoice's on-chain
        // address in their Strike account (a fresh address per invoice via a
        // receive request). Only live when a Strike key is configured and the
        // store's on-chain network is mainnet — Strike itself is mainnet-only,
        // and the chain watcher polls the store's provider, which follows
        // onchain_network. A store with no xpub/static address at all still
        // defaults onchain_network to mainnet, so Strike can be its only
        // on-chain source. The keys are walked in chain priority order.
        $strikeOnchainKeys = [];
        if (!empty($store['onchain_strike_enabled'])
            && (($store['onchain_network'] ?? 'mainnet') ?: 'mainnet') === 'mainnet') {
            foreach ($destinations as $dest) {
                if ($dest['type'] === StoreLnAddresses::TYPE_STRIKE) {
                    $strikeOnchainKeys[] = $dest['value'];
                }
            }
        }
        // Whether to OFFER the on-chain rail to customers. A store can keep its
        // xpub configured (submarine swaps still settle on-chain to it) while
        // turning off the customer-facing pay-to-address for a Lightning-only
        // checkout. The swap path below keeps using $onchainConfigured — it
        // needs the xpub regardless of what the customer is shown. Strike
        // on-chain makes the rail offerable even without an xpub/static
        // address (the address then has no local fallback source).
        $onchainOffered = ($onchainConfigured || $strikeOnchainKeys !== [])
            && OnchainConfig::isEnabledForStore($storeId);

        $exchangeFee = (float)($store['exchange_fee_percent'] ?? 0);
        $primaryProvider = $store['price_provider_primary'] ?? 'coingecko';
        $secondaryProvider = $store['price_provider_secondary'] ?? 'binance';

        // Get exchange rate for fiat currencies (used by both payment methods).
        $exchangeRate = null;
        if (!in_array(strtoupper($currency), ['SAT', 'SATS', 'BTC'])) {
            $exchangeRate = ExchangeRates::getBtcPrice($currency, $primaryProvider, $secondaryProvider);
        }

        // ---- Fee-redirect path: when a fee (dev / hosting) is
        // already owed in an amount >= this invoice, route the rails that fee
        // can cover straight to its destination instead of the merchant. The
        // remaining offered rails fall through to the normal merchant logic
        // below, so an invoice can be mixed (e.g. lightning -> fee payee,
        // on-chain -> merchant). Whichever rail the customer actually pays
        // decides attribution at settlement (see Invoice::railIsFeeRouted); any
        // fee not collected this way is still melted out by the cron. A single
        // fee owns whichever rails it covers — see FeeRedirect::decide. ----
        $invoiceSats = (int) ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
        // Direct-receive destinations (LNURL addresses / CLINK noffers) make the
        // store lightning-capable independent of the auto-cashout toggle: an
        // invoice can fetch a bolt11 from a destination whether or not
        // threshold-based cashout is enabled. The toggle only governs the cron
        // threshold-melt of accumulated mint balance, not invoice creation.
        $lightningCapable = $cashuConfigured
            || $destinations !== []
            || (SwapsConfig::isEnabledForStore($storeId) && $onchainConfigured);
        $offeredRails = [];
        if ($lightningCapable)   { $offeredRails[] = 'lightning'; }
        if ($onchainOffered)     { $offeredRails[] = 'onchain'; }

        // Long-lived invoices take part too: a fee-redirected invoice locks its
        // sats at creation (fees are owed in sats, so there is no fiat
        // re-quote — see requoteForView) and a lapsed fee bolt11 is re-fetched
        // from the SAME fee payee at that amount (see refreshLightning), so the
        // gate decided here holds for the invoice's whole life.
        $feeRoute       = FeeRedirect::decide($storeId, $store, $invoiceSats, $offeredRails);
        $feeNote        = $feeRoute['note'] ?? null;
        $feeRails       = $feeRoute['rails'] ?? [];
        $feeDestination = $feeRoute['destination'] ?? null;
        // Per-rail fee destinations (null = that rail stays with the merchant).
        $feeLightning   = $feeRoute['lightning'] ?? null; // ['bolt11','verify_url','destination']
        $feeOnchain     = $feeRoute['onchain'] ?? null;   // ['address','index','tip_height','destination']

        // ---- LNURL direct-receive path: route LN payment straight to the
        // merchant's auto-cashout LN address when the host supports LUD-21
        // (verify URL) so we can detect settlement without running the LN
        // node ourselves. Wins over swap and mint when eligible. ----
        $lnurlAttempt = null;          // ['bolt11','verify_url','amount_sats'] on success
        $nofferAttempt = null;         // CLINK noffer receive context on success
        $nwcAttempt = null;            // NWC (NIP-47) receive context on success
        $strikeAttempt = null;         // Strike API receive context on success
        // Sanitized [{type, reason}] records of destinations that failed while
        // walking the chain, persisted as invoices.receive_errors and shown to
        // the payer on the payment page. Reasons are fixed server-side phrases
        // — never wallet/host-provided text, URIs, or addresses (the page is
        // public and NWC URIs embed the wallet secret).
        $receiveErrors = [];
        // Ordered fallback chain ($destinations, loaded with the rails gate
        // above): try each destination (Lightning address via LNURL, NWC, or
        // CLINK noffer via Nostr) in priority order until one yields a usable
        // invoice. Walked regardless of the auto-cashout (threshold-melt)
        // toggle — configuring a destination is enough to direct-receive to it;
        // the toggle only governs the cron threshold-melt of mint balance. The
        // single auto_melt_address column was replaced by store_ln_addresses.
        // Skip the merchant lightning rail entirely when a fee owns it — the
        // single lightning option on this invoice is the fee LNURL's bolt11.
        //
        // Fee collection never reroutes this chain: owed fees are either taken
        // by the fee-redirect path above (a fee large enough to cover the whole
        // invoice claims the rail outright) or melted from mint balance by the
        // cron's DevFee::settleStore pass. The old fees-due override — which
        // parked payments on the mint rail for immediate collection — was
        // removed in favor of those two paths.
        // Generated up front (rather than at insert time below) so the Strike
        // rail can stamp it into the Strike invoice's correlationId, letting
        // the merchant match the two in Strike's dashboard.
        $invoiceId = Database::generateId('inv');
        if ($feeLightning === null && !empty($destinations)) {
            $lnurlTargetSats = (int) ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
            if ($lnurlTargetSats > 0) {
                $direct = self::sourceDirectReceive(
                    $storeId, $store, $destinations, $lnurlTargetSats, $metadata, $invoiceId, $receiveErrors
                );
                if ($direct !== null) {
                    switch ($direct['type']) {
                        case 'strike': $strikeAttempt = $direct['attempt']; break;
                        case 'noffer': $nofferAttempt = $direct['attempt']; break;
                        case 'nwc':    $nwcAttempt = $direct['attempt']; break;
                        case 'lnurl':  $lnurlAttempt = $direct['attempt']; break;
                    }
                }
            }
        }

        // ---- Submarine-swap path: replaces the cashu mint with a non-custodial
        // LN→on-chain swap that settles directly to the merchant's xpub. ----
        // Suppressed when a fee owns the lightning rail (the fee supplies the
        // bolt11) or the on-chain rail (a swap invoice must stay lightning-only,
        // so we don't pair it with a fee-owned on-chain address).
        $swapAttempt = null; // populated by self::trySwapCreate on success
        // Set when strict-no-mint-fallback suppressed the mint path after a
        // failed swap (invoice proceeds on-chain only); keeps the mint block
        // below from quoting in violation of strict mode.
        $strictMintFallbackBlocked = false;
        if ($lnurlAttempt === null && $nofferAttempt === null && $nwcAttempt === null
            && $strikeAttempt === null
            && $feeLightning === null && $feeOnchain === null
            && SwapsConfig::isEnabledForStore($storeId) && $onchainConfigured) {
            // Target = what the merchant wants to receive on-chain in sats.
            $targetSats = ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
            $swapFailures = []; // per-provider reasons, populated by trySwapCreate

            // Fee-too-high → mint fallback thresholds. Only meaningful when this
            // store has a cashu mint to fall back to, and only when strict mode
            // is OFF — strict_no_mint_fallback means "never fall back to mint",
            // so the fee thresholds are intentionally inert under it. When
            // inactive we pass 0/0, which disables the gate in trySwapCreate
            // (swap proceeds regardless of fee, preserving prior behaviour).
            $feeMaxPct = 0.0;
            $feeMaxSats = 0;
            if ($cashuConfigured && !SwapsConfig::strictNoMintFallbackForStore($storeId)) {
                $thresholds = SwapsConfig::effectiveFeeFallbackForStore($storeId);
                $feeMaxPct = $thresholds['pct'];
                $feeMaxSats = $thresholds['sats'];
            }

            $swapAttempt = self::trySwapCreate(
                $storeId, $store, $targetSats, $swapFailures, $feeMaxPct, $feeMaxSats
            );
            if ($swapAttempt === null && SwapsConfig::strictNoMintFallbackForStore($storeId)) {
                // Surface each provider's reason so the operator can act
                // (typically: "Boltz: amount 10000 sat outside range [50000, 5000000]").
                $detail = $swapFailures
                    ? ' Provider attempts: ' . implode('; ', $swapFailures) . '.'
                    : '';
                if (!$onchainOffered) {
                    throw new Exception(
                        'Submarine swap could not be created for ' . $targetSats . ' sat target.'
                        . ' Strict mode is on (no mint fallback).' . $detail
                    );
                }
                // Strict mode forbids the MINT fallback, not the on-chain rail.
                // With an on-chain address on offer the invoice must still come
                // out payable (on-chain only): a Lightning-path failure never
                // aborts creation while another rail can serve. The payer sees
                // a fixed phrase; provider detail stays in the logs.
                error_log(sprintf(
                    '[swap] strict mode: swap failed for store=%s target=%d sat; serving on-chain only.%s',
                    $storeId, $targetSats, $detail
                ));
                $receiveErrors[] = [
                    'type' => 'swap',
                    'reason' => 'a Lightning invoice could not be created for this amount',
                ];
                AdminLog::log('swap', 'checkout', $storeId, null, null,
                    'strict mode: no swap provider accepted the ' . $targetSats
                    . ' sat target; invoice created on-chain only.' . $detail);
                $strictMintFallbackBlocked = true;
            }
        }

        // ---- Cashu / Lightning path: try mint quote(s) if a mint is configured ----
        // Skipped when the swap path won — the swap supplies its own BOLT11 and
        // the customer must pay that exact invoice (mint would create a different one).
        $quote = null;
        $usedMintUrl = null;
        $amountInMintUnit = null;
        $recoveringMints = [];
        if ($cashuConfigured && !$strictMintFallbackBlocked && $swapAttempt === null
            && $lnurlAttempt === null && $strikeAttempt === null
            && $nofferAttempt === null && $nwcAttempt === null && $feeLightning === null) {
            $mq = self::sourceMintQuote($storeId, $store, (string)$amount, $currency, $onchainConfigured);
            $quote = $mq['quote'];
            $usedMintUrl = $mq['mint_url'];
            $amountInMintUnit = $mq['amount'];
            $recoveringMints = $mq['recovering'];
            $lastError = $mq['last_error'];
            if ($quote === null && !$onchainConfigured) {
                if ($lastError === null && !empty($recoveringMints)) {
                    // Every candidate mint is still being prepared — nothing
                    // was created, so the caller can simply retry later.
                    throw new Exception(self::RECOVERY_MESSAGE);
                }
                throw new Exception(
                    'Failed to get mint quote from all configured mints. '
                    . 'Last error: ' . ($lastError ? $lastError->getMessage() : 'Unknown')
                );
            }
        }

        // ---- On-chain path: allocate a receive address ----
        // The "normal on-chain route" is always offered when the store has an
        // xpub, in parallel with whatever Lightning rail (swap / LNURL / noffer
        // / mint) this invoice landed on. A swap (or LN address) is only a
        // convenience Lightning option for merchants with no inbound liquidity;
        // it must not disable the on-chain address. The rails settle
        // independently: the on-chain poller keys off onchain_address (not
        // payment_rail), settlement is status-guarded (settle-once), and the
        // swap's held invoice is released by SwapPoller::expireStale() once the
        // invoice expires. Only skipped when a fee payee owns the on-chain rail
        // (its address is set below instead of the merchant's).
        //
        // In xpub mode the allocation derives a fresh address per invoice. In
        // static-address mode it returns the shared address plus a per-invoice
        // tweak (in sats) that makes the expected total unique among open
        // invoices, so incoming txs can be attributed by exact amount match.
        $onchainAddress = null;
        $onchainIndex = null;
        $onchainAmountSat = null;
        $onchainAmountTweakSats = null;
        $onchainCreatedTipHeight = null;
        // Set when the address was minted in the merchant's Strike account.
        // Persisted for dashboard reconciliation AND read by the chain
        // watcher: a Strike address is invoice-unique, so pollInvoice never
        // applies static-mode amount matching to it.
        $strikeReceiveRequestId = null;
        if ($onchainOffered && $feeOnchain === null) {
            $baseAmountSat = (int)ExchangeRates::convertToSats((string)$amount, $currency, 'sat');

            // ---- Address sources, walked in the store's configured order
            // (RailOrder::onchainOrderFromRow; default Strike first, local
            // xpub/static as fallback). 'strike' mints a fresh address in the
            // merchant's Strike account; 'local' is the xpub-derived / static
            // allocation. Whichever source first yields an address wins; a
            // source's failure falls through to the next (and is only surfaced
            // to the payer when NO source produced an address — a working
            // on-chain rail shouldn't carry a scary banner). ----
            $strikeOnchainErrors = [];
            // Local-allocation failure deferred so a later source can still
            // rescue the rail (relevant in local-first order); thrown at the
            // end only when nothing produced an address. In the default
            // Strike-first order local runs last, so this matches the old
            // throw-immediately behavior exactly.
            $localAllocationError = null;
            foreach (RailOrder::onchainOrderFromRow($store) as $onchainSource) {
                if ($onchainAddress !== null) {
                    break;
                }
                if ($onchainSource === 'strike') {
                    if ($strikeOnchainKeys === [] || $baseAmountSat <= 0) {
                        continue;
                    }
                    // Keys walk in priority order, mirroring the Lightning
                    // chain above.
                    foreach ($strikeOnchainKeys as $priority => $skey) {
                        try {
                            $made = StrikeClient::createOnchainReceiveRequest(
                                $skey,
                                $baseAmountSat,
                                self::directReceiveTimeoutSec('STRIKE_TIMEOUT_SEC')
                            );
                            // Never watch (or show a customer) a string we can't
                            // verify is a mainnet Bitcoin address.
                            $addrCheck = AddressCheck::validate($made['address'], 'mainnet');
                            if (!$addrCheck['valid']) {
                                throw new StrikeException('Strike returned an invalid on-chain address');
                            }
                        } catch (Throwable $e) {
                            error_log(sprintf(
                                '[strike-onchain] receive request failed store=%s priority=%d dest=%s: %s; falling back',
                                $storeId, $priority, StrikeClient::maskKey($skey), $e->getMessage()
                            ));
                            $strikeOnchainErrors[] = [
                                'type' => 'strike',
                                'reason' => StrikeClient::describeFailure($e),
                            ];
                            AdminLog::log('strike', 'onchain', $storeId, null,
                                StrikeClient::maskKey($skey), $e->getMessage());
                            continue;
                        }
                        $onchainAddress = $made['address'];
                        $onchainIndex = null;
                        $onchainAmountTweakSats = null;
                        $onchainAmountSat = $baseAmountSat;
                        $strikeReceiveRequestId = $made['receive_request_id'];
                        $onchainCreatedTipHeight = OnchainPayments::currentTipBestEffort($store);
                        if ($priority > 0) {
                            error_log("[strike-onchain] using fallback Strike key store={$storeId} priority={$priority}");
                        }
                        break;
                    }
                    continue;
                }

                // 'local': the store's xpub-derived / static-address source.
                if (!$onchainConfigured) {
                    continue;
                }
                try {
                    $allocation = OnchainPayments::allocateAddress($storeId, $baseAmountSat);
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[onchain] local address allocation failed store=%s: %s',
                        $storeId, $e->getMessage()
                    ));
                    $localAllocationError = $e;
                    continue;
                }
                if ($allocation !== null) {
                    $onchainAddress = $allocation['address'];
                    $onchainIndex = $allocation['index'];
                    $onchainCreatedTipHeight = $allocation['tip_height'] ?? null;
                    $tweak = $allocation['tweak'] ?? null;
                    $onchainAmountTweakSats = $tweak;
                    $onchainAmountSat = $baseAmountSat + ($tweak !== null ? (int)$tweak : 0);
                }
            }

            // Local allocation failed AND no other source rescued the rail:
            // abort invoice creation as before (payment slots exhausted gets
            // its operator-actionable message; anything else propagates).
            if ($onchainAddress === null && $localAllocationError !== null) {
                if ($localAllocationError instanceof RuntimeException
                        && $localAllocationError->getMessage() === OnchainPayments::ERR_TWEAK_SLOTS_EXHAUSTED) {
                    throw new RuntimeException(
                        'All on-chain payment slots are temporarily reserved. Please try again in a few minutes.'
                    );
                }
                throw $localAllocationError;
            }

            // Strike failed AND no local source rescued the rail: tell the
            // payer why on-chain is missing from this invoice.
            if ($onchainAddress === null && $strikeOnchainErrors !== []) {
                foreach ($strikeOnchainErrors as $err) {
                    $receiveErrors[] = $err;
                }
            }
        }

        // Fee-redirect on-chain rail: the fee payee's xpub-derived address
        // (allocated in FeeRedirect::buildRails) replaces the merchant's
        // on-chain rail on this invoice. The customer pays the exact invoice
        // amount — no uniqueness tweak, since each fee address is fresh.
        if ($feeOnchain !== null) {
            $onchainAddress = $feeOnchain['address'];
            $onchainIndex = $feeOnchain['index'] ?? null;
            $onchainAmountSat = $invoiceSats;
            $onchainAmountTweakSats = null;
            $onchainCreatedTipHeight = $feeOnchain['tip_height'] ?? null;
        }

        // Calculate expiration. A mint quote carries its own (short, ~minutes)
        // expiry; everything else uses the default invoice window.
        $defaultExpiration = time() + $lifetimeSec;
        $expiration = ($quote && isset($quote->expiry) && !$longLived)
            ? $quote->expiry
            : $defaultExpiration;
        // An explicitly requested lifetime (checkout.expirationMinutes on an
        // order invoice) is an upper bound the caller relies on: a mint quote
        // that outlives it must not stretch the invoice past it. Without an
        // explicit lifetime the historical rule stands — the quote's expiry
        // wins over the default window, even when it is longer.
        if (!$longLived && isset($options['lifetime_sec']) && $expiration > $defaultExpiration) {
            $expiration = $defaultExpiration;
        }
        // When an on-chain address is offered alongside a shorter-lived
        // Lightning rail (e.g. a ~15-min mint quote), don't let that rail cut
        // the on-chain window short — keep the invoice alive for the longer of
        // the two. The Lightning bolt11 may lapse first (its wallet shows it as
        // expired); the on-chain address stays payable for the full window.
        if ($onchainAddress !== null && $expiration < $defaultExpiration) {
            $expiration = $defaultExpiration;
        }

        $now = Database::timestamp();

        // Decide payment_rail + final field values
        $lnurlVerifyUrl = null;
        // The Lightning destination the bolt11 points at (LN address / LNURL,
        // or the noffer string for the CLINK rail). Only set for the direct-
        // receive rails; NULL for mint/swap/onchain.
        $lnDestination = null;
        // CLINK noffer receive context, persisted so the payment page and cron
        // can subscribe for the kind-21001 payment receipt. Only set on the
        // 'noffer' rail.
        $nofferRelay = null;
        $nofferReceiverPubkey = null;
        $nofferEphemeralSk = null;
        $nofferEphemeralPubkey = null;
        $nofferRequestEventId = null;
        $nofferCreatedAt = null;
        // NWC receive context: connection URI + payment hash, persisted so the
        // payment-page poll and cron can run lookup_invoice. Only set on the
        // 'nwc' rail.
        $nwcUri = null;
        $nwcPaymentHash = null;
        // Strike receive context: the Strike invoice id the settlement polls
        // read back, plus the API key it was created with (secret-bearing,
        // like nwc_uri — never rides an API/browser payload). Only set on the
        // 'strike' rail.
        $strikeInvoiceId = null;
        $strikeApiKey = null;
        if ($feeLightning !== null) {
            // Fee-redirect lightning rail: the customer pays the fee LNURL's
            // bolt11 directly. Rides payment_rail='lnaddress' so the existing
            // verify-URL poller detects settlement (the LNURL poller is the
            // only one gated on payment_rail).
            $paymentRail = 'lnaddress';
            $bolt11Final = $feeLightning['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $invoiceSats;
            $lnurlVerifyUrl = $feeLightning['verify_url'];
            $lnDestination = $feeLightning['destination'] ?? null;
        } elseif ($strikeAttempt !== null) {
            $paymentRail = 'strike';
            $bolt11Final = $strikeAttempt['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $strikeAttempt['amount_sats'];
            // Masked label only — ln_destination surfaces in the admin invoice
            // view and API payloads, and the raw key is the account credential.
            $lnDestination = StrikeClient::maskKey($strikeAttempt['api_key']);
            $strikeInvoiceId = $strikeAttempt['strike_invoice_id'];
            $strikeApiKey = $strikeAttempt['api_key'];
        } elseif ($lnurlAttempt !== null) {
            $paymentRail = 'lnaddress';
            $bolt11Final = $lnurlAttempt['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $lnurlAttempt['amount_sats'];
            $lnurlVerifyUrl = $lnurlAttempt['verify_url'];
            $lnDestination = $lnurlAttempt['address'] ?? null;
        } elseif ($nwcAttempt !== null) {
            $paymentRail = 'nwc';
            $bolt11Final = $nwcAttempt['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $nwcAttempt['amount_sats'];
            // Masked label only — ln_destination surfaces in the admin invoice
            // view and API payloads, and the raw URI embeds the wallet secret.
            $lnDestination = NwcUri::displayLabel($nwcAttempt['uri']);
            $nwcUri = $nwcAttempt['uri'];
            $nwcPaymentHash = $nwcAttempt['payment_hash'];
        } elseif ($nofferAttempt !== null) {
            $paymentRail = 'noffer';
            $bolt11Final = $nofferAttempt['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $nofferAttempt['amount_sats'];
            $lnDestination = $nofferAttempt['noffer'];
            $nofferRelay = $nofferAttempt['relay'];
            $nofferReceiverPubkey = $nofferAttempt['receiver_pubkey'];
            $nofferEphemeralSk = $nofferAttempt['ephemeral_sk'];
            $nofferEphemeralPubkey = $nofferAttempt['ephemeral_pubkey'];
            $nofferRequestEventId = $nofferAttempt['request_event_id'];
            $nofferCreatedAt = $nofferAttempt['created_at'];
        } elseif ($swapAttempt !== null) {
            $paymentRail = 'swap';
            $bolt11Final = $swapAttempt['swap']->invoice;
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $swapAttempt['swap']->invoiceAmountSats;
        } else {
            if ($quote === null && $onchainAddress === null) {
                // No rail produced anything payable. Refuse rather than insert
                // an invoice with a null bolt11 and no on-chain address — the
                // customer would land on a payment page with no way to pay.
                // Typical trigger: a Lightning-destination-only store whose
                // wallet is offline, so every direct-receive attempt failed
                // and there is no mint/xpub to fall back to.
                $hints = [];
                if ($destinations !== []) {
                    $hints[] = 'none of the store\'s Lightning destinations '
                        . '(address / NWC / noffer) returned an invoice — check '
                        . 'that the receiving wallet is online';
                }
                if ($cashuConfigured) {
                    $hints[] = 'no configured mint produced a quote';
                }
                if ($onchainConfigured && !$onchainOffered) {
                    $hints[] = 'the on-chain rail is switched off for customers';
                }
                throw new Exception(
                    'Could not create an invoice: no payment rail is available.'
                    . ($hints !== [] ? ' ' . ucfirst(implode('; ', $hints)) . '.' : '')
                );
            }
            $paymentRail = $quote ? 'mint' : 'onchain';
            $bolt11Final = $quote ? $quote->request : null;
            $mintUrlFinal = $usedMintUrl;
            $quoteIdFinal = $quote ? $quote->quote : null;
            $amountSatsFinal = $amountInMintUnit;
        }

        // A fee invoice always carries the canonical sat value so revenue math
        // and the fee credit (recordFeeRedirectCredit) reflect the amount the
        // customer actually paid, independent of which rail/mint unit the
        // merchant side used. Mirrors the previous createRedirectInvoice path.
        if ($feeNote !== null) {
            $amountSatsFinal = $invoiceSats;
        }

        // When the Lightning side lapses, independent of the invoice: the mint
        // quote's own expiry, else what the bolt11 encodes, else the default
        // window. Drives the long-lived page's refresh timer and the pollers'
        // "still payable" cutoff.
        $lnExpiresAt = self::lightningExpiresAt($bolt11Final, $quote, $now);

        Database::insert('invoices', [
            'id' => $invoiceId,
            'store_id' => $storeId,
            'status' => 'New',
            'additional_status' => 'None',
            'amount' => $amount,
            'currency' => $currency,
            'amount_sats' => $amountSatsFinal,
            'exchange_rate' => $exchangeRate,
            'quote_id' => $quoteIdFinal,
            'bolt11' => $bolt11Final,
            'mint_url' => $mintUrlFinal,
            'onchain_address' => $onchainAddress,
            'onchain_address_index' => $onchainIndex,
            'onchain_amount_sat' => $onchainAmountSat,
            'onchain_amount_tweak_sats' => $onchainAmountTweakSats,
            'onchain_created_tip_height' => $onchainCreatedTipHeight,
            'payment_rail' => $paymentRail,
            'lnurl_verify_url' => $lnurlVerifyUrl,
            'ln_destination' => $lnDestination,
            'noffer_relay' => $nofferRelay,
            'noffer_receiver_pubkey' => $nofferReceiverPubkey,
            'noffer_ephemeral_sk' => $nofferEphemeralSk,
            'noffer_ephemeral_pubkey' => $nofferEphemeralPubkey,
            'noffer_request_event_id' => $nofferRequestEventId,
            'noffer_created_at' => $nofferCreatedAt,
            'nwc_uri' => $nwcUri,
            'nwc_payment_hash' => $nwcPaymentHash,
            'strike_invoice_id' => $strikeInvoiceId,
            'strike_api_key' => $strikeApiKey,
            'strike_receive_request_id' => $strikeReceiveRequestId,
            'fee_redirect_note' => $feeNote,
            'fee_redirect_destination' => $feeDestination,
            'fee_redirect_rails' => $feeRails ? implode(',', $feeRails) : null,
            'receive_errors' => $receiveErrors !== [] ? json_encode(self::dedupeReceiveErrors($receiveErrors)) : null,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'checkout_config' => $checkout ? json_encode($checkout) : null,
            'created_at' => $now,
            'expiration_time' => $expiration,
            'long_lived' => $longLived ? 1 : 0,
            'ln_expires_at' => $lnExpiresAt,
        ]);

        // Persist the swap_attempts row, now that the invoice exists for the FK.
        if ($swapAttempt !== null) {
            self::persistSwapAttempt($invoiceId, $storeId, $swapAttempt, $now);
        }

        $invoice = self::getById($invoiceId);

        // Fire InvoiceCreated webhook
        WebhookSender::fireEvent($storeId, 'InvoiceCreated', $invoice);

        return $invoice;
    }

    /**
     * Walk the store's ordered direct-receive chain (Strike / CLINK noffer /
     * NWC / Lightning address) for a bolt11 of exactly $lnurlTargetSats.
     * First destination to return a usable invoice wins; the rest are only
     * tried when earlier ones are down / out of range / can't produce a
     * verifiable invoice. Sanitized failure records are appended to
     * $receiveErrors. Shared by Invoice::create and the long-lived-invoice
     * Lightning refresh (refreshLightning), so a re-sourced bolt11 follows
     * exactly the chain a fresh invoice would.
     *
     * @return array{type:string, attempt:array}|null type is one of
     *         strike|noffer|nwc|lnurl; attempt is that rail's context array.
     */
    private static function sourceDirectReceive(
        string $storeId,
        array $store,
        array $destinations,
        int $lnurlTargetSats,
        ?array $metadata,
        string $invoiceId,
        array &$receiveErrors
    ): ?array {
        $strikeAttempt = null;
        $nofferAttempt = null;
        $nwcAttempt = null;
        $lnurlAttempt = null;
        // Walk the priority chain. First destination to return a usable
        // invoice wins; the rest are only tried when earlier ones are
        // down / out of range / can't produce a verifiable invoice.
        foreach ($destinations as $priority => $dest) {
            if ($dest['type'] === StoreLnAddresses::TYPE_STRIKE) {
                // Strike API: create a BTC invoice in the merchant's
                // Strike account and quote it into a BOLT11. Settlement
                // is later confirmed by reading the Strike invoice back
                // (payment page + cron) until state=PAID. Strike leads
                // the chain in the default type order (configurable per
                // store — see RailOrder). Strike caps
                // descriptions at 200 chars, so the memo gets the wider
                // cap rather than the 100-char CLINK one.
                $strikeMemo = self::buildInvoiceMemo($store, $metadata, 200);
                try {
                    $made = StrikeClient::createInvoiceWithQuote(
                        $dest['value'],
                        $lnurlTargetSats,
                        $strikeMemo !== '' ? $strikeMemo : null,
                        $invoiceId,
                        self::directReceiveTimeoutSec('STRIKE_TIMEOUT_SEC')
                    );
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[strike-receive] create+quote failed store=%s priority=%d dest=%s: %s; trying next',
                        $storeId, $priority, StrikeClient::maskKey($dest['value']), $e->getMessage()
                    ));
                    $receiveErrors[] = ['type' => 'strike', 'reason' => StrikeClient::describeFailure($e)];
                    AdminLog::log('strike', 'checkout', $storeId, null, StrikeClient::maskKey($dest['value']), $e->getMessage());
                    continue;
                }
                $strikeAttempt = [
                    'bolt11' => $made['bolt11'],
                    'amount_sats' => $lnurlTargetSats,
                    'api_key' => $dest['value'],
                    'strike_invoice_id' => $made['invoice_id'],
                ];
                if ($priority > 0) {
                    error_log("[strike-receive] using fallback Strike key store={$storeId} priority={$priority}");
                }
                return ['type' => 'strike', 'attempt' => $strikeAttempt];
            }

            if ($dest['type'] === StoreLnAddresses::TYPE_NOFFER) {
                // CLINK noffer: act as the payer toward the merchant's
                // Nostr service and fetch a BOLT11. Settlement is later
                // confirmed by a kind-21001 receipt (payment page + cron).
                // Send a best-effort, spec-compliant memo (NIP-69
                // description) so the receiving wallet can label the
                // payment with the store name; the wallet may ignore it.
                $nofferMemo = self::nofferMemo($store, $metadata);
                try {
                    $resolved = ClinkClient::requestInvoice(
                        $dest['value'],
                        $lnurlTargetSats,
                        $nofferMemo !== '' ? $nofferMemo : null,
                        self::directReceiveTimeoutSec('CLINK_NOFFER_TIMEOUT_SEC')
                    );
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[clink-receive] noffer fetch failed store=%s priority=%d: %s; trying next',
                        $storeId, $priority, $e->getMessage()
                    ));
                    $receiveErrors[] = ['type' => 'noffer', 'reason' => self::describeNofferFailure($e)];
                    AdminLog::log('noffer', 'checkout', $storeId, null, $dest['value'], $e->getMessage());
                    continue;
                }
                $nofferAttempt = [
                    'bolt11' => $resolved['bolt11'],
                    'amount_sats' => $lnurlTargetSats,
                    'noffer' => $dest['value'],
                    'relay' => $resolved['relay'],
                    'receiver_pubkey' => $resolved['receiver_pubkey'],
                    'ephemeral_sk' => $resolved['ephemeral_sk'],
                    'ephemeral_pubkey' => $resolved['ephemeral_pubkey'],
                    'request_event_id' => $resolved['request_event_id'],
                    'created_at' => $resolved['created_at'],
                ];
                if ($priority > 0) {
                    error_log("[clink-receive] using fallback noffer store={$storeId} priority={$priority}");
                }
                return ['type' => 'noffer', 'attempt' => $nofferAttempt];
            }

            if ($dest['type'] === StoreLnAddresses::TYPE_NWC) {
                // NWC (NIP-47): ask the merchant's own wallet to mint
                // the BOLT11 over Nostr Wallet Connect. Settlement is
                // later confirmed by polling lookup_invoice (payment
                // page + cron). Same memo the noffer rail sends; the
                // wallet may ignore it. Give the wallet invoice the
                // same lifetime the payserver invoice will get so the
                // two expire together. NwcClient verifies the returned
                // bolt11 encodes exactly the requested amount. Errors
                // (including a GMP-less host) log + fall through to
                // the next destination.
                $nwcMemo = self::nofferMemo($store, $metadata);
                try {
                    $made = NwcClient::makeInvoice(
                        $dest['value'],
                        $lnurlTargetSats,
                        $nwcMemo !== '' ? $nwcMemo : null,
                        Config::getInvoiceExpiration(),
                        self::directReceiveTimeoutSec('NWC_TIMEOUT_SEC')
                    );
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[nwc-receive] make_invoice failed store=%s priority=%d dest=%s: %s; trying next',
                        $storeId, $priority, NwcUri::displayLabel($dest['value']), $e->getMessage()
                    ));
                    $receiveErrors[] = ['type' => 'nwc', 'reason' => self::describeNwcFailure($e)];
                    AdminLog::log('nwc', 'checkout', $storeId, null, NwcUri::displayLabel($dest['value']), $e->getMessage());
                    continue;
                }
                $nwcAttempt = [
                    'bolt11' => $made['bolt11'],
                    'amount_sats' => $lnurlTargetSats,
                    'uri' => $dest['value'],
                    'payment_hash' => $made['payment_hash'],
                ];
                if ($priority > 0) {
                    error_log("[nwc-receive] using fallback NWC connection store={$storeId} priority={$priority}");
                }
                return ['type' => 'nwc', 'attempt' => $nwcAttempt];
            }

            // Lightning address (LNURL/LUD-21) path.
            if (!StoreLnAddresses::isValid($dest['value'])) {
                error_log(sprintf(
                    '[lnurl-receive] skipping malformed address store=%s priority=%d address=%s',
                    $storeId, $priority, $dest['value']
                ));
                $receiveErrors[] = ['type' => 'lnurl', 'reason' => 'the configured Lightning address is malformed'];
                AdminLog::log('lnurl', 'checkout', $storeId, null, $dest['value'], 'malformed Lightning address configured');
                continue;
            }
            $lnurlFailReason = null;
            try {
                $probed = LnUrlReceive::probeAndFetchInvoice(
                    $dest['value'], $lnurlTargetSats, null, $lnurlFailReason
                );
            } catch (Throwable $e) {
                error_log("[lnurl-receive] probe threw for store {$storeId} address {$dest['value']}: " . $e->getMessage());
                $probed = null;
                $lnurlFailReason = 'the Lightning address service could not be reached';
            }
            if ($probed !== null) {
                $lnurlAttempt = [
                    'bolt11' => $probed['bolt11'],
                    'verify_url' => $probed['verify_url'],
                    'amount_sats' => $lnurlTargetSats,
                    // The LN address this bolt11 was fetched from. Persisted
                    // as ln_destination so the admin invoice view can show
                    // where a lightning payment was sent.
                    'address' => $dest['value'],
                ];
                if ($priority > 0) {
                    error_log(sprintf(
                        '[lnurl-receive] using fallback address store=%s priority=%d address=%s',
                        $storeId, $priority, $dest['value']
                    ));
                }
                return ['type' => 'lnurl', 'attempt' => $lnurlAttempt];
            }
            error_log(sprintf(
                '[lnurl-receive] probe failed for store=%s priority=%d address=%s amount_sats=%d reason=%s; trying next',
                $storeId, $priority, $dest['value'], $lnurlTargetSats,
                $lnurlFailReason ?? 'unknown'
            ));
            $receiveErrors[] = [
                'type' => 'lnurl',
                'reason' => $lnurlFailReason ?? 'the Lightning address could not produce an invoice',
            ];
            AdminLog::log('lnurl', 'checkout', $storeId, null, $dest['value'],
                $lnurlFailReason ?? 'probe failed');
        }
        error_log(sprintf(
            '[direct-receive] all %d destination(s) failed for store=%s amount_sats=%d; falling back to swap/mint/onchain',
            count($destinations), $storeId, $lnurlTargetSats
        ));
        return null;
    }

    /**
     * Request a mint quote for $amount/$currency from the store's mints in
     * failover order (accounts still recovering are skipped). $fixedAmount
     * (already in the mint unit) bypasses the rate conversion — the Lightning
     * refresh of a long-lived invoice re-quotes at the amount the payer is
     * already looking at. Throws on a rate failure only when no on-chain rail
     * could serve the invoice instead (same contract as Invoice::create).
     *
     * @return array{quote:?\Cashu\MintQuote, mint_url:?string, amount:?int,
     *               recovering:string[], last_error:?Throwable}
     */
    private static function sourceMintQuote(
        string $storeId,
        array $store,
        string $amount,
        string $currency,
        bool $onchainConfigured,
        ?int $fixedAmount = null
    ): array {
        $mintUnit = $store['mint_unit'];
        $exchangeFee = (float)($store['exchange_fee_percent'] ?? 0);
        $primaryProvider = $store['price_provider_primary'] ?? 'coingecko';
        $secondaryProvider = $store['price_provider_secondary'] ?? 'binance';
        $lastError = null;
        $quote = null;
        $usedMintUrl = null;
        $recoveringMints = [];
        try {
            $amountInMintUnit = $fixedAmount ?? ExchangeRates::convertToMintUnit(
                $amount, $currency, $mintUnit, $exchangeFee, $primaryProvider, $secondaryProvider
            );
        } catch (Throwable $e) {
            // A rate failure (fiat-unit mint, providers down) is a failure
            // of the MINT path, not of the invoice: with an on-chain rail
            // configured the invoice still comes out payable. Only abort
            // when nothing else could serve it (same contract as the
            // all-mints-failed throw below).
            if (!$onchainConfigured) {
                throw $e;
            }
            error_log(sprintf(
                '[mint] unit conversion failed for store=%s (%s -> %s): %s; skipping mint path',
                $storeId, $currency, (string)$mintUnit, $e->getMessage()
            ));
            $amountInMintUnit = null;
        }
        if ($amountInMintUnit !== null) {
            require_once __DIR__ . '/mint_reliability.php';
            $allMints = Config::getStoreAllMintUrls($storeId);
            $recoveringMints = [];
            foreach ($allMints as $tryMintUrl) {
                try {
                    $wallet = self::getWalletForStore($storeId, $tryMintUrl);
                    // An account whose seed has not been checked against
                    // this mint yet must not issue a quote: the payment
                    // would arrive at the mint but the wallet could not
                    // mint the ecash (counter reuse). Use a ready backup;
                    // the cron's recovery task readies this one.
                    if ($wallet->requiresRecovery()) {
                        $recoveringMints[] = $tryMintUrl;
                        error_log("Mint {$tryMintUrl} skipped for store {$storeId}: wallet recovery in progress");
                        continue;
                    }
                    $quote = $wallet->requestMintQuote($amountInMintUnit);
                    $usedMintUrl = $tryMintUrl;
                    MintReliability::recordQuoteSuccess($tryMintUrl, $storeId);
                    break;
                } catch (Exception $e) {
                    $lastError = $e;
                    error_log("Mint quote failed for $tryMintUrl: " . $e->getMessage());
                    $kind = MintReliability::classifyException($e, 'requestMintQuote');
                    MintReliability::recordQuoteFailure($tryMintUrl, $storeId, $kind, $e->getMessage());
                    continue;
                }
            }
        }
        return [
            'quote' => $quote,
            'mint_url' => $usedMintUrl,
            'amount' => $amountInMintUnit,
            'recovering' => $recoveringMints,
            'last_error' => $lastError,
        ];
    }

    /**
     * Try each configured swap provider in order. Returns null if none could
     * service this request (provider unreachable, amount out of range, lockup
     * verification failed). The caller then either falls back to the mint or
     * errors out depending on the store's effective strict-fallback setting
     * (per-store override, else the site default).
     *
     * On success returns:
     *   ['swap' => SwapCreateResult,
     *    'provider' => string,
     *    'network' => string,
     *    'claim_privkey' => string (32 bytes),
     *    'claim_pubkey' => string (33 bytes),
     *    'preimage' => string (32 bytes),
     *    'preimage_hash' => string (32 bytes),
     *    'merchant_address' => string,
     *    'merchant_address_index' => int,
     *    'lockup_fee_sats' => int,
     *    'percent_fee_sats' => int]
     *
     * $maxFeePct / $maxFeeSats gate the fee-too-high → mint fallback: a
     * provider whose prospective total cost exceeds either active threshold
     * (0 = that check disabled) is skipped. Because providers are ranked
     * cheapest-first, when the cheapest is too expensive they all are, so this
     * method returns null and Invoice::create falls through to the mint path.
     */
    private static function trySwapCreate(string $storeId, array $store, int $targetSats,
                                          ?array &$failureReasons = null,
                                          float $maxFeePct = 0.0, int $maxFeeSats = 0): ?array {
        if ($failureReasons === null) $failureReasons = [];
        if ($targetSats <= 0) return null;
        // Swap claim keys and lockup verification are EC/Taproot math, which
        // needs GMP. On a host without it, bail before any provider round-trip
        // so the invoice falls straight through to the mint rail instead of
        // burning a quote fetch per provider and logging a cryptic
        // "undefined function gmp_init" for each.
        require_once __DIR__ . '/onchain/wallet.php';
        if (($envError = OnchainWallet::environmentError()) !== null) {
            $failureReasons[] = "swap rail unavailable: {$envError}";
            return null;
        }
        $localMin = SwapsConfig::minimumTargetSatsForStore($storeId);
        if ($localMin !== null && $targetSats < $localMin) {
            $failureReasons[] = "store min override ({$localMin} sat) blocks {$targetSats} sat target";
            return null;
        }
        $network = $store['onchain_network'] ?? 'mainnet';

        // When auto-select-cheapest is on, rankedForStore() fetches quotes from
        // every reachable provider in parallel and reorders them by total cost
        // (subject to the 10%-cheaper threshold). When off, it returns the
        // configured priority order with no cached quotes — behaviour matches
        // the historical sequential path.
        foreach (SwapProviderFactory::rankedForStore($storeId, $network, $targetSats) as ['provider' => $provider, 'quote' => $cachedQuote]) {
            $name = $provider->getName();
            try {
                $pairInfo = $cachedQuote ?? $provider->getReversePairInfo($network);
                if ($targetSats < $pairInfo->minSats || $targetSats > $pairInfo->maxSats) {
                    $failureReasons[] = sprintf(
                        "%s: %d sat outside range [%d, %d]",
                        $name, $targetSats, $pairInfo->minSats, $pairInfo->maxSats
                    );
                    continue;
                }

                // Fee-too-high → mint fallback gate. Evaluate the prospective
                // total cost from the (cached) quote BEFORE creating the swap on
                // the provider side, so we never create-then-discard a swap.
                if ($maxFeePct > 0 || $maxFeeSats > 0) {
                    $totalCost = SwapQuoteFetcher::totalCostSats($pairInfo, $targetSats);
                    if (SwapsConfig::swapFeeExceedsThreshold($totalCost, $targetSats, $maxFeePct, $maxFeeSats)) {
                        $failureReasons[] = sprintf(
                            "%s: total fee %d sat over mint-fallback threshold (pct=%s, sats=%s)",
                            $name, $totalCost,
                            $maxFeePct > 0 ? $maxFeePct : 'off',
                            $maxFeeSats > 0 ? $maxFeeSats : 'off'
                        );
                        continue;
                    }
                }

                // Allocate destination address (shared xpub counter).
                $alloc = OnchainPayments::allocateClaimAddress($storeId);
                if ($alloc === null) {
                    // Should not happen — caller guards on $onchainConfigured.
                    $failureReasons[] = "{$name}: store has no on-chain xpub allocated";
                    return null;
                }

                // Per-swap fresh keypair + preimage.
                $claimPriv = random_bytes(32);
                $claimPubPoint = Secp256k1::generatorMult(Secp256k1::bytesToNum($claimPriv));
                if ($claimPubPoint === null) {
                    $failureReasons[] = "{$name}: claim key derivation failed (retry)";
                    continue;
                }
                $claimPub = Secp256k1::pointToCompressed($claimPubPoint);
                $preimage = random_bytes(32);
                $preimageHash = hash('sha256', $preimage, true);

                $swap = $provider->createReverseSwap(
                    $network,
                    $targetSats,
                    bin2hex($claimPub),
                    bin2hex($preimageHash)
                );

                // Verify the lockup_address Boltz returned matches what we
                // compute locally from the parsed swap tree. Defends against
                // a buggy or hostile provider that would give us an address
                // whose script-path we can't satisfy.
                if (!self::verifySwapLockup($swap, $claimPub, $network)) {
                    error_log("swap: lockup address mismatch from {$name}; trying next");
                    $failureReasons[] = "{$name}: lockup address mismatch (provider returned non-matching script tree)";
                    continue;
                }

                $lockupFee = $pairInfo->lockupFeeSats;

                // Bound the LN invoice the customer is about to be shown against
                // the quoted economics. invoiceAmountSats is provider-controlled;
                // the range/threshold gates above validated the QUOTE, not the
                // issued invoice. Without this a provider could quote fairly then
                // return an invoice charging far more, overcharging the payer.
                // Mirrors SwapAutoMelt::buildSweep. Expected max = target +
                // lockup fee + ceil(target * feePercent) + tolerance.
                $expectedMaxInvoice = $targetSats
                    + $lockupFee
                    + (int)ceil($targetSats * $pairInfo->feePercent / 100.0);
                $invoiceTolerance = max(2, (int)ceil($expectedMaxInvoice * 0.005));
                if ($swap->invoiceAmountSats > $expectedMaxInvoice + $invoiceTolerance) {
                    error_log(sprintf(
                        'swap: %s issued invoice %d sat exceeds quoted max %d (+%d tol) for target %d; trying next',
                        $name, $swap->invoiceAmountSats, $expectedMaxInvoice, $invoiceTolerance, $targetSats
                    ));
                    $failureReasons[] = "{$name}: issued invoice exceeds quoted max (provider overcharge)";
                    continue;
                }

                $percentFee = max(0, $swap->invoiceAmountSats - $targetSats - $lockupFee);

                return [
                    'swap' => $swap,
                    'provider' => $name,
                    'network' => $network,
                    'claim_privkey' => $claimPriv,
                    'claim_pubkey' => $claimPub,
                    'preimage' => $preimage,
                    'preimage_hash' => $preimageHash,
                    'merchant_address' => $alloc['address'],
                    'merchant_address_index' => $alloc['index'],
                    'lockup_fee_sats' => $lockupFee,
                    'percent_fee_sats' => $percentFee,
                    'quotes_audit' => SwapQuoteFetcher::lastAuditTrail(),
                ];
            } catch (Throwable $e) {
                error_log("swap: provider {$name} failed: " . $e->getMessage());
                $failureReasons[] = "{$name}: " . $e->getMessage();
                continue;
            }
        }
        return null;
    }

    /**
     * Recompute the Taproot output key from claim+refund pubkeys + the parsed
     * swap-tree leaves, and compare against the provider-returned lockup
     * address. Returns false on any mismatch.
     */
    private static function verifySwapLockup(SwapCreateResult $swap, string $claimPub33, string $network): bool {
        try {
            $claimLeafHash  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $swap->claimLeafScript);
            $refundLeafHash = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $swap->refundLeafScript);
            $merkleRoot = Taproot::tapBranchHash($claimLeafHash, $refundLeafHash);
            $refundPub33 = hex2bin($swap->refundPublicKeyHex);
            $internalKey = Taproot::keyAggInternalKey([$refundPub33, $claimPub33]);
            [$outKey, $_parity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
            $expected = Taproot::encodeP2trAddress($outKey, $network);
            return strcasecmp($expected, $swap->lockupAddress) === 0;
        } catch (Throwable $e) {
            error_log('swap: lockup verification threw: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Persist the swap_attempts row after the parent invoice has been inserted.
     */
    private static function persistSwapAttempt(string $invoiceId, string $storeId, array $att, int $now): void {
        $swap = $att['swap'];
        $quotesAuditJson = !empty($att['quotes_audit'])
            ? json_encode($att['quotes_audit'])
            : null;
        Database::getInstance()->prepare(
            "INSERT INTO swap_attempts (
                invoice_id, store_id, provider, network, direction,
                swap_id_external, status,
                preimage_hex, preimage_hash_hex,
                claim_pubkey_hex, claim_privkey_hex, refund_pubkey_hex,
                lockup_address, timeout_block_height,
                claim_leaf_script_hex, refund_leaf_script_hex,
                lightning_invoice,
                target_onchain_amount_sats, invoice_amount_sats,
                swap_lockup_fee_sats, swap_percent_fee_sats,
                merchant_address, merchant_address_index,
                provider_response_json, quotes_compared_json,
                created_at, updated_at
            ) VALUES (
                :invoice_id, :store_id, :provider, :network, :direction,
                :swap_id_external, :status,
                :preimage_hex, :preimage_hash_hex,
                :claim_pubkey_hex, :claim_privkey_hex, :refund_pubkey_hex,
                :lockup_address, :timeout_block_height,
                :claim_leaf_script_hex, :refund_leaf_script_hex,
                :lightning_invoice,
                :target_onchain_amount_sats, :invoice_amount_sats,
                :swap_lockup_fee_sats, :swap_percent_fee_sats,
                :merchant_address, :merchant_address_index,
                :provider_response_json, :quotes_compared_json,
                :created_at, :updated_at
            )"
        )->execute([
            ':invoice_id' => $invoiceId,
            ':store_id' => $storeId,
            ':provider' => $att['provider'],
            ':network' => $att['network'],
            ':direction' => 'reverse',
            ':swap_id_external' => $swap->swapId,
            ':status' => 'swap.created',
            ':preimage_hex' => bin2hex($att['preimage']),
            ':preimage_hash_hex' => bin2hex($att['preimage_hash']),
            ':claim_pubkey_hex' => bin2hex($att['claim_pubkey']),
            ':claim_privkey_hex' => bin2hex($att['claim_privkey']),
            ':refund_pubkey_hex' => $swap->refundPublicKeyHex,
            ':lockup_address' => $swap->lockupAddress,
            ':timeout_block_height' => $swap->timeoutBlockHeight,
            ':claim_leaf_script_hex' => bin2hex($swap->claimLeafScript),
            ':refund_leaf_script_hex' => bin2hex($swap->refundLeafScript),
            ':lightning_invoice' => $swap->invoice,
            ':target_onchain_amount_sats' => $swap->onchainAmountSats,
            ':invoice_amount_sats' => $swap->invoiceAmountSats,
            ':swap_lockup_fee_sats' => $att['lockup_fee_sats'],
            ':swap_percent_fee_sats' => $att['percent_fee_sats'],
            ':merchant_address' => $att['merchant_address'],
            ':merchant_address_index' => $att['merchant_address_index'],
            ':provider_response_json' => $swap->rawResponse ? json_encode($swap->rawResponse) : null,
            ':quotes_compared_json' => $quotesAuditJson,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * Get invoice by ID
     */
    public static function getById(string $id): ?array {
        return Database::fetchOne(
            "SELECT * FROM invoices WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get invoices by store
     */
    public static function getByStore(string $storeId, ?string $status = null, int $limit = 50, int $offset = 0): array {
        $sql = "SELECT * FROM invoices WHERE store_id = ?";
        $params = [$storeId];

        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll($sql, $params);
    }

    /**
     * Update invoice status.
     *
     * Every transition is a status-guarded CAS. Settled only ever applies to a
     * row that is not already Settled; any other status applies only while the
     * row is in one of $expectedStatuses (default: MUTABLE_STATUSES — New,
     * Processing, Provisional), so a stale read can never write Expired /
     * Invalid / Processing over a Settled invoice. Returns whether this call
     * changed the row; nothing is fired when it did not.
     *
     * @param string[]|null $expectedStatuses explicit "from" states for the
     *        non-settlement transitions (e.g. ['New'] for an expiry sweep)
     */
    public static function updateStatus(
        string $invoiceId,
        string $status,
        ?string $additionalStatus = null,
        ?string $settledRail = null,
        ?array $expectedStatuses = null
    ): bool {
        $updates = ['status' => $status];

        if ($additionalStatus !== null) {
            $updates['additional_status'] = $additionalStatus;
        }

        // Capture the moment we recognise the invoice as paid + which rail
        // actually moved the funds. Used by the admin invoices view.
        if ($status === 'Settled') {
            $updates['paid_at'] = time();
            if ($settledRail !== null) {
                $updates['settled_rail'] = $settledRail;
            }
        }

        // Settlement is terminal and fires InvoiceSettled + a merchant
        // notification, so guard the transition: only the caller that actually
        // flips the row to Settled proceeds. The on-chain poller, mint poll,
        // and offline reconcile can all reach this concurrently.
        if ($status === 'Settled') {
            $where = 'id = ? AND status != ?';
            $params = [$invoiceId, 'Settled'];
            if ($expectedStatuses !== null && !empty($expectedStatuses)) {
                $where .= ' AND status IN (' . implode(',', array_fill(0, count($expectedStatuses), '?')) . ')';
                $params = array_merge($params, array_values($expectedStatuses));
            }
            $changed = Database::update('invoices', $updates, $where, $params);
        } else {
            $expected = ($expectedStatuses !== null && !empty($expectedStatuses))
                ? array_values($expectedStatuses)
                : self::MUTABLE_STATUSES;
            $changed = Database::update(
                'invoices',
                $updates,
                'id = ? AND status IN (' . implode(',', array_fill(0, count($expected), '?')) . ')',
                array_merge([$invoiceId], $expected)
            );
        }
        if ($changed !== 1) {
            return false; // already settled / closed by another path
        }

        // Get updated invoice for webhook
        $invoice = self::getById($invoiceId);

        // Per-rail attribution: an invoice can be mixed (some rails point at a
        // fee payee, the rest at the merchant). Whether THIS settlement is a
        // fee payment depends on which rail actually paid (settled_rail), not
        // on the invoice merely having a fee route. This branch covers the
        // on-chain + mint settlement paths; the lnaddress path settles via
        // markLnAddressPaid and attributes there.
        $settledToFee = $status === 'Settled' && $invoice
            && self::settledRailIsFeeRouted($invoice);
        if ($settledToFee) {
            self::recordFeeRedirectCredit($invoice, $invoice['lnurl_preimage'] ?? null);
        }

        // Fire appropriate webhook
        $eventType = match ($status) {
            'Processing' => 'InvoiceProcessing',
            'Provisional' => 'InvoiceProvisional',
            'Settled' => 'InvoiceSettled',
            'Expired' => 'InvoiceExpired',
            'Invalid' => 'InvoiceInvalid',
            default => null,
        };

        if ($eventType && $invoice) {
            WebhookSender::fireEvent($invoice['store_id'], $eventType, $invoice);
            // The InvoiceSettled webhook always fires (the customer genuinely
            // paid, so order fulfillment proceeds), but the merchant "you were
            // paid" notification is suppressed only when the rail that paid
            // went to the fee payee — for a merchant rail the merchant really
            // was paid and should be notified.
            if ($status === 'Settled' && !$settledToFee) {
                NotificationSender::queueInvoicePaid($invoice);
            }
        }
        return true;
    }

    /**
     * Map the rail that actually settled an invoice to a logical customer rail.
     * The on-chain poller reports 'onchain'; the mint, lnaddress and swap rails
     * are all lightning from the customer's point of view.
     */
    private static function settledRailToLogical(?string $settledRail): ?string {
        return match ($settledRail) {
            'onchain' => 'onchain',
            'mint', 'lnaddress', 'noffer', 'swap' => 'lightning',
            default => null,
        };
    }

    /**
     * Does the given logical rail ('lightning' | 'onchain') point at the fee
     * payee on this invoice? Reads the fee_redirect_rails CSV written at
     * creation. A NULL/empty value means a normal (all-merchant) invoice.
     */
    public static function railIsFeeRouted(array $invoice, string $logicalRail): bool {
        $raw = (string)($invoice['fee_redirect_rails'] ?? '');
        if ($raw === '') {
            return false;
        }
        $rails = array_map('trim', explode(',', $raw));
        return in_array($logicalRail, $rails, true);
    }

    /**
     * Was the rail that actually settled this invoice routed to the fee payee?
     * Combines settled_rail -> logical rail mapping with the per-rail fee flags.
     */
    public static function settledRailIsFeeRouted(array $invoice): bool {
        $logical = self::settledRailToLogical($invoice['settled_rail'] ?? null);
        return $logical !== null && self::railIsFeeRouted($invoice, $logical);
    }

    /**
     * Record the fee-paid credit for a fee-routed settlement. The rail the
     * customer paid went straight to the fee payee (no cashu proofs spent), so
     * we log a melts row tagged via='redirect' under the fee's note.
     * DevFee::computeOwed sums melts by note, so this immediately reduces the
     * owed amount and stops the cron from melting the same fee out of the
     * wallet.
     *
     * Caller must have confirmed the SETTLED rail is fee-routed
     * (see settledRailIsFeeRouted); we self-guard here too. The credited amount
     * comes from the rail that actually paid: the on-chain rail credits
     * onchain_amount_sat (what the customer sent on-chain), every lightning
     * rail credits amount_sats.
     *
     * Idempotent: guarded by a SELECT and a UNIQUE partial index on
     * (invoice_id) WHERE via='redirect', so dual-rail settlement races can't
     * double-credit.
     */
    private static function recordFeeRedirectCredit(array $invoice, ?string $preimage): void {
        $note = $invoice['fee_redirect_note'] ?? null;
        if (empty($note) || !self::settledRailIsFeeRouted($invoice)) {
            return;
        }
        $invoiceId = (string)$invoice['id'];
        $existing = Database::fetchOne(
            "SELECT 1 AS x FROM melts WHERE invoice_id = ? AND via = 'redirect' LIMIT 1",
            [$invoiceId]
        );
        if ($existing) {
            return;
        }
        // Credit the amount the paying rail actually moved.
        $logical = self::settledRailToLogical($invoice['settled_rail'] ?? null);
        $amount = $logical === 'onchain'
            ? (int)($invoice['onchain_amount_sat'] ?? $invoice['amount_sats'] ?? 0)
            : (int)($invoice['amount_sats'] ?? 0);
        $amount = max(0, $amount);
        try {
            Database::insert('melts', [
                'store_id' => (string)$invoice['store_id'],
                'amount_sats' => $amount,
                'network_fee_sats' => 0,
                'destination' => (string)($invoice['fee_redirect_destination'] ?? ''),
                'preimage' => $preimage ?: null,
                'note' => (string)$note,
                'via' => 'redirect',
                'invoice_id' => $invoiceId,
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            // Lost a race to the other rail's poller (UNIQUE violation) — the
            // credit already exists, so this is a no-op, not an error.
            error_log("[fee-redirect] credit insert skipped for {$invoiceId}: " . $e->getMessage());
            return;
        }
        error_log(sprintf(
            '[fee-redirect] credited %d sats to %s via redirect (invoice=%s rail=%s)',
            $amount, (string)$note, $invoiceId, (string)($invoice['settled_rail'] ?? '?')
        ));
    }

    /**
     * Expire `New` invoices whose expiry is confirmed or long past, without
     * contacting any mint.
     *
     *   - Mint-rail invoices (quote_id set) are expired only once a poll that
     *     ran after their expiry saw the quote UNPAID (the pollers normally
     *     expire them themselves), or when they are more than
     *     EXPIRY_CONFIRM_WINDOW past expiry. A payment made in the last
     *     seconds, or while the mint was unreachable, is no longer closed as
     *     Expired and then Settled a moment later.
     *   - Invoices carrying an on-chain address are left to the chain watcher
     *     until expiration_time + the store's onchain_confirm_timeout_sec, the
     *     window during which an on-chain payment is still attributed.
     *   - Other rails expire at expiration_time as before.
     *
     * @return int Number of invoices marked as expired
     */
    public static function markExpiredInvoices(): int {
        $now = time();
        // SQLite quirk: bound parameters arrive as TEXT and an arithmetic
        // expression has no column affinity, so `(expiration_time + x) < ?`
        // would compare integer to text (always true). CAST the bound
        // timestamp; bare column comparisons coerce via INTEGER affinity.
        return self::expireNewInvoices(
            "expiration_time < ?
             AND (onchain_address IS NULL OR onchain_address = ''
                  OR (expiration_time + COALESCE((SELECT s.onchain_confirm_timeout_sec FROM stores s WHERE s.id = invoices.store_id), 86400)) < CAST(? AS INTEGER))
             AND (quote_id IS NULL OR quote_id = ''
                  OR expiration_time < ?
                  OR (last_polled_at IS NOT NULL AND last_polled_at >= expiration_time
                      AND last_poll_error IS NULL
                      AND UPPER(COALESCE(last_poll_state, '')) = 'UNPAID'))",
            [$now, $now, $now - self::EXPIRY_CONFIRM_WINDOW]
        );
    }

    /**
     * Expire `New` invoices created before the given time, whatever their
     * expiry says. Same path as markExpiredInvoices(), so each one emits
     * InvoiceExpired (the old bulk UPDATE fired nothing and shop orders stayed
     * pending forever).
     */
    public static function expireInvoicesCreatedBefore(int $createdBefore): int {
        return self::expireNewInvoices('created_at < ?', [$createdBefore]);
    }

    /**
     * Move the `New` invoices matching $where to Expired, firing InvoiceExpired
     * for each one actually flipped.
     *
     * Bounded batches, each its own write transaction (an unbounded SELECT of
     * a large backlog exhausted memory and the fatal took every later cron
     * task with it), with a per-row status-guarded UPDATE: only the caller
     * whose UPDATE flips a row (rowCount 1) fires that invoice's webhook, so
     * concurrent sweeps can't emit duplicates and a row paid between the
     * SELECT and the UPDATE is left alone. Events are fired after the commit
     * so the write lock is not held across the outbox insert / nudge.
     *
     * @param string $where SQL condition on invoices (ANDed with status = 'New')
     */
    private static function expireNewInvoices(string $where, array $params): int {
        $count = 0;
        while (true) {
            $flipped = [];
            Database::beginImmediate();
            try {
                $expiring = Database::fetchAll(
                    "SELECT id, store_id FROM invoices WHERE status = 'New' AND ({$where}) ORDER BY id ASC LIMIT ?",
                    array_merge($params, [self::EXPIRY_BATCH_SIZE])
                );
                foreach ($expiring as $row) {
                    $changed = Database::update(
                        'invoices',
                        ['status' => 'Expired'],
                        "id = ? AND status = 'New'",
                        [$row['id']]
                    );
                    if ($changed !== 1) {
                        continue; // settled by another worker in the meantime
                    }
                    $flipped[] = $row;
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollback();
                throw $e;
            }
            foreach ($flipped as $row) {
                $count++;
                $invoice = self::getById($row['id']);
                if ($invoice) {
                    WebhookSender::fireEvent($row['store_id'], 'InvoiceExpired', $invoice);
                }
            }
            if (count($expiring) < self::EXPIRY_BATCH_SIZE) {
                break;
            }
        }
        return $count;
    }

    /**
     * Remember what the mint last said about an invoice's quote, and any
     * failure, so the expiry sweep can act on a confirmed UNPAID and the
     * operator can see why an order was not marked paid. Never throws.
     */
    private static function recordPollOutcome(string $invoiceId, ?string $state, ?string $error): void {
        try {
            if ($error !== null) {
                // Losing a race is not a failure: another worker may have minted
                // and settled the same invoice while this one was talking to
                // the mint. Don't put a scare on a settled row.
                $current = Database::fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]);
                if ($current !== null && !in_array($current['status'], ['New', 'Processing'], true)) {
                    return;
                }
            }
            Database::update(
                'invoices',
                [
                    'last_poll_state' => $state,
                    'last_poll_error' => $error !== null ? mb_substr($error, 0, 300) : null,
                ],
                'id = ?',
                [$invoiceId]
            );
        } catch (\Throwable $e) {
            // bookkeeping only
        }
    }

    /**
     * Whether the mint has said, in so many words, that nothing was paid on
     * this quote. The NUT-04 accounting fields win when the library exposes
     * them; otherwise only an explicit UNPAID state counts.
     */
    private static function quoteIsDefinitelyUnpaid(\Cashu\MintQuote $quote): bool {
        if (property_exists($quote, 'amountPaid') && property_exists($quote, 'amountIssued')
            && $quote->amountPaid !== null && $quote->amountIssued !== null) {
            return (int)$quote->amountPaid === 0 && (int)$quote->amountIssued === 0;
        }
        return strtoupper((string)$quote->state) === 'UNPAID';
    }

    /** Expire a New invoice past its expiry once the mint has said its quote is unpaid. */
    private static function expireIfConfirmedUnpaid(array $invoice, \Cashu\MintQuote $quote): void {
        if ($invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time()
            && self::quoteIsDefinitelyUnpaid($quote)) {
            self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
        }
    }

    /**
     * Poll pending quotes and process payments with rate limiting and backoff
     *
     * @param int $minInterval Minimum seconds between polls for the same invoice (default 30)
     * @param int $batchLimit Maximum invoices to poll per call (default 10)
     */
    public static function pollPendingQuotes(int $minInterval = 30, int $batchLimit = 10): void {
        // First, mark all expired invoices without contacting the mint
        self::markExpiredInvoices();

        $now = time();

        // Fetch invoices that need polling with backoff strategy:
        // - Not expired
        // - Not recently polled (respects minInterval)
        // - Ordered by last_polled_at (NULL first = never polled)
        // - Limited batch size to avoid hammering mint
        //
        // The throttle MUST be `last_polled_at <= cutoff`, never
        // `(? - last_polled_at) >= ?`: PDO binds execute() params as TEXT,
        // and while a bare column comparison coerces the param via the
        // column's INTEGER affinity, an arithmetic expression has no
        // affinity — the old shape compared integer against text, which is
        // ALWAYS false in SQLite (integers sort below text). Every batch
        // poller carried it, so a stamped invoice was never re-polled and
        // cron could only settle payments made before its FIRST pass (the
        // payment page's 2s poll masked this for open tabs). Same quirk
        // SwapPoller::pollPending and OnchainPayments::pollPending document.
        // The four rail pollers below follow this same pattern.
        // A New invoice that has just expired is still polled for up to
        // EXPIRY_CONFIRM_WINDOW, and expired only once the mint says the
        // quote is unpaid (see markExpiredInvoices()).
        // A long-lived invoice (90-day expiration_time) is polled only while
        // its CURRENT bolt11 is payable (ln_expires_at): a lapsed one cannot
        // be paid, and the page re-sources it (resetting last_polled_at) when
        // someone is looking. Same cutoff in the four rail pollers below.
        $cutoff = $now - $minInterval;
        $pendingInvoices = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'New'
             AND quote_id IS NOT NULL
             AND ((long_lived = 0 AND expiration_time > ?)
                  OR (long_lived = 1 AND COALESCE(ln_expires_at, expiration_time) > ?))
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?",
            [$now - self::EXPIRY_CONFIRM_WINDOW, $now - self::EXPIRY_CONFIRM_WINDOW, $cutoff, $batchLimit]
        );

        if (empty($pendingInvoices)) {
            return;
        }

        foreach ($pendingInvoices as $invoice) {
            try {
                // Update last_polled_at before polling (so we don't re-poll on failure)
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                // Get wallet for the exact mint that issued this invoice's quote
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);

                // Check quote status
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);
                self::recordPollOutcome($invoice['id'], (string)$quoteStatus->state, null);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                } else {
                    self::expireIfConfirmedUnpaid($invoice, $quoteStatus);
                }
            } catch (\Throwable $e) {
                // \Throwable, not Exception: a TypeError on one row must not
                // abort the rest of the batch.
                self::recordPollOutcome($invoice['id'], null, $e->getMessage());
                error_log("CashuPayServer: Error polling invoice {$invoice['id']} (" . get_class($e) . "): " . $e->getMessage());
            }
        }
    }

    /**
     * Mint tokens and store proofs
     */
    private static function mintAndStoreTokens(array $invoice, Wallet $wallet): void {
        self::clearWebhookQueue();

        // The money is at the mint, but this seed may not issue secrets there
        // until its scan completed (an imported seed; a mint added after the
        // fact). Leave the invoice as it is — the quote stays PAID at the mint
        // and the regular/late-payment pollers collect it once the account is
        // ready — rather than claiming Processing and failing inside mint().
        if ($wallet->requiresRecovery()) {
            throw new Exception(
                'Payment received at the mint, but the wallet is still being set up (recovering); '
                . 'it will be collected once recovery completes.'
            );
        }

        // Mark as Processing BEFORE minting — status-guarded so a worker that
        // read the row before another one settled it never overwrites Settled
        // with Processing (and then re-fires InvoiceSettled). Expired/Invalid
        // are claimable too: the late-payment recovery reaches here only after
        // the mint said PAID, i.e. money that already arrived.
        $claimed = Database::update(
            'invoices',
            ['status' => 'Processing'],
            "id = ? AND status IN ('New', 'Processing', 'Expired', 'Invalid')",
            [$invoice['id']]
        );
        if ($claimed !== 1) {
            return; // already settled (or gone) by another path
        }

        // Mint tokens - library stores proofs in cashu_proofs with quote_id
        $proofs = $wallet->mint($invoice['quote_id'], $invoice['amount_sats']);

        // Update invoice status in a transaction
        Database::beginTransaction();

        try {
            self::queueWebhook($invoice['store_id'], 'InvoiceReceivedPayment', $invoice);

            // Status-guarded settle: the customer browser polls every ~2s while
            // cron and the API can poll the same invoice concurrently. Only the
            // caller that actually flips the row New/Processing -> Settled may
            // fire InvoiceSettled + the merchant notification; without this gate
            // every racing poller re-fires them. rowCount()===0 means another
            // path already settled, so we bail (the mint() above is idempotent
            // via the library's deterministic secrets / pending-op rebuild).
            $settled = Database::update(
                'invoices',
                ['status' => 'Settled', 'paid_at' => time(), 'settled_rail' => 'mint'],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            if ($settled !== 1) {
                Database::rollback();
                self::clearWebhookQueue();
                return;
            }
            $updatedInvoice = self::getById($invoice['id']);
            self::queueWebhook($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);

            Database::commit();

            self::flushWebhookQueue();
            NotificationSender::queueInvoicePaid($updatedInvoice);
        } catch (\Throwable $e) {
            // Catch \Throwable, not Exception: a \TypeError/\Error thrown
            // mid-transaction (PDO is a process-wide singleton) would otherwise
            // leave the transaction open and wedge every later write in this
            // request/cron pass with "active transaction already".
            Database::rollback();
            self::clearWebhookQueue();
            throw $e;
        }
    }

    /**
     * Poll LUD-21 verify URLs for LNURL-direct-receive invoices. Mirrors
     * {@see pollPendingQuotes}: rate-limited via last_polled_at, batched, and
     * settles the invoice with payment_rail='lnaddress' on settled=true.
     *
     * Unlike the mint path, no tokens get minted into our wallet — the
     * payment lands directly at the merchant's LN address. We just record
     * the settlement (with preimage for cryptographic proof) and fire the
     * InvoiceSettled webhook.
     */
    public static function pollPendingLnAddress(int $minInterval = 30, int $batchLimit = 10): void {
        self::markExpiredInvoices();
        $now = time();

        // Cutoff comparison — see pollPendingQuotes for the PDO TEXT-affinity
        // quirk the old `(? - last_polled_at) >= ?` shape tripped over.
        $cutoff = $now - $minInterval;
        $pending = Database::fetchAll(
            "SELECT * FROM invoices
              WHERE status = 'New'
                AND payment_rail = 'lnaddress'
                AND lnurl_verify_url IS NOT NULL
                AND ((long_lived = 0 AND expiration_time > ?)
                     OR (long_lived = 1 AND COALESCE(ln_expires_at, expiration_time) > ?))
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $now, $cutoff, $batchLimit]
        );

        if (empty($pending)) {
            return;
        }

        foreach ($pending as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);
                $result = LnUrlReceive::pollVerifyUrl((string)$invoice['lnurl_verify_url']);
                if ($result['state'] === 'paid') {
                    self::markLnAddressPaid($invoice, $result['preimage']);
                }
            } catch (Throwable $e) {
                error_log("[lnurl-receive] poll failed for invoice {$invoice['id']}: " . $e->getMessage());
                AdminLog::log('lnurl', 'poll', $invoice['store_id'], $invoice['id'],
                    $invoice['ln_destination'] ?? null, $e->getMessage());
            }
        }
    }

    /**
     * Single-invoice variant of {@see pollPendingLnAddress} for the live
     * payment-page poll. No rate-limit gate; the customer's tab is already
     * waiting for us.
     */
    public static function pollSingleLnAddress(string $invoiceId): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice) {
            return;
        }
        if (($invoice['payment_rail'] ?? null) !== 'lnaddress') {
            return;
        }
        if (!in_array($invoice['status'], ['New', 'Processing'], true)) {
            return;
        }
        // Past the confirm window expire on the clock; within it ask the rail
        // first, so a payment that landed in the last seconds is settled
        // rather than closed as Expired.
        if (self::expiredBeyondConfirmWindow($invoice)) {
            self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            return;
        }
        $pastExpiry = $invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time();
        if (empty($invoice['lnurl_verify_url'])) {
            if ($pastExpiry) {
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
            return;
        }
        try {
            Database::update('invoices', ['last_polled_at' => time()], 'id = ?', [$invoice['id']]);
            $result = LnUrlReceive::pollVerifyUrl((string)$invoice['lnurl_verify_url']);
            if ($result['state'] === 'paid') {
                self::markLnAddressPaid($invoice, $result['preimage']);
            } elseif ($pastExpiry && $result['state'] === 'pending') {
                // The host answered "not paid" after expiry: close it. An
                // unreachable host ('error') keeps it open within the window.
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
        } catch (Throwable $e) {
            error_log("[lnurl-receive] single poll failed for {$invoiceId}: " . $e->getMessage());
            AdminLog::log('lnurl', 'poll', $invoice['store_id'], $invoiceId,
                $invoice['ln_destination'] ?? null, $e->getMessage());
        }
    }

    /**
     * Mark an LNURL-rail invoice as Settled. Stores the preimage from the
     * LUD-21 verify response as cryptographic settlement proof, then fires
     * webhooks + notification queue parallel to the mint-rail settlement
     * path. No minting because the funds went straight to the merchant LN
     * address — there are no proofs in our wallet.
     */
    private static function markLnAddressPaid(array $invoice, ?string $preimage): void {
        Database::beginTransaction();
        try {
            // Status-guarded settle (see mintAndStoreTokens): the lnaddress poll
            // can run from the checkout poll, cron, and the API at once. Only the
            // winner of the New/Processing -> Settled transition records the fee
            // credit + fires webhooks/notifications below.
            $settled = Database::update(
                'invoices',
                [
                    'status' => 'Settled',
                    'paid_at' => time(),
                    'settled_rail' => 'lnaddress',
                    'lnurl_preimage' => $preimage ?: null,
                ],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        if ($settled !== 1) {
            return; // already settled by another poller
        }

        $updated = self::getById((string)$invoice['id']);
        if ($updated !== null) {
            // The lightning rail just settled (settled_rail='lnaddress'). If
            // that rail was routed to a fee payee, the customer paid the fee
            // LNURL directly: record the credit and skip the merchant "paid"
            // notice. Otherwise it was the merchant's own LN address and the
            // merchant should be notified.
            $settledToFee = self::railIsFeeRouted($updated, 'lightning');
            if ($settledToFee) {
                self::recordFeeRedirectCredit($updated, $preimage);
            }
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updated);
            if (!$settledToFee) {
                NotificationSender::queueInvoicePaid($updated);
            }
        }
        error_log(sprintf(
            '[lnurl-receive] invoice=%s store=%s settled via lnaddress (preimage=%s)',
            $invoice['id'], $invoice['store_id'],
            $preimage ? substr($preimage, 0, 8) . '…' : 'missing'
        ));
    }

    /**
     * Cron poll for pending NWC-rail invoices: lookup_invoice against the
     * merchant's wallet for each. Unlike the noffer receipt poll this is
     * fully reliable — lookup_invoice is a stored-state query, not an
     * ephemeral-event race — so it settles invoices whose customer closed
     * the payment page.
     */
    public static function pollPendingNwc(int $minInterval = 15, int $batchLimit = 10): void {
        self::markExpiredInvoices();
        $now = time();

        // Cutoff comparison — see pollPendingQuotes for the PDO TEXT-affinity
        // quirk the old `(? - last_polled_at) >= ?` shape tripped over.
        $cutoff = $now - $minInterval;
        $pending = Database::fetchAll(
            "SELECT * FROM invoices
              WHERE status = 'New'
                AND payment_rail = 'nwc'
                AND nwc_payment_hash IS NOT NULL
                AND ((long_lived = 0 AND expiration_time > ?)
                     OR (long_lived = 1 AND COALESCE(ln_expires_at, expiration_time) > ?))
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $now, $cutoff, $batchLimit]
        );

        foreach ($pending as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);
                self::checkNwcSettlement($invoice);
            } catch (Throwable $e) {
                error_log("[nwc-receive] cron poll failed for invoice {$invoice['id']}: " . $e->getMessage());
                AdminLog::log('nwc', 'poll', $invoice['store_id'], $invoice['id'],
                    $invoice['ln_destination'] ?? null, $e->getMessage());
            }
        }
    }

    /**
     * Single-invoice NWC poll for the live payment-page tick. Unlike
     * pollSingleLnAddress (a cheap HTTPS GET) every NWC check is a websocket
     * round trip through a Nostr relay, so this keeps a small atomic
     * min-interval gate: of the 2s checkout ticks (and any concurrent tabs),
     * only one wins the CAS on last_polled_at per window and actually
     * contacts the relay; the rest return and re-read status next tick.
     */
    public static function pollSingleNwc(string $invoiceId): void {
        $minInterval = 5;
        $invoice = self::getById($invoiceId);
        if (!$invoice) {
            return;
        }
        if (($invoice['payment_rail'] ?? null) !== 'nwc') {
            return;
        }
        if (!in_array($invoice['status'], ['New', 'Processing'], true)) {
            return;
        }
        if (self::expiredBeyondConfirmWindow($invoice)) {
            self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            return;
        }
        $pastExpiry = $invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time();
        if (empty($invoice['nwc_uri']) || empty($invoice['nwc_payment_hash'])) {
            if ($pastExpiry) {
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
            return;
        }
        $now = time();
        $claimed = Database::update(
            'invoices',
            ['last_polled_at' => $now],
            'id = ? AND (last_polled_at IS NULL OR last_polled_at <= ?)',
            [$invoice['id'], $now - $minInterval]
        );
        if ($claimed !== 1) {
            return; // another poller checked within the window
        }
        try {
            self::checkNwcSettlement($invoice);
            if ($pastExpiry) {
                // The wallet was asked and did not report it paid: close it.
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
        } catch (Throwable $e) {
            error_log("[nwc-receive] single poll failed for {$invoiceId}: " . $e->getMessage());
            AdminLog::log('nwc', 'poll', $invoice['store_id'], $invoiceId,
                $invoice['ln_destination'] ?? null, $e->getMessage());
        }
    }

    /**
     * One lookup_invoice round trip for a pending NWC-rail invoice; settles
     * it when the wallet reports the invoice paid. NOT_FOUND (found=false)
     * is left pending — the expiry sweep will close it out.
     */
    private static function checkNwcSettlement(array $invoice): void {
        $result = NwcClient::lookupInvoice(
            (string)$invoice['nwc_uri'],
            (string)$invoice['nwc_payment_hash']
        );
        if ($result['found'] && $result['paid']) {
            self::markNwcPaid($invoice, $result['preimage']);
        }
    }

    /**
     * Mark an NWC-rail invoice as Settled. Stores the preimage lookup_invoice
     * returned as settlement proof, then fires webhooks + notification queue
     * parallel to the lnaddress settlement path. No minting — the funds went
     * straight into the merchant's own wallet. The NWC rail is never
     * fee-routed (fee-redirect lightning rides payment_rail='lnaddress'), so
     * there is no fee-credit branch here.
     */
    private static function markNwcPaid(array $invoice, ?string $preimage): void {
        Database::beginTransaction();
        try {
            // Status-guarded settle (see mintAndStoreTokens): the checkout
            // poll, cron, and the API can race. Only the winner of the
            // New/Processing -> Settled transition fires webhooks below.
            $settled = Database::update(
                'invoices',
                [
                    'status' => 'Settled',
                    'paid_at' => time(),
                    'settled_rail' => 'nwc',
                    'nwc_preimage' => $preimage ?: null,
                ],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        if ($settled !== 1) {
            return; // already settled by another poller
        }

        $updated = self::getById((string)$invoice['id']);
        if ($updated !== null) {
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updated);
            NotificationSender::queueInvoicePaid($updated);
        }
        error_log(sprintf(
            '[nwc-receive] invoice=%s store=%s settled via nwc (preimage=%s)',
            $invoice['id'], $invoice['store_id'],
            $preimage ? substr($preimage, 0, 8) . '…' : 'missing'
        ));
    }

    /**
     * Cron poll for pending Strike-rail invoices: read each Strike invoice
     * back until it reports PAID. Like the NWC lookup this is a stored-state
     * query, so it settles invoices whose customer closed the payment page.
     */
    public static function pollPendingStrike(int $minInterval = 15, int $batchLimit = 10): void {
        self::markExpiredInvoices();
        $now = time();

        // Cutoff comparison — see pollPendingQuotes for the PDO TEXT-affinity
        // quirk the old `(? - last_polled_at) >= ?` shape tripped over.
        $cutoff = $now - $minInterval;
        $pending = Database::fetchAll(
            "SELECT * FROM invoices
              WHERE status = 'New'
                AND payment_rail = 'strike'
                AND strike_invoice_id IS NOT NULL
                AND ((long_lived = 0 AND expiration_time > ?)
                     OR (long_lived = 1 AND COALESCE(ln_expires_at, expiration_time) > ?))
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $now, $cutoff, $batchLimit]
        );

        foreach ($pending as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);
                self::checkStrikeSettlement($invoice);
            } catch (Throwable $e) {
                error_log("[strike-receive] cron poll failed for invoice {$invoice['id']}: " . $e->getMessage());
                AdminLog::log('strike', 'poll', $invoice['store_id'], $invoice['id'],
                    $invoice['ln_destination'] ?? null, $e->getMessage());
            }
        }
    }

    /**
     * Single-invoice Strike poll for the live payment-page tick. Each check
     * is an HTTPS round trip against the Strike API, so — like pollSingleNwc —
     * a small atomic min-interval gate keeps the 2s checkout ticks (and any
     * concurrent tabs) from hammering the API and its rate limits.
     */
    public static function pollSingleStrike(string $invoiceId): void {
        $minInterval = 5;
        $invoice = self::getById($invoiceId);
        if (!$invoice) {
            return;
        }
        if (($invoice['payment_rail'] ?? null) !== 'strike') {
            return;
        }
        if (!in_array($invoice['status'], ['New', 'Processing'], true)) {
            return;
        }
        if (self::expiredBeyondConfirmWindow($invoice)) {
            self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            return;
        }
        $pastExpiry = $invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time();
        if (empty($invoice['strike_invoice_id']) || empty($invoice['strike_api_key'])) {
            if ($pastExpiry) {
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
            return;
        }
        $now = time();
        $claimed = Database::update(
            'invoices',
            ['last_polled_at' => $now],
            'id = ? AND (last_polled_at IS NULL OR last_polled_at <= ?)',
            [$invoice['id'], $now - $minInterval]
        );
        if ($claimed !== 1) {
            return; // another poller checked within the window
        }
        try {
            self::checkStrikeSettlement($invoice);
            if ($pastExpiry) {
                // Strike was asked and did not report it paid: close it.
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
        } catch (Throwable $e) {
            error_log("[strike-receive] single poll failed for {$invoiceId}: " . $e->getMessage());
            AdminLog::log('strike', 'poll', $invoice['store_id'], $invoiceId,
                $invoice['ln_destination'] ?? null, $e->getMessage());
        }
    }

    /**
     * One read-back round trip for a pending Strike-rail invoice; settles it
     * when Strike reports state=PAID. A CANCELLED invoice is left pending —
     * the expiry sweep will close it out.
     */
    private static function checkStrikeSettlement(array $invoice): void {
        $result = StrikeClient::findInvoice(
            (string)$invoice['strike_api_key'],
            (string)$invoice['strike_invoice_id']
        );
        if ($result['state'] === 'paid') {
            self::markStrikePaid($invoice);
        }
    }

    /**
     * Mark a Strike-rail invoice as Settled. Strike's read-back has no
     * preimage to record — its PAID state is the settlement assertion of the
     * account that received the funds. Fires webhooks + notification queue
     * parallel to the other direct-receive settlement paths. No minting —
     * the funds landed in the merchant's Strike account. The Strike rail is
     * never fee-routed (fee-redirect lightning rides payment_rail=
     * 'lnaddress'), so there is no fee-credit branch here.
     */
    private static function markStrikePaid(array $invoice): void {
        Database::beginTransaction();
        try {
            // Status-guarded settle (see mintAndStoreTokens): the checkout
            // poll, cron, and the API can race. Only the winner of the
            // New/Processing -> Settled transition fires webhooks below.
            $settled = Database::update(
                'invoices',
                [
                    'status' => 'Settled',
                    'paid_at' => time(),
                    'settled_rail' => 'strike',
                ],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        if ($settled !== 1) {
            return; // already settled by another poller
        }

        $updated = self::getById((string)$invoice['id']);
        if ($updated !== null) {
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updated);
            NotificationSender::queueInvoicePaid($updated);
        }
        error_log(sprintf(
            '[strike-receive] invoice=%s store=%s settled via strike (strike_invoice_id=%s)',
            $invoice['id'], $invoice['store_id'], (string)$invoice['strike_invoice_id']
        ));
    }

    /**
     * Rebuild the CLINK client context (relay, keys, request id) from a
     * persisted noffer-rail invoice, or null if the row lacks it.
     *
     * @return array{relay:string,receiver_pubkey:string,ephemeral_sk:string,
     *               ephemeral_pubkey:string,request_event_id:string,created_at:int}|null
     */
    public static function nofferCtxFromInvoice(array $invoice): ?array {
        foreach (['noffer_relay', 'noffer_receiver_pubkey', 'noffer_ephemeral_sk',
                  'noffer_ephemeral_pubkey', 'noffer_request_event_id'] as $k) {
            if (empty($invoice[$k])) {
                return null;
            }
        }
        return [
            'relay' => (string)$invoice['noffer_relay'],
            'receiver_pubkey' => (string)$invoice['noffer_receiver_pubkey'],
            'ephemeral_sk' => (string)$invoice['noffer_ephemeral_sk'],
            'ephemeral_pubkey' => (string)$invoice['noffer_ephemeral_pubkey'],
            'request_event_id' => (string)$invoice['noffer_request_event_id'],
            'created_at' => (int)($invoice['noffer_created_at'] ?? 0),
        ];
    }

    /**
     * Cron best-effort receipt poll for noffer-rail invoices. Re-subscribes to
     * each invoice's relay and looks for the merchant's kind-21001 payment
     * receipt. NOTE: kind 21001 is ephemeral; spec-compliant relays may not
     * retain it, so this only catches receipts on retention-friendly relays —
     * the payment page's live subscription is the reliable path.
     */
    public static function pollPendingNoffer(int $minInterval = 30, int $batchLimit = 10): void {
        self::markExpiredInvoices();
        $now = time();

        // Cutoff comparison — see pollPendingQuotes for the PDO TEXT-affinity
        // quirk the old `(? - last_polled_at) >= ?` shape tripped over.
        $cutoff = $now - $minInterval;
        $pending = Database::fetchAll(
            "SELECT * FROM invoices
              WHERE status = 'New'
                AND payment_rail = 'noffer'
                AND noffer_request_event_id IS NOT NULL
                AND ((long_lived = 0 AND expiration_time > ?)
                     OR (long_lived = 1 AND COALESCE(ln_expires_at, expiration_time) > ?))
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $now, $cutoff, $batchLimit]
        );

        foreach ($pending as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);
                $ctx = self::nofferCtxFromInvoice($invoice);
                if ($ctx === null) {
                    continue;
                }
                $res = ClinkClient::fetchReceipt($ctx);
                if (!empty($res['paid'])) {
                    self::markNofferPaid($invoice);
                }
            } catch (Throwable $e) {
                error_log("[clink-receive] cron poll failed for invoice {$invoice['id']}: " . $e->getMessage());
                AdminLog::log('noffer', 'poll', $invoice['store_id'], $invoice['id'],
                    $invoice['ln_destination'] ?? null, $e->getMessage());
            }
        }
    }

    /**
     * Single-invoice noffer receipt poll for the live payment-page tick. The
     * browser's own relay subscription is the fast path, but it can miss the
     * ephemeral kind-21001 receipt entirely — blocked WebSocket (ws:// relay
     * on an https page), a reconnect gap, a suspended tab — and a missed
     * ephemeral event is unrecoverable from a spec-compliant relay. So the
     * checkout poll re-subscribes server-side and keeps the subscription open
     * briefly past EOSE: that catches both receipts a retention-friendly relay
     * replays AND receipts broadcast live during the window. Same CAS
     * min-interval gate as pollSingleNwc — each check is a relay round trip,
     * so only one 2s tick per window pays for it (and the fresh
     * last_polled_at keeps cron's batch poll off invoices a page is watching).
     */
    public static function pollSingleNoffer(string $invoiceId): void {
        $minInterval = 5;
        $liveWindowSec = 4;
        $invoice = self::getById($invoiceId);
        if (!$invoice || ($invoice['payment_rail'] ?? null) !== 'noffer') {
            return;
        }
        // noffer settles New -> Settled directly; anything else is done here.
        if ($invoice['status'] !== 'New') {
            return;
        }
        if (self::expiredBeyondConfirmWindow($invoice)) {
            self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            return;
        }
        $pastExpiry = (int)$invoice['expiration_time'] < time();
        $ctx = self::nofferCtxFromInvoice($invoice);
        if ($ctx === null) {
            if ($pastExpiry) {
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
            return;
        }
        $now = time();
        $claimed = Database::update(
            'invoices',
            ['last_polled_at' => $now],
            'id = ? AND (last_polled_at IS NULL OR last_polled_at <= ?)',
            [$invoice['id'], $now - $minInterval]
        );
        if ($claimed !== 1) {
            return; // another poller checked within the window
        }
        try {
            $res = ClinkClient::fetchReceipt($ctx, $liveWindowSec, true);
            if (!empty($res['paid'])) {
                self::markNofferPaid($invoice);
            } elseif ($pastExpiry) {
                // The relays were asked and no receipt was found: close it.
                self::updateStatus((string)$invoice['id'], 'Expired', null, null, ['New']);
            }
        } catch (Throwable $e) {
            error_log("[clink-receive] single poll failed for {$invoiceId}: " . $e->getMessage());
            AdminLog::log('noffer', 'poll', $invoice['store_id'], $invoiceId,
                $invoice['ln_destination'] ?? null, $e->getMessage());
        }
    }

    /**
     * Settle a noffer-rail invoice from a receipt event the payment page
     * forwarded off its live subscription. Returns true only when the event is
     * a valid, merchant-signed payment receipt for this invoice. All trust is
     * the merchant's Schnorr signature — the browser relays but cannot forge.
     */
    public static function settleNofferFromReceiptEvent(string $invoiceId, array $rawEvent): bool {
        $invoice = self::getById($invoiceId);
        if (!$invoice || ($invoice['payment_rail'] ?? null) !== 'noffer') {
            return false;
        }
        if (($invoice['status'] ?? null) === 'Settled') {
            return true;
        }
        $ctx = self::nofferCtxFromInvoice($invoice);
        if ($ctx === null) {
            return false;
        }
        $verdict = ClinkClient::verifyReceiptEvent($rawEvent, $ctx);
        if (empty($verdict['paid'])) {
            // Say WHY: a screen stuck on "waiting for payment" while the
            // wallet insists "receipt sent" is undiagnosable without this.
            error_log(sprintf(
                '[clink-receive] rejected forwarded receipt for invoice=%s: %s',
                $invoiceId, (string)($verdict['reason'] ?? 'unspecified')
            ));
            return false;
        }
        self::markNofferPaid($invoice);
        return true;
    }

    /**
     * Mark a noffer-rail invoice as Settled. Funds went straight to the
     * merchant's CLINK wallet (no proofs in ours, no preimage in the receipt),
     * so this just records the settlement and fires webhook + notification,
     * status-guarded so the page and cron can't double-fire.
     */
    private static function markNofferPaid(array $invoice): void {
        Database::beginTransaction();
        try {
            $settled = Database::update(
                'invoices',
                [
                    'status' => 'Settled',
                    'paid_at' => time(),
                    'settled_rail' => 'noffer',
                ],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        if ($settled !== 1) {
            return; // already settled by another path
        }

        $updated = self::getById((string)$invoice['id']);
        if ($updated !== null) {
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updated);
            NotificationSender::queueInvoicePaid($updated);
        }
        error_log(sprintf(
            '[clink-receive] invoice=%s store=%s settled via noffer receipt',
            $invoice['id'], $invoice['store_id']
        ));
    }

    // =========================================================================
    // LONG-LIVED INVOICES: Lightning refresh + fiat re-quote
    //
    // A long-lived invoice (invoices.long_lived = 1) is a shareable payment
    // request: it stays payable for Config::getSharedInvoiceLifetime() while
    // its Lightning bolt11 lapses every hour or so. The payment page keeps the
    // Lightning side payable by re-sourcing the bolt11 (same destination chain
    // / mint failover as creation, never a submarine swap) when it lapses —
    // on page load and, while someone is watching, when the timer runs out.
    // A fiat-denominated request is also re-quoted in sats on page load, so a
    // link opened days after it was made asks for today's value; after that
    // load the amounts stay fixed for the viewing session.
    //
    // Replaced bolt11s are parked in invoice_ln_quotes and still polled for a
    // grace period, so a payer who scanned the previous QR seconds before the
    // refresh is settled against the quote they actually paid.
    //
    // Fee-redirected invoices (fee_redirect_rails set): the fee was owed in
    // sats, so the sats decided at creation are locked — no fiat re-quote on
    // either rail. A fee-routed Lightning rail still refreshes when its bolt11
    // lapses, re-fetched from the same fee payee (ln_destination) at the same
    // amount with nothing but that amount in the request (see
    // FeeRedirect's PRIVACY note); routing never changes mid-life, so a
    // superseded fee quote paid late still credits the fee.
    // =========================================================================

    /** A bolt11 this close to lapsing may already be refreshed. */
    public const LN_REFRESH_LEAD_SEC = 30;
    /** At most one Lightning re-source per invoice per this many seconds. */
    public const LN_REFRESH_MIN_INTERVAL = 15;
    /** At most one fiat re-quote per invoice per this many seconds. */
    public const REQUOTE_MIN_INTERVAL = 60;
    /** How long past its own expiry a superseded bolt11 is still polled. */
    public const SUPERSEDED_QUOTE_GRACE = 3600;
    /** Rails whose bolt11 comes from a merchant destination (not a mint). */
    private const DIRECT_RAILS = ['strike', 'lnaddress', 'nwc', 'noffer'];
    /** Every Lightning-rail column; reset together on a refresh. */
    private const LN_COLUMNS = [
        'payment_rail', 'bolt11', 'quote_id', 'mint_url', 'lnurl_verify_url', 'lnurl_preimage',
        'ln_destination', 'noffer_relay', 'noffer_receiver_pubkey', 'noffer_ephemeral_sk',
        'noffer_ephemeral_pubkey', 'noffer_request_event_id', 'noffer_created_at',
        'nwc_uri', 'nwc_payment_hash', 'nwc_preimage', 'strike_invoice_id', 'strike_api_key',
    ];

    /**
     * When an invoice's Lightning side lapses: the mint quote's own expiry,
     * else what the bolt11 encodes (BOLT #11 `x` field, default 3600 s), else
     * the default invoice window. Null when there is no bolt11 at all.
     */
    public static function lightningExpiresAt(?string $bolt11, $quote, int $now): ?int {
        if ($bolt11 === null || $bolt11 === '') {
            return null;
        }
        if ($quote !== null && isset($quote->expiry) && (int)$quote->expiry > 0) {
            return (int)$quote->expiry;
        }
        require_once __DIR__ . '/bolt11_expiry.php';
        $decoded = Bolt11Expiry::expiresAt($bolt11);
        // An expiry already (nearly) in the past would put the page into a
        // refresh loop; treat it like an unreadable one.
        if ($decoded !== null && $decoded > $now + self::LN_REFRESH_LEAD_SEC) {
            return $decoded;
        }
        return $now + Config::getInvoiceExpiration();
    }

    /** Is this invoice's current bolt11 missing or about to lapse? */
    public static function lightningRefreshDue(array $invoice, ?int $now = null): bool {
        $now = $now ?? time();
        if (empty($invoice['bolt11'])) {
            return true;
        }
        $exp = (int)($invoice['ln_expires_at'] ?? 0);
        return $exp > 0 && $exp <= $now + self::LN_REFRESH_LEAD_SEC;
    }

    /**
     * Payment-page load hook for a long-lived invoice: re-quote a fiat amount
     * at today's rate, then re-source the Lightning invoice when the amount
     * changed or the bolt11 has lapsed. Never throws — a failure here leaves
     * the page rendering whatever the invoice already carries.
     */
    public static function prepareForView(string $invoiceId): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice || empty($invoice['long_lived']) || $invoice['status'] !== 'New') {
            return;
        }
        try {
            $requoted = self::requoteForView($invoice);
            $invoice = self::getById($invoiceId);
            if ($invoice && $invoice['status'] === 'New'
                    && !$requoted['lightning_done'] && self::lightningRefreshDue($invoice)) {
                self::refreshLightning($invoiceId);
            }
        } catch (Throwable $e) {
            error_log("[long-lived] prepareForView failed for {$invoiceId}: " . $e->getMessage());
        }
    }

    /** Is this currency priced through an exchange rate (fiat) rather than BTC/sat? */
    public static function isFiatCurrency(string $currency): bool {
        return !in_array(strtoupper($currency), ['SAT', 'SATS', 'MSAT', 'BTC'], true);
    }

    /**
     * Re-quote a long-lived fiat invoice's sats amounts at the current rate.
     *
     *   - Lightning: re-sourced at the new amount through refreshLightning
     *     (which also handles the lapsed-bolt11 case), so amount_sats and the
     *     bolt11 only ever change together. A failed re-source leaves the
     *     previous bolt11 + amount in place.
     *   - On-chain: onchain_amount_sat is moved to the new value and
     *     onchain_amount_floor_sat keeps the smallest amount ever shown, so a
     *     payer who already saw (and pays) an earlier amount still settles.
     *     Only for invoice-unique addresses (xpub / Strike): a static shared
     *     address attributes by exact amount, so its amount stays locked.
     *   - Nothing moves once a payment has been seen on-chain, and reloads
     *     are throttled to one re-quote per REQUOTE_MIN_INTERVAL.
     *   - Fee-redirected invoices are never re-quoted: the fee is owed in
     *     sats and the amount decided at creation is what the payee is due.
     *     This covers every rail of such an invoice, including a merchant
     *     rail on a mixed one — the fee's share must stay as decided.
     *
     * @return array{requoted:bool, lightning_done:bool} lightning_done is true
     *         when this call already (re)sourced the bolt11.
     */
    public static function requoteForView(array $invoice): array {
        $none = ['requoted' => false, 'lightning_done' => false];
        if (empty($invoice['long_lived']) || $invoice['status'] !== 'New'
                || !self::isFiatCurrency((string)$invoice['currency'])
                || !empty($invoice['fee_redirect_rails'])) {
            return $none;
        }
        $now = time();
        if ((int)($invoice['requoted_at'] ?? 0) > $now - self::REQUOTE_MIN_INTERVAL) {
            return $none;
        }
        if (!empty($invoice['onchain_first_seen_at'])) {
            return $none;
        }
        $seen = Database::fetchOne(
            "SELECT 1 AS x FROM onchain_payments WHERE invoice_id = ? LIMIT 1", [$invoice['id']]
        );
        if ($seen) {
            return $none;
        }
        // Stamp first: a rate outage must not make every reload retry at once.
        Database::update('invoices', ['requoted_at' => $now], 'id = ? AND status = ?', [$invoice['id'], 'New']);

        $store = Config::getStore($invoice['store_id']);
        if (!$store) {
            return $none;
        }
        try {
            $newSats = (int)ExchangeRates::convertToSats((string)$invoice['amount'], (string)$invoice['currency'], 'sat');
            $rate = ExchangeRates::getBtcPrice(
                (string)$invoice['currency'],
                $store['price_provider_primary'] ?? 'coingecko',
                $store['price_provider_secondary'] ?? 'binance'
            );
        } catch (Throwable $e) {
            error_log("[long-lived] re-quote skipped for {$invoice['id']}: rate unavailable: " . $e->getMessage());
            return $none;
        }
        if ($newSats <= 0) {
            return $none;
        }

        // ---- Lightning first: re-source at the new amount when it differs ----
        // (or when the bolt11 has lapsed anyway). The on-chain amount below
        // only moves when the Lightning side is consistent with it: a refresh
        // blocked by a concurrent one ('busy') or a settled invoice leaves
        // everything as it is; a destination that is down ('unavailable')
        // keeps the old bolt11 (it encodes its own amount) while on-chain
        // still re-quotes.
        $changed = false;
        $lightningDone = false;
        $lnChanged = false;
        if (!empty($invoice['bolt11']) && !empty($invoice['payment_rail'])) {
            $rail = (string)$invoice['payment_rail'];
            if (in_array($rail, self::DIRECT_RAILS, true)) {
                $lnChanged = (int)$invoice['amount_sats'] !== $newSats;
            } elseif ($rail === 'mint') {
                try {
                    $mintTarget = ExchangeRates::convertToMintUnit(
                        (string)$invoice['amount'], (string)$invoice['currency'],
                        (string)($store['mint_unit'] ?? 'sat'),
                        (float)($store['exchange_fee_percent'] ?? 0),
                        $store['price_provider_primary'] ?? null,
                        $store['price_provider_secondary'] ?? null
                    );
                    $lnChanged = (int)$invoice['amount_sats'] !== (int)$mintTarget;
                } catch (Throwable $e) {
                    $lnChanged = false;
                }
            } elseif ($rail === 'swap') {
                // Swaps are never refreshed in place; a direct rail or mint
                // may still take the invoice over at the new amount.
                $lnChanged = (int)$invoice['amount_sats'] !== $newSats;
            }
        }
        if ($lnChanged || self::lightningRefreshDue($invoice, $now)) {
            $res = self::refreshLightning((string)$invoice['id'], ['force' => true, 'requote' => true]);
            $lightningDone = true;
            if (!empty($res['ok'])) {
                $changed = true;
            } elseif (in_array($res['reason'] ?? '', ['busy', 'settled', 'not_refreshable'], true)) {
                return ['requoted' => false, 'lightning_done' => true];
            } elseif (empty($invoice['bolt11']) && (int)$invoice['amount_sats'] !== $newSats) {
                // No Lightning rail could be sourced (none at creation, none
                // now): nothing ties amount_sats to a bolt11, so move the
                // plain sats figure (Cashu request / API) to today's value.
                Database::update(
                    'invoices', ['amount_sats' => $newSats],
                    "id = ? AND status = 'New' AND bolt11 IS NULL", [$invoice['id']]
                );
                $changed = true;
            }
        }

        // ---- On-chain amount (unique-address modes only) ----
        if (!empty($invoice['onchain_address']) && empty($invoice['fee_redirect_rails'])) {
            $staticShared = (($store['onchain_address_mode'] ?? 'xpub') === 'static')
                && empty($invoice['strike_receive_request_id']);
            $current = (int)($invoice['onchain_amount_sat'] ?? 0);
            if (!$staticShared && $current > 0 && $current !== $newSats) {
                $floor = (int)($invoice['onchain_amount_floor_sat'] ?? 0);
                $floor = $floor > 0 ? min($floor, $current, $newSats) : min($current, $newSats);
                Database::update(
                    'invoices',
                    ['onchain_amount_sat' => $newSats, 'onchain_amount_floor_sat' => $floor],
                    'id = ? AND status = ? AND onchain_first_seen_at IS NULL',
                    [$invoice['id'], 'New']
                );
                $changed = true;
            }
        }

        if ($changed && $rate !== null) {
            Database::update('invoices', ['exchange_rate' => $rate], 'id = ? AND status = ?', [$invoice['id'], 'New']);
        }
        return ['requoted' => $changed, 'lightning_done' => $lightningDone];
    }

    /**
     * Replace a long-lived invoice's Lightning bolt11 with a freshly sourced
     * one (merchant destination chain first, then the store's mints; never a
     * swap). The current bolt11 is checked for a payment first and, when
     * unpaid, parked in invoice_ln_quotes for a grace-period poll.
     *
     * A fee-routed Lightning rail (fee_redirect_rails includes 'lightning')
     * is re-fetched from the same fee payee at the invoice's locked sats
     * amount — never from the merchant chain or a mint, since that would
     * silently hand the fee's payment to the merchant. Unreachable payee ->
     * 'unavailable', the current bolt11 stays.
     *
     * Options:
     *   force    skip the "is it due yet" gate (re-quote / explicit request)
     *   requote  price the new bolt11 at the CURRENT rate instead of reusing
     *            the invoice's present sats amount (ignored on a fee-routed
     *            rail: its sats are locked)
     *
     * @return array{ok:bool, reason?:string, status?:string, invoice?:array,
     *               receive_errors?:array, ln_expires_at?:int}
     *   reasons: not_refreshable | not_due | busy | settled | unavailable
     */
    public static function refreshLightning(string $invoiceId, array $opts = []): array {
        $invoice = self::getById($invoiceId);
        if (!$invoice || empty($invoice['long_lived'])) {
            return ['ok' => false, 'reason' => 'not_refreshable'];
        }
        if ($invoice['status'] !== 'New') {
            return ['ok' => false, 'reason' => 'settled', 'status' => $invoice['status']];
        }
        $feeRails = array_filter(explode(',', (string)($invoice['fee_redirect_rails'] ?? '')));
        $feeRouted = in_array('lightning', $feeRails, true);
        $now = time();
        if (empty($opts['force']) && !self::lightningRefreshDue($invoice, $now)) {
            return ['ok' => false, 'reason' => 'not_due', 'ln_expires_at' => (int)$invoice['ln_expires_at'], 'invoice' => $invoice];
        }
        // One re-source per window across tabs / reloads / the cron. A re-quote
        // is already throttled by REQUOTE_MIN_INTERVAL, so it only needs the
        // lock as a mutex (interval 0), not as a rate limit — otherwise a
        // reload right after a timer-driven refresh could move the on-chain
        // amount while the bolt11 stayed at the old one.
        $lockInterval = !empty($opts['requote']) ? 0 : self::LN_REFRESH_MIN_INTERVAL;
        $claimed = Database::update(
            'invoices',
            ['ln_refresh_lock_at' => $now],
            "id = ? AND status = 'New' AND (ln_refresh_lock_at IS NULL OR ln_refresh_lock_at <= ?)",
            [$invoiceId, $now - $lockInterval]
        );
        if ($claimed !== 1) {
            return ['ok' => false, 'reason' => 'busy', 'invoice' => $invoice];
        }

        // The current bolt11 may have been paid in the last seconds: ask its
        // rail before replacing it, so that payment settles THIS invoice.
        if (!empty($invoice['bolt11'])) {
            try {
                self::checkLightningPaid($invoice);
            } catch (Throwable $e) {
                error_log("[long-lived] pre-refresh check failed for {$invoiceId}: " . $e->getMessage());
            }
            $invoice = self::getById($invoiceId);
            if (!$invoice || $invoice['status'] !== 'New') {
                return ['ok' => false, 'reason' => 'settled', 'status' => $invoice['status'] ?? 'Unknown'];
            }
        }

        $store = Config::getStore($invoice['store_id']);
        if (!$store) {
            return ['ok' => false, 'reason' => 'not_refreshable'];
        }
        $storeId = (string)$invoice['store_id'];
        $amount = (string)$invoice['amount'];
        $currency = (string)$invoice['currency'];
        $currentRail = (string)($invoice['payment_rail'] ?? '');
        $currentIsDirect = in_array($currentRail, self::DIRECT_RAILS, true);
        $requote = !empty($opts['requote']) && !$feeRouted;

        // Target amounts. Without a re-quote the payer keeps seeing the same
        // sats: a direct rail reuses amount_sats (plain sats), the mint rail
        // reuses amount_sats as the fixed mint-unit amount. Across a rail
        // class switch (wallet down -> mint, or back) the amount is derived
        // from the invoice amount the way creation would.
        $sourced = null;
        $receiveErrors = [];
        if ($feeRouted) {
            $sourced = self::sourceFeeLightning($invoice, $receiveErrors);
            if ($sourced === null) {
                error_log(sprintf('[long-lived] fee payee could not re-issue invoice=%s store=%s', $invoiceId, $storeId));
                AdminLog::log('lightning', 'refresh', $storeId, $invoiceId, (string)($invoice['ln_destination'] ?? ''),
                    'Fee-routed Lightning invoice could not be refreshed: the fee payee produced no bolt11');
                return [
                    'ok' => false, 'reason' => 'unavailable', 'invoice' => $invoice,
                    'receive_errors' => self::dedupeReceiveErrors($receiveErrors),
                ];
            }
            $sourced['_quote'] = null;
        }
        try {
            $directSats = (!$requote && $currentIsDirect && !empty($invoice['bolt11']))
                ? (int)$invoice['amount_sats']
                : (int)ExchangeRates::convertToSats($amount, $currency, 'sat');
        } catch (Throwable $e) {
            $directSats = $currentIsDirect ? (int)$invoice['amount_sats'] : 0;
        }
        $destinations = StoreLnAddresses::destinationsForStore($storeId);
        if ($sourced === null && $destinations !== [] && $directSats > 0) {
            $direct = self::sourceDirectReceive(
                $storeId, $store, $destinations, $directSats, self::metadataOf($invoice), $invoiceId, $receiveErrors
            );
            if ($direct !== null) {
                $sourced = self::lightningFieldsFromAttempt($direct['type'], $direct['attempt']);
                $sourced['_quote'] = null;
            }
        }
        // Strict no-mint-fallback: with swaps enabled the mint is only ever a
        // fallback for the swap rail, and strict mode forbids exactly that
        // (see Invoice::create). A refresh never creates a swap (decision 6b),
        // so under strict mode the mint is off the table here too — otherwise
        // a long-lived invoice created on-chain only would quietly move onto
        // the mint on its first page view.
        $strictMintBlocked = $sourced === null
            && SwapsConfig::isEnabledForStore($storeId)
            && SwapsConfig::strictNoMintFallbackForStore($storeId);
        if ($strictMintBlocked) {
            error_log(sprintf(
                '[long-lived] strict mode: mint fallback skipped on refresh invoice=%s store=%s',
                $invoiceId, $storeId
            ));
        }
        if ($sourced === null && !$strictMintBlocked && Config::isStoreConfigured($storeId)) {
            $fixed = (!$requote && $currentRail === 'mint' && !empty($invoice['bolt11']))
                ? (int)$invoice['amount_sats'] : null;
            try {
                $mq = self::sourceMintQuote($storeId, $store, $amount, $currency, true, $fixed);
            } catch (Throwable $e) {
                $mq = ['quote' => null, 'last_error' => $e];
            }
            if (!empty($mq['quote'])) {
                $q = $mq['quote'];
                $sourced = array_fill_keys(self::LN_COLUMNS, null);
                $sourced['payment_rail'] = 'mint';
                $sourced['bolt11'] = $q->request;
                $sourced['quote_id'] = $q->quote;
                $sourced['mint_url'] = $mq['mint_url'];
                $sourced['amount_sats'] = $mq['amount'];
                $sourced['_quote'] = $q;
            } elseif (!empty($mq['last_error'])) {
                $receiveErrors[] = ['type' => 'mint', 'reason' => 'no configured mint could issue a Lightning invoice'];
            }
        }
        if ($sourced === null) {
            error_log(sprintf('[long-lived] no Lightning rail could re-source invoice=%s store=%s', $invoiceId, $storeId));
            AdminLog::log('lightning', 'refresh', $storeId, $invoiceId, null,
                $strictMintBlocked
                    ? 'Lightning invoice could not be refreshed: no destination produced a bolt11 and strict mode (no mint fallback) is on'
                    : 'Lightning invoice could not be refreshed: no destination or mint produced a bolt11');
            return [
                'ok' => false, 'reason' => 'unavailable', 'invoice' => $invoice,
                'receive_errors' => self::dedupeReceiveErrors($receiveErrors),
            ];
        }

        $quote = $sourced['_quote'];
        unset($sourced['_quote']);
        $sourced['ln_expires_at'] = self::lightningExpiresAt($sourced['bolt11'], $quote, $now);
        $sourced['ln_refreshed_at'] = $now;
        $sourced['last_polled_at'] = null;
        $sourced['last_poll_state'] = null;
        $sourced['last_poll_error'] = null;
        $sourced['receive_errors'] = $receiveErrors !== []
            ? json_encode(self::dedupeReceiveErrors($receiveErrors)) : null;

        Database::beginImmediate();
        try {
            if (!empty($invoice['bolt11'])) {
                self::parkCurrentLightning($invoice, $now);
            }
            $updated = Database::update('invoices', $sourced, "id = ? AND status = 'New'", [$invoiceId]);
            if ($updated !== 1) {
                Database::rollback();
                $fresh = self::getById($invoiceId);
                return ['ok' => false, 'reason' => 'settled', 'status' => $fresh['status'] ?? 'Unknown'];
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
        if ($currentRail === 'swap') {
            // The held swap invoice is no longer shown to anyone: release it.
            try {
                SwapPoller::cancelOpenAttemptsForInvoice($invoiceId, 'Lightning invoice superseded by refresh');
            } catch (Throwable $e) {
                error_log("[long-lived] swap release failed for {$invoiceId}: " . $e->getMessage());
            }
        }
        error_log(sprintf(
            '[long-lived] invoice=%s lightning refreshed rail=%s->%s amount_sats=%s%s',
            $invoiceId, $currentRail ?: 'none', $sourced['payment_rail'],
            (string)$sourced['amount_sats'], $requote ? ' (re-quoted)' : ''
        ));
        return ['ok' => true, 'invoice' => self::getById($invoiceId)];
    }

    /**
     * Re-fetch a fee-routed invoice's bolt11 from the fee payee it was issued
     * by (ln_destination, the dev / hosting LNURL chosen at creation) for the
     * invoice's locked sats amount. The request carries the amount and
     * nothing else — the payee learns nothing about the sale (see the
     * PRIVACY note in includes/fee_redirect.php). Returns the LN column map
     * (same shape as lightningFieldsFromAttempt) or null when the payee is
     * unreachable / refuses; a sanitized reason is appended to $receiveErrors.
     */
    private static function sourceFeeLightning(array $invoice, array &$receiveErrors): ?array {
        $address = (string)($invoice['ln_destination'] ?? '');
        $sats = (int)($invoice['amount_sats'] ?? 0);
        if ($address === '' || $sats < 1) {
            error_log(sprintf(
                '[long-lived] fee-routed invoice=%s has no fee LNURL / amount to re-source from (dest=%s sats=%d)',
                (string)$invoice['id'], $address, $sats
            ));
            $receiveErrors[] = ['type' => 'lnurl', 'reason' => 'the Lightning address could not produce an invoice'];
            return null;
        }
        $failReason = null;
        try {
            $probed = LnUrlReceive::probeAndFetchInvoice($address, $sats, null, $failReason);
        } catch (Throwable $e) {
            error_log("[long-lived] fee LNURL probe threw for invoice {$invoice['id']}: " . $e->getMessage());
            $probed = null;
            $failReason = 'the Lightning address service could not be reached';
        }
        if ($probed === null) {
            $receiveErrors[] = [
                'type' => 'lnurl',
                'reason' => $failReason ?? 'the Lightning address could not produce an invoice',
            ];
            return null;
        }
        return self::lightningFieldsFromAttempt('lnurl', [
            'bolt11' => $probed['bolt11'],
            'verify_url' => $probed['verify_url'],
            'amount_sats' => $sats,
            'address' => $address,
        ]);
    }

    /** Decoded metadata array of an invoice row (null when absent / invalid). */
    private static function metadataOf(array $invoice): ?array {
        if (empty($invoice['metadata'])) {
            return null;
        }
        $m = json_decode((string)$invoice['metadata'], true);
        return is_array($m) ? $m : null;
    }

    /**
     * Column map for a direct-receive attempt (every LN column present, the
     * unused ones null) — the refresh-path twin of the "decide payment_rail"
     * block in Invoice::create.
     */
    private static function lightningFieldsFromAttempt(string $type, array $attempt): array {
        $f = array_fill_keys(self::LN_COLUMNS, null);
        $f['bolt11'] = $attempt['bolt11'];
        $f['amount_sats'] = (int)$attempt['amount_sats'];
        switch ($type) {
            case 'strike':
                $f['payment_rail'] = 'strike';
                $f['ln_destination'] = StrikeClient::maskKey($attempt['api_key']);
                $f['strike_invoice_id'] = $attempt['strike_invoice_id'];
                $f['strike_api_key'] = $attempt['api_key'];
                break;
            case 'lnurl':
                $f['payment_rail'] = 'lnaddress';
                $f['lnurl_verify_url'] = $attempt['verify_url'];
                $f['ln_destination'] = $attempt['address'] ?? null;
                break;
            case 'nwc':
                $f['payment_rail'] = 'nwc';
                $f['ln_destination'] = NwcUri::displayLabel($attempt['uri']);
                $f['nwc_uri'] = $attempt['uri'];
                $f['nwc_payment_hash'] = $attempt['payment_hash'];
                break;
            case 'noffer':
                $f['payment_rail'] = 'noffer';
                $f['ln_destination'] = $attempt['noffer'];
                $f['noffer_relay'] = $attempt['relay'];
                $f['noffer_receiver_pubkey'] = $attempt['receiver_pubkey'];
                $f['noffer_ephemeral_sk'] = $attempt['ephemeral_sk'];
                $f['noffer_ephemeral_pubkey'] = $attempt['ephemeral_pubkey'];
                $f['noffer_request_event_id'] = $attempt['request_event_id'];
                $f['noffer_created_at'] = $attempt['created_at'];
                break;
            default:
                throw new InvalidArgumentException("unknown direct rail type {$type}");
        }
        return $f;
    }

    /**
     * Park the invoice's CURRENT Lightning quote in invoice_ln_quotes (state
     * open) so the superseded-quote poller keeps watching it. Runs inside the
     * caller's transaction.
     */
    private static function parkCurrentLightning(array $invoice, int $now): void {
        $context = [];
        foreach (self::LN_COLUMNS as $col) {
            $context[$col] = $invoice[$col] ?? null;
        }
        Database::insert('invoice_ln_quotes', [
            'id' => Database::generateId('lnq'),
            'invoice_id' => $invoice['id'],
            'store_id' => $invoice['store_id'],
            'payment_rail' => (string)($invoice['payment_rail'] ?? ''),
            'bolt11' => $invoice['bolt11'],
            'amount_sats' => $invoice['amount_sats'],
            'context' => json_encode($context),
            'expires_at' => $invoice['ln_expires_at'] ?? null,
            'superseded_at' => $now,
            'state' => 'open',
            'created_at' => $now,
        ]);
    }

    /**
     * Ask the invoice's current Lightning rail whether its bolt11 was paid and
     * settle through the rail's normal path when it was. No expiry handling,
     * no rate-limit gate — the callers already decided this check is wanted.
     */
    private static function checkLightningPaid(array $invoice): void {
        switch ((string)($invoice['payment_rail'] ?? '')) {
            case 'mint':
                if (empty($invoice['quote_id'])) {
                    return;
                }
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                $st = $wallet->checkMintQuote($invoice['quote_id']);
                self::recordPollOutcome($invoice['id'], (string)$st->state, null);
                if ($st->isIssued()) {
                    self::completeIssuedInvoice($invoice, $wallet);
                } elseif ($st->isPaid()) {
                    self::mintAndStoreTokens($invoice, $wallet);
                }
                return;
            case 'lnaddress':
                if (empty($invoice['lnurl_verify_url'])) {
                    return;
                }
                $result = LnUrlReceive::pollVerifyUrl((string)$invoice['lnurl_verify_url']);
                if ($result['state'] === 'paid') {
                    self::markLnAddressPaid($invoice, $result['preimage']);
                }
                return;
            case 'nwc':
                if (!empty($invoice['nwc_uri']) && !empty($invoice['nwc_payment_hash'])) {
                    self::checkNwcSettlement($invoice);
                }
                return;
            case 'strike':
                if (!empty($invoice['strike_invoice_id']) && !empty($invoice['strike_api_key'])) {
                    self::checkStrikeSettlement($invoice);
                }
                return;
            case 'noffer':
                $ctx = self::nofferCtxFromInvoice($invoice);
                if ($ctx !== null) {
                    $res = ClinkClient::fetchReceipt($ctx);
                    if (!empty($res['paid'])) {
                        self::markNofferPaid($invoice);
                    }
                }
                return;
            case 'swap':
                SwapPoller::pollByInvoiceId((string)$invoice['id'], 0);
                return;
        }
    }

    /**
     * Side-effect-free "was this (superseded) bolt11 paid?" probe for one
     * rail, from an invoice row overlaid with the parked quote's context.
     */
    private static function probeLightningPaid(array $overlay): bool {
        switch ((string)($overlay['payment_rail'] ?? '')) {
            case 'mint':
                if (empty($overlay['quote_id'])) {
                    return false;
                }
                $wallet = self::getWalletForStore($overlay['store_id'], $overlay['mint_url'] ?? null);
                $st = $wallet->checkMintQuote($overlay['quote_id']);
                return $st->isPaid() || $st->isIssued();
            case 'lnaddress':
                if (empty($overlay['lnurl_verify_url'])) {
                    return false;
                }
                return LnUrlReceive::pollVerifyUrl((string)$overlay['lnurl_verify_url'])['state'] === 'paid';
            case 'nwc':
                if (empty($overlay['nwc_uri']) || empty($overlay['nwc_payment_hash'])) {
                    return false;
                }
                $r = NwcClient::lookupInvoice((string)$overlay['nwc_uri'], (string)$overlay['nwc_payment_hash']);
                return !empty($r['found']) && !empty($r['paid']);
            case 'strike':
                if (empty($overlay['strike_invoice_id']) || empty($overlay['strike_api_key'])) {
                    return false;
                }
                return StrikeClient::findInvoice((string)$overlay['strike_api_key'], (string)$overlay['strike_invoice_id'])['state'] === 'paid';
            case 'noffer':
                $ctx = self::nofferCtxFromInvoice($overlay);
                return $ctx !== null && !empty(ClinkClient::fetchReceipt($ctx)['paid']);
        }
        return false;
    }

    /**
     * Poll superseded Lightning quotes (invoice_ln_quotes, state open) of
     * still-open invoices. A quote found paid is restored onto its invoice
     * (the bolt11 the payer actually paid becomes the invoice's bolt11 again,
     * the one it displaced is parked in turn) and settled through the rail's
     * normal path, so webhooks and notifications fire exactly as usual.
     * Quotes past expires_at + SUPERSEDED_QUOTE_GRACE, or whose invoice has
     * left New/Processing, are closed.
     *
     * @param string|null $invoiceId Only this invoice's quotes (payment-page
     *        tick); null = cron batch.
     * @return array{polled:int, settled:int, closed:int}
     */
    public static function pollSupersededQuotes(?string $invoiceId = null, int $minInterval = 30, int $batchLimit = 20): array {
        $now = time();
        $cutoff = $now - $minInterval;
        $params = [$cutoff];
        $where = "q.state = 'open' AND (q.last_polled_at IS NULL OR q.last_polled_at <= ?)";
        if ($invoiceId !== null) {
            $where .= " AND q.invoice_id = ?";
            $params[] = $invoiceId;
        }
        $params[] = $batchLimit;
        $rows = Database::fetchAll(
            "SELECT q.*, i.status AS inv_status
               FROM invoice_ln_quotes q
               JOIN invoices i ON i.id = q.invoice_id
              WHERE {$where}
              ORDER BY CASE WHEN q.last_polled_at IS NULL THEN 0 ELSE 1 END, q.last_polled_at ASC
              LIMIT ?",
            $params
        );
        $out = ['polled' => 0, 'settled' => 0, 'closed' => 0];
        foreach ($rows as $q) {
            $graceEnd = (int)($q['expires_at'] ?? 0) + self::SUPERSEDED_QUOTE_GRACE;
            if (!in_array($q['inv_status'], ['New', 'Processing'], true)
                    || ((int)($q['expires_at'] ?? 0) > 0 && $graceEnd < $now)) {
                Database::update('invoice_ln_quotes', ['state' => 'closed'], "id = ? AND state = 'open'", [$q['id']]);
                $out['closed']++;
                continue;
            }
            $claimed = Database::update(
                'invoice_ln_quotes',
                ['last_polled_at' => $now],
                "id = ? AND state = 'open' AND (last_polled_at IS NULL OR last_polled_at <= ?)",
                [$q['id'], $cutoff]
            );
            if ($claimed !== 1) {
                continue;
            }
            $out['polled']++;
            $invoice = self::getById((string)$q['invoice_id']);
            if (!$invoice) {
                continue;
            }
            $context = json_decode((string)($q['context'] ?? ''), true);
            $overlay = array_merge($invoice, is_array($context) ? $context : [], [
                'payment_rail' => $q['payment_rail'],
                'bolt11' => $q['bolt11'],
                'amount_sats' => $q['amount_sats'],
            ]);
            try {
                if (!self::probeLightningPaid($overlay)) {
                    continue;
                }
                if (self::restoreSupersededQuote($q, $invoice, $now)) {
                    $fresh = self::getById((string)$invoice['id']);
                    if ($fresh) {
                        self::checkLightningPaid($fresh);
                        $after = self::getById((string)$invoice['id']);
                        if ($after && $after['status'] === 'Settled') {
                            $out['settled']++;
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log("[long-lived] superseded quote poll failed quote={$q['id']} invoice={$q['invoice_id']}: " . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Make a parked quote the invoice's current Lightning quote again (the
     * displaced one is parked), status-guarded to New/Processing. Returns
     * whether the swap happened.
     */
    private static function restoreSupersededQuote(array $q, array $invoice, int $now): bool {
        $context = json_decode((string)($q['context'] ?? ''), true);
        if (!is_array($context)) {
            return false;
        }
        $fields = array_fill_keys(self::LN_COLUMNS, null);
        foreach (self::LN_COLUMNS as $col) {
            $fields[$col] = $context[$col] ?? null;
        }
        $fields['payment_rail'] = $q['payment_rail'];
        $fields['bolt11'] = $q['bolt11'];
        $fields['amount_sats'] = $q['amount_sats'];
        $fields['ln_expires_at'] = $q['expires_at'];
        $fields['last_polled_at'] = null;
        $fields['last_poll_state'] = null;
        $fields['last_poll_error'] = null;
        Database::beginImmediate();
        try {
            $current = self::getById((string)$invoice['id']);
            if (!$current || !in_array($current['status'], ['New', 'Processing'], true)) {
                Database::rollback();
                return false;
            }
            if (!empty($current['bolt11']) && $current['bolt11'] !== $q['bolt11']) {
                self::parkCurrentLightning($current, $now);
            }
            $n = Database::update('invoices', $fields, "id = ? AND status IN ('New', 'Processing')", [$invoice['id']]);
            Database::update('invoice_ln_quotes', ['state' => 'settled'], 'id = ?', [$q['id']]);
            Database::commit();
            error_log(sprintf(
                '[long-lived] invoice=%s superseded %s quote paid; restored for settlement',
                $invoice['id'], $q['payment_rail']
            ));
            return $n === 1;
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * The rail that actually moved funds, corrected for a historical quirk:
     * direct ecash-token receipts used to settle with settled_rail='mint'
     * even though no Lightning was involved. For those rows the created
     * rail ('cashu') is the truth; new token settlements record 'cashu'
     * directly. A creation rail of 'cashu' is only ever set by the token
     * receive path, so the pair (settled='mint', created='cashu') uniquely
     * fingerprints the legacy rows.
     */
    public static function effectiveRail(array $invoice): ?string {
        $settled = $invoice['settled_rail'] ?? null;
        $created = $invoice['payment_rail'] ?? null;
        if ($settled === 'mint' && $created === 'cashu') {
            return 'cashu';
        }
        return $settled ?: $created;
    }

    /**
     * Format invoice for API response
     */
    public static function formatForApi(array $invoice): array {
        // Get store's mint unit for proper display
        $mintUnit = Config::getStoreMintUnit($invoice['store_id']);

        $result = [
            'id' => $invoice['id'],
            'storeId' => $invoice['store_id'],
            'amount' => $invoice['amount'],
            'currency' => $invoice['currency'],
            'status' => $invoice['status'],
            'additionalStatus' => $invoice['additional_status'],
            'createdTime' => $invoice['created_at'],
            'expirationTime' => $invoice['expiration_time'],
            // Shareable payment request: lives for the shared lifetime, the
            // payment page re-sources its Lightning invoice as it lapses and
            // re-quotes a fiat amount on load. See Invoice::refreshLightning.
            'longLived' => !empty($invoice['long_lived']),
            'paidTime' => $invoice['paid_at'] ?? null,
            // settled_rail is the rail that actually moved funds; fall back to
            // payment_rail (the rail chosen at create time) for rows that
            // settled before the column existed, or for the Greenfield API
            // path that marks Settled without knowing the rail.
            'paymentRail' => self::effectiveRail($invoice),
            // Mint the funds landed at (mint-quote Lightning receives and
            // direct ecash-token receives); null for every other rail. The
            // admin UI shows the mint host next to the payment method.
            'mintUrl' => $invoice['mint_url'] ?? null,
            'checkoutLink' => Urls::payment($invoice['id']),
            // Customer-supplied email + newsletter opt-in (entered on the
            // payment-complete screen). null until the payer submits the form.
            'customerEmail' => $invoice['customer_email'] ?? null,
            'newsletterOptIn' => isset($invoice['newsletter_opt_in']) && $invoice['newsletter_opt_in'] !== null
                ? (bool)$invoice['newsletter_opt_in']
                : null,
        ];

        // Per-store network drives mempool.space URLs in the admin UI. We
        // can't always look up the store (deleted store_id), so default to
        // mainnet on lookup failure.
        $store = Config::getStore($invoice['store_id']);
        $result['network'] = $store['onchain_network'] ?? 'mainnet';

        // Destination + customer-side txid populated per rail. Used by the
        // admin invoices view; null for Lightning-only.
        $rail = $result['paymentRail'];
        if ($rail === 'onchain' && !empty($invoice['onchain_address'])) {
            $result['destination'] = $invoice['onchain_address'];
            $oc = Database::fetchOne(
                "SELECT txid FROM onchain_payments
                  WHERE invoice_id = ?
                  ORDER BY first_seen_at ASC, id ASC
                  LIMIT 1",
                [$invoice['id']]
            );
            if ($oc) {
                $result['txid'] = $oc['txid'];
            }
        } elseif ($rail === 'swap') {
            $sa = Database::fetchOne(
                "SELECT merchant_address, status, lockup_txid, claim_txid
                   FROM swap_attempts
                  WHERE invoice_id = ?
                  ORDER BY id DESC
                  LIMIT 1",
                [$invoice['id']]
            );
            if ($sa) {
                $result['destination'] = $sa['merchant_address'];
                $result['swapStatus'] = $sa['status'];
                // Show the customer-side on-chain event (the provider's
                // lockup). claimTxid is also exposed so the UI can reveal
                // both in a hover tooltip.
                if (!empty($sa['lockup_txid'])) {
                    $result['txid'] = $sa['lockup_txid'];
                }
                if (!empty($sa['claim_txid'])) {
                    $result['claimTxid'] = $sa['claim_txid'];
                }
            }
        } elseif (($rail === 'lnaddress' || $rail === 'noffer' || $rail === 'nwc' || $rail === 'strike' || $rail === 'mint') && !empty($invoice['bolt11'])) {
            // Lightning rails have no block-chain txid; surface the bolt11 as the
            // "TxID" (rendered copy-only, not as an explorer link) and the LN
            // address / LNURL / noffer it was sent to as the destination (for
            // nwc and strike, ln_destination is a masked label — the raw NWC URI
            // and Strike API key are secrets and never leave the server). The
            // mint rail has no such destination (paid to the mint), so
            // ln_destination is NULL there and the destination cell stays empty.
            $result['txid'] = $invoice['bolt11'];
            $result['txidIsLightning'] = true;
            if (!empty($invoice['ln_destination'])) {
                $result['destination'] = $invoice['ln_destination'];
                $result['destinationIsLightning'] = true;
            }
        }

        // Strike receive: Strike's own invoice id lets the operator look the
        // payment up in their Strike dashboard (it's also searchable there by
        // correlationId = our invoice id). The bearer strike_api_key never
        // rides an API payload.
        if ($rail === 'strike' && !empty($invoice['strike_invoice_id'])) {
            $result['strikeInvoiceId'] = $invoice['strike_invoice_id'];
        }

        // Strike on-chain: the address was minted in the merchant's Strike
        // account via a receive request; its id lets the operator match the
        // incoming payment in Strike's dashboard. Independent of payment_rail
        // — the on-chain rail rides alongside whatever Lightning rail won.
        if (!empty($invoice['strike_receive_request_id'])) {
            $result['strikeReceiveRequestId'] = $invoice['strike_receive_request_id'];
        }

        // Aggregate payment methods (Lightning + on-chain, both optional).
        $methods = [];
        if ($invoice['bolt11']) {
            $methods['BTC-LightningNetwork'] = [
                'paymentLink' => 'lightning:' . $invoice['bolt11'],
                'destination' => $invoice['bolt11'],
            ];
        }
        $onchain = OnchainPayments::formatPaymentMethod($invoice);
        if ($onchain !== null) {
            $methods['BTC-OnChain'] = $onchain;
        }
        if (!empty($methods)) {
            $result['checkout'] = ['paymentMethods' => $methods];
        }

        // Fee-redirect: surface the badge data for the admin invoice list. An
        // invoice can be mixed — some rails go to a fee payee, the rest to the
        // merchant — so we expose which rails are fee-routed and, once settled,
        // whether the rail that actually paid was a fee rail.
        if (!empty($invoice['fee_redirect_note'])) {
            $feeLabels = [
                'UPSTREAM_DEV_FEE' => 'upstream dev fee',
                'DEV_FEE' => 'dev fee',
                'HOSTING_FEE' => 'hosting fee',
            ];
            $railsRaw = (string)($invoice['fee_redirect_rails'] ?? '');
            $rails = $railsRaw === '' ? [] : array_map('trim', explode(',', $railsRaw));
            $isSettled = ($invoice['status'] ?? '') === 'Settled';
            // Mixed = the merchant still owns a rail that is actually present on
            // this invoice (lightning bolt11 / on-chain address) but isn't
            // fee-routed. Drives the "either / or" wording in the badge.
            $merchantOwnsRail =
                (!empty($invoice['bolt11']) && !in_array('lightning', $rails, true))
                || (!empty($invoice['onchain_address']) && !in_array('onchain', $rails, true));
            $result['feeRedirect'] = [
                'note' => $invoice['fee_redirect_note'],
                'label' => $feeLabels[$invoice['fee_redirect_note']] ?? 'fees',
                'destination' => $invoice['fee_redirect_destination'] ?? null,
                'rails' => $rails,
                'mixed' => $merchantOwnsRail,
                // Decided only at settlement: did the paying rail go to the fee?
                'settled' => $isSettled,
                'settledToFee' => $isSettled && self::settledRailIsFeeRouted($invoice),
            ];
        }

        // Include converted amount in mint unit
        if ($invoice['amount_sats']) {
            $result['amountInMintUnit'] = $invoice['amount_sats'];
            $result['mintUnit'] = $mintUnit;
        }

        if ($invoice['exchange_rate']) {
            $result['exchangeRate'] = [
                'rate' => $invoice['exchange_rate'],
                'currency' => $invoice['currency'],
            ];
        }

        // Include metadata
        if ($invoice['metadata']) {
            $result['metadata'] = json_decode($invoice['metadata'], true);
        }

        // Include checkout config
        if ($invoice['checkout_config']) {
            $checkoutConfig = json_decode($invoice['checkout_config'], true);
            if (isset($checkoutConfig['redirectURL'])) {
                $result['checkout']['redirectURL'] = $checkoutConfig['redirectURL'];
            }
            if (isset($checkoutConfig['redirectAutomatically'])) {
                $result['checkout']['redirectAutomatically'] = $checkoutConfig['redirectAutomatically'];
            }
        }

        return $result;
    }

    /**
     * Cache for wallet instances per store+mint
     */
    private static array $walletCache = [];

    /**
     * Webhook queue for deferred delivery after transaction commit
     */
    private static array $webhookQueue = [];

    /**
     * Get or create wallet instance for a store
     *
     * @param string $storeId Store ID
     * @param string|null $mintUrl Optional specific mint URL (for backup mints)
     * @return Wallet
     */
    public static function getWalletForStore(string $storeId, ?string $mintUrl = null, ?string $mintUnit = null): Wallet {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $mintUrl = $mintUrl ?? $store['mint_url'];
        // A backup account may run in another unit than the primary; callers
        // that open a specific account pass its unit.
        $mintUnit = $mintUnit ?? ($store['mint_unit'] ?? 'sat');
        $seedPhrase = $store['seed_phrase'];

        if (empty($mintUrl) || empty($seedPhrase)) {
            throw new Exception('Store wallet not configured');
        }

        $cacheKey = $storeId . '|' . $mintUrl . '|' . $mintUnit;

        if (!isset(self::$walletCache[$cacheKey])) {
            $wallet = new Wallet($mintUrl, $mintUnit, Database::getDbPath());
            $wallet->loadMint();
            // Seed provenance is tracked per (namespace, seed). A seed that
            // was bound through bindStoreSeed() keeps the readiness recorded
            // there (a pasted seed stays unready until its scan completes).
            // A seed not yet bound on this namespace is adopted the backward-
            // compatible way (existing data → adopted ready, empty → fresh
            // ready) — EXCEPT when the store's seed was imported: then every
            // namespace it first touches (backup mints reached by failover)
            // needs its own scan before it may issue secrets there.
            if (self::storeSeedIsImported($store)
                && !self::seedBoundOn($wallet->getStorage(), $seedPhrase)) {
                $wallet->initializeForRestore($seedPhrase);
            } else {
                $wallet->initFromMnemonic($seedPhrase);
            }

            self::$walletCache[$cacheKey] = $wallet;
        }

        return self::$walletCache[$cacheKey];
    }

    /**
     * Get wallet instance for a store (public accessor)
     */
    public static function getWalletInstance(string $storeId): Wallet {
        return self::getWalletForStore($storeId);
    }

    /**
     * Drop cached wallet objects (all stores, or one). Needed after a seed
     * was (re)bound or a store's mint changed in this process, so the next
     * getWalletForStore() reflects the recorded readiness.
     */
    public static function forgetWallets(?string $storeId = null): void {
        if ($storeId === null) {
            self::$walletCache = [];
            self::$importedSeedCache = [];
            return;
        }
        foreach (array_keys(self::$walletCache) as $key) {
            if (str_starts_with($key, $storeId . '|')) {
                unset(self::$walletCache[$key]);
            }
        }
        unset(self::$importedSeedCache[$storeId]);
    }

    // =========================================================================
    // WALLET READINESS (seed provenance + restore-before-issue gate)
    // =========================================================================

    /** Operator/payer-facing reason an unready account cannot take payments. */
    public const RECOVERY_MESSAGE =
        'Payments are being set up with your mint: the wallet is still being checked '
        . 'against the mint before it can issue new ecash. Please try again in a few minutes.';

    /** Seed-binding modes for bindStoreSeed(). */
    public const SEED_NEW = 'new';           // generated in this request: never used anywhere
    public const SEED_RESTORE = 'restore';   // pasted/imported, or a known seed on a new namespace
    public const SEED_EXISTING = 'existing'; // backward-compatible adoption (upgrade path)

    /** Recovery bookkeeping: retry schedule for failed restore steps. */
    private const RECOVERY_BACKOFF_BASE_SEC = 30;
    private const RECOVERY_BACKOFF_MAX_SEC = 3600;

    /** @var array<string, bool> store id => the store's seed was imported (restore-bound on its primary) */
    private static array $importedSeedCache = [];

    /**
     * Bind a store's seed to one wallet account (mint + unit) with explicit
     * provenance:
     *   SEED_NEW      — the seed was generated in this request; it has no
     *                   history anywhere, so the account is ready at once.
     *   SEED_RESTORE  — the seed came from outside (pasted, imported) or is
     *                   being pointed at a mint it may have used before; the
     *                   account stays unready until restoreStep() scanned the
     *                   mint for the seed's existing outputs (otherwise the
     *                   first mint request would reuse counter 0 and the mint
     *                   would refuse the already-signed outputs, or worse,
     *                   sign duplicates).
     *   SEED_EXISTING — backward-compatible: adopt whatever the namespace
     *                   holds (upgrade path for stores that predate seed
     *                   provenance).
     * A seed already bound on the namespace keeps the readiness recorded
     * there; binding is idempotent. Returns the (uncached) wallet.
     *
     * @throws Exception when the mint cannot be loaded or the binding is refused
     */
    public static function bindStoreSeed(string $storeId, string $mintUrl, string $unit, string $mode): Wallet {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }
        $seedPhrase = (string)($store['seed_phrase'] ?? '');
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        if ($mintUrl === '' || $seedPhrase === '') {
            throw new Exception('Store wallet not configured');
        }
        $wallet = new Wallet($mintUrl, $unit, Database::getDbPath());
        $wallet->loadMint();
        $storage = $wallet->getStorage();
        switch ($mode) {
            case self::SEED_NEW:
                // A namespace that already holds wallet data but has no bound
                // seed at all is legacy pooled storage of another store (one
                // namespace per mint:unit on a multi-store server). A seed
                // generated right now has no history there, so adopting it
                // ready is safe; the library refuses a "new" binding on such
                // data on purpose (it cannot tell our fresh seed from a
                // re-imported one).
                if ($storage->hasWalletData() && $storage->getBoundSeedFingerprints() === []) {
                    $wallet->adoptSeedForExistingStorage($seedPhrase);
                } else {
                    $wallet->initializeNewFromMnemonic($seedPhrase);
                }
                break;
            case self::SEED_RESTORE:
                $wallet->initializeForRestore($seedPhrase);
                break;
            case self::SEED_EXISTING:
                $wallet->initFromMnemonic($seedPhrase);
                break;
            default:
                throw new Exception('Unknown seed binding mode: ' . $mode);
        }
        self::forgetWallets($storeId);
        self::recordRecoveryState($storeId, $mintUrl, $unit, $wallet->requiresRecovery() ? 'pending' : 'ready', null, 0, 0);
        return $wallet;
    }

    /** Is this seed already bound (any readiness) on the storage's namespace? */
    private static function seedBoundOn(WalletStorage $storage, string $seedPhrase): bool {
        try {
            $fingerprint = Wallet::calculateSeedFingerprint($seedPhrase);
        } catch (\Throwable $e) {
            return false;
        }
        return in_array($fingerprint, $storage->getBoundSeedFingerprints(), true);
    }

    /**
     * Was the store's seed imported (bound for restore on its primary
     * account)? Such a seed may have history at ANY mint, so every namespace
     * it first touches is bound for restore too. Read-only (no mint contact).
     */
    private static function storeSeedIsImported(array $store): bool {
        $storeId = (string)($store['id'] ?? '');
        if (isset(self::$importedSeedCache[$storeId])) {
            return self::$importedSeedCache[$storeId];
        }
        $imported = false;
        $mintUrl = rtrim((string)($store['mint_url'] ?? ''), '/');
        $seed = (string)($store['seed_phrase'] ?? '');
        if ($mintUrl !== '' && $seed !== '') {
            try {
                $storage = StoreSafety::storage($mintUrl, (string)($store['mint_unit'] ?? 'sat'));
                $storage->setSeedFingerprint(Wallet::calculateSeedFingerprint($seed));
                $imported = $storage->getSeedProvenance()['init_reason'] === WalletStorage::INIT_RESTORE_REQUIRED;
            } catch (\Throwable $e) {
                $imported = false;
            }
        }
        return self::$importedSeedCache[$storeId] = $imported;
    }

    /**
     * Readiness of one wallet account, read from the database only (never
     * contacts the mint — safe for dashboards and store lists).
     *
     * @return array{status: string, ready: bool, error: ?string, bound: bool, progress: array, attempts: int, next_attempt_at: int}
     *         status: 'ready' | 'pending' (scan in progress or not started) | 'error' (last step failed)
     */
    public static function accountRecoveryStatus(string $storeId, string $mintUrl, string $unit): array {
        $store = Config::getStore($storeId);
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        $out = ['status' => 'ready', 'ready' => true, 'error' => null, 'bound' => false,
            'progress' => [], 'attempts' => 0, 'next_attempt_at' => 0];
        $seed = (string)($store['seed_phrase'] ?? '');
        if (!$store || $mintUrl === '' || $seed === '') {
            return $out;
        }
        try {
            $storage = StoreSafety::storage($mintUrl, $unit);
            $storage->setSeedFingerprint(Wallet::calculateSeedFingerprint($seed));
            $provenance = $storage->getSeedProvenance();
        } catch (\Throwable $e) {
            return $out;
        }
        $out['bound'] = (bool)$provenance['bound'];
        if (!$provenance['bound'] || $provenance['ready']) {
            // Unbound = legacy namespace, adopted ready on first use.
            return $out;
        }
        $recovery = $provenance['recovery'];
        $out['ready'] = false;
        $out['status'] = ($recovery['status'] === 'in_progress' && $recovery['error'] !== null) ? 'error' : 'pending';
        $out['error'] = $recovery['error'];
        $out['progress'] = $recovery['keysets'] ?? [];
        $row = self::recoveryRow($storeId, $mintUrl, $unit);
        if ($row) {
            $out['attempts'] = (int)$row['attempts'];
            $out['next_attempt_at'] = (int)$row['next_attempt_at'];
            if ($row['status'] === 'error') {
                $out['status'] = 'error';
                $out['error'] = $out['error'] ?? $row['last_error'];
            }
        }
        return $out;
    }

    /**
     * Readiness summary for a store's dashboard: the primary and every
     * backup account, plus `pending` = the store cannot take mint payments on
     * at least one ENABLED account. Read-only.
     *
     * @return array{pending: bool, primaryPending: bool, message: ?string, accounts: list<array>}
     */
    public static function storeRecoveryStatus(string $storeId): array {
        $accounts = [];
        $pending = false;
        $primaryPending = false;
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            $st = self::accountRecoveryStatus($storeId, $account['mint_url'], $account['unit']);
            $accounts[] = [
                'mint_url' => $account['mint_url'],
                'unit' => $account['unit'],
                'primary' => (bool)$account['primary'],
                'enabled' => (bool)$account['enabled'],
                'status' => $st['status'],
                'ready' => $st['ready'],
                'error' => $st['error'],
                'attempts' => $st['attempts'],
                'next_attempt_at' => $st['next_attempt_at'],
            ];
            if (!$st['ready'] && ($account['primary'] || $account['enabled'])) {
                $pending = true;
            }
            if (!$st['ready'] && $account['primary']) {
                $primaryPending = true;
            }
        }
        return [
            'pending' => $pending,
            'primaryPending' => $primaryPending,
            'message' => $pending ? 'Preparing payments with your mint' : null,
            'accounts' => $accounts,
        ];
    }

    /** Does the store's PRIMARY account still require recovery? Read-only. */
    public static function primaryRequiresRecovery(string $storeId): bool {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url']) || empty($store['seed_phrase'])) {
            return false;
        }
        return !self::accountRecoveryStatus($storeId, (string)$store['mint_url'], (string)($store['mint_unit'] ?? 'sat'))['ready'];
    }

    /**
     * Drive one bounded restore step for an account that requires recovery.
     *
     * Honours the exponential backoff recorded for repeated failures unless
     * $force. $maxOutputs defaults to 300 with GMP and 30 without (BCMath
     * derivation is ~10x slower; the step must fit a cron tick on a GMP-less
     * shared host). Returns the library's step result plus `skipped`
     * ('ready' | 'backoff') when nothing was done.
     */
    public static function advanceStoreRecovery(
        string $storeId,
        string $mintUrl,
        string $unit,
        ?float $deadline = null,
        ?int $maxOutputs = null,
        bool $force = false
    ): array {
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        $maxOutputs = $maxOutputs ?? (\Cashu\BigInt::isUsingGmp() ? 300 : 30);
        $deadline = $deadline ?? (microtime(true) + 20.0);

        $row = self::recoveryRow($storeId, $mintUrl, $unit);
        if (!$force && $row && (int)$row['next_attempt_at'] > time()) {
            return ['status' => $row['status'], 'skipped' => 'backoff', 'ready' => false,
                'error' => $row['last_error'], 'next_attempt_at' => (int)$row['next_attempt_at']];
        }

        try {
            $wallet = self::getWalletForStore($storeId, $mintUrl, $unit);
        } catch (\Throwable $e) {
            self::recordRecoveryFailure($storeId, $mintUrl, $unit, $row, $e->getMessage());
            return ['status' => 'error', 'ready' => false, 'error' => $e->getMessage(), 'skipped' => null];
        }
        if (!$wallet->requiresRecovery()) {
            self::recordRecoveryState($storeId, $mintUrl, $unit, 'ready', null, 0, 0);
            return ['status' => 'complete', 'skipped' => 'ready', 'ready' => true, 'error' => null];
        }

        try {
            $result = $wallet->restoreStep($maxOutputs, $deadline);
        } catch (\Throwable $e) {
            self::recordRecoveryFailure($storeId, $mintUrl, $unit, $row, $e->getMessage());
            return ['status' => 'error', 'ready' => false, 'error' => $e->getMessage(), 'skipped' => null];
        }
        $result['skipped'] = null;
        if ($result['status'] === 'error') {
            self::recordRecoveryFailure($storeId, $mintUrl, $unit, $row, (string)($result['error'] ?? 'restore step failed'));
        } elseif ($result['status'] === 'complete' || !empty($result['ready'])) {
            self::recordRecoveryState($storeId, $mintUrl, $unit, 'ready', null, 0, 0);
            self::forgetWallets($storeId);
        } else {
            // Progress was made: a later failure starts its backoff afresh.
            self::recordRecoveryState($storeId, $mintUrl, $unit, 'pending', null, 0, 0);
        }
        return $result;
    }

    /**
     * Cron entry point: step every account (primary + backups, disabled ones
     * included so a "Retry recovery" is not the only way out of a transient
     * mint outage) that still requires recovery, within a total time budget.
     * Accounts are visited in a stable order and each gets at most one step
     * per call, so a long scan cannot starve the others.
     *
     * @return array{accounts: int, stepped: int, completed: int, errors: int, backoff: int, details: array}
     */
    public static function ensureWalletsReady(float $budgetSeconds = 20.0): array {
        $out = ['accounts' => 0, 'stepped' => 0, 'completed' => 0, 'errors' => 0, 'backoff' => 0, 'details' => []];
        $deadline = microtime(true) + max(1.0, $budgetSeconds);
        $stores = Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND mint_url <> '' AND seed_phrase IS NOT NULL AND seed_phrase <> '' ORDER BY created_at"
        );
        $todo = [];
        foreach ($stores as $store) {
            foreach (Config::getStoreWalletAccounts((string)$store['id']) as $account) {
                $st = self::accountRecoveryStatus((string)$store['id'], $account['mint_url'], $account['unit']);
                if ($st['ready']) {
                    continue;
                }
                $todo[] = [(string)$store['id'], $account['mint_url'], $account['unit']];
            }
        }
        $out['accounts'] = count($todo);
        foreach ($todo as [$storeId, $mintUrl, $unit]) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.5) {
                break;
            }
            $r = self::advanceStoreRecovery($storeId, $mintUrl, $unit, microtime(true) + $remaining);
            $key = $storeId . '@' . $mintUrl . ':' . $unit;
            if (($r['skipped'] ?? null) === 'backoff') {
                $out['backoff']++;
                $out['details'][$key] = 'backoff until ' . date('H:i:s', (int)$r['next_attempt_at']);
                continue;
            }
            $out['stepped']++;
            if (!empty($r['ready'])) {
                $out['completed']++;
                $out['details'][$key] = 'complete';
            } elseif ($r['status'] === 'error') {
                $out['errors']++;
                $out['details'][$key] = 'error: ' . (string)($r['error'] ?? '');
            } else {
                $out['details'][$key] = 'pending';
            }
        }
        return $out;
    }

    private static function recoveryRow(string $storeId, string $mintUrl, string $unit): ?array {
        try {
            return Database::fetchOne(
                "SELECT * FROM wallet_recovery WHERE store_id = ? AND mint_url = ? AND unit = ?",
                [$storeId, $mintUrl, $unit]
            ) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function recordRecoveryState(
        string $storeId, string $mintUrl, string $unit, string $status, ?string $error, int $attempts, int $nextAttemptAt
    ): void {
        $now = time();
        try {
            $existing = self::recoveryRow($storeId, $mintUrl, $unit);
            $data = [
                'status' => $status,
                'attempts' => $attempts,
                'next_attempt_at' => $nextAttemptAt,
                'last_error' => $error,
                'updated_at' => $now,
            ];
            if ($existing) {
                Database::update('wallet_recovery', $data, 'store_id = ? AND mint_url = ? AND unit = ?', [$storeId, $mintUrl, $unit]);
            } else {
                Database::insert('wallet_recovery', $data + [
                    'store_id' => $storeId, 'mint_url' => $mintUrl, 'unit' => $unit, 'started_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("CashuPayServer: could not record wallet recovery state for {$storeId}@{$mintUrl}: " . $e->getMessage());
        }
    }

    private static function recordRecoveryFailure(string $storeId, string $mintUrl, string $unit, ?array $row, string $error): void {
        $attempts = ($row ? (int)$row['attempts'] : 0) + 1;
        $wait = min(self::RECOVERY_BACKOFF_MAX_SEC, self::RECOVERY_BACKOFF_BASE_SEC * (2 ** min(12, $attempts - 1)));
        self::recordRecoveryState($storeId, $mintUrl, $unit, 'error', $error, $attempts, time() + $wait);
        error_log("CashuPayServer: wallet recovery step failed for store {$storeId} at {$mintUrl} ({$unit}), attempt {$attempts}, retry in {$wait}s: {$error}");
    }

    // =========================================================================
    // WEBHOOK QUEUE
    // =========================================================================

    private static function queueWebhook(string $storeId, string $event, array $data): void {
        self::$webhookQueue[] = compact('storeId', 'event', 'data');
    }

    private static function flushWebhookQueue(): void {
        foreach (self::$webhookQueue as $item) {
            WebhookSender::fireEvent($item['storeId'], $item['event'], $item['data']);
        }
        self::$webhookQueue = [];
    }

    private static function clearWebhookQueue(): void {
        self::$webhookQueue = [];
    }

    // =========================================================================
    // SINGLE INVOICE POLLING
    // =========================================================================

    /**
     * Poll a single invoice's quote status
     */
    public static function pollSingleQuote(string $invoiceId): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice) {
            return;
        }

        // Only process New or Processing invoices
        if (!in_array($invoice['status'], ['New', 'Processing'])) {
            return;
        }

        // Long-lived invoice: a payer may have paid the PREVIOUS bolt11 just
        // before a refresh replaced it. Cheap no-op when nothing is parked.
        if (!empty($invoice['long_lived'])) {
            try {
                self::pollSupersededQuotes($invoiceId, 10, 5);
            } catch (Throwable $e) {
                error_log("superseded-quote poll failed for {$invoiceId}: " . $e->getMessage());
            }
            $invoice = self::getById($invoiceId);
            if (!$invoice || !in_array($invoice['status'], ['New', 'Processing'])) {
                return;
            }
        }

        // Direct-receive / swap Lightning rails: poll the Lightning side first,
        // then fall through to the on-chain poll below when this invoice ALSO
        // carries an on-chain address (the normal on-chain route is offered in
        // parallel whenever the store has an xpub). Each rail's Lightning poll:
        //
        //   swap     — advance the swap state machine inline so settlement isn't
        //              dependent on the cron poller (Task 4c). The single-row
        //              poll reuses SwapPoller's atomic last_polled_at gate, so
        //              it's safe against the cron task and other concurrent
        //              checkout polls, and the min-interval keeps us off the
        //              provider API on every 2s tick. Cron's expireStale()
        //              remains the backstop that releases the held swap invoice.
        //   noffer   — settlement arrives via the merchant's kind-21001
        //              receipt. The page's own relay subscription is the fast
        //              path, but the receipt is ephemeral — if the browser
        //              misses it (blocked WebSocket, reconnect gap) it's gone
        //              from spec-compliant relays. pollSingleNoffer
        //              re-subscribes server-side with a short live-listen
        //              window (rate-limited like NWC) so the checkout tick can
        //              still recover the settlement.
        //   lnaddress— poll the LUD-21 verify URL.
        //   nwc      — run lookup_invoice against the merchant wallet over
        //              Nostr Wallet Connect. Each check is a full relay
        //              round trip, so pollSingleNwc keeps its own small
        //              min-interval gate against the 2s checkout tick.
        //   strike   — read the Strike invoice back over the Strike API until
        //              it reports PAID. Same min-interval gate as nwc so the
        //              checkout tick stays clear of Strike's rate limits.
        //
        // If the rail has no on-chain address, we're done after the LN poll.
        $rail = $invoice['payment_rail'] ?? null;
        if (in_array($rail, ['swap', 'noffer', 'lnaddress', 'nwc', 'strike'], true)) {
            if ($rail === 'swap') {
                try {
                    SwapPoller::pollByInvoiceId($invoiceId);
                } catch (Throwable $e) {
                    error_log("swap poll failed for {$invoiceId}: " . $e->getMessage());
                }
            } elseif ($rail === 'lnaddress') {
                self::pollSingleLnAddress($invoiceId);
            } elseif ($rail === 'nwc') {
                self::pollSingleNwc($invoiceId);
            } elseif ($rail === 'strike') {
                self::pollSingleStrike($invoiceId);
            } elseif ($rail === 'noffer') {
                self::pollSingleNoffer($invoiceId);
            }
            if (empty($invoice['onchain_address'])) {
                return;
            }
            $invoice = self::getById($invoiceId);
            if (!$invoice || !in_array($invoice['status'], ['New', 'Processing'])) {
                return;
            }
        }

        // Best-effort on-chain poll first — if the invoice has an on-chain
        // address, this can transition state independent of any Cashu quote.
        if (!empty($invoice['onchain_address'])) {
            try {
                OnchainPayments::pollInvoice($invoiceId);
                $invoice = self::getById($invoiceId);
                if (!$invoice || !in_array($invoice['status'], ['New', 'Processing'])) {
                    return;
                }
            } catch (Throwable $e) {
                error_log("on-chain poll failed for {$invoiceId}: " . $e->getMessage());
            }
        }

        // No Cashu quote means nothing more to do here.
        if (empty($invoice['quote_id'])) {
            // Check expiration on on-chain-only invoices.
            if ($invoice['status'] === 'New' && $invoice['expiration_time'] < time()) {
                self::updateStatus($invoice['id'], 'Expired', null, null, ['New']);
            }
            return;
        }

        // An expired New invoice is not expired on the local clock alone: the
        // mint is asked below and the invoice expires only when it says
        // "unpaid" — a payment made just before expiry used to get
        // InvoiceExpired and then InvoiceSettled. Past the confirm window the
        // clock wins (markExpiredInvoices() takes over as well).
        if (self::expiredBeyondConfirmWindow($invoice)) {
            self::updateStatus($invoice['id'], 'Expired', null, null, ['New']);
            return;
        }

        try {
            $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
            $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);
            self::recordPollOutcome($invoice['id'], (string)$quoteStatus->state, null);

            error_log("CashuPayServer: Quote {$invoice['quote_id']} state: {$quoteStatus->state}");

            if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                error_log("CashuPayServer: Quote is paid/issued, processing...");
                if ($quoteStatus->isIssued()) {
                    self::completeIssuedInvoice($invoice, $wallet);
                } elseif ($invoice['status'] === 'New') {
                    self::mintAndStoreTokens($invoice, $wallet);
                } elseif ($invoice['status'] === 'Processing') {
                    self::mintAndStoreTokens($invoice, $wallet);
                }
            } else {
                self::expireIfConfirmedUnpaid($invoice, $quoteStatus);
            }
        } catch (\Throwable $e) {
            self::recordPollOutcome($invoice['id'], null, $e->getMessage());
            error_log("CashuPayServer: Error polling single quote {$invoice['id']}: " . $e->getMessage());
        }
    }

    /**
     * Is this New invoice so far past its expiry that no rail is asked before
     * it is closed? Within EXPIRY_CONFIRM_WINDOW the pollers ask first.
     */
    private static function expiredBeyondConfirmWindow(array $invoice): bool {
        return ($invoice['status'] ?? null) === 'New'
            && (int)$invoice['expiration_time'] < time() - self::EXPIRY_CONFIRM_WINDOW;
    }

    // =========================================================================
    // ISSUED QUOTE HANDLING
    // =========================================================================

    private static function completeIssuedInvoice(array $invoice, Wallet $wallet): void {
        if ($wallet->hasStorage()) {
            $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
            if (!empty($proofs)) {
                Database::beginTransaction();
                try {
                    // Status-guarded settle (see mintAndStoreTokens): only fire
                    // webhooks/notifications if this caller actually settled the
                    // row, so concurrent pollers don't double-fire.
                    $settled = Database::update(
                        'invoices',
                        ['status' => 'Settled', 'paid_at' => time(), 'settled_rail' => 'mint'],
                        'id = ? AND status != ?',
                        [$invoice['id'], 'Settled']
                    );
                    Database::commit();

                    if ($settled !== 1) {
                        return; // already settled by another poller
                    }

                    $updatedInvoice = self::getById($invoice['id']);
                    WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);
                    NotificationSender::queueInvoicePaid($updatedInvoice);
                    return;
                } catch (\Throwable $e) {
                    // \Throwable (not Exception): a \TypeError/\Error must not
                    // leave the singleton PDO's transaction open for later code.
                    Database::rollback();
                    throw $e;
                }
            }
        }

        error_log("CashuPayServer: ISSUED quote {$invoice['quote_id']} has no proofs in storage - invoice {$invoice['id']}");
    }

    // =========================================================================
    // ORPHANED INVOICE RECOVERY
    // =========================================================================

    /**
     * Recover orphaned invoices stuck in Processing state.
     *
     * Bounded: at most $batchLimit rows per call, each re-visited no more
     * than once per ORPHAN_RECOVERY_COOLDOWN (stamped on last_polled_at,
     * which the New-only rail pollers never read for a Processing row), and a
     * \Throwable on one row never aborts the rest — this runs on every tick,
     * including page-load triggers, so an unbounded SELECT * over a stuck
     * backlog or one bad row used to take the whole run down.
     */
    public static function recoverOrphanedInvoices(int $batchLimit = 50): array {
        $recovered = [];
        $now = time();

        $stuck = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'Processing' AND created_at < ?
               AND quote_id IS NOT NULL AND quote_id <> ''
               AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY created_at ASC
             LIMIT ?",
            [$now - 60, $now - self::ORPHAN_RECOVERY_COOLDOWN, $batchLimit]
        );

        foreach ($stuck as $invoice) {
            try {
                Database::update(
                    'invoices',
                    ['last_polled_at' => $now],
                    "id = ? AND status = 'Processing'",
                    [$invoice['id']]
                );
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                if ($wallet->hasStorage() && $invoice['quote_id']) {
                    $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
                    if (!empty($proofs)) {
                        // Status-guarded settle: a regular poller may settle this
                        // row between our SELECT and here. Only fire webhooks/
                        // notifications if we actually won the transition.
                        $settled = Database::update(
                            'invoices',
                            ['status' => 'Settled', 'paid_at' => time(), 'settled_rail' => 'mint'],
                            'id = ? AND status != ?',
                            [$invoice['id'], 'Settled']
                        );
                        if ($settled !== 1) {
                            continue; // already settled elsewhere
                        }
                        $recovered[] = $invoice['id'];

                        $updatedInvoice = self::getById($invoice['id']);
                        WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);
                        NotificationSender::queueInvoicePaid($updatedInvoice);

                        error_log("CashuPayServer: Recovered orphaned invoice {$invoice['id']}");
                    }
                }
            } catch (\Throwable $e) {
                error_log("CashuPayServer: Error recovering invoice {$invoice['id']} (" . get_class($e) . "): " . $e->getMessage());
            }
        }

        return $recovered;
    }

    // =========================================================================
    // LATE PAYMENT RECOVERY (mint rail)
    // =========================================================================

    /**
     * Collect payments made on invoices the local clock, or the merchant,
     * already closed.
     *
     * A NUT-04 mint quote cannot be cancelled: the bolt11 stays payable until
     * the mint's own quote expiry, and a payment that landed just before
     * expiry — or while cron was down — is otherwise minted by nobody and the
     * sats sit at the mint under a quote id the server never claims. Expired
     * and Invalid rows that still carry a quote are re-checked often for
     * EXPIRY_RECOVERY_GRACE after expiry, then daily, until the mint gives a
     * terminal answer. PAID/ISSUED quotes are minted and settled through the
     * normal CAS paths (so InvoiceReceivedPayment/InvoiceSettled fire); a
     * quote the mint says plainly is UNPAID more than UNPAID_WRITE_OFF_DELAY
     * after its own expiry is written off (quote_id cleared), which lets the
     * cleanup task delete the row by age.
     *
     * @return string[] ids of invoices settled by this call
     */
    public static function recoverLatePaidInvoices(int $minInterval = 60, int $batchLimit = 10): array {
        $recovered = [];
        $now = time();

        $rows = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status IN ('Expired', 'Invalid')
               AND quote_id IS NOT NULL AND quote_id <> ''
               AND (payment_rail IS NULL OR payment_rail = '' OR payment_rail = 'mint')
               AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY (expiration_time > ?) DESC, last_polled_at ASC
             LIMIT ?",
            [$now - $minInterval, $now - self::EXPIRY_RECOVERY_GRACE, $batchLimit]
        );

        // Past the frequent window a row is only re-checked once a day.
        $rows = array_values(array_filter($rows, function (array $invoice) use ($now): bool {
            if ((int)$invoice['expiration_time'] > $now - self::EXPIRY_RECOVERY_GRACE) {
                return true;
            }
            $last = (int)($invoice['last_polled_at'] ?? 0);
            return $now - $last >= self::EXPIRY_RECOVERY_SLOW_INTERVAL;
        }));

        foreach ($rows as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);
                self::recordPollOutcome($invoice['id'], (string)$quoteStatus->state, null);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    error_log("CashuPayServer: Late payment on {$invoice['status']} invoice {$invoice['id']} - recovering");
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                    $after = self::getById($invoice['id']);
                    if ($after && $after['status'] === 'Settled') {
                        $recovered[] = $invoice['id'];
                    }
                } elseif ($quoteStatus->expiry !== null
                          && (int)$quoteStatus->expiry < $now - self::UNPAID_WRITE_OFF_DELAY
                          && self::quoteIsDefinitelyUnpaid($quoteStatus)) {
                    // The mint's own quote has long expired and the mint says
                    // plainly that nothing was paid: this claim is resolved and
                    // the row may be cleaned up by age from now on. Anything
                    // less than that plain answer keeps the quote — dropping
                    // the only link to a payment is a one-way door.
                    Database::update(
                        'invoices',
                        ['quote_id' => null, 'last_poll_state' => 'written_off_unpaid'],
                        "id = ? AND status IN ('Expired', 'Invalid') AND quote_id IS NOT NULL",
                        [$invoice['id']]
                    );
                }
            } catch (\Throwable $e) {
                self::recordPollOutcome($invoice['id'], null, $e->getMessage());
                error_log("CashuPayServer: Error recovering late payment on invoice {$invoice['id']}: " . $e->getMessage());
            }
        }

        return $recovered;
    }

    /**
     * Delete finished invoices older than $maxAgeSec. Settled rows go by age;
     * Expired/Invalid rows only once they no longer carry a quote_id — a
     * quote still on the row is the only link to a payment that may yet be
     * collected (see recoverLatePaidInvoices()).
     */
    public static function cleanupOldInvoices(int $maxAgeSec): int {
        return (int)Database::query(
            "DELETE FROM invoices
             WHERE created_at < ?
               AND (status = 'Settled'
                    OR (status IN ('Expired', 'Invalid') AND (quote_id IS NULL OR quote_id = '')))",
            [time() - $maxAgeSec]
        )->rowCount();
    }

    // =========================================================================
    // EXPORTED TOKEN TRANSFERS
    // =========================================================================

    /**
     * Ask the mint whether pending exported tokens were cashed in; settle the
     * ones that were (proofs SPENT, ledger row completed). Driven by cron so a
     * token redeemed while no export screen was watching does not stay
     * "not cashed in" forever.
     *
     * @return array{checked:int, completed:int, errors:int}
     */
    public static function reconcilePendingTokenTransfers(int $limit = 20, int $minInterval = 300): array {
        $out = ['checked' => 0, 'completed' => 0, 'errors' => 0];
        $rows = Transfer::getPending($limit, null, time() - $minInterval);
        foreach ($rows as $row) {
            if (!in_array($row['type'], Transfer::TOKEN_TYPES, true)) {
                continue;
            }
            $out['checked']++;
            Transfer::touch((string)$row['id']);
            try {
                $secrets = Transfer::secretsOf($row);
                if (empty($secrets)) {
                    continue;
                }
                $unit = (string)($row['unit'] ?? 'sat');
                $mintUrl = (string)($row['mint_url'] ?? '');
                if ($mintUrl === '') {
                    $account = self::namespaceHoldingSecrets((string)$row['store_id'], $secrets);
                    $mintUrl = $account['mint_url'];
                    $unit = $account['unit'];
                }
                $states = self::checkProofStatesBySecrets((string)$row['store_id'], $secrets, $mintUrl, $unit);
                $spent = count(array_filter($states, fn($st) => $st === ProofState::SPENT));
                if ($spent === count($secrets)) {
                    StoreSafety::transitionProofStates(
                        $mintUrl, $unit, $secrets, ProofState::SPENT,
                        [StoreSafety::exportedState(), ProofState::PENDING, ProofState::UNSPENT]
                    );
                    if (Transfer::complete((string)$row['id'], 'Cashed in by the recipient')) {
                        $out['completed']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['errors']++;
                error_log("CashuPayServer: reconcile transfer {$row['id']} failed: " . $e->getMessage());
            }
        }
        return $out;
    }

    // =========================================================================
    // PER-STORE BALANCE OPERATIONS (OFFLINE-FIRST)
    // =========================================================================
    // These methods read directly from local storage without contacting the mint.
    // Ecash is offline-first - local storage is the source of truth for proofs.

    /**
     * Spendable balance of the store's PRIMARY mint (reads from local storage).
     *
     * This is the default offline-first method: no mint contact. It is the
     * number every spending path (withdraw, export, auto-cashout, fee melt)
     * can actually draw on, which is why funds settled by failover into a
     * backup mint are NOT added here — see getAccountBalances() for the
     * per-account breakdown the dashboard shows.
     */
    public static function getBalance(string $storeId): int {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return 0;
        }
        return StoreSafety::storage($store['mint_url'], $store['mint_unit'] ?? 'sat')->getBalance();
    }

    /**
     * Balance per wallet account (primary and every backup mint, disabled ones
     * included). A failover payment is minted at whichever mint issued its
     * quote, so money can sit at a backup; it is listed here so it is never
     * invisible. `exported` is value handed out in tokens nobody has cashed
     * in yet; `same_unit` says whether the account's unit matches the primary.
     *
     * @return list<array{mint_url:string, unit:string, primary:bool, enabled:bool, balance:int, exported:int, same_unit:bool}>
     */
    public static function getAccountBalances(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }
        $primaryUnit = strtolower((string)($store['mint_unit'] ?? 'sat'));
        $out = [];
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $held = StoreSafety::namespaceHoldings($account['mint_url'], $account['unit']);
            } catch (\Throwable $e) {
                $held = ['balance' => 0, 'exported' => 0];
            }
            $out[] = [
                'mint_url' => $account['mint_url'],
                'unit' => $account['unit'],
                'primary' => (bool)$account['primary'],
                'enabled' => (bool)$account['enabled'],
                'balance' => (int)$held['balance'],
                'exported' => (int)$held['exported'],
                'same_unit' => $account['unit'] === $primaryUnit,
            ];
        }
        return $out;
    }

    /**
     * Value sitting in tokens this store handed out that nobody has redeemed
     * yet (primary unit only). Shown next to the balance so the money is
     * visibly accounted for.
     */
    public static function getExportedBalance(string $storeId): int {
        $total = 0;
        foreach (self::getAccountBalances($storeId) as $account) {
            if ($account['same_unit']) {
                $total += $account['exported'];
            }
        }
        return $total;
    }

    /**
     * Get unspent proofs for a store's primary mint (reads from local storage).
     *
     * Returns Proof objects directly from SQLite storage. Proofs an unfinished
     * melt/swap journal still owns are excluded defensively: the library
     * leaves inputs UNSPENT until the mint answers, and handing them out in a
     * token while that request is queued would double-spend.
     */
    public static function getUnspentProofs(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }
        $storage = StoreSafety::storage($store['mint_url'], $store['mint_unit'] ?? 'sat');
        $proofs = $storage->getProofsAsObjects(ProofState::UNSPENT);
        $reserved = array_flip(StoreSafety::reservedInputSecrets($storage));
        if (empty($reserved)) {
            return $proofs;
        }
        return array_values(array_filter($proofs, fn($p) => !isset($reserved[$p->secret])));
    }

    /**
     * Which of the store's wallet accounts holds ALL the given proof secrets.
     * A token's ecash lives in the namespace of the mint that issued it, which
     * is not necessarily the current primary (mint changed, failover settle);
     * asking the wrong mint answers UNSPENT for Ys it has never seen.
     *
     * @param string[] $secrets
     * @return array{mint_url:string, unit:string}
     */
    public static function namespaceHoldingSecrets(string $storeId, array $secrets): array {
        $store = Config::getStore($storeId);
        $fallback = [
            'mint_url' => rtrim((string)($store['mint_url'] ?? ''), '/'),
            'unit' => strtolower((string)($store['mint_unit'] ?? 'sat')),
        ];
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (!$store || empty($secrets)) {
            return $fallback;
        }
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $states = StoreSafety::storage($account['mint_url'], $account['unit'])->getProofsStatesBySecrets($secrets);
                if (count($states) === count($secrets)) {
                    return ['mint_url' => $account['mint_url'], 'unit' => $account['unit']];
                }
            } catch (\Throwable $e) {
                // An account we cannot open is not a reason to fail the lookup.
            }
        }
        return $fallback;
    }

    /**
     * Mark proofs as spent (updates local storage). Looks up the namespace
     * that actually holds the secrets (primary or backup).
     */
    public static function markProofsSpent(string $storeId, array $secrets): void {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets)) {
            return;
        }
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }
        $account = self::namespaceHoldingSecrets($storeId, $secrets);
        // Only rows the server may own move: inputs reserved by a melt/swap
        // journal are excluded (the library's recovery settles them), and
        // an already-SPENT row is simply not counted.
        $changed = StoreSafety::transitionProofStates(
            $account['mint_url'], $account['unit'], $secrets, ProofState::SPENT,
            [ProofState::UNSPENT, ProofState::PENDING, ProofState::EXPORTED, ProofState::UNKNOWN]
        );
        if ($changed !== count($secrets)) {
            error_log(sprintf(
                'CashuPayServer: markProofsSpent moved %d of %d proofs for store %s (rest already spent or journal-reserved)',
                $changed, count($secrets), $storeId
            ));
        }
    }

    /**
     * Mark proofs as handed to a third party (an exported token).
     *
     * Distinct from PENDING on purpose: the mint reporting an exported proof
     * UNSPENT only means the recipient has not redeemed it yet, so nothing may
     * put it back into the spendable balance on its own. Taking an export
     * back is an explicit operator action that swaps the proofs
     * (reclaimExportedProofs()).
     *
     * Only UNSPENT proofs may be handed out: if the cron's cashout or a
     * concurrent request already reserved or spent one, the token being built
     * is not ours to give — nothing is changed and the export fails.
     *
     * @param string[] $secrets
     * @throws Exception
     */
    public static function markProofsExported(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): void {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets)) {
            return;
        }
        if ($mintUrl === null || $unit === null) {
            $account = self::namespaceHoldingSecrets($storeId, $secrets);
            $mintUrl = $account['mint_url'];
            $unit = $account['unit'];
        }
        $changed = StoreSafety::transitionProofStates(
            $mintUrl, $unit, $secrets, StoreSafety::exportedState(), [ProofState::UNSPENT], true
        );
        if ($changed !== count($secrets)) {
            throw new Exception(
                'Some of this money was being used by another payment at the same moment. '
                . 'Nothing was handed out; please try again.'
            );
        }
    }

    /**
     * Mark proofs as pending for a store (updates local storage).
     *
     * PENDING means "reserved by an in-flight mint operation" and is owned by
     * the library's melt/swap journals. Token exports use markProofsExported().
     */
    public static function markProofsPending(string $storeId, array $secrets): void {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets)) {
            return;
        }
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }
        // Only UNSPENT rows may be reserved, and only all of them: a proof
        // another path already reserved or spent is not ours to hold.
        $changed = StoreSafety::transitionProofStates(
            $store['mint_url'], $store['mint_unit'] ?? 'sat', $secrets, ProofState::PENDING, [ProofState::UNSPENT], true
        );
        if ($changed !== count($secrets)) {
            throw new Exception('Some of these proofs are no longer available; nothing was reserved.');
        }
    }

    /**
     * Store proofs as unspent for a store
     */
    public static function storeProofs(string $storeId, array $proofs): void {
        if (empty($proofs)) {
            return;
        }

        $wallet = self::getWalletForStore($storeId);
        $wallet->getStorage()->storeProofs($proofs);
    }

    /**
     * Ask the issuing mint what it thinks of the given proofs.
     *
     * The namespace holding the secrets (primary or backup) decides which mint
     * is asked unless the caller names one. The reply is validated: a missing,
     * short, long or re-ordered states array is an error, never an answer.
     *
     * @param string[] $secrets
     * @return array<string, string> secret => upper-cased NUT-07 state
     * @throws Exception
     */
    public static function checkProofStatesBySecrets(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): array {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets)) {
            return [];
        }
        if ($mintUrl === null || $mintUrl === '') {
            $account = self::namespaceHoldingSecrets($storeId, $secrets);
            $mintUrl = $account['mint_url'];
        }
        if ($mintUrl === '') {
            throw new Exception('Store has no mint to ask');
        }
        if ($unit === null || $unit === '') {
            $account = $account ?? self::namespaceHoldingSecrets($storeId, $secrets);
            $unit = $account['unit'];
        }
        return StoreSafety::checkStatesBySecrets($mintUrl, $unit, $secrets);
    }

    /**
     * Pure decision for "the recipient has cashed this token in": every Y in
     * the request must come back SPENT from a complete, in-order NUT-07 reply.
     *
     * @param string[] $Ys
     * @param mixed $response decoded mint reply
     * @throws Exception on a missing / short / re-ordered reply
     */
    public static function proofStatesAllSpent(array $Ys, $response): bool {
        return StoreSafety::proofStatesAllSpent($Ys, $response);
    }

    /**
     * Reconcile PENDING proofs against their mint: apply the SPENT transition
     * only.
     *
     * Returning a proof to the spendable pool because the mint says UNSPENT is
     * exactly how the same bearer proofs used to be handed out twice ("not
     * redeemed yet" is not "still ours"), so this never writes UNSPENT.
     * Inputs an unfinished melt/swap journal owns are skipped: the library's
     * recovery owns them, and a mint may briefly report them UNSPENT after a
     * timeout even though the request is queued. Every account of the store
     * (primary and backups) is checked against ITS mint.
     */
    public static function checkPendingProofs(string $storeId): array {
        $result = ['checked' => 0, 'spent' => 0, 'pending' => 0, 'recovered' => 0];
        $errors = [];
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $storage = StoreSafety::storage($account['mint_url'], $account['unit']);
                $rows = $storage->getProofs(ProofState::PENDING);
                if (empty($rows)) {
                    continue;
                }
                $reserved = array_flip(StoreSafety::reservedInputSecrets($storage));
                $rows = array_values(array_filter($rows, fn($row) => !isset($reserved[$row['secret']])));
                if (empty($rows)) {
                    continue;
                }

                $proofs = array_map(
                    fn($row) => new Proof((string)$row['keyset_id'], (int)$row['amount'], (string)$row['secret'], (string)$row['C']),
                    $rows
                );
                // Library NUT-07 check: fail-closed, Y-matched, UNKNOWN when
                // the mint sent no state (never read as UNSPENT or SPENT).
                $states = StoreSafety::checkProofStates($account['mint_url'], $account['unit'], $proofs);

                $spentSecrets = [];
                $stillPending = 0;
                foreach ($proofs as $proof) {
                    if (($states[$proof->Y] ?? '') === ProofState::SPENT) {
                        $spentSecrets[] = $proof->secret;
                    } else {
                        $stillPending++;
                    }
                }
                if (!empty($spentSecrets)) {
                    $moved = StoreSafety::transitionProofStates(
                        $account['mint_url'], $account['unit'], $spentSecrets, ProofState::SPENT, [ProofState::PENDING]
                    );
                    if ($moved !== count($spentSecrets)) {
                        error_log(sprintf(
                            'CashuPayServer: checkPendingProofs moved %d of %d spent proofs at %s (rest changed state meanwhile)',
                            $moved, count($spentSecrets), $account['mint_url']
                        ));
                    }
                }
                $result['checked'] += count($rows);
                $result['spent'] += count($spentSecrets);
                $result['pending'] += $stillPending;
            } catch (\Throwable $e) {
                $errors[] = $account['mint_url'] . ': ' . $e->getMessage();
                error_log("CashuPayServer: Error checking pending proofs at {$account['mint_url']}: " . $e->getMessage());
            }
        }
        if (!empty($errors)) {
            $result['error'] = implode('; ', $errors);
        }
        return $result;
    }

    /**
     * Take back an exported token's value by swapping its proofs for fresh
     * ones. Simply marking them spendable again would leave the token in the
     * recipient's hands still valid, and whoever got there second would lose;
     * swapping invalidates the old token at the mint.
     *
     * Refuses unless the mint confirms every proof is still UNSPENT — if the
     * recipient already redeemed it there is nothing to take back. Must be
     * called with the store's funds lock held.
     *
     * @param string[] $secrets
     * @return int amount returned to the spendable balance
     * @throws Exception
     */
    public static function reclaimExportedProofs(string $storeId, array $secrets): int {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets)) {
            throw new Exception('Nothing to reclaim');
        }

        $account = self::namespaceHoldingSecrets($storeId, $secrets);
        $states = self::checkProofStatesBySecrets($storeId, $secrets, $account['mint_url'], $account['unit']);
        foreach ($secrets as $secret) {
            if (($states[$secret] ?? null) !== ProofState::UNSPENT) {
                throw new Exception(
                    'This token has already been cashed in (or is being cashed in right now), '
                    . 'so it cannot be taken back.'
                );
            }
        }

        $wallet = self::getWalletForStore($storeId, $account['mint_url'], $account['unit']);
        $storage = $wallet->getStorage();
        $exported = StoreSafety::exportedState();
        $wanted = array_flip($secrets);
        $proofs = array_values(array_filter(
            $storage->getProofsAsObjects($exported),
            fn($p) => isset($wanted[$p->secret])
        ));
        if (count($proofs) !== count($secrets)) {
            throw new Exception('Some of this token\'s ecash is no longer in the wallet');
        }

        $amount = Wallet::sumProofs($proofs);
        $fee = $wallet->calculateFee($proofs);

        // Dust: on a mint that charges per input a small token can cost more
        // to swap than it is worth. Swapping it together with ecash we already
        // hold fixes it — NUT-02 sums the per-input fee and rounds once.
        $helpers = [];
        if ($fee >= $amount) {
            foreach (self::getUnspentProofs($storeId) as $candidate) {
                if (isset($wanted[$candidate->secret])) {
                    continue;
                }
                $helpers[] = $candidate;
                if ($wallet->calculateFee(array_merge($proofs, $helpers)) < $amount + Wallet::sumProofs($helpers)) {
                    break;
                }
            }
            $fee = $wallet->calculateFee(array_merge($proofs, $helpers));
            if ($fee >= $amount + Wallet::sumProofs($helpers)) {
                throw new Exception(
                    'This is worth less than the mint charges to move it, and there is no other ecash '
                    . 'in this store to combine it with. It will become recoverable once you take another payment.'
                );
            }
        }

        $inputs = array_merge($proofs, $helpers);
        $total = Wallet::sumProofs($inputs);

        // swap() with the allowed-states list reserves the EXPORTED inputs
        // straight from EXPORTED inside its journaled transaction: they are
        // never spendable on the way, and a rejected swap returns them to
        // EXPORTED itself.
        $wallet->swap($inputs, Wallet::splitAmount($total - $fee), [ProofState::UNSPENT, $exported]);

        // What the operator gets back is the token's own value less the fee
        // this swap cost; the helpers were already theirs.
        return max(0, $amount - $fee);
    }

    // =========================================================================
    // STRANDED NAMESPACES (funds at a mint no store uses any more)
    // =========================================================================

    /**
     * Wallet namespaces that still hold UNSPENT/EXPORTED proofs but are not
     * reachable by any store's current accounts (primary + backups) — funds
     * left behind by a past mint/unit change. Each one's mint is identified
     * (best effort) by matching known and historical mint URLs against the
     * namespace derivation. Pure local/DB, no mint contact.
     *
     * @return list<array{wallet_id:string, mint_url:?string, unit:?string, amount:int, exported:int, count:int, keysets:string[], export_id:?string}>
     */
    public static function scanStrandedNamespaces(): array {
        $exported = StoreSafety::exportedState();
        $rows = Database::fetchAll(
            "SELECT wallet_id,
                    COUNT(*) AS c,
                    COALESCE(SUM(CASE WHEN state = ? THEN amount ELSE 0 END), 0) AS unspent,
                    COALESCE(SUM(CASE WHEN state = ? THEN amount ELSE 0 END), 0) AS exported
             FROM cashu_proofs WHERE state IN (?, ?) GROUP BY wallet_id",
            [ProofState::UNSPENT, $exported, ProofState::UNSPENT, $exported]
        );
        if (empty($rows)) {
            return [];
        }

        $live = [];
        $candidateMints = [];
        foreach (Database::fetchAll("SELECT id FROM stores") as $st) {
            foreach (Config::getStoreWalletAccounts($st['id']) as $acc) {
                $candidateMints[$acc['mint_url']] = true;
                $live[StoreSafety::walletId($acc['mint_url'], $acc['unit'])] = true;
            }
        }
        foreach (Database::fetchAll("SELECT DISTINCT mint_url FROM invoices WHERE mint_url IS NOT NULL AND mint_url <> ''") as $r) {
            $candidateMints[rtrim((string)$r['mint_url'], '/')] = true;
        }
        foreach (Database::fetchAll("SELECT DISTINCT mint_url FROM store_mints") as $r) {
            $candidateMints[rtrim((string)$r['mint_url'], '/')] = true;
        }
        foreach (Database::fetchAll("SELECT DISTINCT mint_url FROM transfers WHERE mint_url IS NOT NULL AND mint_url <> ''") as $r) {
            $candidateMints[rtrim((string)$r['mint_url'], '/')] = true;
        }
        // Mints a store moved away from (recorded by Config::updateStore when a
        // funded primary is changed with consent) and every mint the
        // reliability tracker has ever talked to.
        foreach ((array)Config::get('previous_mints', []) as $prev) {
            if (is_array($prev) && !empty($prev['mint_url'])) {
                $candidateMints[rtrim((string)$prev['mint_url'], '/')] = true;
            }
        }
        try {
            foreach (Database::fetchAll("SELECT DISTINCT mint_url FROM mint_reliability") as $r) {
                $candidateMints[rtrim((string)$r['mint_url'], '/')] = true;
            }
        } catch (\Throwable $e) {
            // table absent on a very old install — history only
        }

        $units = ['sat', 'usd', 'eur', 'usdt', 'msat'];
        $out = [];
        foreach ($rows as $r) {
            $wid = (string)$r['wallet_id'];
            if (isset($live[$wid])) {
                continue;
            }
            $found = null;
            foreach (array_keys($candidateMints) as $mu) {
                foreach ($units as $unit) {
                    if (StoreSafety::walletId((string)$mu, $unit) === $wid) {
                        $found = ['mint_url' => (string)$mu, 'unit' => $unit];
                        break 2;
                    }
                }
            }
            $keysets = array_column(
                Database::fetchAll("SELECT DISTINCT keyset_id FROM cashu_proofs WHERE wallet_id = ?", [$wid]),
                'keyset_id'
            );
            $pendingExport = $found ? Transfer::findPendingStranded($found['mint_url'], $found['unit']) : null;
            $out[] = [
                'wallet_id' => $wid,
                'mint_url' => $found['mint_url'] ?? null,
                'unit' => $found['unit'] ?? null,
                'amount' => (int)$r['unspent'] + (int)$r['exported'],
                'exported' => (int)$r['exported'],
                'count' => (int)$r['c'],
                'keysets' => $keysets,
                'export_id' => $pendingExport ? (string)$pendingExport['id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Refuse the stranded-funds tools on a namespace a store still uses: live
     * wallets have the ordinary withdraw and export buttons, and writing a
     * live namespace off would lose payments that arrived in between.
     */
    private static function assertNotLiveNamespace(string $mintUrl, string $unit): void {
        $wid = StoreSafety::walletId($mintUrl, $unit);
        foreach (Database::fetchAll("SELECT id FROM stores") as $st) {
            foreach (Config::getStoreWalletAccounts($st['id']) as $acc) {
                if (StoreSafety::walletId($acc['mint_url'], $acc['unit']) === $wid) {
                    throw new Exception('This money belongs to a mint a store still uses. Use Withdraw or Export on that store instead.');
                }
            }
        }
    }

    /**
     * Serialise a stranded namespace's proofs into one claimable token.
     *
     * The proofs are reserved as EXPORTED first, so this is the only live copy
     * and the money leaves the (invisible) balance while the token circulates.
     * Calling it again returns the SAME token and export id as long as the
     * reserved set is unchanged; a newer set supersedes the older pending
     * export. The export is recorded in the transfers ledger before it is
     * returned, so "mark recovered" writes off exactly these proofs.
     *
     * @return array{export_id:string, token:string, amount:int, count:int, wallet_id:string, mint_url:string, unit:string}
     * @throws Exception when the namespace is live or empty
     */
    public static function exportNamespaceAsToken(string $mintUrl, string $unit, string $storeId = ''): array {
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        self::assertNotLiveNamespace($mintUrl, $unit);
        $storage = StoreSafety::storage($mintUrl, $unit);
        $exported = StoreSafety::exportedState();

        $unspent = $storage->getProofsAsObjects(ProofState::UNSPENT);
        if (!empty($unspent)) {
            StoreSafety::transitionProofStates(
                $mintUrl, $unit, array_map(fn($p) => $p->secret, $unspent), $exported, [ProofState::UNSPENT]
            );
        }
        $proofs = $storage->getProofsAsObjects($exported);
        if (empty($proofs)) {
            throw new Exception('No funds found in that namespace.');
        }
        $secrets = array_map(fn($p) => $p->secret, $proofs);
        $amount = Wallet::sumProofs($proofs);

        $existing = Transfer::findPendingStranded($mintUrl, $unit);
        if ($existing !== null) {
            $have = Transfer::secretsOf($existing);
            $want = array_values(array_unique($secrets));
            sort($want, SORT_STRING);
            if ($have === $want && !empty($existing['token'])) {
                return [
                    'export_id' => (string)$existing['id'],
                    'token' => (string)$existing['token'],
                    'amount' => (int)$existing['amount'],
                    'count' => count($proofs),
                    'wallet_id' => StoreSafety::walletId($mintUrl, $unit),
                    'mint_url' => $mintUrl,
                    'unit' => $unit,
                ];
            }
            Transfer::fail((string)$existing['id'], 'Superseded by a newer recovery token for the same mint');
        }

        $useV4 = true;
        foreach ($proofs as $proof) {
            if (!\Cashu\TokenSerializer::isHexKeysetId($proof->id)) {
                $useV4 = false;
                break;
            }
        }
        $token = $useV4
            ? \Cashu\TokenSerializer::serializeV4($mintUrl, $proofs, $unit)
            : \Cashu\TokenSerializer::serializeV3($mintUrl, $proofs, $unit);

        if ($storeId === '') {
            $any = Database::fetchOne("SELECT id FROM stores ORDER BY created_at ASC LIMIT 1");
            $storeId = (string)($any['id'] ?? '');
        }
        $exportId = Transfer::open(
            $storeId, Transfer::TYPE_STRANDED_EXPORT, $amount, $unit, $secrets, $token, $mintUrl,
            'Recovery token for funds at a previous mint'
        );
        return [
            'export_id' => $exportId,
            'token' => $token,
            'amount' => $amount,
            'count' => count($proofs),
            'wallet_id' => StoreSafety::walletId($mintUrl, $unit),
            'mint_url' => $mintUrl,
            'unit' => $unit,
        ];
    }

    /**
     * Write off a claimed recovery token: exactly the proofs of the given
     * export become SPENT and its ledger row is completed. Bound to one export
     * id so a later export that picked up more proofs is never written off by
     * mistake.
     *
     * @return int number of proofs marked
     * @throws Exception when the export is unknown or already finished
     */
    public static function markNamespaceRecovered(string $exportId): int {
        $row = Transfer::getById($exportId);
        if (!$row || $row['type'] !== Transfer::TYPE_STRANDED_EXPORT) {
            throw new Exception('Create the recovery token again first, then confirm it was claimed.');
        }
        if ($row['status'] !== Transfer::STATUS_PENDING) {
            throw new Exception('This recovery token is already ' . $row['status'] . '.');
        }
        $mintUrl = (string)$row['mint_url'];
        $unit = (string)$row['unit'];
        self::assertNotLiveNamespace($mintUrl, $unit);
        $n = StoreSafety::transitionProofStates(
            $mintUrl, $unit, Transfer::secretsOf($row), ProofState::SPENT,
            [StoreSafety::exportedState(), ProofState::UNSPENT]
        );
        Transfer::complete($exportId, 'Marked as recovered by the operator');
        return $n;
    }

    /**
     * Check if an exception indicates the mint is unreachable
     *
     * This includes connection errors, timeouts, and other network issues.
     * Used to determine when to fall back to offline token export.
     */
    public static function isMintUnreachable(\Exception $e): bool {
        $message = strtolower($e->getMessage());

        // cURL connection/network errors
        $networkErrors = [
            'http request failed',
            'could not resolve',
            'connection refused',
            'connection timed out',
            'operation timed out',
            'failed to connect',
            'network is unreachable',
            'no route to host',
            'ssl connect error',
            'couldn\'t connect to server',
            'recv failure',
            'send failure',
            'tls handshake',
        ];

        foreach ($networkErrors as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        // Check for specific HTTP errors that indicate server issues
        // 5xx errors, 0 (no response), certain 4xx that indicate server problems
        if ($e instanceof \Cashu\CashuException) {
            // CashuException with "HTTP request failed" means network error
            if (strpos($message, 'http request failed') !== false) {
                return true;
            }
        }

        return false;
    }
}
