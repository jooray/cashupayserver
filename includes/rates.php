<?php
/**
 * CashuPayServer - Exchange Rate Module
 *
 * Fetches and caches exchange rates from multiple providers with fallback.
 * Supports bidirectional currency conversion between any currencies.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

/**
 * Price provider interface
 */
interface PriceProvider {
    public function getBtcPrice(string $currency): ?float;
    public function getName(): string;
    /** Whether this provider can price BTC in $currency at all (no network call). */
    public function supports(string $currency): bool;
}

/**
 * CoinGecko price provider
 */
class CoinGeckoProvider implements PriceProvider {
    private const API_URL = 'https://api.coingecko.com/api/v3/simple/price';

    public function getName(): string {
        return 'coingecko';
    }

    public function supports(string $currency): bool {
        return true; // CoinGecko quotes BTC in every fiat currency we offer
    }

    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);

        $url = self::API_URL . '?' . http_build_query([
            'ids' => 'bitcoin',
            'vs_currencies' => $currency,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['bitcoin'][$currency] ?? null;
    }
}

/**
 * Binance price provider
 */
class BinanceProvider implements PriceProvider {
    private const API_URL = 'https://api.binance.com/api/v3/ticker/price';

    // Currency mapping for Binance symbols
    private const CURRENCY_MAP = [
        'eur' => 'BTCEUR',
        'usd' => 'BTCUSDT',
        'usdt' => 'BTCUSDT',
        'usdc' => 'BTCUSDC',
        'gbp' => 'BTCGBP',
        'aud' => 'BTCAUD',
        'brl' => 'BTCBRL',
        'try' => 'BTCTRY',
    ];

    public function getName(): string {
        return 'binance';
    }

    public function supports(string $currency): bool {
        return isset(self::CURRENCY_MAP[strtolower($currency)]);
    }

    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);

        $symbol = self::CURRENCY_MAP[$currency] ?? null;
        if ($symbol === null) {
            return null; // Currency not supported by Binance
        }

        $url = self::API_URL . '?symbol=' . $symbol;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);
        return isset($data['price']) ? (float)$data['price'] : null;
    }
}

/**
 * Kraken price provider
 */
class KrakenProvider implements PriceProvider {
    private const API_URL = 'https://api.kraken.com/0/public/Ticker';

    // Currency mapping for Kraken pairs
    private const PAIR_MAP = [
        'eur' => 'XBTEUR',
        'usd' => 'XBTUSD',
        'gbp' => 'XBTGBP',
        'cad' => 'XBTCAD',
        'jpy' => 'XBTJPY',
        'aud' => 'XBTAUD',
        'chf' => 'XBTCHF',
    ];

    public function getName(): string {
        return 'kraken';
    }

    public function supports(string $currency): bool {
        return isset(self::PAIR_MAP[strtolower($currency)]);
    }

    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);

        $pair = self::PAIR_MAP[$currency] ?? null;
        if ($pair === null) {
            return null; // Currency not supported by Kraken
        }

        $url = self::API_URL . '?pair=' . $pair;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);

        if (!empty($data['error'])) {
            return null;
        }

        // Kraken returns nested result with pair name as key
        $result = $data['result'] ?? [];
        foreach ($result as $pairData) {
            // 'c' is the last trade closed [price, lot-volume]
            if (isset($pairData['c'][0])) {
                return (float)$pairData['c'][0];
            }
        }

        return null;
    }
}

/**
 * Exchange rates manager
 */
class ExchangeRates {
    /**
     * Working precision for intermediate conversions.
     *
     * Wide enough that a chain of divisions and multiplications rounds only once, at the
     * end, in toSmallestUnit(). At 8 decimals the BTC intermediate truncated first and
     * the final ceiling had nothing left to round up.
     */
    private const SCALE = 16;

    private const CACHE_TTL = 300; // 5 minutes
    private const STALE_TTL = 3600; // 1 hour (warn if older)

    private static array $providers = [];

    /**
     * Get provider instances
     */
    private static function getProviders(): array {
        if (empty(self::$providers)) {
            self::$providers = [
                'coingecko' => new CoinGeckoProvider(),
                'binance' => new BinanceProvider(),
                'kraken' => new KrakenProvider(),
            ];
        }
        return self::$providers;
    }

