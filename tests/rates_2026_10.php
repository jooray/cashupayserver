<?php
/**
 * C2 (2026-10 audit): one price provider must not be able to walk the invoicing rate
 * away from a rate two providers confirmed. The band is measured against a separate
 * anchor (rate_anchor_<cur>), written only when two providers agree, never against the
 * possibly single-source cache.
 *
 * Run: php tests/rates_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-rates-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/rates.php';

Database::initialize();

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

final class QueueProvider implements PriceProvider {
    public int $calls = 0;
    /** @var callable|null */
    public $next;
    public function __construct(private string $name, ?callable $next) { $this->next = $next; }
    public function getName(): string { return $this->name; }
    public function getBtcPrice(string $currency): ?float {
        $this->calls++;
        return $this->next === null ? null : ($this->next)();
    }
    public function supports(string $currency): bool { return true; }
}
function useProviders(PriceProvider ...$stubs): void {
    $prop = new ReflectionProperty(ExchangeRates::class, 'providers');
    $map = [];
    foreach ($stubs as $s) { $map[$s->getName()] = $s; }
    $prop->setValue(null, $map);
}
/** Let the 5-minute cache expire so the next conversion asks the providers again. */
function ageCache(): void {
    $data = Config::get('rate_eur');
    $data['timestamp'] = time() - 301;
    Config::set('rate_eur', $data);
}

$fair = 60000.0;

echo "A hostile primary ratchets x1.45 against honest secondaries\n";
Config::set('rate_eur', ['rate' => $fair, 'timestamp' => time() - 301, 'provider' => 'coingecko']);
Config::set('rate_anchor_eur', ['rate' => $fair, 'timestamp' => time() - 301]);
$last = $fair;
$hostile = new QueueProvider('coingecko', function () use (&$last) { return $last * 1.45; });
$binance = new QueueProvider('binance', fn() => 60000.0);
$kraken = new QueueProvider('kraken', fn() => 60000.0);
useProviders($hostile, $binance, $kraken);
$fairInvoice = ExchangeRates::convertToMintUnit('100', 'EUR', 'SAT', 0, 'coingecko', 'binance');
for ($i = 0; $i < 10; $i++) {
    ageCache();
    $rate = ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance');
    check($rate !== null && abs($rate - $fair) <= 0.05 * $fair, "step $i: the rate stays near the confirmed anchor ($rate)");
    $last = $rate;
}
check($binance->calls >= 10, 'the honest secondary was consulted on every refresh');
ageCache();
$sats = ExchangeRates::convertToMintUnit('100', 'EUR', 'SAT', 0, 'coingecko', 'binance');
check($sats >= (int)floor($fairInvoice * 0.95), "a 100 EUR invoice is still priced fairly ($sats sat, fair $fairInvoice)");
check(abs((float)Config::get('rate_anchor_eur')['rate'] - $fair) <= 0.02 * $fair, 'the anchor never moved off the honest rate');

echo "A hostile primary creeping down within the band cannot move the anchor\n";
Config::set('rate_eur', ['rate' => $fair, 'timestamp' => time() - 301, 'provider' => 'coingecko']);
Config::set('rate_anchor_eur', ['rate' => $fair, 'timestamp' => time() - 301]);
$last = $fair;
$creep = new QueueProvider('coingecko', function () use (&$last) { return $last * 0.97; });
useProviders($creep, new QueueProvider('binance', fn() => 60000.0), new QueueProvider('kraken', fn() => 60000.0));
for ($i = 0; $i < 10; $i++) {
    ageCache();
    $rate = ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance');
    check($rate !== null && $rate >= 0.95 * $fair - 0.01, "step $i: never more than 5% below the anchor ($rate)");
    $last = $rate;
}
check((float)Config::get('rate_anchor_eur')['rate'] === $fair, 'single-provider answers never move the anchor');

echo "A real market move is followed once two providers agree\n";
Config::set('rate_eur', ['rate' => $fair, 'timestamp' => time() - 301, 'provider' => 'coingecko']);
Config::set('rate_anchor_eur', ['rate' => $fair, 'timestamp' => time() - 301]);
useProviders(new QueueProvider('coingecko', fn() => 72000.0), new QueueProvider('binance', fn() => 72500.0),
    new QueueProvider('kraken', fn() => 72100.0));
