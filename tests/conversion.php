<?php
/**
 * Amount conversion regressions from the FABLE-51 / GPT-ASTRA audits.
 *
 * Run: php tests/conversion.php
 */

declare(strict_types=1);

// Tests create disposable databases and spawn helper processes; they must never be
// reachable over HTTP, even on a deployment that serves this directory.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-conversion-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/rates.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

require_once dirname(__DIR__) . '/includes/config.php';

// Seed the rate cache so the maths is checked against a fixed price rather than a live
// provider. ExchangeRates reads rates through Config.
Database::initialize();
Config::set('rate_usd', ['rate' => 60000.0, 'timestamp' => time(), 'provider' => 'test']);

$convert = function (string $amount, string $from, string $to): int {
    return ExchangeRates::convertToMintUnit($amount, $from, $to);
};

// --- Rounding happens once, at the end ---------------------------------------
// 1 USD at 60,000 USD/BTC is 1666.66… sats. Truncating the BTC intermediate to 8
// decimals first produced 1666; the merchant must not be undercharged.
check($convert('1', 'USD', 'SAT') === 1667, '1 USD at 60k rounds up to 1667 sats, not 1666');
check($convert('60000', 'USD', 'SAT') === 100000000, '60,000 USD is exactly 1 BTC in sats');

// --- msat is its own smallest unit -------------------------------------------
// Dividing by 1000 here quoted 1000 msat as 1 msat.
check($convert('1000', 'MSAT', 'MSAT') === 1000, '1000 msat priced on an msat mint stays 1000 msat');
check($convert('1', 'SAT', 'MSAT') === 1000, '1 sat is 1000 msat');
check($convert('1000', 'MSAT', 'SAT') === 1, '1000 msat is 1 sat');

// --- Same-unit fiat -----------------------------------------------------------
check($convert('1.23', 'USD', 'USD') === 123, '1.23 USD on a USD mint is 123 cents');
check($convert('1', 'USD', 'USD') === 100, '1 USD on a USD mint is 100 cents');

// --- Fiat mint balances back to sats -----------------------------------------
check(
    ExchangeRates::convertMintUnitToSats(100, 'USD') === 1666,
    '100 cents converts back to 1666 sats (round down, we must not overspend)'
);

// --- Sanity band on fetched rates (SPB-OPS-011 / OPUS-019) --------------------
final class StubProvider implements PriceProvider {
    public int $calls = 0;
    public function __construct(private string $name, private ?float $rate) {}
    public function getName(): string { return $this->name; }
    public function getBtcPrice(string $currency): ?float { $this->calls++; return $this->rate; }
    public function supports(string $currency): bool { return true; }
}
function useProviders(StubProvider ...$stubs): void {
    $prop = new ReflectionProperty(ExchangeRates::class, 'providers');
    $map = [];
    foreach ($stubs as $s) { $map[$s->getName()] = $s; }
    $prop->setValue(null, $map);
}
// The cached rate plus the anchor two providers confirmed (C2: the band is measured
// against the anchor, never against the possibly single-source cache).
$expire = function (string $cur, float $rate, int $age): void {
    Config::set("rate_$cur", ['rate' => $rate, 'timestamp' => time() - $age, 'provider' => 'test']);
    Config::set("rate_anchor_$cur", ['rate' => $rate, 'timestamp' => time() - $age]);
};

$expire('eur', 50000.0, 600); // expired (older than 5 min), but a recent reference
useProviders($glitch = new StubProvider('glitch', 500000.0), $good = new StubProvider('good', 51000.0));
check(ExchangeRates::getBtcPrice('EUR', 'glitch', 'good') === 51000.0,
    'a 10x glitch from the primary is rejected and the secondary is used');
check($good->calls === 1, 'the secondary was asked');
check(Config::get('rate_eur')['provider'] === 'good', 'and only the sane rate was cached');

$expire('eur', 50000.0, 600);
useProviders(new StubProvider('a', 52000.0), $b = new StubProvider('b', 99999.0));
check(ExchangeRates::getBtcPrice('EUR', 'a', 'b') === 52000.0 && $b->calls === 0,
    'a sane primary answer needs no second opinion');

$expire('eur', 50000.0, 600);
useProviders(new StubProvider('a', 20000.0), new StubProvider('b', 20300.0));
check(ExchangeRates::getBtcPrice('EUR', 'a', 'b') === 20000.0,
    'when two providers agree on a big move, the market moved: their rate is used');

Config::set('rate_eur', null); // cold start, nothing to compare with
Config::set('rate_anchor_eur', null);
useProviders(new StubProvider('a', 500000.0), new StubProvider('b', 50000.0), new StubProvider('c', 50500.0));
check(ExchangeRates::getBtcPrice('EUR', 'a', 'b') === 50000.0,
    'with no known rate, the answer two providers agree on wins over a lone outlier');

Config::set('rate_eur', null);
Config::set('rate_anchor_eur', null);
useProviders(new StubProvider('a', 50000.0));
check(ExchangeRates::getBtcPrice('EUR', 'a') === null, 'a single unconfirmed provider never prices invoices (C2)');

$expire('eur', 50000.0, 2 * 3600); // too old for the stale fallback, still a reference
useProviders(new StubProvider('a', 500000.0), new StubProvider('b', null));
$msg = null;
try { ExchangeRates::convertToMintUnit('10', 'EUR', 'SAT'); } catch (Exception $e) { $msg = $e->getMessage(); }
check($msg === ExchangeRates::UNAVAILABLE_MESSAGE && !str_contains((string)$msg, 'Cannot get exchange rate'),
    'with no trustworthy rate the shop gets a plain message, not "Cannot get exchange rate" (' . $msg . ')');

// One decimals rule for pricing, the dashboard, the payment page and NUT-18 requests.
check(Config::unitDecimals('sat') === 0 && Config::unitDecimals('msat') === 0, 'sat and msat are whole numbers');
check(Config::unitDecimals('btc') === 8, 'btc counts satoshis');
check(Config::unitDecimals('usdt') === 2 && Config::unitDecimals('eur') === 2, 'fiat and stablecoins count cents');
check(Config::unitFor('usdt')->parse('1.23') === 123, 'a usdt amount with cents parses (library agrees)');
check(Config::unitFor('btc')->parse('0.001') === 100000, 'a btc amount parses to satoshis');
check(ExchangeRates::convertMintUnitToSats(12345, 'btc') === 12345, 'a btc mint amount is already in sats');
check(ExchangeRates::convertSatsToMintUnit(12345, 'btc') === 12345, 'and sats are a btc mint amount');

echo "conversion: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
