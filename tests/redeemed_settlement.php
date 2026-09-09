<?php
/**
 * When a recipient cashes in an exported token, the export screen sees it at the mint and
 * turns green. The ledger row has to be settled at that same moment — otherwise closing
 * the modal shows a dashboard still calling that very token "not cashed in yet", which is
 * what an operator actually hit.
 *
 * Transfer::findPendingBySecrets() is the link between the proofs the mint reports spent
 * and the row that recorded handing them over.
 *
 * Run: php tests/redeemed_settlement.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-settle-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/transfer.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Database::insert('stores', [
    'id' => 'store_a', 'name' => 'A', 'mint_url' => 'https://m.example',
    'mint_unit' => 'sat', 'wallet_account_id' => 'wa_a', 'created_at' => Database::timestamp(),
]);
Database::insert('stores', [
    'id' => 'store_b', 'name' => 'B', 'mint_url' => 'https://m.example',
    'mint_unit' => 'sat', 'wallet_account_id' => 'wa_b', 'created_at' => Database::timestamp(),
]);

$exportId = Transfer::open('store_a', Transfer::TYPE_TOKEN_EXPORT, 18, 'sat', 'Exported', 's1,s2', null, 'cashuTOKEN');
$otherId  = Transfer::open('store_a', Transfer::TYPE_TOKEN_EXPORT, 5, 'sat', 'Exported', 's3', null, 'cashuOTHER');

echo "Matching a redeemed token to its ledger row\n";
$found = Transfer::findPendingBySecrets('store_a', ['s1', 's2']);
check($found !== null && $found['id'] === $exportId, 'the export is found by its proof secrets');

$found = Transfer::findPendingBySecrets('store_a', ['s2', 's1']);
check($found !== null && $found['id'] === $exportId, 'secret order does not matter — the mint may answer in any order');

check(Transfer::findPendingBySecrets('store_a', ['s3'])['id'] === $otherId,
    'a different token matches its own row, not the first pending one');

echo "Refusing to settle the wrong row\n";
check(Transfer::findPendingBySecrets('store_a', ['s1']) === null,
    'a partial match settles nothing — half a token redeemed is not a completed transfer');
check(Transfer::findPendingBySecrets('store_a', ['s1', 's2', 's9']) === null,
    'extra secrets do not match either');
check(Transfer::findPendingBySecrets('store_b', ['s1', 's2']) === null,
    'another store never settles this store\'s transfer');
check(Transfer::findPendingBySecrets('store_a', []) === null, 'no secrets, no match');
check(Transfer::findPendingBySecrets('store_a', ['', '  ']) === null, 'blank secrets do not match');

echo "Settling it, the way the export screen now does\n";
Transfer::complete($exportId, 18);
check(Transfer::getById($exportId)['status'] === Transfer::STATUS_COMPLETED, 'the transfer is completed');
check(Transfer::findPendingBySecrets('store_a', ['s1', 's2']) === null,
    'and is no longer pending, so the dashboard stops calling it unclaimed');
check(Transfer::findPendingBySecrets('store_a', ['s3'])['id'] === $otherId,
    'the still-outstanding token is untouched');

echo "\nAll redeemed-settlement checks passed.\n";

foreach (glob($dataDir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dataDir);
