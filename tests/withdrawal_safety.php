<?php
/**
 * Withdrawals must never pay twice and must always end up in the ledger correctly.
 *
 *  - A withdrawal records its mint and the proofs it spent, and the browser never gets
 *    those proof secrets.
 *  - A second withdrawal is refused while an earlier one is still pending.
 *  - The automatic withdrawal skips a store whose funds lock is held, or which has a
 *    withdrawal pending.
 *  - A withdrawal whose melt quote the mint has forgotten is settled from the state of
 *    the inputs it spent: completed when spent, failed when unspent (DS41-25).
 *  - Unit conversion for Lightning: msat is not sats (DS41-20).
 *
 * Uses a local mock mint (tests/mock_mint_router.php); no network, no real funds.
 *
 * Run: php tests/withdrawal_safety.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-withdraw-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/lightning_address.php';
require_once $root . '/includes/background_runner.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$mintProc = null;
$port = random_int(19100, 19999);
$statePath = "$dataDir/mint.json";
$mintProc = proc_open(
    ['php', '-S', "127.0.0.1:$port", "$root/tests/mock_mint_router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, $root, ['MOCK_MINT_STATE' => $statePath] + getenv()
);
$mintUrl = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents("$mintUrl/v1/info"); $i++) {
    usleep(100000);
}
register_shutdown_function(function () use (&$mintProc, $dataDir) {
    if ($mintProc) { proc_terminate($mintProc); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

function freshRequest(): void {
    $cache = new ReflectionProperty(Invoice::class, 'walletCache');
    $cache->setValue(null, []);
}

Database::initialize();
Config::set('setup_complete', '1');

$storeId = 'store_w';
Database::insert('stores', [
    'id' => $storeId, 'name' => 'Withdraw test', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore($storeId, false);

// Fund the store with a real (mock) payment.
$invoice = Invoice::create($storeId, ['amount' => 500, 'currency' => 'sat']);
@file_get_contents("$mintUrl/__control/pay", false, stream_context_create(['http' => [
    'method' => 'POST', 'header' => 'Content-Type: application/json',
    'content' => json_encode(['quote' => $invoice['quote_id']]),
]]));
Invoice::pollSingleQuote($invoice['id'], true);
check(Invoice::getBalance($storeId) === 500, 'store is funded with 500 sat');

echo "A withdrawal records its mint and inputs, and hides them from the browser\n";
$result = LightningAddress::meltToBolt11($storeId, 'lnbc200n1lnbcmockmelt20');
check($result['success'] === true && $result['amountPaid'] === 20, 'a 20 sat withdrawal is paid');
$row = Transfer::getById($result['transferId']);
check($row['status'] === Transfer::STATUS_COMPLETED, 'its ledger row is completed');
check($row['mint_url'] === $mintUrl, 'the row records the mint the money left from');
check(Transfer::meltQuoteId($row) === $result['quote'], 'the row names its melt quote');
check(count(Transfer::meltInputSecrets($row)) > 0, 'the row records the proofs it spent');
$api = Transfer::formatForApi($row);
check(!array_key_exists('reference', $api), 'proof secrets are not sent to the browser');

echo "A withdrawal is refused while another is pending\n";
$pendingId = Transfer::open($storeId, Transfer::TYPE_LIGHTNING, 10, 'sat', 'lnbc…', 'deadbeef');
$refused = null;
try {
    LightningAddress::meltToBolt11($storeId, 'lnbc100n1lnbcmockmelt10');
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
check($refused !== null && str_contains($refused, 'could pay twice'), 'the second withdrawal is refused in plain words');
$refused = null;
try {
    LightningAddress::meltToAddress($storeId, 'nobody@nowhere.invalid', 10);
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
check($refused !== null && str_contains($refused, 'still being confirmed'),
    'a Lightning address withdrawal is refused before the address is even asked for an invoice');
Transfer::fail($pendingId, 'test cleanup');

echo "A withdrawal from a mint the store does not use is refused\n";
$refused = null;
try {
    LightningAddress::meltToBolt11($storeId, 'lnbc100n1lnbcmockmelt10', null, ['mint_url' => 'https://evil.example', 'unit' => 'sat']);
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
check($refused !== null && str_contains($refused, 'not one of this store'), 'only the store\'s own wallets can be paid from');

echo "The automatic withdrawal waits for the operator and for pending withdrawals\n";
Database::update('stores', [
    'auto_melt_enabled' => 1, 'auto_melt_address' => 'nobody@nowhere.invalid', 'auto_melt_threshold' => 100,
], 'id = ?', [$storeId]);
Config::clearCache();
$lock = cashupay_acquire_lock('funds_' . $storeId);
check($lock !== null, 'the operator holds the funds lock');
$auto = LightningAddress::checkAutoMelt();
check(is_array($auto) && ($auto[0]['skipped'] ?? null) === 'busy', 'auto-withdrawal skips a store that is busy');
cashupay_release_lock($lock);

$pendingId = Transfer::open($storeId, Transfer::TYPE_LIGHTNING, 10, 'sat', 'lnbc…', 'deadbeef');
$auto = LightningAddress::checkAutoMelt();
check(is_array($auto) && ($auto[0]['skipped'] ?? null) === 'pending', 'auto-withdrawal skips a store with a pending withdrawal');
Transfer::fail($pendingId, 'test cleanup');

$auto = LightningAddress::checkAutoMelt();
check(is_array($auto) && !empty($auto[0]['error']) && ($auto[0]['store_id'] ?? null) === $storeId,
    'a failed auto-withdrawal names the store and the error');
$mapped = (new ReflectionMethod(BackgroundRunner::class, 'autoMelt'))->invoke(null);
check(($mapped[0]['store'] ?? null) === 'Withdraw test' && !empty($mapped[0]['error']), 'the task result names the store');
check(BackgroundRunner::failureOf($mapped) !== null, 'and is classified as a failure');
Database::update('stores', ['auto_melt_enabled' => 0], 'id = ?', [$storeId]);
Config::clearCache();

echo "A withdrawal whose quote the mint forgot is settled from its inputs\n";
$reconcile = new ReflectionMethod(BackgroundRunner::class, 'reconcileTransfers');
$paid = LightningAddress::meltToBolt11($storeId, 'lnbc300n1lnbcmockmelt30');
Database::update('transfers', ['status' => Transfer::STATUS_PENDING], 'id = ?', [$paid['transferId']]);
$state = json_decode(file_get_contents($statePath), true);
unset($state['melt_quotes'][$paid['quote']]);
file_put_contents($statePath, json_encode($state));
freshRequest();
$reconcile->invoke(null);
check(Transfer::getById($paid['transferId'])['status'] === Transfer::STATUS_COMPLETED,
    'a forgotten quote whose inputs are spent is completed');

$unspent = array_map(
    fn($p) => $p->secret,
    array_slice(Invoice::getWalletForStore($storeId)->getStorage()->getProofsAsObjects(\Cashu\ProofState::UNSPENT), 0, 1)
);
$neverPaid = Transfer::open(
    $storeId, Transfer::TYPE_LIGHTNING, 5, 'sat', 'lnbc…',
    Transfer::meltReference(str_repeat('ab', 16), $unspent), null, null, $mintUrl
);
$reconcile->invoke(null);
check(Transfer::getById($neverPaid)['status'] === Transfer::STATUS_FAILED,
    'a forgotten quote whose inputs are unspent is failed');

echo "Auto-withdrawal picks the account that holds the money\n";
$pick = new ReflectionMethod(LightningAddress::class, 'autoMeltAccounts');
$backupUrl = 'https://backup.example';
Database::insert('store_mints', [
    'store_id' => $storeId, 'mint_url' => $backupUrl, 'unit' => 'sat', 'priority' => 1,
    'enabled' => 1, 'created_at' => Database::timestamp(),
]);
$backupStorage = new \Cashu\WalletStorage(
    Database::getDbPath(), $backupUrl, 'sat', Config::getStore($storeId)['wallet_account_id']
);
$backupStorage->storeProofs([new \Cashu\Proof('009a1f293253e41e', 1024, 'backup-secret', '02' . str_repeat('11', 32))]);
$storeRow = Database::fetchOne('SELECT * FROM stores WHERE id = ?', [$storeId]);
$primaryBalance = Invoice::getWalletForStore($storeId)->getStorage()->getBalance();

$storeRow['auto_melt_threshold'] = 1000;
$due = $pick->invoke(null, $storeRow);
check(count($due) === 1 && $due[0]['mint_url'] === $backupUrl && $due[0]['balance'] === 1024,
    'the backup mint over the threshold is withdrawn from the backup, not the primary');

$storeRow['auto_melt_threshold'] = min($primaryBalance, 1024);
$due = $pick->invoke(null, $storeRow);
check(count($due) === 2, 'every account over the threshold is withdrawn on its own');

$storeRow['auto_melt_threshold'] = 1024 + $primaryBalance;
$due = $pick->invoke(null, $storeRow);
check(count($due) === 1 && $due[0]['mint_url'] === $backupUrl,
    'when only the total reaches the threshold, the largest account is withdrawn');

$storeRow['auto_melt_threshold'] = 1024 + $primaryBalance + 1;
check($pick->invoke(null, $storeRow) === [], 'below the threshold nothing is due');

echo "Mint units are converted for Lightning\n";
check(LightningAddress::mintAmountToSats($storeId, 21000, 'msat') === 21, '21000 msat is 21 sat');
check(LightningAddress::mintAmountToSats($storeId, 21999, 'msat') === 21, 'msat rounds down, never asking for more than the balance');
check(LightningAddress::mintAmountToSats($storeId, 21, 'sat') === 21, 'sat stays sat');

echo "Transfer::open refuses to continue without its ledger row\n";
$threw = false;
Database::getInstance()->exec('DROP TABLE transfers');
try {
    Transfer::open($storeId, Transfer::TYPE_LIGHTNING, 1, 'sat');
} catch (Throwable $e) {
    $threw = true;
}
check($threw, 'a failed ledger write throws instead of returning null');

echo "withdrawal_safety: OK\n";