check(ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance') === 72000.0, 'two agreeing providers set the rate');
check((float)Config::get('rate_anchor_eur')['rate'] === 72000.0, 'and the anchor follows them');

echo "With no anchor, one answer alone never prices invoices\n";
Config::set('rate_eur', null);
Config::set('rate_anchor_eur', null);
useProviders(new QueueProvider('coingecko', fn() => 60000.0), new QueueProvider('binance', null),
    new QueueProvider('kraken', null));
check(ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance') === null, 'a lone unconfirmed rate is refused');
$msg = null;
try { ExchangeRates::convertToMintUnit('10', 'EUR', 'SAT', 0, 'coingecko', 'binance'); } catch (Exception $e) { $msg = $e->getMessage(); }
check($msg === ExchangeRates::UNAVAILABLE_MESSAGE, 'the shop gets the plain "prices are unavailable" message');

echo "An old cached rate is not an anchor\n";
Config::set('rate_eur', ['rate' => $fair, 'timestamp' => time() - 301, 'provider' => 'coingecko']);
Config::set('rate_anchor_eur', null);
useProviders(new QueueProvider('coingecko', fn() => 61000.0), new QueueProvider('binance', null),
    new QueueProvider('kraken', null));
check(ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance') === $fair,
    'without a confirmed anchor a lone answer is not taken; the recent cache is served instead');

// --- Currencies only one provider quotes (CZK, PLN, HUF, ...) ------------------
// Binance and Kraken do not quote CZK, so two providers can never agree on it. CZK is
// priced as the confirmed BTC/USD times CoinGecko's own CZK/USD ratio, and that ratio is
// held to an anchor that moves at most 2 % a day.

/** A provider stub that quotes only the currencies it is given. */
final class MarketProvider implements PriceProvider {
    public array $asked = [];
    /** @param array<string, callable|null> $quotes currency => fn(): ?float */
    public function __construct(private string $name, public array $quotes) {}
    public function getName(): string { return $this->name; }
    public function supports(string $currency): bool { return array_key_exists(strtolower($currency), $this->quotes); }
    public function getBtcPrice(string $currency): ?float {
        $currency = strtolower($currency);
        $this->asked[] = $currency;
        $q = $this->quotes[$currency] ?? null;
        return $q === null ? null : $q();
    }
}
/** Pretend $seconds passed: every cached rate and anchor is that much older. */
function passTime(int $seconds): void {
    foreach (['rate_usd', 'rate_czk', 'rate_eur', 'rate_cad', 'rate_anchor_usd', 'rate_anchor_cad', 'fx_anchor_czk', 'fx_anchor_cad'] as $key) {
        $data = Config::get($key);
        if (is_array($data) && isset($data['timestamp'])) {
            $data['timestamp'] -= $seconds;
            Config::set($key, $data);
        }
    }
}
function resetCzk(): void {
    foreach (['rate_usd', 'rate_czk', 'rate_anchor_usd', 'fx_anchor_czk'] as $key) { Config::set($key, null); }
}

$usd = 60000.0;
$fx = 23.0;                // CZK per USD
$fairCzk = $usd * $fx;     // CZK per BTC

echo "CZK with only CoinGecko quoting it prices fine on a fresh install\n";
resetCzk();
$czk = $fairCzk;
$cg = new MarketProvider('coingecko', ['usd' => fn() => $usd, 'czk' => function () use (&$czk) { return $czk; }]);
useProviders($cg, new MarketProvider('binance', ['usd' => fn() => $usd, 'eur' => fn() => 55000.0]),
    new MarketProvider('kraken', ['usd' => fn() => $usd, 'eur' => fn() => 55000.0]));
$rate = ExchangeRates::getBtcPrice('CZK', 'coingecko', 'binance');
check($rate !== null && abs($rate - $fairCzk) < 0.01, "a fresh install prices CZK ($rate)");
check(abs((float)Config::get('fx_anchor_czk')['rate'] - $fx) < 1e-9, 'the first CZK/USD ratio starts the anchor');
check(ExchangeRates::convertToMintUnit('1380', 'CZK', 'SAT', 0, 'coingecko', 'binance') === 100000,
    '1380 CZK is 100000 sat at the fair rate');

echo "Normal CZK/USD moves keep pricing\n";
$prevAnchor = $fx;
foreach ([23.1, 23.25, 23.4, 23.3, 23.5] as $i => $step) {
    passTime(3600);
    $czk = $usd * $step;
    $rate = ExchangeRates::getBtcPrice('CZK', 'coingecko', 'binance');
    check($rate !== null && abs($rate - $usd * $step) < 0.01, "hour $i: CZK follows a normal FX move to $step ($rate)");
    $anchor = (float)Config::get('fx_anchor_czk')['rate'];
    check(abs($anchor - $prevAnchor) <= $prevAnchor * 0.02 / 24 + 1e-9, "hour $i: the anchor moved at most 2%/day ($anchor)");
    $prevAnchor = $anchor;
}

echo "A hostile CoinGecko ratchets CZK x1.45 per refresh while its USD stays honest\n";
resetCzk();
Config::set('fx_anchor_czk', ['rate' => $fx, 'timestamp' => time()]);
Config::set('rate_czk', ['rate' => $fairCzk, 'timestamp' => time(), 'provider' => 'coingecko']);
$last = $fairCzk;
$cg->quotes['czk'] = function () use (&$last) { return $last * 1.45; };
$days = 0.0; // simulated time since the attack began: ten hourly refreshes, then ten daily ones
foreach ([3600, 86400] as $step) {
    for ($i = 0; $i < 10; $i++) {
        passTime($step);
        $days += $step / 86400;
        // Keep a recent cached rate around so a refusal serves it, as in production.
        Config::set('rate_czk', ['rate' => $last, 'timestamp' => time() - 301, 'provider' => 'coingecko']);
        $rate = ExchangeRates::getBtcPrice('CZK', 'coingecko', 'binance');
        // 2 % a day, compounded per move, plus the 3 % acceptance band.
        $bound = $fairCzk * exp(0.02 * $days) * 1.03;
        check($rate !== null && $rate <= $bound, sprintf('after %.2f days the price is %.0f, within the 2%%/day bound %.0f', $days, $rate, $bound));
        $anchor = (float)Config::get('fx_anchor_czk')['rate'];
        check($anchor <= $fx * exp(0.02 * $days) + 1e-9, sprintf('and the anchor drifted only to %.4f', $anchor));
        $last = $rate;
    }
}
check($last <= $fairCzk * 1.25, "ten days of attack moved the price at most ~2%/day ($last vs $fairCzk)");

echo "CoinGecko's USD disagreeing with Binance and Kraken refuses CZK\n";
resetCzk();
Config::set('fx_anchor_czk', ['rate' => $fx, 'timestamp' => time()]);
$cg->quotes = ['usd' => fn() => $usd * 1.03, 'czk' => fn() => $usd * 1.03 * $fx];
check(ExchangeRates::getBtcPrice('CZK', 'coingecko', 'binance') === null, 'an incoherent CoinGecko feed prices no CZK');
$msg = null;
try { ExchangeRates::convertToMintUnit('100', 'CZK', 'SAT', 0, 'coingecko', 'binance'); } catch (Exception $e) { $msg = $e->getMessage(); }
check($msg === ExchangeRates::UNAVAILABLE_MESSAGE, 'the shop gets the plain "prices are unavailable" message');

echo "With no confirmed BTC/USD, CZK is refused\n";
resetCzk();
$cg->quotes = ['usd' => fn() => $usd, 'czk' => fn() => $fairCzk];
useProviders($cg, new MarketProvider('binance', ['usd' => null]), new MarketProvider('kraken', ['usd' => null]));
check(ExchangeRates::getBtcPrice('CZK', 'coingecko', 'binance') === null, 'CoinGecko alone cannot confirm BTC/USD');

echo "EUR, which several providers quote, is unchanged\n";
Config::set('rate_eur', null);
Config::set('rate_anchor_eur', null);
Config::set('rate_usd', null);
$cgEur = new MarketProvider('coingecko', ['usd' => fn() => $usd, 'eur' => fn() => 55000.0]);
useProviders($cgEur, new MarketProvider('binance', ['usd' => fn() => $usd, 'eur' => fn() => 55200.0]),
    new MarketProvider('kraken', ['usd' => fn() => $usd, 'eur' => fn() => 55100.0]));
check(ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance') === 55000.0, 'EUR comes from two agreeing providers');
check($cgEur->asked === ['eur'] && Config::get('fx_anchor_eur') === null && Config::get('rate_usd') === null,
    'and never goes through USD or an FX anchor');

// --- Currencies two providers quote, with one of them down (R2) -----------------
// CAD comes from CoinGecko and Kraken. With Kraken down and the CAD anchor older than a
// day, two can never agree, so CAD is priced like CZK: confirmed BTC/USD times
// CoinGecko's own CAD/USD ratio, held to an FX anchor that moves at most 2 % a day.

function resetCad(): void {
    foreach (['rate_usd', 'rate_cad', 'rate_eur', 'rate_anchor_usd', 'rate_anchor_cad', 'rate_anchor_eur',
        'fx_anchor_cad', 'fx_anchor_eur'] as $key) { Config::set($key, null); }
}

$fxCad = 1.37;             // CAD per USD
$fairCad = $usd * $fxCad;  // CAD per BTC
$staleDay = time() - 2 * 86400;

echo "CAD with Kraken down and a two-day-old anchor still prices through CoinGecko's FX\n";
resetCad();
Config::set('rate_anchor_cad', ['rate' => $fairCad * 0.97, 'timestamp' => $staleDay, 'providers' => ['coingecko', 'kraken']]);
$cad = $fairCad;
$cgCad = new MarketProvider('coingecko', ['usd' => fn() => $usd, 'cad' => function () use (&$cad) { return $cad; }]);
$krakenDown = new MarketProvider('kraken', ['usd' => null, 'cad' => null]);
useProviders($cgCad, new MarketProvider('binance', ['usd' => fn() => $usd]), $krakenDown);
$rate = ExchangeRates::getBtcPrice('CAD', 'coingecko', 'binance');
check($rate !== null && abs($rate - $fairCad) < 0.01, "CAD is priced at the fair rate ($rate)");
check(abs((float)Config::get('fx_anchor_cad')['rate'] - $fxCad) < 1e-9, 'the CAD/USD ratio starts an FX anchor');
check(in_array('cad', $krakenDown->asked, true), 'Kraken was asked first');
check(count(array_filter($cgCad->asked, fn($c) => $c === 'cad')) === 1, 'CoinGecko was asked for CAD only once');
check((float)Config::get('rate_anchor_cad')['rate'] === $fairCad * 0.97, 'one provider never moves the CAD anchor');

echo "A broken CoinGecko CAD feed cannot start the FX anchor\n";
resetCad();
Config::set('rate_anchor_cad', ['rate' => $fairCad, 'timestamp' => $staleDay, 'providers' => ['coingecko', 'kraken']]);
$cad = $fairCad / 10; // decimal slip
check(ExchangeRates::getBtcPrice('CAD', 'coingecko', 'binance') === null, 'a rate 10x off the last agreed one is refused');
check(Config::get('fx_anchor_cad') === null, 'and it does not become the FX anchor');

echo "A hostile CoinGecko, the only CAD source left, ratchets x1.45 per refresh\n";
resetCad();
Config::set('rate_anchor_cad', ['rate' => $fairCad, 'timestamp' => $staleDay, 'providers' => ['coingecko', 'kraken']]);
Config::set('fx_anchor_cad', ['rate' => $fxCad, 'timestamp' => time()]);
$last = $fairCad;
$cgCad->quotes['cad'] = function () use (&$last) { return $last * 1.45; };
$days = 0.0;
foreach ([3600, 86400] as $step) {
    for ($i = 0; $i < 10; $i++) {
        passTime($step);
        $days += $step / 86400;
        Config::set('rate_cad', ['rate' => $last, 'timestamp' => time() - 301, 'provider' => 'coingecko']);
        $rate = ExchangeRates::getBtcPrice('CAD', 'coingecko', 'binance');
        $bound = $fairCad * exp(0.02 * $days) * 1.03;
        check($rate !== null && $rate <= $bound, sprintf('after %.2f days CAD is %.0f, within the 2%%/day bound %.0f', $days, $rate, $bound));
        $anchor = (float)Config::get('fx_anchor_cad')['rate'];
        check($anchor <= $fxCad * exp(0.02 * $days) + 1e-9, sprintf('and the FX anchor drifted only to %.4f', $anchor));
        $last = $rate;
    }
}
check($last <= $fairCad * 1.25, "ten days of attack moved CAD at most ~2%/day ($last vs $fairCad)");

echo "EUR with Binance and Kraken both down still prices from CoinGecko\n";
resetCad();
Config::set('rate_anchor_eur', ['rate' => 55000.0, 'timestamp' => $staleDay, 'providers' => ['coingecko', 'binance']]);
Config::set('rate_anchor_usd', ['rate' => $usd, 'timestamp' => time() - 3600, 'providers' => ['coingecko', 'binance']]);
$fxEur = 55000.0 / $usd;
$cgEur = new MarketProvider('coingecko', ['usd' => fn() => $usd, 'eur' => fn() => 55000.0]);
useProviders($cgEur, new MarketProvider('binance', ['usd' => null, 'eur' => null]),
    new MarketProvider('kraken', ['usd' => null, 'eur' => null]));
$rate = ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance');
check($rate !== null && abs($rate - 55000.0) < 0.01, "EUR is priced through a fresh BTC/USD anchor and CoinGecko's FX ($rate)");
check(abs((float)Config::get('fx_anchor_eur')['rate'] - $fxEur) < 1e-9, 'the EUR/USD ratio starts an FX anchor');

echo "USD with only one provider and an old anchor still refuses (and so does EUR)\n";
resetCad();
Config::set('rate_anchor_eur', ['rate' => 55000.0, 'timestamp' => $staleDay, 'providers' => ['coingecko', 'binance']]);
Config::set('rate_anchor_usd', ['rate' => $usd, 'timestamp' => $staleDay, 'providers' => ['coingecko', 'binance']]);
check(ExchangeRates::getBtcPrice('USD', 'coingecko', 'binance') === null, 'a lone BTC/USD answer is not confirmed');
check(ExchangeRates::getBtcPrice('EUR', 'coingecko', 'binance') === null, 'so EUR has nothing to be priced through');
check(Config::get('fx_anchor_eur') === null, 'and no FX anchor is started');

echo "rates_2026_10: OK\n";
