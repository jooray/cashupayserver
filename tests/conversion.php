<?php
/**
 * Amount conversion regressions from the FABLE-51 / GPT-ASTRA audits.
 *
 * Run: php tests/conversion.php
 */

declare(strict_types=1);

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

echo "conversion: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