    /**
     * Get Bitcoin price in fiat currency with provider fallback
     *
     * @param string $currency Target currency (EUR, USD, etc.)
     * @param string|null $primary Primary provider name
     * @param string|null $secondary Secondary provider name
     * @return float|null Price or null if unavailable
     */
    public static function getBtcPrice(string $currency, ?string $primary = null, ?string $secondary = null): ?float {
        $currency = strtolower($currency);

        // BTC and sat don't need conversion
        if (in_array($currency, ['btc', 'sat', 'sats', 'msat'])) {
            return null;
        }

        // Check cache first
        $cached = self::getCached($currency);
        if ($cached !== null) {
            return $cached;
        }

        // Build provider order
        $providers = self::getProviders();

        // Binance and Kraken quote only a handful of currencies. For anything else
        // (CZK, PLN, HUF, ...) one provider is the only source, so two can never agree.
        // Price those through the confirmed BTC/USD rate and that provider's own FX rate.
        $supporting = array_values(array_filter($providers, fn($p) => $p->supports($currency)));
        if ($currency !== 'usd' && count($supporting) === 1) {
            return self::singleSourcePrice($currency, $supporting[0], $primary, $secondary);
        }

        $order = [];

        if ($primary && isset($providers[$primary])) {
            $order[] = $providers[$primary];
        }
        if ($secondary && isset($providers[$secondary]) && $secondary !== $primary) {
            $order[] = $providers[$secondary];
        }
        // Add remaining providers as fallback
        foreach ($providers as $name => $provider) {
            if ($name !== $primary && $name !== $secondary) {
                $order[] = $provider;
            }
        }

        // Try each provider. A positive number is not enough: one bad answer (a decimal
        // slip, a wrong field, a hijacked response) would price every invoice for minutes
        // to an hour (SPB-OPS-011, OPUS-019). Nor is agreeing with the last cached rate:
        // that rate may itself be one provider's word, so a hostile primary could walk
        // the price down 50 % per refresh (C2). The reference is a separate anchor,
        // written only when two providers agree. One provider alone is trusted only
        // close to a fresh anchor; anything else needs a second provider to agree.
        $anchor = self::confirmedAnchor($currency);
        $collected = [];
        foreach ($order as $provider) {
            try {
                $rate = $provider->getBtcPrice($currency);
            } catch (Exception $e) {
                error_log("ExchangeRates: {$provider->getName()} failed for {$currency}: " . $e->getMessage());
                continue;
            }
            // Reject non-positive rates: a provider glitch returning 0 would otherwise
            // cause a division-by-zero or wildly wrong pricing. See FABLE-CASHUPAYSERVER-AUDIT (C-RATE-1).
            if ($rate === null || !is_finite($rate) || $rate <= 0) {
                continue;
            }
            foreach ($collected as [$earlierRate, $earlierName]) {
                if (self::withinBand($rate, $earlierRate, self::AGREEMENT_BAND)) {
                    // Two independent sources agree: prefer the higher-priority one, and
                    // make it the new anchor.
                    self::saveToCache($currency, $earlierRate, $earlierName);
                    Config::set('rate_anchor_' . $currency, [
                        'rate' => $earlierRate,
                        'timestamp' => time(),
                        'providers' => [$earlierName, $provider->getName()],
                    ]);
                    return $earlierRate;
                }
            }
            if ($anchor !== null && self::withinBand($rate, $anchor, self::ANCHOR_BAND)) {
                // Close to a confirmed rate: fine on its own, but it does not move the anchor.
                self::saveToCache($currency, $rate, $provider->getName());
                return $rate;
            }
            if ($anchor !== null) {
                error_log("ExchangeRates: {$provider->getName()} {$currency} rate {$rate} is more than "
                    . (int)(self::ANCHOR_BAND * 100) . "% away from the confirmed rate {$anchor}; asking another provider");
            }
            $collected[] = [$rate, $provider->getName()];
        }
        if (count($collected) > 1) {
            error_log("ExchangeRates: providers disagree on the {$currency} rate; refusing to price from any of them");
        } elseif (count($collected) === 1 && $anchor === null && $currency !== 'usd') {
            // Only one provider answered (the other is down, rate-limited or blocked in
            // this country) and the anchor is too old to compare with. Price the way a
            // currency only one provider quotes is priced: confirmed BTC/USD times that
            // provider's own FX ratio, held to the FX anchor. USD itself cannot go this
            // way, it is the base of that path: USD with one provider answering prices
            // only near a fresh anchor, otherwise it waits for a second provider.
            [$rate, $name] = $collected[0];
            error_log("ExchangeRates: only {$name} answered for {$currency}; pricing it through BTC/USD and {$name}'s exchange rate");
            return self::singleSourcePrice($currency, $providers[$name], $primary, $secondary, $rate,
                self::lastConfirmedRate($currency));
        } elseif (count($collected) === 1) {
            error_log("ExchangeRates: only {$collected[0][1]} answered for {$currency} and nothing confirms it; "
                . 'refusing to price from a single unconfirmed rate');
        }

        // Fall back to stale cache as last resort
        return self::getCached($currency, true);
    }

