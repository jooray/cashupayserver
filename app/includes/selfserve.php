<?php
/**
 * Self-serve invoices — site- and per-store-level configuration + input
 * validation for the public, unauthenticated "create your own invoice" page
 * (pay.php, reached at /pay/{storeId}).
 *
 * Normally an invoice can only be created by an authenticated caller (the admin
 * "Request Payment" modal or a Greenfield API key). Self-serve lets a customer
 * create and pay an invoice themselves: they pick an amount + currency and an
 * optional note, we validate it (untrusted input), then hand off to
 * Invoice::create and the regular /payment/{id} display page.
 *
 * The settings are per-store only:
 *   stores.selfserve_enabled   — 0 off / 1 on. Default off (NULL and legacy
 *                                -1 "inherit" rows — from when a site-wide
 *                                default existed — resolve to off).
 *   stores.selfserve_max_sats  — INTEGER, NULL = the built-in default
 *                                (DEFAULT_MAX_SATS).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

final class SelfServe {
    /** Legacy sentinel still present in pre-store-only rows; resolves to off. */
    public const INHERIT   = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON  = 1;

    /** Default per-invoice maximum (sats) when nothing is configured. */
    public const DEFAULT_MAX_SATS = 500000;

    /** Smallest invoice we will create, in sats. */
    public const MIN_SATS = 1;

    /** Hard ceiling on the optional customer note length, in characters. */
    public const NOTES_MAX_LEN = 200;

    // ------------------------------------------------------------------
    // Per-store toggle + resolution
    // ------------------------------------------------------------------

    /**
     * Raw per-store value (0 off / 1 on / legacy -1, which resolves to off).
     * Defaults to INHERIT when the column is NULL (older rows) or the store
     * is missing.
     */
    public static function storeOverride(string $storeId): int {
        $row = Database::fetchOne(
            "SELECT selfserve_enabled FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || $row['selfserve_enabled'] === null) {
            return self::INHERIT;
        }
        return (int)$row['selfserve_enabled'];
    }

    public static function setStoreOverride(string $storeId, int $enabled): void {
        if (!in_array($enabled, [self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid selfserve_enabled value: {$enabled}");
        }
        Database::query(
            "UPDATE stores SET selfserve_enabled = ? WHERE id = ?",
            [$enabled, $storeId]
        );
    }

    /**
     * Is self-serve effectively enabled for this store? Requires the per-store
     * flag to be ON (NULL / legacy -1 rows resolve to off), and additionally
     * requires the store to actually be able to take a payment — otherwise the
     * public page would render a form that always errors on submit.
     */
    public static function isEnabledForStore(string $storeId): bool {
        $store = Config::getStore($storeId);
        if (!$store) {
            return false;
        }
        if (!self::storeIsPaymentCapable($store)) {
            return false;
        }
        return $store['selfserve_enabled'] !== null
            && (int)$store['selfserve_enabled'] === self::FORCE_ON;
    }

    /**
     * A store can take a payment if it has a Cashu mint configured or an
     * on-chain destination (xpub, or a static address in static mode). Mirrors
     * the capability check at the top of Invoice::create.
     */
    public static function storeIsPaymentCapable(array $store): bool {
        $cashuConfigured = !empty($store['mint_url']) && !empty($store['seed_phrase']);
        $mode = $store['onchain_address_mode'] ?? 'xpub';
        $onchainConfigured = ($mode === 'static')
            ? !empty($store['onchain_static_address'])
            : !empty($store['onchain_xpub']);
        return $cashuConfigured || $onchainConfigured;
    }

    // ------------------------------------------------------------------
    // Per-store maximum invoice size (sats)
    // ------------------------------------------------------------------

    /**
     * Raw per-store override (sats), or null when the store uses the built-in
     * default. Returned value is always > 0 when non-null.
     */
    public static function storeMaxSats(string $storeId): ?int {
        $row = Database::fetchOne(
            "SELECT selfserve_max_sats FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || $row['selfserve_max_sats'] === null) {
            return null;
        }
        $v = (int)$row['selfserve_max_sats'];
        return $v > 0 ? $v : null;
    }

    public static function setStoreMaxSats(string $storeId, ?int $sats): void {
        if ($sats !== null && $sats <= 0) {
            throw new InvalidArgumentException('Per-store self-serve max must be a positive number of sats');
        }
        Database::query(
            "UPDATE stores SET selfserve_max_sats = ? WHERE id = ?",
            [$sats, $storeId]
        );
    }

    /**
     * Resolved maximum invoice size in sats for a store: per-store value
     * wins, else the built-in default.
     */
    public static function effectiveMaxSats(string $storeId): int {
        return self::storeMaxSats($storeId) ?? self::DEFAULT_MAX_SATS;
    }

    // ------------------------------------------------------------------
    // Currency options
    // ------------------------------------------------------------------

    /**
     * Currencies the customer may choose on the self-serve form: always 'sat',
     * plus the store's configured default display currency when that is a fiat
     * (i.e. not sat). Per the product decision, no other fiat currencies are
     * offered. Returned codes are normalized: 'sat' lowercase, fiat uppercase.
     *
     * @return string[]
     */
    public static function allowedCurrencies(string $storeId): array {
        $out = ['sat'];
        $default = Config::getStoreDefaultCurrency($storeId); // 'sat' or e.g. 'USD'
        $norm = strtoupper($default) === 'SATS' ? 'sat' : $default;
        if (strtolower($norm) !== 'sat') {
            $out[] = strtoupper($norm);
        }
        return $out;
    }

    /**
     * The currency the self-serve form should pre-select for this store: the
     * store's configured default display currency, normalized to match the
     * codes in allowedCurrencies() ('sat' lowercase, fiat uppercase). Always
     * one of the allowed currencies, so it is safe to use as the default
     * selection.
     */
    public static function defaultCurrency(string $storeId): string {
        $default = Config::getStoreDefaultCurrency($storeId); // 'sat' or e.g. 'USD'
        $norm = strtoupper($default) === 'SATS' ? 'sat' : $default;
        return strtolower($norm) === 'sat' ? 'sat' : strtoupper($norm);
    }

    /**
     * Validate that a customer-supplied currency code is one we offer for this
     * store. Returns the normalized code, or throws.
     */
    public static function validateCurrency(string $storeId, string $currency): string {
        $currency = trim($currency);
        $norm = strtoupper($currency) === 'SATS' ? 'sat' : $currency;
        foreach (self::allowedCurrencies($storeId) as $allowed) {
            if (strcasecmp($allowed, $norm) === 0) {
                return $allowed;
            }
        }
        throw new SelfServeValidationException('Unsupported currency.');
    }

    // ------------------------------------------------------------------
    // Amount + notes validation (untrusted input)
    // ------------------------------------------------------------------

    /**
     * Validate a raw amount string for a given currency and return it as a
     * clean decimal string suitable for Invoice::create / ExchangeRates. Throws
     * SelfServeValidationException on any malformed / out-of-range input.
     *
     * For 'sat'/'BTC'-style units only whole numbers are accepted; fiat allows
     * up to 2 decimal places. This does NOT enforce the sats max — that needs a
     * rate conversion and is done by the caller via assertWithinMax().
     */
    public static function validateAmount(string $raw, string $currency): string {
        $raw = trim($raw);
        if ($raw === '') {
            throw new SelfServeValidationException('Please enter an amount.');
        }
        // Reject anything that isn't a plain positive decimal. No signs,
        // exponents, thousands separators, or whitespace.
        $isSatUnit = in_array(strtoupper($currency), ['SAT', 'SATS', 'MSAT', 'BTC'], true);
        if ($isSatUnit && strtoupper($currency) !== 'BTC') {
            // Whole sats only.
            if (!preg_match('/^\d{1,15}$/', $raw)) {
                throw new SelfServeValidationException('Amount must be a whole number of sats.');
            }
            if ((int)$raw < self::MIN_SATS) {
                throw new SelfServeValidationException('Amount is too small.');
            }
            return $raw;
        }
        // BTC or fiat: allow up to 8 / 2 decimals respectively. The integer
        // part may be omitted when a fraction is present (".10" is how many
        // people type ten cents); it is normalized to "0.10" so downstream
        // consumers (rate conversion, stored invoice amount) always see a
        // conventional decimal string.
        $maxDecimals = strtoupper($currency) === 'BTC' ? 8 : 2;
        if (!preg_match('/^(\d{1,12}|\d{0,12}\.\d{1,' . $maxDecimals . '})$/', $raw)) {
            throw new SelfServeValidationException('Amount is not a valid number.');
        }
        if ($raw[0] === '.') {
            $raw = '0' . $raw;
        }
        if ((float)$raw <= 0) {
            throw new SelfServeValidationException('Amount must be greater than zero.');
        }
        return $raw;
    }

    /**
     * Validate + normalize the optional note. Empty is allowed (returns ''). We
     * strip control characters (except none are kept — notes are single-line)
     * and enforce the length cap. Throws when over the cap so the customer gets
     * clear feedback rather than silent truncation.
     */
    public static function validateNotes(string $raw): string {
        // Normalize newlines/tabs to spaces, strip other control chars.
        $notes = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $notes = trim((string)$notes);
        if ($notes === '') {
            return '';
        }
        if (mb_strlen($notes) > self::NOTES_MAX_LEN) {
            throw new SelfServeValidationException(
                'Note is too long (max ' . self::NOTES_MAX_LEN . ' characters).'
            );
        }
        return $notes;
    }

    // ------------------------------------------------------------------
    // Product-prefilled links (?products=prod_x:2,prod_y) + optional tip
    // ------------------------------------------------------------------

    /** Hard cap on distinct products encodable in one link. */
    public const MAX_LINK_PRODUCTS = 20;

    /** Tip percentage presets offered on the product self-serve page. */
    public const TIP_PERCENT_PRESETS = [10, 15, 20];

    /**
     * Parse + resolve the untrusted ?products= link parameter. Each token is
     * "{productId}" or "{productId}:{qty}" (qty 1-999, default 1), separated
     * by commas; duplicate ids merge their quantities.
     *
     * A malformed parameter throws (the caller should 404 — same no-leak
     * policy as unknown stores). Products that are missing, disabled, or
     * belong to another store are DROPPED, not errors, so a shared link keeps
     * working when the merchant retires one of its items; the caller decides
     * what to do when nothing remains.
     *
     * @return array ['items' => [['product' => row, 'quantity' => int], ...],
     *                'dropped' => int]
     */
    public static function parseProductsParam(string $storeId, string $raw): array {
        require_once __DIR__ . '/products.php';
        $raw = trim($raw);
        if ($raw === '') {
            throw new SelfServeValidationException('Invalid product link.');
        }
        $tokens = explode(',', $raw);
        if (count($tokens) > self::MAX_LINK_PRODUCTS) {
            throw new SelfServeValidationException('Invalid product link.');
        }
        $wanted = []; // id => qty, insertion-ordered
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if (!preg_match('/^(prod_[a-f0-9]{12,})(?::([0-9]{1,3}))?$/', $tok, $m)) {
                throw new SelfServeValidationException('Invalid product link.');
            }
            $qty = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 1;
            if ($qty < 1) {
                throw new SelfServeValidationException('Invalid product link.');
            }
            $wanted[$m[1]] = min(999, ($wanted[$m[1]] ?? 0) + $qty);
        }
        $items = [];
        $dropped = 0;
        foreach ($wanted as $id => $qty) {
            $p = Product::get($id, $storeId);
            if ($p === null || (int)$p['enabled'] !== 1) {
                $dropped++;
                continue;
            }
            $items[] = ['product' => $p, 'quantity' => $qty];
        }
        return ['items' => $items, 'dropped' => $dropped];
    }

    /**
     * Validate the (untrusted) tip input from the product self-serve form and
     * turn it into a Cart::priceItems-compatible custom line, or null for no
     * tip. 'percent' mode must name one of TIP_PERCENT_PRESETS and is computed
     * on the products' sats subtotal; 'custom' mode takes an amount in the
     * store's default display currency (empty = no tip). Throws
     * SelfServeValidationException on anything malformed.
     */
    public static function buildTipLine(
        string $storeId, string $mode, string $percentRaw, string $amountRaw, int $subtotalSats
    ): ?array {
        $mode = trim($mode);
        if ($mode === '' || $mode === 'none') {
            return null;
        }
        if ($mode === 'percent') {
            $percentRaw = trim($percentRaw);
            if (!preg_match('/^\d{1,3}$/', $percentRaw)
                || !in_array((int)$percentRaw, self::TIP_PERCENT_PRESETS, true)) {
                throw new SelfServeValidationException('Invalid tip selection.');
            }
            $pct = (int)$percentRaw;
            $tipSats = (int)round($subtotalSats * $pct / 100);
            if ($tipSats < 1) {
                return null;
            }
            return ['title' => 'Tip (' . $pct . '%)', 'price' => (string)$tipSats, 'currency' => 'sat', 'quantity' => 1];
        }
        if ($mode === 'custom') {
            $amountRaw = trim($amountRaw);
            if ($amountRaw === '') {
                return null;
            }
            $currency = self::defaultCurrency($storeId);
            try {
                $amount = self::validateAmount($amountRaw, $currency);
            } catch (SelfServeValidationException $e) {
                throw new SelfServeValidationException('Tip: ' . $e->getMessage());
            }
            return ['title' => 'Tip', 'price' => $amount, 'currency' => $currency, 'quantity' => 1];
        }
        throw new SelfServeValidationException('Invalid tip selection.');
    }

    /**
     * Assert a computed sats amount is within [MIN_SATS, effective max]. Throws
     * SelfServeValidationException otherwise.
     */
    public static function assertWithinMax(string $storeId, int $sats): void {
        if ($sats < self::MIN_SATS) {
            throw new SelfServeValidationException('Amount is too small.');
        }
        $max = self::effectiveMaxSats($storeId);
        if ($sats > $max) {
            throw new SelfServeValidationException(
                'Amount exceeds the maximum of ' . number_format($max) . ' sats.'
            );
        }
    }
}

/**
 * Thrown for any invalid customer-supplied self-serve input. The message is
 * safe to surface directly to the (untrusted) customer.
 */
class SelfServeValidationException extends Exception {}
