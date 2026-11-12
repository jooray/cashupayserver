<?php
/**
 * An automatic withdrawal fits the mint's real Lightning fee reserve (R1, 2026-10-28).
 *
 * The payout used to be sized with a fee buffer capped at 100 sats, so on a mint that
 * reserves 1-2 % every automatic withdrawal above ~10k sats failed with "Insufficient
 * balance", run after run, while the money piled up at the mint. The mock mint here
 * reserves 2 % (MOCK_FEE_RESERVE_PPK=20), like many CDK mints.
 *
 * Run: php tests/auto_withdraw_fees.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-autofee-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/lightning_address.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$port = testFreePort();
$mintProc = proc_open(
    ['php', '-S', "127.0.0.1:$port", "$root/tests/mock_mint_router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, $root, ['MOCK_MINT_STATE' => "$dataDir/mint.json", 'MOCK_FEE_RESERVE_PPK' => '20'] + getenv()
);
$mintUrl = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents("$mintUrl/v1/info"); $i++) {
    usleep(100000);
}
register_shutdown_function(function () use (&$mintProc, $dataDir) {
    if ($mintProc) { testStopProcess($mintProc); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

Database::initialize();
Config::set('setup_complete', '1');
Config::set('allow_private_mints', true);
$storeId = 'store_f';
Database::insert('stores', [
    'id' => $storeId, 'name' => 'Fee test', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore($storeId, false);

// The mock mint's largest denomination is 2048, so fund in ten payments of 2000.
for ($i = 0; $i < 10; $i++) {
    $invoice = Invoice::create($storeId, ['amount' => 2000, 'currency' => 'sat']);
    @file_get_contents("$mintUrl/__control/pay", false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => json_encode(['quote' => $invoice['quote_id']]),
    ]]));
    Invoice::pollSingleQuote($invoice['id'], true);
}
$balance = Invoice::getBalance($storeId);
check($balance === 20000, "the store holds 20000 sat ($balance)");

// What autoMeltAccount does: a 1 % guess, then the mint's real numbers. The Lightning
// address step is replaced by the mock's lnbcmockmelt<N> invoices.
$executeMelt = new ReflectionMethod(LightningAddress::class, 'executeMelt');
$attempts = [];
$send = function (int $sats) use ($executeMelt, $storeId, $balance, &$attempts) {
    $attempts[] = $sats;
    return $executeMelt->invoke(null, $storeId, "lnbc1lnbcmockmelt{$sats}", 'test@example.com',
        Transfer::TYPE_AUTO_WITHDRAW, null, $balance);
};

echo "A 2 % fee reserve does not fit the 1 % guess; the payout shrinks to fit\n";
$guess = $balance - max(2, (int)ceil($balance * 0.01));
$sent = LightningAddress::sendFitted($guess, $send);
check(count($attempts) >= 2 && count($attempts) <= 4 && $attempts[0] === $guess, 'attempts that do not fit are refused before anything is sent (' . count($attempts) . ' attempts)');
check($sent < $guess && $sent > $balance * 0.97, "the payout is shrunk to fit ($sent of $balance sat)");
$rows = Database::fetchAll('SELECT status, amount FROM transfers WHERE store_id = ?', [$storeId]);
check(count($rows) === 1 && $rows[0]['status'] === Transfer::STATUS_COMPLETED,
    'one withdrawal is recorded, and it completed');
check(Invoice::getBalance($storeId) < $balance * 0.03, 'almost the whole balance left the mint (' . Invoice::getBalance($storeId) . ' sat left)');

echo "A payout that cannot fit even after shrinking still fails in plain words\n";
$attempts = [];
$failed = null;
try {
    LightningAddress::sendFitted(100000, fn(int $sats) => $executeMelt->invoke(null, $storeId,
        "lnbc1lnbcmockmelt{$sats}", 'test@example.com', Transfer::TYPE_AUTO_WITHDRAW, null, 0));
} catch (MeltDoesNotFit $e) {
    $failed = $e->getMessage();
}
check($failed !== null && str_contains($failed, 'Insufficient balance'), 'an empty budget is refused');

echo "auto_withdraw_fees: OK\n";