    /** Two providers within this fraction of each other agree (and set the anchor). */
    private const AGREEMENT_BAND = 0.02;
    /** One provider alone is trusted within this fraction of the confirmed anchor. */
    private const ANCHOR_BAND = 0.05;
    /** How old the confirmed anchor may be and still let a single provider through. */
    private const REFERENCE_TTL = 86400;

    private static function withinBand(float $rate, float $reference, float $band): bool {
        return abs($rate - $reference) <= $band * $reference;
    }

    /** The last rate two providers agreed on, while it is recent enough to compare with. */
    private static function confirmedAnchor(string $currency): ?float {
        $data = Config::get('rate_anchor_' . $currency);
        if (!is_array($data) || !isset($data['rate']) || (float)$data['rate'] <= 0
            || time() - (int)($data['timestamp'] ?? 0) > self::REFERENCE_TTL) {
            return null;
        }
        return (float)$data['rate'];
    }

    /** The last rate two providers agreed on, however old (null if they never did). */
    private static function lastConfirmedRate(string $currency): ?float {
        $data = Config::get('rate_anchor_' . $currency);
        if (!is_array($data) || !isset($data['rate']) || (float)$data['rate'] <= 0) {
            return null;
        }
        return (float)$data['rate'];
    }

    /** A single source's own BTC/USD must be this close to the confirmed one. */
    private const FX_USD_BAND = 0.02;
    /** A single source's FX rate is accepted within this fraction of the FX anchor. */
    private const FX_ANCHOR_BAND = 0.03;
    /** The FX anchor moves toward observed rates by at most this fraction per FX_DRIFT_PERIOD. */
    private const FX_DRIFT = 0.02;
    private const FX_DRIFT_PERIOD = 86400;
    /**
     * A first FX ratio for a currency two providers used to confirm must price it within
     * this fraction of their last agreed rate. Wide, because that rate may be days old and
     * bitcoin moves; it is there to catch a broken feed, not ordinary market moves.
     */
    private const FX_BOOTSTRAP_BAND = 0.25;

