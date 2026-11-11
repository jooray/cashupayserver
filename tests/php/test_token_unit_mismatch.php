<?php
/**
 * A Cashu token denominated in a different unit than the store's mint unit must
 * be REJECTED before any amount is credited. The token's face value is otherwise
 * taken raw and credited 1:1 (msat = 1000x, usd = arbitrary), corrupting the
 * exposure cap, the invoice cover check, and the recorded settlement.
 *
 * Reuses the NUT-12 "Carol" DLEQ vector (same as test_offline_cashu) so the
 * matching-unit case is a genuinely valid token, and serializes a second token
 * under a 'usd' wallet for the mismatch case. The unit gate sits BEFORE DLEQ
 * verification, so the usd token is rejected without needing usd keyset keys.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

require_once dirname(__DIR__, 2) . '/app/includes/offline_cashu.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\Proof;
use Cashu\DLEQWallet;

// ---- normUnit unit-tests ----------------------------------------------------
assert_eq('sat', OfflineCashu::normUnit(null), 'null -> sat');
assert_eq('sat', OfflineCashu::normUnit(''), 'empty -> sat');
assert_eq('sat', OfflineCashu::normUnit('  SAT '), 'SAT trims+lowercases -> sat');
assert_eq('msat', OfflineCashu::normUnit('MSAT'), 'MSAT -> msat');
assert_eq('usd', OfflineCashu::normUnit('usd'), 'usd -> usd');

// ---- Fixtures: the NUT-12 Carol vector (sat) --------------------------------
$MINT = 'https://mint.invalid';
$KEYSET = '00882760bfa2eb41';
$A      = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
$secret = 'daf4dd00a2b68a0858a80450f52c8a7d2ccf87d375e43e216e0c571f089f63e9';
$C      = '024369d2d22a80ecf78f3937da9d5f30c1b9f74f0c32684d583cca0fa6a61cdcfc';
$e      = 'b31e58ac6527f34975ffab13e70a48b6d2b0d35abc4b03f0151f09ee1a9763d4';
$s      = '8fbae004c59e754d71df67e392b6ae4e29293113ddc2ec86592a0431d16306d8';
$r      = 'a6d13fcd7a18442e6076f5e1e7c887ad5de40a019824bdfa9fe740d302e8d861';

$store = 'store_unit';
make_store($store, $MINT, 'sat');          // store unit = sat
$dbPath = Database::getDbPath();
$storage = new WalletStorage($dbPath, $MINT, 'sat');
$storage->storeKeysetKeys($KEYSET, 'sat', [1 => $A], 0);

OfflineCashu::saveSettings($store, ['enabled' => true, 'policy' => 'dleq', 'max_per_tx' => 0, 'max_outstanding' => 0]);
OfflineCashu::seedAllowlistFromStoreMints($store);

$proof = new Proof($KEYSET, 1, $secret, $C, new DLEQWallet($e, $s, $r));

// Matching unit (sat): passes the unit gate and verifies fully.
$satToken = (new Wallet($MINT, 'sat', $dbPath))->serializeToken([$proof], 'v4', null, true);
$ok = OfflineCashu::verifyToken($store, $satToken);
assert_true($ok['ok'], 'sat token verifies for sat store: ' . ($ok['reason'] ?? ''));

// Mismatched unit (usd token, sat store): rejected at the unit gate.
$usdToken = (new Wallet($MINT, 'usd', $dbPath))->serializeToken([$proof], 'v4', null, true);
$bad = OfflineCashu::verifyToken($store, $usdToken);
assert_eq(false, $bad['ok'], 'usd token rejected for sat store');
assert_true(stripos((string)$bad['reason'], 'unit') !== false,
    'rejection reason mentions unit: ' . (string)$bad['reason']);

// acceptOffline must surface the same rejection (no Provisional invoice created).
$res = OfflineCashu::acceptOffline($store, $usdToken);
assert_eq(false, $res['ok'], 'acceptOffline rejects unit mismatch');
$count = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE store_id = ?", [$store])['c'] ?? 0);
assert_eq(0, $count, 'no invoice recorded for a unit-mismatched token');

echo "PASS test_token_unit_mismatch\n";
exit(0);
