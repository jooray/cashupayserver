<?php
/**
 * Findings from AUDIT-ARGON-NOV2026 on the receiving side and its bookkeeping.
 *
 * CPS-01  A payment request is marked paid only when the swap's outputs are ours, not
 *         merely because its inputs ended SPENT in our wallet (a double-spent token
 *         looks the same under older wallet libraries).
 * REL-01  The background check does not release a claim while receive.php is working
 *         on it.
 * REL-06  A request is paid at the mint it named; a request for a mint the shop
 *         dropped is refused in plain words; a deleted webhook is not requeued.
 * UX-06/8 The request page's status carries a formatted amount.
 * CPS-08  Store deletion recognises wallets in any recorded unit, and an unmatched
 *         in-flight operation blocks it.
 * CPS-11  Proof checks can target the exact mint a transfer came from.
 * CPS-10  A withdrawal completed in the background records its real fee.
 * REL-03  Leftover PENDING proofs are checked on backup mints too.
 *
 * Exercises the real code against the mock mint (and receive.php under PHP's server).
 *
 * Run: php tests/argon_2026_11_receiving.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-argon-rcv-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/payment_request.php';
require_once $root . '/includes/store_safety.php';
require_once $root . '/includes/background_runner.php';
require_once $root . '/includes/locks.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
register_shutdown_function(function () use ($dataDir) {
    global $procs;
    foreach ($procs as $p) { testStopProcess($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

function startServer(array $cmd, array $env): int {
    global $procs, $root;
    $port = testFreePort();
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $root, $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return $port;
}

/** @return array{0:int,1:string} */
function http(string $method, string $url, ?string $body = null): array {
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Content-Type: application/json\r\n", 'content' => $body ?? '',
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
    }
    return [$code, (string)$resp];
}

$mintPort = startServer(['php', '-S', '127.0.0.1:{port}', "$root/tests/mock_mint_router.php"],
    ['MOCK_MINT_STATE' => "$dataDir/mint.json"]);
$mintUrl = "http://127.0.0.1:$mintPort";
$offline = 'http://127.0.0.1:9'; // nothing listens here

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true);
$accountId = Database::generateWalletAccountId();
Database::insert('stores', [
    'id' => 'store_1', 'name' => 'Shop', 'wallet_account_id' => $accountId,
    'mint_url' => $mintUrl, 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore('store_1', false);
$store = Invoice::getWalletForStore('store_1');

$payer = new \Cashu\Wallet($mintUrl, 'sat', "$dataDir/payer.sqlite");
$payer->loadMint();
$payer->initializeNewFromMnemonic(\Cashu\Mnemonic::generate());
$quote = $payer->requestMintQuote(1024);
http('POST', "$mintUrl/__control/pay", json_encode(['quote' => $quote->quote]));
$payer->mint($quote->quote, 1024);

/** Fresh proofs worth $amount from the payer, still spendable by the payer. */
function tokenWorth(\Cashu\Wallet $payer, int $amount): array {
    $unspent = $payer->getStorage()->getProofsAsObjects(\Cashu\ProofState::UNSPENT);
    $send = $payer->split($unspent, $amount)['send'];
    // Handed over: the payer must not select these again.
    $payer->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $send), \Cashu\ProofState::SPENT);
    return $send;
}

// Someone else holding a copy of the token, spending it at the mint directly.
$thief = new \Cashu\Wallet($mintUrl, 'sat', "$dataDir/thief.sqlite");
$thief->loadMint();
$thief->initializeNewFromMnemonic(\Cashu\Mnemonic::generate());

/**
 * What receive.php leaves behind when its swap request timed out: the request claimed
 * by these proofs, and the store wallet's swap journal with the proofs imported PENDING.
 */
function claimAndJournal(\Cashu\Wallet $store, array $proofs): array {
    global $mintUrl;
    $request = PaymentRequest::create('store_1', 20, 'sat', $mintUrl);
    $secrets = array_map(fn($p) => $p->secret, $proofs);
    check(PaymentRequest::claim($request, $secrets) === 'claimed', 'the request is claimed');
    Database::update('payment_requests', ['receiving_since' => time() - 600], 'id = ?', [$request['id']]);
    $net = \Cashu\Wallet::sumProofs($proofs) - $store->calculateFee($proofs);
    $store->getStorage()->preparePendingSpend(
        'swap:' . hash('sha256', implode("\0", $secrets)), 'swap', $proofs,
        $store->getActiveKeysetId(), \Cashu\Wallet::splitAmount($net)
    );
    return [$request, $secrets, $net];
}