    /**
     * BTC price in a currency only one provider quotes.
     *
     * BTC/C = confirmed BTC/USD (the normal multi-provider path) x fx, with
     * fx = P(C) / P(USD) from that provider. The provider's own P(USD) must match the
     * confirmed BTC/USD within FX_USD_BAND, so its feed is coherent. fx is compared with
     * fx_anchor_<cur>: the first fx bootstraps it (same trust as a fresh install had
     * before C2), later ones are accepted only within FX_ANCHOR_BAND. Every observation
     * pulls the anchor toward it by at most FX_DRIFT per FX_DRIFT_PERIOD (time-scaled,
     * so frequent refreshes cannot add up to more), refused ones included: a genuine FX
     * jump beyond the band heals in a day or two instead of locking the shop out, while a
     * hostile or broken provider still moves prices by at most ~2 % a day.
     *
     * getBtcPrice() also lands here for a currency several providers quote when only one
     * of them answered and no fresh anchor exists. It then passes the rate it already got
     * ($own) and the last rate two providers agreed on ($lastConfirmed, however old).
     * Such a currency usually has no FX anchor yet, and the first FX ratio would start
     * one unchecked, so it must first land within FX_BOOTSTRAP_BAND of that last agreed
     * rate: a decimal slip or wrong field cannot become the anchor. Never called for USD,
     * so the getBtcPrice('usd') below cannot come back here.
     */
    private static function singleSourcePrice(string $currency, PriceProvider $provider, ?string $primary, ?string $secondary,
        ?float $own = null, ?float $lastConfirmed = null): ?float {
        $name = $provider->getName();
        $usd = self::getBtcPrice('usd', $primary, $secondary);
        if ($usd === null || $usd <= 0) {
            error_log("ExchangeRates: no confirmed BTC/USD rate to price {$currency} from {$name}");
            return self::getCached($currency, true);
        }
        try {
            $ownUsd = $provider->getBtcPrice('usd');
            $own ??= $provider->getBtcPrice($currency);
        } catch (Exception $e) {
            error_log("ExchangeRates: {$name} failed for {$currency}: " . $e->getMessage());
            return self::getCached($currency, true);
        }
        foreach ([$ownUsd, $own] as $r) {
            if ($r === null || !is_finite($r) || $r <= 0) {
                return self::getCached($currency, true);
            }
        }
        if (!self::withinBand($ownUsd, $usd, self::FX_USD_BAND)) {
            error_log("ExchangeRates: {$name} BTC/USD {$ownUsd} is more than " . (int)(self::FX_USD_BAND * 100)
                . "% away from the confirmed {$usd}; refusing to price {$currency} from it");
            return self::getCached($currency, true);
        }
        $fx = $own / $ownUsd;

        $key = 'fx_anchor_' . $currency;
        $anchor = Config::get($key);
        if (!is_array($anchor) || !isset($anchor['rate']) || (float)$anchor['rate'] <= 0) {
            if ($lastConfirmed !== null && !self::withinBand($usd * $fx, $lastConfirmed, self::FX_BOOTSTRAP_BAND)) {
                error_log("ExchangeRates: {$name} prices {$currency} at " . ($usd * $fx) . ", more than "
                    . (int)(self::FX_BOOTSTRAP_BAND * 100) . "% away from the last rate two providers agreed on "
                    . "({$lastConfirmed}); refusing to price {$currency} until a second provider answers again");
                return self::getCached($currency, true);
            }
            Config::set($key, ['rate' => $fx, 'timestamp' => time(), 'provider' => $name]);
            error_log("ExchangeRates: no {$currency} FX anchor yet; starting it at {$fx} {$currency} per USD from {$name}");
        } else {
            $anchorRate = (float)$anchor['rate'];
            $elapsed = max(0, time() - (int)($anchor['timestamp'] ?? 0));
            $maxMove = $anchorRate * self::FX_DRIFT * min(1.0, $elapsed / self::FX_DRIFT_PERIOD);
            $moved = $anchorRate + max(-$maxMove, min($maxMove, $fx - $anchorRate));
            if ($moved != $anchorRate) {
                Config::set($key, ['rate' => $moved, 'timestamp' => time(), 'provider' => $name]);
            }
            if (!self::withinBand($fx, $anchorRate, self::FX_ANCHOR_BAND)) {
                error_log("ExchangeRates: {$name} {$currency}/USD rate {$fx} is more than "
                    . (int)(self::FX_ANCHOR_BAND * 100) . "% away from the anchored {$anchorRate}; refusing to price "
                    . "{$currency} (the anchor drifts toward it by at most " . (int)(self::FX_DRIFT * 100) . "% a day)");
                return self::getCached($currency, true);
            }
        }

        $rate = $usd * $fx;
        self::saveToCache($currency, $rate, $name);
        return $rate;
    }

    /** What the shop and the API show when no price can be had. */
    public const UNAVAILABLE_MESSAGE = 'We could not confirm the Bitcoin price from two sources, so fiat prices are paused. It resumes by itself when another price source answers.';

    private static function rateUnavailable(string $currency): Exception {
        error_log("ExchangeRates: no usable exchange rate for {$currency}");
        return new Exception(self::UNAVAILABLE_MESSAGE);
    }

