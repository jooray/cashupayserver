<?php
/**
 * NUT-18 payment requests are business records, not just QR codes.
 *
 * Without a persisted request the receiving endpoint can only accept whatever arrives:
 * it cannot check the payment against what was asked for, cannot tell a retry from a
 * second payment, and has nothing to return when its response was lost.
 *
 * Run: php tests/payment_request.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-preq-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/payment_request.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
$now = time();
Database::query(
    "INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, wallet_account_id, created_at)
     VALUES ('store_1','Shop','https://mint.example','sat','seed words','wa_1',?)",
    [$now]
);

// --- A published request records its terms -----------------------------------
$req = PaymentRequest::create('store_1', 1000, 'sat', 'https://mint.example', 'Order 42');
check(strlen($req['id']) === 32, 'the request id is 16 random bytes, not guessable from another request');
check((int)$req['amount'] === 1000 && $req['unit'] === 'sat', 'the amount and unit are fixed at publication');
check($req['status'] === PaymentRequest::STATUS_PENDING, 'a new request starts pending');
check((int)$req['expires_at'] > $now, 'the request has an expiry');

$other = PaymentRequest::create('store_1', 1000, 'sat', 'https://mint.example');
check($other['id'] !== $req['id'], 'two requests get different ids');

check(PaymentRequest::create('store_1', 5, 'sat', 'https://mint.example')['id'] !== '', 'small amounts are allowed');
try {
    PaymentRequest::create('store_1', 0, 'sat', 'https://mint.example');
    check(false, 'a zero-amount request is refused');
} catch (Exception $e) {
    check(true, 'a zero-amount request is refused');
}

// --- Paying it is recorded exactly once --------------------------------------
$result = ['success' => true, 'amount' => 1000, 'unit' => 'sat', 'proofs_count' => 3];
check(PaymentRequest::markPaid($req, 1000, $result), 'the first payment is recorded');

$reloaded = PaymentRequest::getById($req['id']);
check($reloaded['status'] === PaymentRequest::STATUS_PAID, 'the request is now paid');
check((int)$reloaded['received_amount'] === 1000, 'the amount actually received is recorded');

// A second delivery of the same request must not credit again.
check(!PaymentRequest::markPaid($reloaded, 1000, $result), 'a second payment for the same request is refused');

// --- A retry after a lost response replays the original answer ---------------
$replay = PaymentRequest::storedResult($reloaded);
check($replay !== null && $replay['amount'] === 1000, 'a paid request replays its original result');
check(PaymentRequest::storedResult($other) === null, 'an unpaid request has nothing to replay');

// --- Expiry -------------------------------------------------------------------
check(!PaymentRequest::isExpired($other), 'a fresh request is not expired');
Database::update('payment_requests', ['expires_at' => $now - 10], 'id = ?', [$other['id']]);
check(PaymentRequest::isExpired(PaymentRequest::getById($other['id'])), 'a request past its expiry is expired');
check(!PaymentRequest::isExpired($reloaded), 'a paid request does not become expired afterwards');

// --- Cleanup keeps paid requests ---------------------------------------------
Database::update('payment_requests', ['created_at' => $now - 30 * 86400], 'id IN (?, ?)', [$req['id'], $other['id']]);
PaymentRequest::cleanup();
check(PaymentRequest::getById($other['id']) === null, 'old unpaid requests are cleaned up');
check(PaymentRequest::getById($req['id']) !== null, 'a paid request is kept so retries still replay');

echo "payment_request: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
