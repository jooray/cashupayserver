<?php
/**
 * CashuPayServer - Exchange Rate Module
 *
 * Fetches and caches exchange rates from multiple providers with fallback.
 * Supports bidirectional currency conversion between any currencies.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safe_http.php';
require_once __DIR__ . '/endpoint_health.php';

/**
 * Price provider interface.
 *
 * getBtcPrice() returns null when the provider cannot quote this CURRENCY
 * (not in its pair map, or absent from an otherwise-valid response) — that is
 * a per-currency miss, not a provider outage. Transport failures and malformed
 * bodies THROW instead, so the caller can put the provider on a short failure
 * cooldown without penalizing it for currencies it simply doesn't list.
 */
interface PriceProvider {
    public function getBtcPrice(string $currency): ?float;
    public function getName(): string;
}

/**
 * CoinGecko price provider
 */
class CoinGeckoProvider implements PriceProvider {
    private const API_URL = 'https://api.coingecko.com/api/v3/simple/price';

    public function getName(): string {
        return 'coingecko';
    }

    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);

        $url = self::API_URL . '?' . http_build_query([
            'ids' => 'bitcoin',
            'vs_currencies' => $currency,
        ]);

        $result = \SafeHttp::request($url, [
            'maxBytes' => 256 * 1024,
            'timeout' => 10,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers' => ['Accept: application/json'],
            'allowPrivate' => false,
        ]);

        if ($result['error'] !== '' || $result['status'] !== 200 || $result['body'] === '') {
            throw new RuntimeException("HTTP {$result['status']}: " . ($result['error'] ?: substr($result['body'], 0, 120)));
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('malformed JSON response');
        }
        // Missing key on a valid response = currency not listed, not an outage.
        $rate = $data['bitcoin'][$currency] ?? null;
        return is_numeric($rate) ? (float)$rate : null;
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

    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);

        $symbol = self::CURRENCY_MAP[$currency] ?? null;
        if ($symbol === null) {
            return null; // Currency not supported by Binance
        }

        $url = self::API_URL . '?symbol=' . $symbol;

        $result = \SafeHttp::request($url, [
            'maxBytes' => 256 * 1024,
            'timeout' => 10,
            'headers' => ['Accept: application/json'],
            'allowPrivate' => false,
        ]);

        // The symbol comes from our own fixed map, so any HTTP failure here is
        // a provider problem, not an unknown-currency response.
        if ($result['error'] !== '' || $result['status'] !== 200 || $result['body'] === '') {
            throw new RuntimeException("HTTP {$result['status']}: " . ($result['error'] ?: substr($result['body'], 0, 120)));
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('malformed JSON response');
        }
        return isset($data['price']) && is_numeric($data['price']) ? (float)$data['price'] : null;
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

    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);

        $pair = self::PAIR_MAP[$currency] ?? null;
        if ($pair === null) {
            return null; // Currency not supported by Kraken
        }

        $url = self::API_URL . '?pair=' . $pair;

        $result = \SafeHttp::request($url, [
            'maxBytes' => 256 * 1024,
            'timeout' => 10,
            'headers' => ['Accept: application/json'],
            'allowPrivate' => false,
        ]);

        // The pair comes from our own fixed map, so any HTTP failure here is
        // a provider problem, not an unknown-currency response.
        if ($result['error'] !== '' || $result['status'] !== 200 || $result['body'] === '') {
            throw new RuntimeException("HTTP {$result['status']}: " . ($result['error'] ?: substr($result['body'], 0, 120)));
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('malformed JSON response');
        }

        if (!empty($data['error'])) {
            // Kraken's in-band errors (rate limits, service unavailable) are
            // provider-side failures — the pair itself is from our fixed map.
            throw new RuntimeException('Kraken error: ' . json_encode($data['error']));
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
    private const CACHE_TTL = 300; // 5 minutes
    private const STALE_TTL = 3600; // 1 hour (warn if older)

    private static array $providers = [];

    /**
     * Test seam: when set, getProviders() returns this map instead of the real
     * network-backed providers. Production code never assigns it (null); tests
     * use it to drive the fallback/cooldown logic with scripted providers.
     * Same pattern as OnchainProviderFactory::$testProvider.
     *
     * @var array<string, PriceProvider>|null
     */
    public static ?array $testProviders = null;

    /**
     * Get provider instances
     */
    private static function getProviders(): array {
        if (self::$testProviders !== null) {
            return self::$testProviders;
        }
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

        // Skip providers inside a failure cooldown so a hung/down provider's
        // 10s timeout is paid once per cooldown window, not on every checkout.
        $active = array_values(array_filter(
            $order,
            fn(PriceProvider $p) => !EndpointHealth::isCoolingDown('rates:' . $p->getName())
        ));
        if (empty($active)) {
            // Every provider is cooling down (full outage). Serve cached data
            // immediately rather than hammering dead providers on each request;
            // the cooldown expiry (~2 min) bounds how long we coast on it. Only
            // when there is no cached rate at all do we try them anyway — a
            // request that might succeed beats guaranteed unpriceability.
            $stale = self::getCached($currency, true);
            if ($stale !== null) {
                return $stale;
            }
            $lastGood = self::getLastGood($currency);
            if ($lastGood !== null) {
                return $lastGood;
            }
            $active = $order;
        }

        // Try each provider
        foreach ($active as $provider) {
            try {
                $rate = $provider->getBtcPrice($currency);
                if ($rate !== null && self::isSaneRate($rate)) {
                    EndpointHealth::recordSuccess('rates:' . $provider->getName());
                    self::saveToCache($currency, $rate, $provider->getName());
                    return $rate;
                }
                if ($rate !== null) {
                    // Provider returned a non-positive / non-finite price. This
                    // is observed during upstream outages (e.g. CoinGecko briefly
                    // serving 0). Caching it would price invoices at 0 sats or
                    // divide-by-zero downstream, so treat it as a failure and
                    // fall through to the next provider.
                    error_log("ExchangeRates: {$provider->getName()} returned insane rate for {$currency}: {$rate}");
                    EndpointHealth::recordFailure('rates:' . $provider->getName());
                }
                // $rate === null: currency not supported by this provider — a
                // per-currency miss, not an outage; no cooldown.
            } catch (\Throwable $e) {
                // Catch \Throwable (not just Exception) so a provider-side Error
                // — e.g. a TypeError from a malformed response — doesn't abort
                // the whole price lookup and 500 the payment. Providers throw on
                // transport failures / malformed bodies, so this is where a sick
                // provider earns its cooldown.
                error_log("ExchangeRates: {$provider->getName()} failed for {$currency}: " . $e->getMessage());
                EndpointHealth::recordFailure('rates:' . $provider->getName());
                continue;
            }
        }

        // Fall back to the stale cache (<= STALE_TTL) first.
        $stale = self::getCached($currency, true);
        if ($stale !== null) {
            return $stale;
        }

        // Last resort: the last known-good rate we ever cached, regardless of
        // age. Pricing off an old-but-real rate is far better than hard-failing
        // the invoice — a thrown exception here means the customer cannot pay at
        // all. Staleness remains visible to callers via isStale()/getRateAge().
        $lastGood = self::getLastGood($currency);
        if ($lastGood !== null) {
            error_log("ExchangeRates: all providers + fresh/stale cache unavailable for {$currency}; using last-good rate {$lastGood}");
            return $lastGood;
        }

        return null;
    }

    /**
     * A usable BTC price must be a positive, finite number. Anything else
     * (0, negative, NaN, INF — all observed from flaky upstreams) would corrupt
     * pricing (0-sat invoices, divide-by-zero), so we reject it and fall through
     * to the next provider / cache.
     */
    private static function isSaneRate(float $rate): bool {
        return is_finite($rate) && $rate > 0;
    }

    /**
     * The last sane rate we ever cached for this currency, ignoring age. Used
     * only as the final fallback in getBtcPrice() when live providers and the
     * TTL/stale cache are all unavailable.
     */
    private static function getLastGood(string $currency): ?float {
        $data = Config::get('rate_lastgood_' . strtolower($currency));
        if ($data === null || !isset($data['rate'])) {
            return null;
        }
        $rate = (float)$data['rate'];
        return self::isSaneRate($rate) ? $rate : null;
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

        // Apply exchange fee (positive = user pays more)
        if ($exchangeFeePercent != 0) {
            $mintAmount = bcmul($mintAmount, (string)(1 + $exchangeFeePercent / 100), 8);
        }

        return self::toSmallestUnit($mintAmount, $mintUnit);
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
            return bcdiv($amount, '100000000', 8);
        }

        if ($currency === 'MSAT') {
            return bcdiv($amount, '100000000000', 11);
        }

        // Fiat currency - divide by BTC price. Guard the divisor: getBtcPrice
        // only ever returns a sane (>0) rate or null, but a defensive check here
        // keeps bcdiv from ever dividing by zero.
        $btcPrice = self::getBtcPrice($currency, $primary, $secondary);
        if ($btcPrice === null || $btcPrice <= 0) {
            throw new Exception("Cannot get exchange rate for {$currency}");
        }

        return bcdiv($amount, (string)$btcPrice, 8);
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
            return bcmul($btcAmount, '100000000', 0);
        }

        if ($currency === 'MSAT') {
            return bcmul($btcAmount, '100000000000', 0);
        }

        // Fiat currency - multiply by BTC price.
        $btcPrice = self::getBtcPrice($currency, $primary, $secondary);
        if ($btcPrice === null || $btcPrice <= 0) {
            throw new Exception("Cannot get exchange rate for {$currency}");
        }

        return bcmul($btcAmount, (string)$btcPrice, 8);
    }

    /**
     * Convert amount to smallest unit (sats for BTC, cents for fiat)
     */
    private static function toSmallestUnit(string $amount, string $currency): int {
        $currency = strtoupper($currency);

        if ($currency === 'BTC') {
            return (int)bcmul($amount, '100000000', 0);
        }

        if ($currency === 'SAT' || $currency === 'SATS') {
            return (int)$amount;
        }

        if ($currency === 'MSAT') {
            return (int)ceil((float)$amount / 1000);
        }

        // Fiat currencies - convert to cents (multiply by 100)
        return (int)bcmul($amount, '100', 0);
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
        if ($btcPrice === null || $btcPrice <= 0) {
            throw new Exception("Cannot get exchange rate for {$currency}");
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
        if ($btcPrice === null || $btcPrice <= 0) {
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
        $payload = [
            'rate' => $rate,
            'timestamp' => time(),
            'provider' => $provider,
        ];
        Config::set('rate_' . $currency, $payload);
        // Persist a never-expiring last-known-good copy for getBtcPrice()'s
        // graceful fallback when every provider and the TTL/stale cache fail.
        // Only sane rates reach here, so last-good is always usable.
        Config::set('rate_lastgood_' . $currency, $payload);
    }

    /**
     * Convert amount in mint's smallest unit to satoshis
     *
     * @param int $amountInSmallestUnit Amount in smallest unit (sats for sat mint, cents for fiat)
     * @param string $mintUnit The mint's unit (sat, eur, usd, etc.)
     * @param string|null $primaryProvider Primary price provider
     * @param string|null $secondaryProvider Secondary price provider
     * @return int Amount in satoshis
     */
    public static function convertMintUnitToSats(
        int $amountInSmallestUnit,
        string $mintUnit,
        ?string $primaryProvider = null,
        ?string $secondaryProvider = null
    ): int {
        $mintUnit = strtoupper($mintUnit);

        // Already in sats
        if ($mintUnit === 'SAT' || $mintUnit === 'SATS') {
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

        // Step 3: Convert BTC to sats
        return (int)bcmul($btcAmount, '100000000', 0);
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

        // Already in sats
        if ($mintUnit === 'SAT' || $mintUnit === 'SATS') {
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