    /**
     * Convert amount to mint unit (bidirectional conversion)
     *
     * @param string $amount Amount in request currency
     * @param string $requestCurrency Currency of the request (EUR, USD, SAT, etc.)
     * @param string $mintUnit Mint's native unit (sat, eur, usd, etc.)
     * @param float $exchangeFeePercent Fee percentage to apply (positive = user pays more)
     * @param string|null $primaryProvider Primary price provider
     * @param string|null $secondaryProvider Secondary price provider
     * @return int Amount in mint's smallest unit (sats for sat mint, cents for fiat mint)
     */
    public static function convertToMintUnit(
        string $amount,
        string $requestCurrency,
        string $mintUnit,
        float $exchangeFeePercent = 0,
        ?string $primaryProvider = null,
        ?string $secondaryProvider = null
    ): int {
        $requestCurrency = strtoupper($requestCurrency);
        $mintUnit = strtoupper($mintUnit);

        // Normalize sat/sats
        if ($requestCurrency === 'SATS') $requestCurrency = 'SAT';
        if ($mintUnit === 'SATS') $mintUnit = 'SAT';

        // Same unit - no conversion needed
        if ($requestCurrency === $mintUnit) {
            return self::toSmallestUnit($amount, $mintUnit);
        }

        // Convert through BTC as intermediate
        // Step 1: Convert request amount to BTC
        $btcAmount = self::toBtc($amount, $requestCurrency, $primaryProvider, $secondaryProvider);

        // Step 2: Convert BTC to mint unit
        $mintAmount = self::fromBtc($btcAmount, $mintUnit, $primaryProvider, $secondaryProvider);

        // Apply exchange fee (positive = user pays more). Both directions share the same
        // 0–100 % bound, so a payout cannot deduct a different fee than an invoice adds.
        $exchangeFeePercent = max(0.0, min(100.0, $exchangeFeePercent));
        if ($exchangeFeePercent != 0) {
            $mintAmount = bcmul($mintAmount, (string)(1 + $exchangeFeePercent / 100), self::SCALE);
        }

        // Round UP to the mint's smallest unit for invoice pricing, so rounding never
        // makes the merchant undercharge (customer pays at most 1 sat/cent more).
        // See FABLE-CASHUPAYSERVER-AUDIT (C-RATE-1).
        return self::toSmallestUnit($mintAmount, $mintUnit, true);
    }

    /**
     * Convert amount to BTC
     */
    private static function toBtc(string $amount, string $currency, ?string $primary = null, ?string $secondary = null): string {
        $currency = strtoupper($currency);

        if ($currency === 'BTC') {
            return $amount;
        }

        if ($currency === 'SAT' || $currency === 'SATS') {
            return bcdiv($amount, '100000000', self::SCALE);
        }

        if ($currency === 'MSAT') {
            return bcdiv($amount, '100000000000', self::SCALE);
        }

        // Fiat currency - divide by BTC price
        $btcPrice = self::getBtcPrice($currency, $primary, $secondary);
        if ($btcPrice === null || $btcPrice <= 0) {
            throw self::rateUnavailable($currency);
        }

        return bcdiv($amount, (string)$btcPrice, self::SCALE);
    }

    /**
     * Convert BTC to target currency
     */
    private static function fromBtc(string $btcAmount, string $currency, ?string $primary = null, ?string $secondary = null): string {
        $currency = strtoupper($currency);

        if ($currency === 'BTC') {
            return $btcAmount;
        }

        if ($currency === 'SAT' || $currency === 'SATS') {
            return bcmul($btcAmount, '100000000', self::SCALE);
        }

        if ($currency === 'MSAT') {
            return bcmul($btcAmount, '100000000000', self::SCALE);
        }

        // Fiat currency - multiply by BTC price
        $btcPrice = self::getBtcPrice($currency, $primary, $secondary);
        if ($btcPrice === null || $btcPrice <= 0) {
            throw self::rateUnavailable($currency);
        }

        return bcmul($btcAmount, (string)$btcPrice, self::SCALE);
    }

