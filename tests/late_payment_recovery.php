<?php
/**
 * A payment that arrives after the shop stopped expecting it is still collected.
 *
 * - An invoice the merchant cancelled (Invalid) whose Lightning invoice the customer
 *   paid anyway used to stay Invalid forever: the claim only accepted New/Expired, so
 *   the money sat at the mint under a quote nothing could mint.
 * - An expired quote whose answer does not plainly say "unpaid" keeps its quote id;
 *   clearing it on a vague answer deleted the only link to a possible payment.
 * - The invoice records the unit it was quoted in, so a later unit change cannot make
 *   the quote uncollectable.
 *
 * Uses a local mock mint (tests/mock_mint_router.php); no network, no real funds.
 *
 * Run: php tests/late_payment_recovery.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-latepay-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$mintProc = null;
$port = random_int(18100, 18999);
$mintProc = proc_open(
    ['php', '-S', "127.0.0.1:$port", "$root/tests/mock_mint_router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, $root, ['MOCK_MINT_STATE' => "$dataDir/mint.json"] + getenv()
);
$mint = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents("$mint/v1/info"); $i++) {
    usleep(100000);
}
register_shutdown_function(function () use ($dataDir, &$mintProc) {
    if ($mintProc) { proc_terminate($mintProc); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

function pay(string $mintUrl, string $quoteId): void {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => json_encode(['quote' => $quoteId]),
    ]]);
    @file_get_contents("$mintUrl/__control/pay", false, $ctx);
}

Database::initialize();
Config::set('setup_complete', '1');
Database::insert('stores', [
    'id' => 's1', 'name' => 's1', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mint, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore('s1', false);

echo "The invoice remembers the unit it was quoted in\n";
$inv = Invoice::create('s1', ['amount' => 21, 'currency' => 'sat']);
check(($inv['mint_unit'] ?? null) === 'sat', 'mint_unit is stored on the invoice');

echo "A customer who pays a cancelled invoice is still credited\n";
Database::update('invoices', ['status' => 'Invalid', 'expiration_time' => time() - 10], 'id = ?', [$inv['id']]);
pay($mint, $inv['quote_id']);
$recovered = Invoice::recoverExpiredPaidInvoices(0);
$row = Invoice::getById($inv['id']);
check($row['status'] === 'Settled', 'the cancelled-but-paid invoice settles (got ' . $row['status'] . ')');
check(in_array($inv['id'], $recovered, true), 'and is reported as recovered');
check(Invoice::getBalance('s1') === 21, 'the balance includes the late payment (' . Invoice::getBalance('s1') . ')');

echo "An unpaid cancelled invoice is not re-opened\n";
$unpaid = Invoice::create('s1', ['amount' => 13, 'currency' => 'sat']);
Database::update('invoices', ['status' => 'Invalid', 'expiration_time' => time() - 10], 'id = ?', [$unpaid['id']]);
Invoice::recoverExpiredPaidInvoices(0);
$row = Invoice::getById($unpaid['id']);
check($row['status'] === 'Invalid', 'it stays cancelled');
check($row['quote_id'] !== null, 'and keeps its quote while the mint quote has not expired');

echo "A payment on an expired invoice settles too\n";
$late = Invoice::create('s1', ['amount' => 8, 'currency' => 'sat']);
Database::update('invoices', ['status' => 'Expired', 'expiration_time' => time() - 10], 'id = ?', [$late['id']]);
pay($mint, $late['quote_id']);
Invoice::recoverExpiredPaidInvoices(0);
check(Invoice::getById($late['id'])['status'] === 'Settled', 'the expired-but-paid invoice settles');

echo "late_payment_recovery: OK\n";
