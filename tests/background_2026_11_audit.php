<?php
/**
 * Background, outbox and health fixes from the 2026-11 audit (AUDIT-KIMIK31-VERIFICATION).
 *
 *  - A webhook response is read up to 64 KiB, never buffered whole; a refusal keeps a
 *    short readable prefix; the attempt is counted when the row is claimed.
 *  - Cancelled deliveries are not "undelivered": not counted, not blocking, pruned.
 *    listUndelivered() / requeueForStore() for the redelivery view.
 *  - A run that died inside a task backs that task off instead of wedging every run.
 *  - Poll failures reach the payment health (FAIL after PAYMENT_PATH_FAILURES).
 *  - Poll queue order: Processing first, then the longest-waiting.
 *  - Withdrawal reconciliation keeps a cursor and skips a mint that just failed to answer.
 *  - A donation whose proofs never left the balance is closed, not re-posted.
 *  - Unpaid payment requests are purged a day after they expire.
 *
 * Everything is local: a mock mint and a webhook receiver under `php -S`.
 *
 * Run: php tests/background_2026_11_audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-bg-audit-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/background_runner.php';
require_once $root . '/includes/webhook_sender.php';
require_once $root . '/includes/alerts.php';
require_once $root . '/includes/health.php';
require_once $root . '/includes/store_safety.php';
require_once $root . '/includes/payment_request.php';

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

function startServer(string $router, array $env = []): string {
    global $procs, $root;
    $port = testFreePort();
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $root, $env + getenv()
    );
    testWaitForServer($proc, $port);
    $procs[] = $proc;
    return "http://127.0.0.1:$port";
}

$mint = startServer("$root/tests/mock_mint_router.php", ['MOCK_MINT_STATE' => "$dataDir/mint.json"]);
$hookRouter = "$dataDir/hook.php";
file_put_contents($hookRouter, <<<'PHP'
<?php
$mode = $_GET['mode'] ?? 'ok';
if ($mode === 'big') {
    header('Content-Type: text/plain');
    for ($i = 0; $i < 256; $i++) { echo str_repeat('x', 1048576); @flush(); }
    exit;
}
if ($mode === 'refuse') {
    http_response_code(400);
    echo "<html><body><h1>Invalid signature</h1>\n\x01 for this order</body></html>";
    exit;
}
echo 'ok';
PHP);
$hook = startServer($hookRouter);

$deadPort = testFreePort();
$deadMint = "http://127.0.0.1:$deadPort";

Database::initialize();
Config::set('setup_complete', true);
Config::set('allow_private_mints', true);
Config::set('update_check_enabled', false);
// The receiver is this installation's own origin, so a 127.0.0.1 target is allowed
// without opting in to private targets (which would hide the response prefix).
Config::set('base_url', $hook);
Database::insert('stores', [
    'id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mint, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore('s1', false);

function addWebhook(string $id, string $url): void {
    Database::insert('webhooks', [
        'id' => $id, 'store_id' => 's1', 'url' => $url, 'secret' => 'sekret',
        'events' => '[]', 'enabled' => 1, 'created_at' => Database::timestamp(),
    ]);
}
function fakeInvoice(string $id, array $metadata = []): array {
    return ['id' => $id, 'store_id' => 's1', 'status' => 'Settled', 'amount' => '10', 'currency' => 'SAT',
        'created_at' => time(), 'expiration_time' => time() + 600, 'metadata' => json_encode($metadata)];
}
function delivery(string $invoiceId): array {
    return Database::fetchOne('SELECT * FROM webhook_deliveries WHERE invoice_id = ?', [$invoiceId]);
}

echo "A huge webhook response is cut off, not buffered\n";
ini_set('memory_limit', '96M');
addWebhook('wh_big', "$hook/?mode=big");
WebhookSender::fireEvent('s1', 'InvoiceSettled', fakeInvoice('inv_big'));
$t0 = microtime(true);
WebhookSender::deliverPending();
$row = delivery('inv_big');
check($row['delivered_at'] !== null && (int)$row['status_code'] === 200,
    'a 256 MiB answer with HTTP 200 counts as delivered (and the process survived it)');
check((int)$row['attempts'] === 1 && microtime(true) - $t0 < 8, 'one attempt, cut off quickly');
Database::query("UPDATE webhooks SET enabled = 0 WHERE id = 'wh_big'");

echo "A refusal keeps a short readable prefix\n";
addWebhook('wh_refuse', "$hook/?mode=refuse");
WebhookSender::fireEvent('s1', 'InvoiceSettled', fakeInvoice('inv_refuse', ['orderId' => '1042']));
WebhookSender::deliverPending();
$row = delivery('inv_refuse');
check($row['delivered_at'] === null && (int)$row['attempts'] === 1, 'not delivered, one attempt');
check(str_starts_with((string)$row['response'], 'HTTP 400: Invalid signature')
    && !str_contains((string)$row['response'], '<') && !str_contains((string)$row['response'], "\x01"),
    'the stored response says what the shop said, as plain text: ' . $row['response']);
check((int)$row['next_attempt_at'] > time(), 'and the retry is scheduled');

echo "The attempt is counted at claim time\n";
Database::query("UPDATE webhook_deliveries SET next_attempt_at = 0 WHERE invoice_id = 'inv_refuse'");
$claim = new ReflectionMethod(WebhookSender::class, 'claimNextDelivery');
$claimed = $claim->invoke(null);
check($claimed !== null && $claimed['invoice_id'] === 'inv_refuse', 'the row is claimed');
// The process "dies" here: the outcome is never written.
$row = delivery('inv_refuse');
check((int)$row['attempts'] === 2, 'the attempt already counts');
check((int)$row['next_attempt_at'] >= time() + 60, 'and the next try is already backed off (' . ($row['next_attempt_at'] - time()) . 's)');
Database::query("UPDATE webhook_deliveries SET leased_until = 0 WHERE invoice_id = 'inv_refuse'");
check($claim->invoke(null) === null, 'after the lease runs out it is not first in line again right away');

echo "The redelivery view\n";
$list = WebhookSender::listUndelivered('s1');
check(count($list) === 1 && $list[0]['invoiceId'] === 'inv_refuse' && $list[0]['orderId'] === '1042'
    && $list[0]['event'] === 'InvoiceSettled' && $list[0]['attempts'] === 2 && !$list[0]['gaveUp']
    && $list[0]['nextAttemptAt'] !== null, 'lists the undelivered event with its order id, attempts and next retry');
check(WebhookSender::listUndelivered('other') === [], 'scoped to the store');
check(WebhookSender::countUndelivered('s1') === 1 && WebhookSender::countUndelivered(null, 5) === 0, 'counts');
check(!WebhookSender::requeueForStore($list[0]['deliveryId'], 'other'), 'another store cannot requeue it');
check(WebhookSender::requeueForStore($list[0]['deliveryId'], 's1'), '"Tell the shop again" requeues it');
$row = delivery('inv_refuse');
check((int)$row['attempts'] === 0 && (int)$row['next_attempt_at'] === 0, 'with a fresh retry budget');

echo "Cancelled deliveries are not owed\n";
Database::query("UPDATE webhook_deliveries SET attempts = 80 WHERE invoice_id = 'inv_refuse'");
check(WebhookSender::listUndelivered('s1')[0]['gaveUp'] === true
    && WebhookSender::listUndelivered('s1')[0]['nextAttemptAt'] === null, 'a row out of attempts shows as given up');
check(count(array_filter(Alerts::problems(), fn($p) => str_starts_with($p['key'], 'webhooks:'))) === 1,
    'given up on: the alert is raised');
check(count(array_filter(StoreSafety::deletionBlockers('s1'), fn($b) => str_contains($b, 'webhook event'))) === 1,
    'and deleting the store is blocked');
WebhookSender::cancelPending('wh_refuse');
check(WebhookSender::countUndelivered() === 0 && WebhookSender::listUndelivered() === [], 'cancelled: not counted or listed');
check(count(array_filter(Alerts::problems(), fn($p) => str_starts_with($p['key'], 'webhooks:'))) === 0,
    'no permanent "your shop has not been told" alert');
check(count(array_filter(StoreSafety::deletionBlockers('s1'), fn($b) => str_contains($b, 'webhook event'))) === 0,
    'and no deletion blocker');
$pdo = Database::getInstance();
$pdo->exec('BEGIN');
$ins = $pdo->prepare("INSERT INTO webhook_deliveries (id, webhook_id, invoice_id, event_type, payload, created_at, attempts, delivered_at)
                      VALUES (?, 'wh_refuse', ?, 'InvoiceSettled', '{}', ?, 1, ?)");
// Newer than the cancelled row, so it is the oldest history row.
for ($i = 0; $i < 1000; $i++) { $ins->execute(["del_h$i", "inv_h$i", time() + 1 + $i, time() + 1 + $i]); }
$pdo->exec('COMMIT');
$cleanup = new ReflectionMethod(BackgroundRunner::class, 'cleanupWebhooks');
$cleanup->invoke(null);
check(Database::fetchOne("SELECT id FROM webhook_deliveries WHERE invoice_id = 'inv_refuse'") === null,
    'cleanup prunes cancelled rows as history');
check((int)Database::fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries WHERE delivered_at IS NOT NULL OR signing_secret IS NULL')['n'] === 1000,
    'keeping the newest 1000 history rows');
Database::query("DELETE FROM webhook_deliveries");
Database::query("UPDATE webhooks SET enabled = 0");

echo "A run that died inside a task backs that task off\n";
Config::set('cron_running_task', 'clean_cache');
$run = BackgroundRunner::run(300);
Config::clearCache();
$health = Config::get('cron_task_health');
check(($run['interrupted'] ?? null) === 'clean_cache', 'the interrupted task is recognised');
check(str_starts_with((string)$run['tasks']['clean_cache'], 'skipped')
    && (int)($health['clean_cache']['retry_after'] ?? 0) > time(), 'it is skipped and backed off');
check(count(array_filter(BackgroundRunner::status()['failing'], fn($f) => $f['task'] === 'clean_cache')) === 1,
    'and shown as failing');
check((string)Config::get('cron_running_task') === '', 'a run that finishes clears the marker');

Config::set('cron_task_health', []);
Config::set('cron_running_task', 'webhook_outbox');
$run = BackgroundRunner::run(1);
check(str_starts_with((string)$run['tasks']['webhook_outbox'], 'skipped')
    && isset($run['tasks']['poll_quotes']) && !str_starts_with((string)json_encode($run['tasks']['poll_quotes']), '"skipped'),
    'an always-run task that killed the run does not stop the others');
Config::set('cron_task_health', []);

echo "Poll queue order: Processing first, then the longest-waiting\n";
$now = time();
$ins = fn(string $id, string $status, int $created, ?int $polled) => Database::insert('invoices', [
    'id' => $id, 'store_id' => 's1', 'status' => $status, 'quote_id' => "q_$id", 'mint_url' => $deadMint,
    'mint_unit' => 'sat', 'amount' => '1', 'currency' => 'SAT', 'expiration_time' => $now + 3600,
    'created_at' => $created, 'last_polled_at' => $polled,
]);
$ins('o_recent_new', 'New', $now - 10, null);
$ins('o_old_polled', 'New', $now - 3000, $now - 600);
$ins('o_processing', 'Processing', $now - 5, $now - 60);
$order = [];
for ($i = 0; $i < 3; $i++) {
    $before = array_column(Database::fetchAll('SELECT id, last_polled_at FROM invoices'), 'last_polled_at', 'id');
    Invoice::pollPendingQuotes(0, 1);
    foreach (Database::fetchAll('SELECT id, last_polled_at FROM invoices') as $r) {
        if ($r['last_polled_at'] !== $before[$r['id']]) {
            $order[] = $r['id'];
            // Out of the way for the next pass.
            Database::update('invoices', ['last_polled_at' => time() + 3600], 'id = ?', [$r['id']]);
        }
    }
}
check($order === ['o_processing', 'o_old_polled', 'o_recent_new'], 'order: ' . implode(', ', $order));

echo "Poll failures reach the payment health\n";
$result = (function () { Database::query('UPDATE invoices SET last_polled_at = NULL'); return Invoice::pollPendingQuotes(0); })();
check($result === ['polled' => 3, 'errors' => 3], 'the poll reports its errors: ' . json_encode($result));
check(BackgroundRunner::failureOf($result) !== null, 'which the runner reads as a failure');
for ($i = 0; $i < Health::PAYMENT_PATH_FAILURES; $i++) {
    Database::query('UPDATE invoices SET last_polled_at = NULL');
    Config::set('cron_lease_until', '0');
    BackgroundRunner::run(1);
}
Config::clearCache();
$h = Config::get('cron_task_health')['poll_quotes'] ?? [];
check((int)($h['failures'] ?? 0) >= Health::PAYMENT_PATH_FAILURES, 'poll_quotes failed ' . ($h['failures'] ?? 0) . ' times in a row');
$statuses = array_column(Health::report(time() + Health::PAYMENT_PATH_FAILING_SECONDS + 1)['checks'], 'status', 'name');
check($statuses['payments'] === Health::FAIL, 'and Health says payments FAIL');
// A run with nothing due learns nothing: the failure streak is kept.
Database::query('UPDATE invoices SET last_polled_at = ?', [time()]);
Config::set('cron_lease_until', '0');
BackgroundRunner::run(1);
Config::clearCache();
check(isset(Config::get('cron_task_health')['poll_quotes']['last_error']), 'an idle poll does not reset the failure streak');
Database::query("DELETE FROM invoices");
Config::set('cron_task_health', []);

echo "Withdrawal reconciliation: cursor, and a mint that does not answer is skipped\n";
$t = $now - 1000;
foreach (['w1', 'w2', 'w3'] as $i => $id) {
    Database::insert('transfers', [
        'id' => "xfer_$id", 'store_id' => 's1', 'type' => Transfer::TYPE_LIGHTNING, 'amount' => 10, 'fee' => 0,
        'unit' => 'sat', 'mint_url' => $deadMint, 'status' => Transfer::STATUS_PENDING,
        'reference' => Transfer::meltReference("quote$id", []), 'created_at' => $t + $i, 'updated_at' => $t + $i,
    ]);
}
$reconcile = new ReflectionMethod(BackgroundRunner::class, 'reconcileTransfers');
$r = $reconcile->invoke(null);
check($r['errors'] === 1 && $r['deferred'] === 2, 'one timeout-like failure, the other rows at that mint wait: ' . json_encode($r));
Config::clearCache();
check(Config::get('reconcile_withdrawal_cursor') === null, 'every row was visited, so the cursor wraps to the start');
Config::set('reconcile_withdrawal_cursor', [$t, 'xfer_w1']);
$r = $reconcile->invoke(null);
Config::clearCache();
check($r['checked'] === 1 && $r['deferred'] === 1,
    'the next run starts after the cursor (w2), defers w3 and stops back at w1: ' . json_encode($r));
Database::query("DELETE FROM transfers");

echo "A donation whose proofs never left the balance is closed, not re-posted\n";
Database::insert('cashu_proofs', [
    'wallet_id' => 'w', 'keyset_id' => '00aa', 'amount' => 2, 'secret' => 'donation-secret-1',
    'C' => '02' . str_repeat('11', 32), 'state' => 'UNSPENT', 'created_at' => $now,
]);
Database::insert('transfers', [
    'id' => 'xfer_don', 'store_id' => 's1', 'type' => Transfer::TYPE_DONATION, 'amount' => 2, 'fee' => 0,
    'unit' => 'sat', 'mint_url' => $mint, 'status' => Transfer::STATUS_PENDING, 'reference' => 'donation-secret-1',
    'token' => 'cashuBnot-a-real-token', 'created_at' => $now - 700, 'updated_at' => $now - 700,
]);
$r = $reconcile->invoke(null);
$don = Transfer::getById('xfer_don');
check($don['status'] === Transfer::STATUS_FAILED && str_contains((string)$don['detail'], 'never sent'),
    'marked failed: the money stayed in the balance (' . $don['detail'] . ')');
Database::query("DELETE FROM transfers");

echo "Unpaid payment requests go a day after they expire\n";
$pr = PaymentRequest::create('s1', 10, 'sat', $mint);
Database::update('payment_requests', ['expires_at' => time() - 86400 - 10, 'created_at' => time() - 86400 - 3700], 'id = ?', [$pr['id']]);
$fresh = PaymentRequest::create('s1', 10, 'sat', $mint);
Database::update('payment_requests', ['expires_at' => time() - 600], 'id = ?', [$fresh['id']]);
PaymentRequest::cleanup();
check(PaymentRequest::getById($pr['id']) === null, 'expired over a day ago: removed');
check(PaymentRequest::getById($fresh['id']) !== null, 'expired ten minutes ago: kept for now');

echo "background_2026_11_audit: OK\n";
