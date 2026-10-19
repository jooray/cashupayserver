<?php
/**
 * A mint that charges per input proof (NUT-02 input_fee_ppk) can make a small amount of
 * ecash cost more to move than it is worth. A 1 sat donation on a 100 ppk mint is the
 * real case that prompted this: the sink could not cash it in, the operator could not
 * take it back, and the background runner retried it forever.
 *
 * This pins the arithmetic that decides "too small to be worth moving".
 *
 * Run: php tests/dust_donation.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-dust-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
// An operator who turns donations off. Defined before config.php's default, the way
// config.local.php would; silence only config.php's "already defined" warning.
define('CASHUPAY_DONATION_PERCENT', 0);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
$reporting = error_reporting(E_ALL & ~E_WARNING);
require_once dirname(__DIR__) . '/includes/lightning_address.php';
error_reporting($reporting);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

use Cashu\Wallet;

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

/** The production helper itself, not a copy of it. */
function redeemFee(int $amount, int $ppk): int {
    return Donation::redeemFeeFor($amount, $ppk);
}

echo "mint.lnvoltz.com charges 100 ppk — the mint the stuck donation was made on\n";
check(count(Wallet::splitAmount(1)) === 1, '1 sat is a single proof');
check(redeemFee(1, 100) === 1, 'redeeming 1 sat costs 1 sat');
check(redeemFee(1, 100) >= 1, 'so a 1 sat donation nets its recipient nothing and must not be created');

echo "Amounts that are worth moving on the same mint\n";
check(redeemFee(18, 100) === 1, '18 sat (two proofs) costs 1 sat to redeem');
check(redeemFee(18, 100) < 18, 'and is therefore worth creating');
check(redeemFee(2, 100) === 1 && redeemFee(2, 100) < 2, '2 sat is the smallest donation that survives');

echo "A fee-free mint (cashu.cz charges 0 ppk) moves anything\n";
check(redeemFee(1, 0) === 0, '1 sat costs nothing to redeem');
check(redeemFee(1, 0) < 1, 'so even a 1 sat donation is fine there');

echo "The fee is per input proof, and rounds once for the whole swap\n";
// This is why batching dust with other ecash works: ten 100 ppk inputs still cost 1 sat.
check(redeemFee(1023, 100) === 1, '1023 sat is ten proofs and still costs only 1 sat');
check(redeemFee(2047, 100) === 2, 'eleven proofs crosses into a second sat');
check(redeemFee(255, 1000) === 8, 'a 1000 ppk mint charges a full sat per proof');

echo "Amounts at the boundary\n";
check(redeemFee(0, 100) === 0, 'zero costs nothing');
check(redeemFee(3, 100) === 1 && redeemFee(3, 100) < 3, '3 sat (two proofs) is viable');

echo "Donation::isDust agrees\n";
check(Donation::isDust(1, 100), 'a 1 sat donation on a 100 ppk mint is dust');
check(!Donation::isDust(2, 100), '2 sat on the same mint is not');
check(!Donation::isDust(1, 0), '1 sat on a fee-free mint is not');

echo "A donation percentage of 0 donates nothing\n";
check(Donation::calculateAmount(1000) === 0, '0 % of 1000 is 0, not the old 1-unit floor');
check(Donation::calculateAmount(1) === 0, '0 % of 1 is 0');
check(Donation::calculateMaxWithdrawal(1000) === 1000, 'the whole balance can be withdrawn');

echo "\nAll dust-donation checks passed.\n";
