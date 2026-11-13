<?php
/**
 * Alerts outside the dashboard (A12, 2026-10-28): the problems worth interrupting the
 * operator for are found, each is sent at most once a day, and the Nostr private
 * message is encrypted the way NIP-44 specifies.
 *
 * Delivery is replaced by a recorder: nothing is emailed and no relay is contacted.
 *
 * Run: php tests/alerts.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-alerts-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/alerts.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Database::insert('stores', [
    'id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'https://mint.example', 'mint_unit' => 'sat', 'created_at' => Database::timestamp(),
]);
$sentMessages = [];
Alerts::$deliverOverride = function (string $subject, string $body) use (&$sentMessages) {
    $sentMessages[] = $body;
    return ['sent' => 1, 'channels' => ['test'], 'errors' => []];
};

echo "Nothing is sent until a channel is set\n";
check(Alerts::run() === 'not configured', 'no email and no npub: nothing to do');

echo "Customers who cannot pay are reported once\n";
Config::set('alert_email', 'owner@example.com');
Config::set('checkout_failures_s1', ['first' => time() - 600, 'last' => time() - 60, 'count' => 4, 'reason' => 'mint', 'detail' => 'x']);
$problems = Alerts::problems();
check(count($problems) === 1 && str_contains($problems[0]['text'], 'cannot pay with Bitcoin'), 'the checkout problem is found');
check(str_contains(Alerts::run(), 'sent 1 alert'), 'and sent');
check(count($sentMessages) === 1 && str_contains($sentMessages[0], 'Yarn Shop'), 'the message names the store');
check(Alerts::run() === 'nothing new', 'the same problem is not sent again the same day');

echo "A single failed checkout is not worth a message\n";
Config::set('checkout_failures_s1', ['first' => time(), 'last' => time(), 'count' => 1, 'reason' => 'mint', 'detail' => 'x']);
check(Alerts::problems() === [], 'one failure alone is not reported');

echo "An exposed database is reported\n";
Config::set('data_exposure_check', ['at' => time(), 'warning' => 'downloadable']);
check(str_contains(Alerts::problems()[0]['text'] ?? '', 'can be downloaded'), 'the exposure is found');

echo "Background tasks that stopped, or never started, are reported\n";
Config::delete('data_exposure_check');
$stale = fn() => array_values(array_filter(Alerts::problems(), fn($p) => str_starts_with($p['key'], 'stale:')));
check($stale() === [], 'before setup is finished: nothing');
Database::query("INSERT INTO config (key, value, created_at, updated_at) VALUES ('setup_complete', 'true', ?, ?)",
    [time() - 60, time() - 60]);
Config::clearCache();
check($stale() === [], 'never ran, but setup was finished a minute ago: not yet');
Database::query("UPDATE config SET updated_at = ? WHERE key = 'setup_complete'", [time() - Alerts::NEVER_RAN_GRACE - 60]);
$p = $stale();
check(count($p) === 1 && str_contains($p[0]['text'], 'never run') && str_contains($p[0]['text'], 'not being marked paid'),
    'never ran 15+ minutes after setup: reported in business terms');
check(!str_contains(Alerts::run(), 'sent') || !str_contains(end($sentMessages), 'never run'),
    'but not sent by the background run (it is running)');

Config::set('cron_last_run', (string)(time() - 7200));
check($stale() === [], 'stale heartbeat on a quiet shop with nothing waiting: not reported');
Database::insert('invoices', ['id' => 'inv_wait', 'store_id' => 's1', 'status' => 'New', 'quote_id' => 'q',
    'amount' => '1', 'currency' => 'SAT', 'expiration_time' => time() + 600, 'created_at' => time() - 3600]);
$p = $stale();
check(count($p) === 1 && str_contains($p[0]['text'], 'have not run since') && str_contains($p[0]['text'], 'withdrawals are paused'),
    'an order waiting longer than that: reported');
Config::set('cron_last_run', (string)time());
check($stale() === [], 'ran just now: fine');
Database::query("DELETE FROM invoices");
Database::query("DELETE FROM config WHERE key = 'setup_complete'");
Config::clearCache();

echo "Nostr: NIP-44 v2 matches the specification's test vector\n";
$conversationKey = hex2bin('c41c775356fd92eadc63ff5a0dc1da211b268cbea22316767095b2871ea1412d');
check(Alerts::nip44EncryptWithNonce($conversationKey, 'a', hex2bin(str_repeat('0', 63) . '1'))
    === 'AgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABee0G5VSK0/9YypIObAtDKfYEAjD35uVkHyB0F4DwrcNaCXlCWZKaArsGrY6M9wnuTMxWfp1RTN9Xga8no+kF5Vsb',
    'the payload is byte-for-byte the published one');
check(Alerts::npubToHex('npub18scydh6yusrkhe67f8uz9mnxxt5znae59ctvhxxpzefkf7xwnx4q0nalja')
    === '3c3046df44e4076be75e49f822ee6632e829f7342e16cb98c1165364f8ce99aa', 'an npub decodes to its key');
$typo = null;
try {
    Alerts::npubToHex('npub18scydh6yusrkhe67f8uz9mnxxt5znae59ctvhxxpzefkf7xwnx4q0nalj4');
} catch (InvalidArgumentException $e) {
    $typo = $e->getMessage();
}
check($typo !== null && str_contains($typo, 'typo'), 'a mistyped npub is refused in plain words');
$wrap = Alerts::giftWrap('3c3046df44e4076be75e49f822ee6632e829f7342e16cb98c1165364f8ce99aa', 'hello');
check($wrap['kind'] === 1059 && $wrap['tags'] === [['p', '3c3046df44e4076be75e49f822ee6632e829f7342e16cb98c1165364f8ce99aa']],
    'the message is a gift wrap addressed to the operator');
check(\Cashu\Secp256k1::schnorrVerify($wrap['pubkey'], hex2bin($wrap['id']), $wrap['sig']), 'and is validly signed');

echo "alerts: OK\n";