echo "CPS-01: a token spent elsewhere while our swap was in doubt does not pay the request\n";
$stolen = tokenWorth($payer, 21);
[$req, $secrets] = claimAndJournal($store, $stolen);
// The sender spends the same proofs at the mint before our swap is retried.
$thief->swap($stolen, \Cashu\Wallet::splitAmount(\Cashu\Wallet::sumProofs($stolen) - $thief->calculateFee($stolen)));
$balanceBefore = $store->getBalance();
Invoice::recoverPendingWalletOperations();
$row = PaymentRequest::getById($req['id']);
check(PaymentRequest::swapPlan($row) !== null, 'the swap plan was recorded before recovery settled the journal');
PaymentRequest::reconcileReceiving();
$row = PaymentRequest::getById($req['id']);
check($row['status'] !== 'paid', "the request is not marked paid (status {$row['status']})");
check($row['status'] === 'pending', 'it is payable again');
check($store->getBalance() === $balanceBefore, 'and the store was credited nothing');

echo "CPS-01: the old library's leftovers (inputs kept SPENT, no outputs) are not a payment\n";
// Older resolveSwapJournal() kept imported inputs as SPENT rows after a double spend.
// Rebuild exactly that state, independent of the library version under test.
$stolen2 = tokenWorth($payer, 21);
[$req2, $secrets2] = claimAndJournal($store, $stolen2);
PaymentRequest::rememberSwapPlans($store);
$opId = 'swap:' . hash('sha256', implode("\0", $secrets2));
$store->getStorage()->deletePendingOperation($opId);
$store->getStorage()->updateProofsState($secrets2, \Cashu\ProofState::SPENT);
$row2 = PaymentRequest::getById($req2['id']);
$state = PaymentRequest::receiptState($store, $secrets2, (int)$row2['receiving_since'], PaymentRequest::swapPlan($row2));
check($state['state'] !== 'paid', "receiptState does not say paid ({$state['state']})");
check(PaymentRequest::receiptState($store, $secrets2, (int)$row2['receiving_since'])['state'] === 'paid',
    'without the recorded plan the same rows would read as paid (why recovery records it first)');
PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($req2['id'])['status'] === 'pending', 'and the background check frees the request');

echo "CPS-01: a genuine swap finished by the background recovery still pays the request\n";
$good = tokenWorth($payer, 21);
[$req3, , $net3] = claimAndJournal($store, $good);
$balanceBefore = $store->getBalance();
Invoice::recoverPendingWalletOperations();
PaymentRequest::reconcileReceiving();
$row3 = PaymentRequest::getById($req3['id']);
check($row3['status'] === 'paid', "the request is paid (status {$row3['status']})");
check((int)$row3['received_amount'] === $net3, "for what the swap credited ({$row3['received_amount']} of $net3)");
check($store->getBalance() === $balanceBefore + $net3, 'and the store holds it');

echo "REL-01: a claim is not released while receive.php holds the store's receive lock\n";
$busy = PaymentRequest::create('store_1', 21, 'sat', $mintUrl);
PaymentRequest::claim($busy, ['not-journaled-yet']);
$lock = cashupay_acquire_lock('receive_store_1');
check($lock !== null, 'the test holds the receive lock');
PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($busy['id'])['status'] === 'receiving', 'the claim stays while the receive is in progress');
cashupay_release_lock($lock);
PaymentRequest::reconcileReceiving();
check(PaymentRequest::getById($busy['id'])['status'] === 'pending', 'and is released once the receive is over');

echo "REL-06 / UX-06: receive.php pays a request at its own mint and answers in plain words\n";
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' require ' . var_export("$root/receive.php", true) . ';');
$port = startServer(['php', '-S', '127.0.0.1:{port}', $router], []);
Config::set('base_url', "http://127.0.0.1:$port");
$base = "http://127.0.0.1:$port/receive.php";

$stale = PaymentRequest::create('store_1', 21, 'sat', 'https://dropped-mint.example');
$proofs = tokenWorth($payer, 21);
[$code, $body] = http('POST', "$base?request_id={$stale['id']}", json_encode([
    'id' => $stale['id'], 'mint' => $mintUrl, 'unit' => 'sat',
    'proofs' => array_map(fn($p) => $p->toArray(true), $proofs),
]));
check($code === 400 && str_contains($body, 'out of date'), "a request for a dropped mint is refused (HTTP $code: " . substr($body, 0, 120) . ')');
check(PaymentRequest::getById($stale['id'])['status'] === 'pending', 'without claiming it');

$paid = PaymentRequest::create('store_1', 21, 'sat', $mintUrl);
PaymentRequest::markPaid($paid, 20, ['success' => true]);
[, $body] = http('GET', "$base?status=1&request_id={$paid['id']}");
$status = json_decode($body, true);
check(($status['receivedFormatted'] ?? null) === Config::unitFor('sat')->format(20),
    'the status carries the formatted amount (' . ($status['receivedFormatted'] ?? 'none') . ')');
check(Config::unitFor('usd')->format(150) !== '150 USD', '150 cents are not shown as 150 dollars');
[, $html] = http('GET', "$base?store_id=store_1&amount=21");
check(str_contains($html, 'function fallbackCopy') && str_contains($html, 'receivedFormatted'),
    'the page copies without the clipboard API and shows the formatted amount');