    /**
     * Convert amount to smallest unit (sats for BTC, cents for fiat)
     */
    private static function toSmallestUnit(string $amount, string $currency, bool $roundUp = false): int {
        $currency = strtoupper($currency);

        if ($currency === 'SAT' || $currency === 'SATS') {
            return self::toInt($amount, $roundUp);
        }

        if ($currency === 'MSAT') {
            // msat IS the smallest unit of an msat mint. Dividing by 1000 here quoted
            // 1000 msat as 1 msat and made every msat-denominated amount 1000x too small.
            return self::toInt($amount, $roundUp);
        }

        if ($currency === 'BTC') {
            return self::toInt(bcmul($amount, '100000000', self::SCALE), $roundUp);
        }

        // Fiat currencies - convert to cents (multiply by 100)
        return self::toInt(bcmul($amount, '100', self::SCALE), $roundUp);
    }

    /**
     * Convert a decimal string to an integer, truncating or rounding up.
     *
     * Everything stays in BCMath: casting through float lost precision on large amounts
     * and `ceil((float)$s)` disagreed with the exact value near a boundary.
     */
    private static function toInt(string $amount, bool $roundUp): int {
        $truncated = bcadd($amount, '0', 0);
        if ($roundUp && bccomp($amount, $truncated, self::SCALE) > 0) {
            $truncated = bcadd($truncated, '1', 0);
        }
        return (int)$truncated;
    }

    /**
     * Legacy method: Convert amount to satoshis
     *
     * @deprecated Use convertToMintUnit() instead for proper bidirectional conversion
     */
    public static function convertToSats(string $amount, string $currency, string $mintUnit): int {
        $currency = strtoupper($currency);

        // Already in sats
        if (in_array($currency, ['SAT', 'SATS'])) {
            return (int)$amount;
        }

        // BTC to sats
        if ($currency === 'BTC') {
            return (int)bcmul($amount, '100000000', 0);
        }

        // msat to sats (round up)
        if ($currency === 'MSAT') {
            return (int)ceil((float)$amount / 1000);
        }

        // If mint uses the same fiat unit, convert to cents
        if (strtolower($mintUnit) === strtolower($currency)) {
            // For fiat mints: convert to smallest unit (cents)
            return (int)bcmul($amount, '100', 0);
        }

        // Convert fiat to sats
        $btcPrice = self::getBtcPrice($currency);
        if ($btcPrice === null) {
            throw self::rateUnavailable($currency);
        }

        $btcAmount = bcdiv($amount, (string)$btcPrice, 8);
        $sats = bcmul($btcAmount, '100000000', 0);

        return (int)$sats;
    }

    /**
     * Convert satoshis to fiat for display
     */
    public static function satsToFiat(int $sats, string $currency): ?string {
        $currency = strtoupper($currency);

        if (in_array($currency, ['SAT', 'SATS', 'BTC'])) {
            return null;
        }

        $btcPrice = self::getBtcPrice($currency);
        if ($btcPrice === null) {
            return null;
        }

        $btcAmount = bcdiv((string)$sats, '100000000', 8);
        $fiatAmount = bcmul($btcAmount, (string)$btcPrice, 2);

        return $fiatAmount;
    }

    /**
     * Get cached rate
     */
    private static function getCached(string $currency, bool $allowStale = false): ?float {
        $key = 'rate_' . $currency;
        $data = Config::get($key);

        if ($data === null) {
            return null;
        }

        $maxAge = $allowStale ? self::STALE_TTL : self::CACHE_TTL;

        if (time() - ($data['timestamp'] ?? 0) > $maxAge) {
            return null;
        }

        return $data['rate'] ?? null;
    }

    /**
     * Save rate to cache
     */
    private static function saveToCache(string $currency, float $rate, string $provider = 'unknown'): void {
        $key = 'rate_' . $currency;
        Config::set($key, [
            'rate' => $rate,
            'timestamp' => time(),
            'provider' => $provider,
        ]);
    }

