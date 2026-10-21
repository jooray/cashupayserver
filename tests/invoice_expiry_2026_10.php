<?php
/**
 * A `New` invoice is not expired on the local clock alone (2026-10 audit, Obs 1).
 *
 * A customer who paid just before expiry used to get InvoiceExpired and then, once the
 * late-payment recovery found the money, InvoiceSettled: the shop cancelled an order
 * that was paid. Now the pollers ask the mint once after expiry and expire only when it
 * says "unpaid"; the bulk sweep expires only invoices a post-expiry poll saw unpaid, or
 * that are more than an hour past expiry. The 30-day sweep (expire_old_invoices) goes
 * through the same path, so it emits InvoiceExpired as well.
 *
 * Uses a local mock mint (tests/mock_mint_router.php); no network, no real funds.
 *
 * Run: php tests/invoice_expiry_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-expiry-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/background_runner.php';

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

/** Event types queued for the shop about one invoice, oldest first. */
function events(string $invoiceId): array {
    return array_column(Database::fetchAll(
        "SELECT event_type FROM webhook_deliveries WHERE invoice_id = ? ORDER BY created_at, rowid",
        [$invoiceId]
    ), 'event_type');
}

function expireAt(string $invoiceId, int $at): void {
    Database::update('invoices', ['expiration_time' => $at], 'id = ?', [$invoiceId]);
}

Database::initialize();
Config::set('setup_complete', '1');
// The mock mint listens on 127.0.0.1; the connect guard refuses that unless allowed.
Config::set('allow_private_mints', true);
Database::insert('stores', [
    'id' => 's1', 'name' => 's1', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mint, 'mint_unit' => 'sat',
    'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp(),
]);
Invoice::initializeWalletForStore('s1', false);
Database::insert('webhooks', [
    'id' => 'wh_1', 'store_id' => 's1', 'url' => 'https://shop.example.com/hook', 'secret' => 's',
    'events' => json_encode(['InvoiceExpired', 'InvoiceSettled']), 'enabled' => 1,
    'created_at' => Database::timestamp(),
]);

echo "Paid just before expiry: settled, never expired\n";
$paid = Invoice::create('s1', ['amount' => 21, 'currency' => 'sat']);
pay($mint, $paid['quote_id']);
expireAt($paid['id'], time() - 10);
check(Invoice::markExpiredInvoices() === 0, 'the bulk sweep leaves a just-expired invoice to the pollers');
check(Invoice::getById($paid['id'])['status'] === 'New', 'so it is still New');
Invoice::pollSingleQuote($paid['id'], true);
$row = Invoice::getById($paid['id']);
check($row['status'] === 'Settled', 'the checkout poll asks the mint and settles it (got ' . $row['status'] . ')');
check(!in_array('InvoiceExpired', events($paid['id']), true), 'and the shop never hears InvoiceExpired');

echo "Same, through the cron batch poller\n";
$paid2 = Invoice::create('s1', ['amount' => 13, 'currency' => 'sat']);
pay($mint, $paid2['quote_id']);
expireAt($paid2['id'], time() - 10);
Invoice::pollPendingQuotes(0);
$row = Invoice::getById($paid2['id']);
check($row['status'] === 'Settled', 'the batch poller still polls a just-expired invoice and settles it (got ' . $row['status'] . ')');
check(!in_array('InvoiceExpired', events($paid2['id']), true), 'with no InvoiceExpired');

echo "Unpaid: expired once the mint says so\n";
$unpaid = Invoice::create('s1', ['amount' => 5, 'currency' => 'sat']);
expireAt($unpaid['id'], time() - 10);
Invoice::pollSingleQuote($unpaid['id'], true);
check(Invoice::getById($unpaid['id'])['status'] === 'Expired', 'the checkout poll expires it when the mint says unpaid');
check(events($unpaid['id']) === ['InvoiceExpired'], 'with exactly one InvoiceExpired');

$unpaid2 = Invoice::create('s1', ['amount' => 6, 'currency' => 'sat']);
expireAt($unpaid2['id'], time() - 10);
Invoice::pollPendingQuotes(0);
check(Invoice::getById($unpaid2['id'])['status'] === 'Expired', 'so does the batch poller');
check(events($unpaid2['id']) === ['InvoiceExpired'], 'with exactly one InvoiceExpired');

echo "Mint unreachable: open for an hour past expiry, then expired anyway\n";
$dead = Invoice::create('s1', ['amount' => 7, 'currency' => 'sat']);
Database::update('invoices', ['mint_url' => 'http://127.0.0.1:1', 'expiration_time' => time() - 10], 'id = ?', [$dead['id']]);
Invoice::pollSingleQuote($dead['id'], true);
check(Invoice::getById($dead['id'])['status'] === 'New', 'a failed poll does not expire it');
check(Invoice::markExpiredInvoices() === 0, 'nor does the bulk sweep while within the hour');
expireAt($dead['id'], time() - 3700);
check(Invoice::markExpiredInvoices() === 1, 'more than an hour past expiry the bulk sweep expires it');
check(events($dead['id']) === ['InvoiceExpired'], 'with InvoiceExpired');

echo "A poll after expiry that saw UNPAID lets the bulk sweep expire it\n";
$seen = Invoice::create('s1', ['amount' => 8, 'currency' => 'sat']);
$expiry = time() - 20;
Database::update('invoices', [
    'expiration_time' => $expiry, 'last_polled_at' => $expiry + 5,
    'last_poll_state' => 'UNPAID', 'last_poll_error' => null,
], 'id = ?', [$seen['id']]);
check(Invoice::markExpiredInvoices() === 1, 'it is expired');

echo "The 30-day sweep emits InvoiceExpired too\n";
$old = Invoice::create('s1', ['amount' => 9, 'currency' => 'sat']);
Database::update('invoices', ['created_at' => time() - 31 * 86400, 'expiration_time' => time() + 3600], 'id = ?', [$old['id']]);
$result = BackgroundRunner::tasks()['expire_old_invoices']();
check($result === 'expired 1 old invoices', 'the very old invoice is expired (' . $result . ')');
check(events($old['id']) === ['InvoiceExpired'], 'and the shop is told');

echo "invoice_expiry_2026_10: OK\n";