echo "REL-06: a deleted webhook's delivery is not requeued\n";
Database::insert('webhooks', ['id' => 'wh_1', 'store_id' => 'store_1', 'url' => 'https://shop.example/hook',
    'secret' => 's', 'events' => '[]', 'enabled' => 1, 'created_at' => time()]);
Database::insert('webhook_deliveries', ['id' => 'd_1', 'webhook_id' => 'wh_1', 'event_type' => 'InvoiceSettled',
    'payload' => '{}', 'delivered_at' => time(), 'created_at' => time()]);
Database::update('webhooks', ['deleted_at' => time()], 'id = ?', ['wh_1']);
WebhookSender::requeueDelivery('d_1');
check(Database::fetchOne('SELECT delivered_at FROM webhook_deliveries WHERE id = ?', ['d_1'])['delivered_at'] !== null,
    'the delivery stays delivered');

echo "CPS-08: deletion safety knows every recorded unit\n";
$gbp = new \Cashu\WalletStorage(Database::getDbPath(), $mintUrl, 'gbp', $accountId);
$gbp->savePendingOperation('mint:q1', 'mint', ['amounts' => [1]]);
$blockers = StoreSafety::deletionBlockers('store_1');
check((bool)array_filter($blockers, fn($b) => str_contains($b, 'previous mint') && str_contains($b, '127.0.0.1')),
    'an in-flight operation of a former gbp wallet is matched to its mint (' . implode(' | ', $blockers) . ')');
$gbp->deletePendingOperation('mint:q1');
$mystery = new \Cashu\WalletStorage(Database::getDbPath(), 'https://never-recorded.example', 'xyz', $accountId);
$mystery->savePendingOperation('mint:q2', 'mint', ['amounts' => [1]]);
$blockers = StoreSafety::deletionBlockers('store_1');
check((bool)array_filter($blockers, fn($b) => str_contains($b, 'unfinished payment on an earlier mint')),
    'an unmatched wallet with only an in-flight operation still blocks deletion');
$mystery->deletePendingOperation('mint:q2');
check(in_array('jpy', StoreSafety::candidateUnits(), true) && in_array('usdc', StoreSafety::candidateUnits(), true),
    'the unit list is not limited to five');

echo "CPS-11: proof checks can name the mint a transfer came from\n";
$exported = tokenWorth($payer, 8);
$store->getStorage()->storeProofs($exported);
$store->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $exported), \Cashu\ProofState::EXPORTED);
$exportedSecrets = array_map(fn($p) => $p->secret, $exported);
// The store has since switched its primary mint; the old one is no longer an account.
Database::update('stores', ['mint_url' => $offline], 'id = ?', ['store_1']);
Config::clearCache();
$threw = false;
try {
    Invoice::checkProofStatesBySecrets('store_1', $exportedSecrets);
} catch (Throwable $e) {
    $threw = true;
}
check($threw, 'without a mint the lookup falls back to the new (offline) primary');
$states = Invoice::checkProofStatesBySecrets('store_1', $exportedSecrets, $mintUrl, 'sat');
check(array_values(array_unique($states)) === ['UNSPENT'], 'with the transfer\'s mint, the right mint answers');
Invoice::markProofsSpent('store_1', $exportedSecrets, $mintUrl, 'sat');
$after = $store->getStorage()->getProofsStatesBySecrets($exportedSecrets);
check(array_values(array_unique($after)) === ['SPENT'], 'and marking them spent reaches that wallet');

echo "REL-03: PENDING proofs on a backup mint are checked while the primary is offline\n";
Config::addStoreBackupMint('store_1', $mintUrl, 'sat');
$left = tokenWorth($payer, 4);
$store->getStorage()->storeProofs($left);
$store->getStorage()->updateProofsState(array_map(fn($p) => $p->secret, $left), \Cashu\ProofState::PENDING);
$result = Invoice::checkPendingProofs('store_1');
check($result['checked'] === count($left) && $result['pending'] === count($left),
    'the backup mint was asked about them (' . json_encode($result) . ')');

echo "CPS-10: a withdrawal finished in the background records its real fee\n";
$inputs = tokenWorth($payer, 16);
$store->getStorage()->storeProofs($inputs);
$row = ['fee' => 0, 'amount' => 10, 'reference' => json_encode([
    'quote' => 'q', 'inputs' => array_map(fn($p) => $p->secret, $inputs)])];
$fee = new ReflectionMethod(BackgroundRunner::class, 'paidMeltFee');
$withChange = new \Cashu\MeltQuote('q', 10, 4, 'PAID', null, null, [['amount' => 2], ['amount' => 1]]);
check($fee->invoke(null, $row, $withChange, $store) === 3, '16 in, 10 paid, 3 back: fee 3');
$noChangeInfo = new \Cashu\MeltQuote('q', 10, 4, 'PAID');
check($fee->invoke(null, $row, $noChangeInfo, $store) === 0, 'unknown change keeps the recorded fee');

echo "argon_2026_11_receiving: OK\n";
