<?php
/**
 * Theme 2: exchange-rate robustness.
 *   - A cached rate is used without hitting the network (cache short-circuit).
 *   - A non-positive cached rate (0 / negative — seen from flaky upstreams)
 *     never produces a 0-sat invoice or a divide-by-zero: the conversion
 *     refuses with an exception instead.
 *
 * Network-free and deterministic: we seed the rate cache directly, so the
 * provider HTTP loop is never reached.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';

/** Seed the fresh-cache slot getBtcPrice() reads first (key is lowercased). */
function seed_rate(string $ccy, $rate): void {
    Config::set('rate_' . strtolower($ccy), [
        'rate' => $rate,
        'timestamp' => Database::timestamp(),
        'provider' => 'test',
    ]);
}

// --- Happy path: a sane cached rate drives the conversion (no network) ------
seed_rate('USD', 40000.0); // $40,000 / BTC
$sats = ExchangeRates::convertToSats('2', 'USD', 'sat');
// 2 USD / 40000 = 0.00005 BTC = 5000 sats
assert_eq(5000, $sats, 'USD->sats uses the cached rate');

// satsToFiat round-trips with the same cached rate.
$fiat = ExchangeRates::satsToFiat(5000, 'USD');
assert_not_null($fiat, 'satsToFiat returns a value for a sane cached rate');
assert_eq('2.00', $fiat, 'satsToFiat inverts the conversion');

// --- Divisor guard: a zero rate must NOT yield 0 sats / div-by-zero --------
seed_rate('EUR', 0);
$threw = false;
try {
    ExchangeRates::convertToSats('10', 'EUR', 'sat');
} catch (\Throwable $e) {
    $threw = true;
}
assert_true($threw, 'a zero cached rate is refused, not used to price at 0 sats');

// --- Divisor guard: negative rate is likewise refused ----------------------
seed_rate('GBP', -123.0);
$threw = false;
try {
    ExchangeRates::convertToSats('10', 'GBP', 'sat');
} catch (\Throwable $e) {
    $threw = true;
}
assert_true($threw, 'a negative cached rate is refused');

// --- satsToFiat degrades to null (not a fatal) on a bad rate ---------------
seed_rate('CHF', 0);
assert_null(ExchangeRates::satsToFiat(5000, 'CHF'), 'satsToFiat returns null on a non-positive rate');

fwrite(STDERR, "test_rates_guard: all assertions passed\n");
