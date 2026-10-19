<?php
/**
 * Deleting a store destroys its seed. It must not happen while value is still in flight
 * (OPUS-007, DS41-16, DS41-17, SPB-CORE-008).
 *
 * StoreSafety::deletionBlockers() is the one list both the dashboard and
 * DELETE /api/v1/stores/{id} consult. It must see every wallet account of the store
 * (backup mints too), and ecash left on a previous mint that only this store's wallet
 * account can unlock. The API refuses with 409 and the reasons unless the caller sends
 * {"confirmFundLoss": true}.
 *
 * Run: php tests/api_store_deletion.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

if (($argv[1] ?? '') === '--confirmed-delete') {
    define('CASHUPAY_DATA_DIR', $argv[2]);
}

require_once __DIR__ . '/api_test_harness.php';
require_once dirname(__DIR__) . '/includes/invoice.php';
require_once dirname(__DIR__) . '/includes/store_safety.php';
require_once dirname(__DIR__) . '/includes/api/stores.php';

use Cashu\WalletStorage;

$ownerKey = ['store_id' => 'store_a', 'permissions' => ['btcpay.store.canmodifystoresettings']];

// Child: the confirmed delete ends in exit(), so it runs in its own process.
if (($argv[1] ?? '') === '--confirmed-delete') {
    handleDeleteStore($ownerKey, ['storeId' => 'store_a'], ['confirmFundLoss' => true]);
    exit(3); // not reached
}

function proof(string $walletId, int $amount, string $state): void {
    static $n = 0;
    $n++;
    Database::query(
        "INSERT INTO cashu_proofs (wallet_id, keyset_id, amount, secret, C, state, created_at)
         VALUES (?, '00ab', ?, ?, '02c', ?, ?)",
        [$walletId, $amount, "secret$n", $state, time()]
    );
}

function blockersMention(array $blockers, string $needle): bool {
    foreach ($blockers as $b) {
        if (str_contains($b, $needle)) {
            return true;
        }
    }
    return false;
}

Database::initialize();
$now = Database::timestamp();
foreach (['store_a' => 'wa_a', 'store_b' => 'wa_b'] as $id => $acct) {
    Database::insert('stores', [
        'id' => $id, 'name' => $id, 'mint_url' => 'https://mint-now.example', 'mint_unit' => 'sat',
        'seed_phrase' => 'test seed', 'wallet_account_id' => $acct, 'created_at' => $now,
    ]);
}
Database::insert('store_mints', [
    'store_id' => 'store_a', 'mint_url' => 'https://backup.example', 'unit' => 'sat',
    'priority' => 100, 'enabled' => 1, 'created_at' => $now,
]);

echo "A clean store\n";
check(StoreSafety::deletionBlockers('store_a') === [], 'nothing blocks deleting an idle, empty store');

echo "Every wallet account is inspected, backups included\n";
$backupWallet = WalletStorage::deriveWalletId('https://backup.example', 'sat', 'wa_a');
Database::query(
    "INSERT INTO cashu_pending_operations (id, wallet_id, type, data, created_at) VALUES ('op1', ?, 'melt', '{}', ?)",
    [$backupWallet, time()]
);
proof($backupWallet, 21, 'EXPORTED');
$blockers = StoreSafety::deletionBlockers('store_a');
check(blockersMention($blockers, 'in-flight wallet operation(s) at backup.example'), 'a journal on a backup mint blocks');
check(blockersMention($blockers, '21 sat in exported tokens from backup.example'), 'an unredeemed export on a backup mint blocks');
check(StoreSafety::deletionBlockers('store_b') === [], "another store's backup wallet is not this store's problem");
Database::query("DELETE FROM cashu_pending_operations");
Database::query("DELETE FROM cashu_proofs");

echo "Ecash left on a previous mint (DS41-16)\n";
// An invoice remembers the old mint, as it does after a mint switch.
Database::insert('invoices', [
    'id' => 'inv_old', 'store_id' => 'store_b', 'amount' => '1', 'currency' => 'SAT', 'status' => 'Settled',
    'mint_url' => 'https://mint-old.example', 'created_at' => $now, 'expiration_time' => $now + 60,
]);
proof(WalletStorage::deriveWalletId('https://mint-old.example', 'sat', 'wa_a'), 50, 'UNSPENT');
$blockers = StoreSafety::deletionBlockers('store_a');
check(blockersMention($blockers, '50 sat of ecash left on a previous mint (mint-old.example)'),
    "a stranded namespace derived from this store's account blocks");
check(StoreSafety::deletionBlockers('store_a', false) === [], 'the mint-switch check can leave that out');
check(StoreSafety::deletionBlockers('store_b') === [],
    'the same namespace does not block a store whose account cannot unlock it');
Database::query("DELETE FROM cashu_proofs");

proof(WalletStorage::deriveWalletId('https://forgotten.example', 'sat', 'wa_a'), 7, 'UNSPENT');
check(blockersMention(StoreSafety::deletionBlockers('store_a'), 'could not be matched to a store'),
    'unidentifiable old ecash blocks too: it may be this store\'s, and deletion is the last chance to know');
Database::query("DELETE FROM cashu_proofs");

echo "DELETE /stores/{id}\n";
Database::insert('invoices', [
    'id' => 'inv_open', 'store_id' => 'store_a', 'amount' => '1', 'currency' => 'SAT', 'status' => 'New',
    'quote_id' => 'q1', 'mint_url' => 'https://mint-now.example', 'created_at' => $now, 'expiration_time' => $now + 600,
]);
[$status, $body] = callApi('handleDeleteStore', $ownerKey, ['storeId' => 'store_a']);
check($status === 409, 'a zero-balance store with a payable quote is refused with 409');
check(blockersMention($body['blockers'] ?? [], 'Lightning quote may still be paid'), 'and the reason is listed');
check(Config::getStore('store_a') !== null, 'the store is still there');

[$status] = callApi('handleDeleteStore', $ownerKey, ['storeId' => 'store_a'], ['confirmFundLoss' => 'yes']);
check($status === 409, 'only a literal true confirms the loss');

[$status] = callApi('handleDeleteStore', ['store_id' => 'store_b', 'permissions' => ['*']], ['storeId' => 'store_a']);
check($status === 403, "a key for another store cannot delete this one");

$proc = proc_open([PHP_BINARY, __FILE__, '--confirmed-delete', CASHUPAY_DATA_DIR], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
$code = proc_close($proc);
check($code === 0, 'an explicit confirmFundLoss: true deletes' . ($code !== 0 ? " ($err)" : ''));
check(str_contains($err, 'deleted over the API despite'), 'and the override is logged');
check(Config::getStore('store_a') === null, 'and the store is gone');

echo "All store deletion checks passed.\n";