    /**
     * Convert amount in mint's smallest unit to satoshis
     *
     * @param int $amountInSmallestUnit Amount in smallest unit (sats for sat mint, cents for fiat)
     * @param string $mintUnit The mint's unit (sat, eur, usd, etc.)
     * @param string|null $primaryProvider Primary price provider
     * @param string|null $secondaryProvider Secondary price provider
     * @param float $exchangeFeePercent The operator's conversion fee, applied in the
     *        same direction as on an invoice: the amount the shop receives is net of it.
     * @return int Amount in satoshis
     */
    public static function convertMintUnitToSats(
        int $amountInSmallestUnit,
        string $mintUnit,
        ?string $primaryProvider = null,
        ?string $secondaryProvider = null,
        float $exchangeFeePercent = 0.0
    ): int {
        $mintUnit = strtoupper($mintUnit);

        // Already in sats (a btc mint's smallest unit is the satoshi)
        if ($mintUnit === 'SAT' || $mintUnit === 'SATS' || $mintUnit === 'BTC') {
            return $amountInSmallestUnit;
        }

        // msat to sats
        if ($mintUnit === 'MSAT') {
            return (int)ceil($amountInSmallestUnit / 1000);
        }

        // Fiat: convert from cents to sats
        // Step 1: Convert cents to fiat amount (divide by 100)
        $fiatAmount = bcdiv((string)$amountInSmallestUnit, '100', 8);

        // Step 2: Convert fiat to BTC
        $btcAmount = self::toBtc($fiatAmount, $mintUnit, $primaryProvider, $secondaryProvider);

        // Step 3: Convert BTC to sats. The fee is taken out of the payout, the mirror of
        // the invoice path where it is added to what the customer pays.
        $sats = bcmul($btcAmount, '100000000', 8);
        $feePercent = max(0.0, min(100.0, $exchangeFeePercent));
        if ($feePercent > 0) {
            $sats = bcmul($sats, (string)(1 - $feePercent / 100), 8);
        }
        return (int)bcadd($sats, '0', 0);
    }

    /**
     * Convert satoshis to mint's smallest unit
     *
     * @param int $sats Amount in satoshis
     * @param string $mintUnit The mint's unit (sat, eur, usd, etc.)
     * @param string|null $primaryProvider Primary price provider
     * @param string|null $secondaryProvider Secondary price provider
     * @return int Amount in mint's smallest unit (sats for sat mint, cents for fiat)
     */
    public static function convertSatsToMintUnit(
        int $sats,
        string $mintUnit,
        ?string $primaryProvider = null,
        ?string $secondaryProvider = null
    ): int {
        $mintUnit = strtoupper($mintUnit);

        // Already in sats (a btc mint's smallest unit is the satoshi)
        if ($mintUnit === 'SAT' || $mintUnit === 'SATS' || $mintUnit === 'BTC') {
            return $sats;
        }

        // sats to msat
        if ($mintUnit === 'MSAT') {
            return $sats * 1000;
        }

        // Sats to fiat: convert to cents
        // Step 1: Convert sats to BTC
        $btcAmount = bcdiv((string)$sats, '100000000', 8);

        // Step 2: Convert BTC to fiat
        $fiatAmount = self::fromBtc($btcAmount, $mintUnit, $primaryProvider, $secondaryProvider);

        // Step 3: Convert fiat to cents (multiply by 100)
        return (int)bcmul($fiatAmount, '100', 0);
    }

    /**
     * Check if rates are stale (for warnings)
     */
    public static function isStale(string $currency): bool {
        $key = 'rate_' . strtolower($currency);
        $data = Config::get($key);

        if ($data === null) {
            return true;
        }

        return time() - ($data['timestamp'] ?? 0) > self::STALE_TTL;
    }

    /**
     * Get rate age in seconds
     */
    public static function getRateAge(string $currency): ?int {
        $key = 'rate_' . strtolower($currency);
        $data = Config::get($key);

        if ($data === null || !isset($data['timestamp'])) {
            return null;
        }

        return time() - $data['timestamp'];
    }

    /**
     * Get the provider that supplied the cached rate
     */
    public static function getCachedProvider(string $currency): ?string {
        $key = 'rate_' . strtolower($currency);
        $data = Config::get($key);
        return $data['provider'] ?? null;
    }

    /**
     * Get available provider names
     */
    public static function getAvailableProviders(): array {
        return array_keys(self::getProviders());
    }
}
