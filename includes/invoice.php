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

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\Proof;
use Cashu\ProofState;

class Invoice {
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
                // Walk the priority chain. First destination to return a usable
                // invoice wins; the rest are only tried when earlier ones are
                // down / out of range / can't produce a verifiable invoice.
                foreach ($destinations as $priority => $dest) {
                    if ($dest['type'] === StoreLnAddresses::TYPE_STRIKE) {
                        // Strike API: create a BTC invoice in the merchant's
                        // Strike account and quote it into a BOLT11. Settlement
                        // is later confirmed by reading the Strike invoice back
                        // (payment page + cron) until state=PAID. Strike keys
                        // sort to position 0 in chainFromLists, so this rail is
                        // tried first whenever a key is configured. Strike caps
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
                        break;
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
                        break;
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
                        break;
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
                        break;
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
                if ($lnurlAttempt === null && $nofferAttempt === null && $nwcAttempt === null
                        && $strikeAttempt === null) {
                    error_log(sprintf(
                        '[direct-receive] all %d destination(s) failed for store=%s amount_sats=%d; falling back to swap/mint/onchain',
                        count($destinations), $storeId, $lnurlTargetSats
                    ));
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
        if ($cashuConfigured && !$strictMintFallbackBlocked && $swapAttempt === null
            && $lnurlAttempt === null && $strikeAttempt === null
            && $nofferAttempt === null && $nwcAttempt === null && $feeLightning === null) {
            $mintUnit = $store['mint_unit'];
            $lastError = null;
            try {
                $amountInMintUnit = ExchangeRates::convertToMintUnit(
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
                foreach ($allMints as $tryMintUrl) {
                    try {
                        $wallet = self::getWalletForStore($storeId, $tryMintUrl);
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
            if ($quote === null && !$onchainConfigured) {
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

            // ---- Strike on-chain: mint a fresh address in the merchant's
            // Strike account. Tried before the local xpub/static allocation;
            // any failure falls back to it (and is only surfaced to the payer
            // when no fallback produced an address — a working on-chain rail
            // shouldn't carry a scary banner). Keys walk in priority order,
            // mirroring the Lightning chain above. ----
            $strikeOnchainErrors = [];
            if ($strikeOnchainKeys !== [] && $baseAmountSat > 0) {
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
            }

            if ($onchainAddress === null && $onchainConfigured) {
                try {
                    $allocation = OnchainPayments::allocateAddress($storeId, $baseAmountSat);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === OnchainPayments::ERR_TWEAK_SLOTS_EXHAUSTED) {
                        throw new RuntimeException(
                            'All on-chain payment slots are temporarily reserved. Please try again in a few minutes.'
                        );
                    }
                    throw $e;
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
        $defaultExpiration = time() + Config::getInvoiceExpiration();
        $expiration = ($quote && isset($quote->expiry))
            ? $quote->expiry
            : $defaultExpiration;
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
     * Update invoice status
     */
    public static function updateStatus(string $invoiceId, string $status, ?string $additionalStatus = null, ?string $settledRail = null): void {
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
        // and offline reconcile can all reach this concurrently. Non-settlement
        // transitions keep their previous unconditional behaviour.
        if ($status === 'Settled') {
            $changed = Database::update('invoices', $updates, 'id = ? AND status != ?', [$invoiceId, 'Settled']);
            if ($changed !== 1) {
                return; // already settled by another path
            }
        } else {
            Database::update('invoices', $updates, 'id = ?', [$invoiceId]);
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
     * Mark expired invoices without contacting the mint
     *
     * Row-at-a-time with a status-guarded UPDATE instead of one bulk UPDATE:
     * only the caller whose UPDATE actually flips a row (rowCount 1) fires
     * that invoice's InvoiceExpired webhook, so concurrent sweeps from the
     * cron pollers can't emit duplicates and a row paid between the SELECT
     * and the UPDATE is left alone.
     *
     * @return int Number of invoices marked as expired
     */
    public static function markExpiredInvoices(): int {
        $rows = Database::fetchAll(
            "SELECT id FROM invoices WHERE status = 'New' AND expiration_time < ?",
            [time()]
        );
        $count = 0;
        foreach ($rows as $row) {
            $changed = Database::update(
                'invoices',
                ['status' => 'Expired'],
                "id = ? AND status = 'New'",
                [$row['id']]
            );
            if ($changed !== 1) {
                continue;
            }
            $count++;
            $invoice = self::getById($row['id']);
            if ($invoice) {
                WebhookSender::fireEvent($invoice['store_id'], 'InvoiceExpired', $invoice);
            }
        }
        return $count;
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
        $cutoff = $now - $minInterval;
        $pendingInvoices = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'New'
             AND quote_id IS NOT NULL
             AND expiration_time > ?
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?",
            [$now, $cutoff, $batchLimit]
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

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                }
            } catch (Exception $e) {
                error_log("CashuPayServer: Error polling invoice {$invoice['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Mint tokens and store proofs
     */
    private static function mintAndStoreTokens(array $invoice, Wallet $wallet): void {
        self::clearWebhookQueue();

        // Mark as Processing BEFORE minting
        Database::update('invoices', ['status' => 'Processing'], 'id = ?', [$invoice['id']]);

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
                AND expiration_time > ?
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $cutoff, $batchLimit]
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
        if ($invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time()) {
            self::updateStatus((string)$invoice['id'], 'Expired');
            return;
        }
        if (empty($invoice['lnurl_verify_url'])) {
            return;
        }
        try {
            Database::update('invoices', ['last_polled_at' => time()], 'id = ?', [$invoice['id']]);
            $result = LnUrlReceive::pollVerifyUrl((string)$invoice['lnurl_verify_url']);
            if ($result['state'] === 'paid') {
                self::markLnAddressPaid($invoice, $result['preimage']);
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
                AND expiration_time > ?
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $cutoff, $batchLimit]
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
        if ($invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time()) {
            self::updateStatus((string)$invoice['id'], 'Expired');
            return;
        }
        if (empty($invoice['nwc_uri']) || empty($invoice['nwc_payment_hash'])) {
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
                AND expiration_time > ?
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $cutoff, $batchLimit]
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
        if ($invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time()) {
            self::updateStatus((string)$invoice['id'], 'Expired');
            return;
        }
        if (empty($invoice['strike_invoice_id']) || empty($invoice['strike_api_key'])) {
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
                AND expiration_time > ?
                AND (last_polled_at IS NULL OR last_polled_at <= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $cutoff, $batchLimit]
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
        if ((int)$invoice['expiration_time'] < time()) {
            self::updateStatus((string)$invoice['id'], 'Expired');
            return;
        }
        $ctx = self::nofferCtxFromInvoice($invoice);
        if ($ctx === null) {
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
    public static function getWalletForStore(string $storeId, ?string $mintUrl = null): Wallet {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $mintUrl = $mintUrl ?? $store['mint_url'];
        $mintUnit = $store['mint_unit'] ?? 'sat';
        $seedPhrase = $store['seed_phrase'];

        if (empty($mintUrl) || empty($seedPhrase)) {
            throw new Exception('Store wallet not configured');
        }

        $cacheKey = $storeId . '|' . $mintUrl . '|' . $mintUnit;

        if (!isset(self::$walletCache[$cacheKey])) {
            $wallet = new Wallet($mintUrl, $mintUnit, Database::getDbPath());
            $wallet->loadMint();
            $wallet->initFromMnemonic($seedPhrase);

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
                self::updateStatus($invoice['id'], 'Expired');
            }
            return;
        }

        // Check expiration (only for New invoices) for the Cashu side.
        if ($invoice['status'] === 'New' && $invoice['expiration_time'] < time()) {
            self::updateStatus($invoice['id'], 'Expired');
            return;
        }

        try {
            $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
            $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

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
            }
        } catch (Exception $e) {
            error_log("CashuPayServer: Error polling single quote {$invoice['id']}: " . $e->getMessage());
        }
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
     * Recover orphaned invoices stuck in Processing state
     */
    public static function recoverOrphanedInvoices(): array {
        $recovered = [];

        $stuck = Database::fetchAll(
            "SELECT * FROM invoices WHERE status = 'Processing' AND created_at < ?",
            [time() - 60]
        );

        foreach ($stuck as $invoice) {
            try {
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
            } catch (Exception $e) {
                error_log("CashuPayServer: Error recovering invoice {$invoice['id']}: " . $e->getMessage());
            }
        }

        return $recovered;
    }

    // =========================================================================
    // PER-STORE BALANCE OPERATIONS (OFFLINE-FIRST)
    // =========================================================================
    // These methods read directly from local storage without contacting the mint.
    // Ecash is offline-first - local storage is the source of truth for proofs.

    /**
     * Get total balance for a store (reads from local storage)
     *
     * This is the default offline-first method. Reads directly from SQLite
     * without contacting the mint. Use for balance display, threshold checks,
     * and any operation that doesn't require mint verification.
     */
    public static function getBalance(string $storeId): int {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return 0;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        return $storage->getBalance();
    }

    /**
     * Get unspent proofs for a store (reads from local storage)
     *
     * Returns Proof objects directly from SQLite storage.
     * No mint contact required - ecash proofs are stored locally.
     */
    public static function getUnspentProofs(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        return $storage->getProofsAsObjects(ProofState::UNSPENT);
    }

    /**
     * Mark proofs as spent for a store (updates local storage)
     */
    public static function markProofsSpent(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        $storage->updateProofsState($secrets, ProofState::SPENT);
    }

    /**
     * Mark proofs as pending for a store (updates local storage)
     *
     * Marks proofs as PENDING in local storage. Used when proofs are sent
     * but not yet confirmed spent (e.g., token export, melt in progress).
     */
    public static function markProofsPending(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        $storage->updateProofsState($secrets, ProofState::PENDING);
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
     * Check pending proofs at the mint and update their state
     */
    public static function checkPendingProofs(string $storeId): array {
        try {
            $wallet = self::getWalletForStore($storeId);

            $rows = [];
            if ($wallet->hasStorage()) {
                $rows = $wallet->getStorage()->getProofs(ProofState::PENDING);
            }

            if (empty($rows)) {
                return ['checked' => 0, 'spent' => 0, 'recovered' => 0];
            }

            // Build Y values for batch check
            $Ys = [];
            $proofMap = [];
            foreach ($rows as $row) {
                $secret = $row['secret'];
                $Y = \Cashu\Crypto::hashToCurve($secret);
                $YHex = bin2hex(\Cashu\Secp256k1::compressPoint($Y));
                $Ys[] = $YHex;
                $proofMap[$YHex] = $secret;
            }

            // Check with mint
            $store = Config::getStore($storeId);
            $client = new \Cashu\MintClient($store['mint_url']);
            $response = $client->post('checkstate', ['Ys' => $Ys]);

            // Separate into spent and unspent
            $spentSecrets = [];
            $unspentSecrets = [];
            foreach ($response['states'] ?? [] as $i => $state) {
                $mintState = $state['state'] ?? ProofState::UNSPENT;
                $YHex = $Ys[$i];
                if (!isset($proofMap[$YHex])) continue;

                if ($mintState === ProofState::SPENT) {
                    $spentSecrets[] = $proofMap[$YHex];
                } else {
                    $unspentSecrets[] = $proofMap[$YHex];
                }
            }

            // Update database
            if (!empty($spentSecrets)) {
                $wallet->getStorage()->updateProofsState($spentSecrets, ProofState::SPENT);
            }

            if (!empty($unspentSecrets)) {
                $wallet->getStorage()->updateProofsState($unspentSecrets, ProofState::UNSPENT);
            }

            return [
                'checked' => count($rows),
                'spent' => count($spentSecrets),
                'recovered' => count($unspentSecrets)
            ];
        } catch (\Exception $e) {
            error_log("CashuPayServer: Error checking pending proofs: " . $e->getMessage());
            return ['checked' => 0, 'spent' => 0, 'recovered' => 0, 'error' => $e->getMessage()];
        }
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
