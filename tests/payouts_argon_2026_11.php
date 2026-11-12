<?php
/**
 * Payout, backup-mint and token-check fixes from the ARGON-NOV2026 audit.
 *
 * - CPS-03: a withdrawal is priced at the mint that will pay it. With the primary mint
 *   down, the money a backup took in can still be paid out to a pasted invoice.
 * - CPS-05: the Greenfield payment-methods endpoint reports BTC amounts in BTC.
 * - CPS-09: a donation swaps only the proofs it needs, not the whole wallet.
 * - CPS-10: a paid withdrawal whose change is still at the mint stays pending, with no
 *   made-up fee, until the background run completes it.
 * - CPS-11: token checks ask the mint the token came from.
 * - UX-03: a backup mint must take the store's currency and differ from the primary.
 * - UX-06: an amountless invoice is refused when pasted, not on submit.
 *
 * Two mock mints, a stub donation sink and the real admin.php, all on loopback.
 *
 * Run: php tests/payouts_argon_2026_11.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-argon-payouts-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
$named = [];
register_shutdown_function(function () use ($dataDir) {
    global $procs;
    foreach ($procs as $p) { testStopProcess($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});
function serve(string $name, string $script, array $env = []): string {
    global $procs, $named, $root;
    $port = testFreePort();
    $p = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-S', "127.0.0.1:$port", $script],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    $procs[] = $p;
    $named[$name] = $p;
    return "http://127.0.0.1:$port";
}
function post(string $url, array $body) {
    return @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST',
        'header' => 'Content-Type: application/json', 'content' => json_encode($body), 'ignore_errors' => true]]));
}

// A sink that records what it is sent. Defined before config.php so no test donation
// can reach the real one (config.php's own define then only warns).
file_put_contents("$dataDir/sink.php", '<?php file_put_contents(__DIR__ . "/sink.log", file_get_contents("php://input") . "\n", FILE_APPEND); echo "{}";');
$sink = serve('sink', "$dataDir/sink.php");
define('CASHUPAY_DONATION_SINK_URL', "$sink/sink.php");

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
$level = error_reporting(E_ALL & ~E_WARNING);
require_once $root . '/includes/config.php';
error_reporting($level);
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/lightning_address.php';
require_once $root . '/includes/transfer.php';
require_once $root . '/includes/background_runner.php';
require_once $root . '/includes/api/stores.php';

use Cashu\ProofState;

function fresh(): void { (new ReflectionProperty(Invoice::class, 'walletCache'))->setValue(null, []); }
function fund(string $store, string $mint, int $amt): void {
    $w = Invoice::getWalletForStore($store, $mint, 'sat');
    $q = $w->requestMintQuote($amt);
    post("$mint/__control/pay", ['quote' => $q->quote]);
    $w->mint($q->quote, $amt);
}
function storageOf(string $mint): \Cashu\WalletStorage {
    global $acct;
    return new \Cashu\WalletStorage(Database::getDbPath(), $mint, 'sat', $acct);
}

$A = serve('A', "$root/tests/mock_mint_router.php", ['MOCK_MINT_STATE' => "$dataDir/a.json"]);
$B = serve('B', "$root/tests/mock_mint_router.php", ['MOCK_MINT_STATE' => "$dataDir/b.json"]);

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true);
Config::set('admin_password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
Config::set('update_check_enabled', false);
Config::set('last_background_trigger', (string)(time() + 3600));
$seed = 'half depart obvious quality work element tank gorilla view sugar picture humble';
$acct = Database::generateWalletAccountId();
Database::insert('stores', ['id' => 's1', 'name' => 's1', 'wallet_account_id' => $acct, 'mint_url' => $A,
    'mint_unit' => 'sat', 'seed_phrase' => $seed, 'exchange_fee_percent' => 0, 'created_at' => Database::timestamp()]);
Database::insert('stores', ['id' => 's2', 'name' => 's2', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $A, 'mint_unit' => 'eur', 'seed_phrase' => $seed, 'exchange_fee_percent' => 0,
    'created_at' => Database::timestamp()]);
Invoice::initializeWalletForStore('s1', false);
Config::addStoreBackupMint('s1', $B, 'sat');
Invoice::initializeWalletForStore('s1', false, $B, 'sat');
fund('s1', $A, 1000);
fund('s1', $B, 3000);

// The real admin page, with the same data folder and the stub sink.
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' define("CASHUPAY_DONATION_SINK_URL", ' . var_export("$sink/sink.php", true) . ');'
    . ' error_reporting(E_ALL & ~E_WARNING);'
    . ' chdir(' . var_export($root, true) . ');'
    . ' require ' . var_export("$root/admin.php", true) . ';');
$adminBase = serve('admin', $router, ['PHP_CLI_SERVER_WORKERS' => '2']);
Config::set('base_url', $adminBase);
$adminUrl = "$adminBase/admin.php";
$cookies = "$dataDir/cookies.txt";
function admin(array $post): array {
    global $adminUrl, $cookies, $csrf;
    $ch = curl_init($adminUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post + ['csrf_token' => $csrf ?? ''])]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, json_decode($body, true) ?? ['raw' => substr($body, 0, 300)]];
}
$csrf = '';
[, $r] = admin(['action' => 'login', 'password' => 'correct horse']);
$csrf = $r['csrfToken'] ?? '';
[, $r] = admin(['action' => 'login', 'password' => 'correct horse']);
$csrf = $r['csrfToken'] ?? '';
check($csrf !== '', 'logged in to the admin page');

echo "CPS-05: payment-methods amounts are in BTC\n";
$eurInvoice = ['bolt11' => 'lnbc25u1pmock', 'currency' => 'EUR', 'amount' => '25.00', 'exchange_rate' => 100000.0,
    'mint_unit' => 'sat', 'amount_sats' => 2500];
check(invoicePaymentMethodBtcAmount($eurInvoice) === '0.00002500', 'a EUR 25 order is 0.00002500 BTC, not 25 BTC');
check(invoicePaymentMethodRate($eurInvoice) === '100000', 'its rate is the EUR price of one BTC');
check(invoicePaymentMethodBtcAmount(['bolt11' => '', 'mint_unit' => 'sat', 'amount_sats' => 123456789]) === '1.23456789',
    'without an invoice string a sat mint\'s amount is used');
check(invoicePaymentMethodRate(['currency' => 'SAT']) === '100000000', 'a sat-priced invoice\'s rate is sats per BTC');

echo "UX-03: a backup must be another mint that takes the store's currency\n";
[$code, $r] = admin(['action' => 'add_backup_mint', 'store_id' => 's1', 'mint_url' => $A]);
check($code === 400 && str_contains($r['error'] ?? '', 'already your store\'s main mint'), 'the primary itself is refused (' . ($r['error'] ?? '') . ')');
[$code, $r] = admin(['action' => 'add_backup_mint', 'store_id' => 's1', 'mint_url' => "$A/"]);
check($code === 400 && str_contains($r['error'] ?? '', 'main mint'), 'also with a trailing slash');
[$code, $r] = admin(['action' => 'add_backup_mint', 'store_id' => 's2', 'mint_url' => $B, 'unit' => 'sat']);
check($code === 400 && str_contains($r['error'] ?? '', 'does not accept payments in EUR'),
    'a sat-only mint is refused as a EUR store\'s backup, whatever unit the form sent (' . ($r['error'] ?? '') . ')');
check(Database::fetchOne("SELECT COUNT(*) AS n FROM store_mints WHERE store_id = 's2'")['n'] == 0, 'and nothing was saved');

echo "UX-06: an amountless invoice is refused when pasted\n";
[, $r] = admin(['action' => 'get_bolt11_amount', 'store_id' => 's1', 'bolt11' => 'lnbc1lnbcmockmelt500']);
check(($r['hasAmount'] ?? null) === false && empty($r['meltError']), 'no amount, and no mint error to show instead');

echo "CPS-03: the withdrawal is priced where it will be paid\n";
$bolt11 = 'lnbc5u1lnbcmockmelt500';
fresh();
$priced = LightningAddress::quotePayingAccount('s1', $bolt11);
check($priced['account']['mint_url'] === $A && $priced['quote'] !== null, 'the primary pays when it can');
$big = 'lnbc20u1lnbcmockmelt2000';
$priced = LightningAddress::quotePayingAccount('s1', $big);
check($priced['account']['mint_url'] === $B && $priced['quote']->amount === 2000, 'the backup pays what the primary cannot cover');

testStopProcess($named['A']);
usleep(300000);
fresh();
$priced = LightningAddress::quotePayingAccount('s1', $bolt11);
check($priced['account']['mint_url'] === $B && $priced['quote'] !== null, 'with the primary down, the backup prices it');
$info = LightningAddress::getBolt11Amount($bolt11, 's1');
check(($info['amountMintUnit'] ?? null) === 500 && !isset($info['meltError']), 'and the pasted invoice is not refused (no mint error)');
[, $r] = admin(['action' => 'get_withdrawal_estimate', 'store_id' => 's1', 'destination' => $bolt11, 'amount_sats' => 500]);
check(($r['success'] ?? false) === true && $r['balance'] === storageOf($B)->getBalance(),
    'the estimate is judged against the backup\'s own balance');
[$code, $r] = admin(['action' => 'manual_melt', 'store_id' => 's1', 'address' => $bolt11, 'amount' => 500, 'donate' => '0']);
check($code === 200 && ($r['success'] ?? false) === true && ($r['mintUrl'] ?? '') === $B,
    'the withdrawal goes out from the backup (HTTP ' . $code . ' ' . json_encode($r) . ')');

echo "A priced quote is reused for the payment while it is fresh\n";
$reuse = new ReflectionMethod(LightningAddress::class, 'reusableQuote');
$q = fn(array $o) => new \Cashu\MeltQuote(...($o + ['quote' => 'q1', 'amount' => 200, 'feeReserve' => 2,
    'state' => 'UNPAID', 'expiry' => time() + 900, 'unit' => 'sat', 'request' => 'lnbc2u1abc']));
check($reuse->invoke(null, $q([]), 'LNBC2U1ABC', 'sat') !== null, 'a fresh unpaid quote for this invoice is reused');
check($reuse->invoke(null, $q(['expiry' => time() + 30]), 'lnbc2u1abc', 'sat') === null, 'one about to expire is not');
check($reuse->invoke(null, $q(['expiry' => null]), 'lnbc2u1abc', 'sat') === null, 'one without an expiry is not');
check($reuse->invoke(null, $q(['state' => 'PAID']), 'lnbc2u1abc', 'sat') === null, 'a paid one is not');
check($reuse->invoke(null, $q([]), 'lnbc2u1other', 'sat') === null, 'one for another invoice is not');
check($reuse->invoke(null, $q([]), 'lnbc2u1abc', 'eur') === null, 'one in another unit is not');
check($reuse->invoke(null, null, 'lnbc2u1abc', 'sat') === null, 'no quote: ask the mint');

echo "CPS-10: paid, change still at the mint\n";
class PendingChangeWallet extends \Cashu\Wallet {
    public function melt(string $quoteId, array $proofs): array {
        $result = parent::melt($quoteId, $proofs);
        return ['change' => [], 'changeRecoveryPending' => true, 'changeRecoveryError' => 'simulated'] + $result;
    }
}
fresh();
$pending = new PendingChangeWallet($B, 'sat', Database::getDbPath(), $acct);
$pending->loadMint();
$pending->initFromMnemonic($seed);
(new ReflectionProperty(Invoice::class, 'walletCache'))->setValue(null, ["s1|$B|sat" => $pending]);
$result = LightningAddress::meltToBolt11('s1', 'lnbc2u1lnbcmockmelt200', null, ['mint_url' => $B, 'unit' => 'sat']);
check(($result['success'] ?? false) === true && ($result['changePending'] ?? false) === true, 'the payment is reported as sent, change pending');
$row = Transfer::getById($result['transferId']);
check($row['status'] === Transfer::STATUS_PENDING, 'the transfer stays pending');
check((int)$row['fee'] === 0, 'no unrecovered change is recorded as a fee');
check(str_contains((string)$row['detail'], 'on its way back'), 'and it says why, in plain words');
$blocked = false;
try { LightningAddress::assertNoPendingWithdrawal('s1'); } catch (Exception $e) { $blocked = true; }
check($blocked, 'the next withdrawal waits until it resolves');
$reconcile = new ReflectionMethod(BackgroundRunner::class, 'reconcileWithdrawal');
check($reconcile->invoke(null, $row) === 'completed', 'the background run completes it once the mint confirms');
check(Transfer::getById($result['transferId'])['status'] === Transfer::STATUS_COMPLETED, 'and the row is completed');

echo "CPS-09: a donation swaps only what it needs\n";
fresh();
$before = array_map(fn($p) => $p->secret, storageOf($B)->getProofsAsObjects(ProofState::UNSPENT));
check(count($before) >= 5, 'the backup holds several proofs (' . count($before) . ')');
$donation = Donation::sendToDonationSink('s1', 20, ['mint_url' => $B, 'unit' => 'sat']);
check(($donation['success'] ?? false) === true, 'the donation went to the sink (' . ($donation['error'] ?? '') . ')');
$after = array_map(fn($p) => $p->secret, storageOf($B)->getProofsAsObjects(ProofState::UNSPENT));
$untouched = count(array_intersect($before, $after));
check($untouched >= count($before) - 2, "only one or two proofs were swapped ($untouched of " . count($before) . ' untouched)');
check(str_contains((string)@file_get_contents("$dataDir/sink.log"), 'cashu'), 'the sink received a token');

echo "CPS-11: token checks ask the mint the token came from\n";
fresh();
$walletB = Invoice::getWalletForStore('s1', $B, 'sat');
$proofs = $walletB->selectProofsWithFees(storageOf($B)->getProofsAsObjects(ProofState::UNSPENT), 30);
$secrets = array_map(fn($p) => $p->secret, $proofs);
$token = $walletB->serializeToken($proofs);
$xfer = Transfer::open('s1', Transfer::TYPE_TOKEN_EXPORT, \Cashu\Wallet::sumProofs($proofs), 'sat', null,
    implode(',', $secrets), null, $token, $B);
Invoice::markProofsExported('s1', $secrets);
// The backup is removed afterwards; the token still belongs to it.
Database::query("DELETE FROM store_mints WHERE store_id = 's1'");
[, $r] = admin(['action' => 'check_proofs_spent', 'store_id' => 's1', 'secrets' => json_encode($secrets)]);
check(($r['spent'] ?? null) === false && empty($r['error']), 'an unclaimed backup-mint token reads as unclaimed, without an error (' . json_encode($r) . ')');
[, $r] = admin(['action' => 'check_transfer', 'id' => $xfer]);
check(($r['reclaimable'] ?? false) === true, 'check_transfer asks the backup too (' . json_encode($r) . ')');

$other = new \Cashu\Wallet($B, 'sat', "$dataDir/other.sqlite");
$other->loadMint();
$other->generateMnemonic();
$other->receive($token);
[, $r] = admin(['action' => 'check_proofs_spent', 'store_id' => 's1', 'secrets' => json_encode($secrets)]);
check(($r['spent'] ?? null) === true, 'once claimed, the claim is noticed (' . json_encode($r) . ')');
check(Transfer::getById($xfer)['status'] === Transfer::STATUS_COMPLETED, 'and the export is marked completed');

echo "payouts_argon_2026_11: OK\n";
