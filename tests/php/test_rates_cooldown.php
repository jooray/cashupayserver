<?php
/**
 * ExchangeRates provider cooldown + fallback behavior (network-free, via the
 * ExchangeRates::$testProviders seam):
 *   - a throwing provider (transport failure / malformed body) earns an
 *     EndpointHealth cooldown and the next provider is used;
 *   - while cooling down, the failed provider is not called at all;
 *   - a null return (currency unsupported) does NOT earn a cooldown;
 *   - an insane (non-positive) rate earns a cooldown and falls through;
 *   - when EVERY provider is cooling down, cached data (stale, then last-good)
 *     is served immediately without touching the network — this is what keeps
 *     checkout fast during a full rates outage;
 *   - but with no cached data at all, cooled-down providers are still tried
 *     (pricing an invoice off a possible success beats guaranteed failure).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';

final class StubPriceProvider implements PriceProvider {
    public int $calls = 0;
    /** @var callable(string):?float */
    private $behavior;
    public function __construct(private string $name, callable $behavior) {
        $this->behavior = $behavior;
    }
    public function getName(): string { return $this->name; }
    public function getBtcPrice(string $currency): ?float {
        $this->calls++;
        return ($this->behavior)($currency);
    }
    public function set(callable $behavior): void { $this->behavior = $behavior; }
}

$alpha = new StubPriceProvider('alpha', fn() => throw new RuntimeException('stub transport failure'));
$beta  = new StubPriceProvider('beta', fn() => 50000.0);
ExchangeRates::$testProviders = ['alpha' => $alpha, 'beta' => $beta];

// --- 1. Throwing primary: fallback used, primary earns a cooldown -----------
assert_eq(50000.0, ExchangeRates::getBtcPrice('usd', 'alpha', 'beta'), 'fallback provider prices the currency');
assert_eq(1, $alpha->calls, 'primary was attempted once');
assert_true(EndpointHealth::isCoolingDown('rates:alpha'), 'throwing provider is cooling down');
assert_false(EndpointHealth::isCoolingDown('rates:beta'), 'healthy provider is not cooling down');

// --- 2. While cooling down, the failed provider is skipped ------------------
// Different currency so the fresh rate cache doesn't short-circuit.
assert_eq(50000.0, ExchangeRates::getBtcPrice('eur', 'alpha', 'beta'), 'fallback keeps pricing');
assert_eq(1, $alpha->calls, 'cooling-down provider is not called');

// --- 3. Unsupported currency (null) earns NO cooldown ------------------------
EndpointHealth::recordSuccess('rates:alpha');
$alpha->set(fn() => null); // "currency not in my pair map"
assert_eq(50000.0, ExchangeRates::getBtcPrice('gbp', 'alpha', 'beta'), 'null falls through to next provider');
assert_eq(2, $alpha->calls, 'provider was consulted');
assert_false(EndpointHealth::isCoolingDown('rates:alpha'), 'unsupported currency is not an outage');

// --- 4. Insane rate (0) earns a cooldown and falls through -------------------
$alpha->set(fn() => 0.0);
assert_eq(50000.0, ExchangeRates::getBtcPrice('chf', 'alpha', 'beta'), 'insane rate falls through');
assert_true(EndpointHealth::isCoolingDown('rates:alpha'), 'insane rate earns a cooldown');

// --- 5. All providers cooling + stale cache: served without network ----------
EndpointHealth::recordFailure('rates:beta');
// Seed a stale-but-usable cached rate (older than CACHE_TTL=300, within STALE_TTL=3600).
Config::set('rate_jpy', ['rate' => 9000000.0, 'timestamp' => time() - 600, 'provider' => 'test']);
$alphaCalls = $alpha->calls; $betaCalls = $beta->calls;
assert_eq(9000000.0, ExchangeRates::getBtcPrice('jpy', 'alpha', 'beta'), 'stale cache served during full outage');
assert_eq($alphaCalls, $alpha->calls, 'no provider called for stale-cache hit (alpha)');
assert_eq($betaCalls, $beta->calls, 'no provider called for stale-cache hit (beta)');

// --- 6. All cooling + only a last-good record: that is served ---------------
Config::set('rate_lastgood_aud', ['rate' => 65000.0, 'timestamp' => time() - 90000, 'provider' => 'test']);
assert_eq(65000.0, ExchangeRates::getBtcPrice('aud', 'alpha', 'beta'), 'last-good served during full outage');
assert_eq($alphaCalls, $alpha->calls, 'no provider called for last-good hit');

// --- 7. All cooling + NO cached data: providers are tried anyway ------------
$alpha->set(fn() => 42000.0);
assert_eq(42000.0, ExchangeRates::getBtcPrice('cad', 'alpha', 'beta'), 'uncached currency still tries cooled providers');
assert_eq($alphaCalls + 1, $alpha->calls, 'cooled provider was tried as last resort');
assert_false(EndpointHealth::isCoolingDown('rates:alpha'), 'its success cleared the cooldown');

ExchangeRates::$testProviders = null;
fwrite(STDERR, "test_rates_cooldown: all assertions passed\n");
