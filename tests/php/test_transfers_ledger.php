<?php
/**
 * DO-7: the cron reconciler for exported tokens
 * (Invoice::reconcilePendingTokenTransfers) and the cron task keys.
 *
 *   - a pending export whose proofs the mint still reports UNSPENT stays
 *     pending (and its EXPORTED proofs are never returned to the balance);
 *   - once the mint reports every proof SPENT the row is completed exactly
 *     once and the proofs are marked SPENT locally;
 *   - a partly-spent token stays pending;
 *   - a stranded_export row is reconciled against the mint it names;
 *   - the throttle (updated_at) keeps a just-checked row from being re-asked;
 *   - cron.php reports the new task keys (recover_late_paid, reconcile_transfers).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/background.php';

use Cashu\Proof;
use Cashu\WalletStorage;

$Y = fn(string $s) => \Cashu\Crypto::computeY($s);
$mint = start_mock_mint(['default_state' => 'UNSPENT']);
make_store('s1', $mint['url']);
$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('88', 32);
$storage = new WalletStorage(Database::getDbPath(), $mint['url'], 'sat');
$storage->storeProofs([
    new Proof($ks, 4, 'x-1', $C), new Proof($ks, 8, 'x-2', $C),
    new Proof($ks, 2, 'y-1', $C), new Proof($ks, 1, 'y-2', $C),
    new Proof($ks, 16, 'z-1', $C),
]);
Invoice::markProofsExported('s1', ['x-1', 'x-2'], $mint['url'], 'sat');
Invoice::markProofsExported('s1', ['y-1', 'y-2'], $mint['url'], 'sat');
Invoice::markProofsExported('s1', ['z-1'], $mint['url'], 'sat');
$tx = Transfer::open('s1', Transfer::TYPE_TOKEN_EXPORT, 12, 'sat', ['x-1', 'x-2'], 'cashuBx', $mint['url']);
$ty = Transfer::open('s1', Transfer::TYPE_TOKEN_EXPORT, 3, 'sat', ['y-1', 'y-2'], 'cashuBy', $mint['url']);
$tz = Transfer::open('s1', Transfer::TYPE_STRANDED_EXPORT, 16, 'sat', ['z-1'], 'cashuBz', $mint['url']);

$res = Invoice::reconcilePendingTokenTransfers(20, 0);
assert_eq(3, $res['checked'], 'all three pending rows checked');
assert_eq(0, $res['completed'], 'nothing completed while the mint says UNSPENT');
assert_eq(0, $res['errors'], 'no errors: ' . json_encode($res));
assert_eq('EXPORTED', $storage->getProofsStatesBySecrets(['x-1'])['x-1'], 'UNSPENT at the mint never un-exports a proof');
assert_eq('pending', Transfer::getById($tx)['status'], 'still pending');

// Throttle: a row checked just now is not re-asked.
mock_mint_clear_requests($mint['dir']);
$res = Invoice::reconcilePendingTokenTransfers(20, 300);
assert_eq(0, $res['checked'], 'rows checked moments ago are skipped');
assert_eq([], array_filter(mock_mint_requests($mint['dir']), fn($l) => str_contains($l, 'checkstate')), 'no checkstate sent');

// x fully spent, y partly, z (stranded) fully.
mock_mint_update_state($mint['dir'], ['states' => [$Y('x-1') => 'SPENT', $Y('x-2') => 'SPENT', $Y('y-1') => 'SPENT', $Y('z-1') => 'SPENT']]);
$res = Invoice::reconcilePendingTokenTransfers(20, 0);
assert_eq(2, $res['completed'], 'x and z completed: ' . json_encode($res));
assert_eq('completed', Transfer::getById($tx)['status'], 'x completed');
assert_eq('pending', Transfer::getById($ty)['status'], 'partly spent y stays pending');
assert_eq('completed', Transfer::getById($tz)['status'], 'stranded export completed against its mint');
$st = $storage->getProofsStatesBySecrets(['x-1', 'x-2', 'y-1', 'y-2', 'z-1']);
assert_eq('SPENT', $st['x-1'], 'x-1 spent locally');
assert_eq('SPENT', $st['x-2'], 'x-2 spent locally');
assert_eq('EXPORTED', $st['y-1'], 'partly spent token: nothing changes locally until it resolves');
assert_eq('SPENT', $st['z-1'], 'z spent locally');

// Completed rows are never revisited.
$res = Invoice::reconcilePendingTokenTransfers(20, 0);
assert_eq(1, $res['checked'], 'only y is still pending');

// cron.php reports the new tasks.
$GLOBALS['test_done'] = false;
register_shutdown_function(function () {
    if (empty($GLOBALS['test_done'])) {
        fwrite(STDERR, "test exited before completion (a cron.php refusal path fired)\n");
        exit(1);
    }
});
$cronKey = (string)Config::get('cron_key');
assert_true($cronKey !== '', 'cron_key seeded');
$_GET = ['key' => $cronKey];
$_SERVER['REQUEST_URI'] = '/cron.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require dirname(__DIR__, 2) . '/app/cron.php';
$out = ob_get_clean();
$decoded = json_decode((string)substr($out, (int)strpos($out, '{')), true);
assert_true(is_array($decoded) && isset($decoded['tasks']), 'cron output is JSON: ' . substr($out, 0, 200));
assert_true(array_key_exists('recover_late_paid', $decoded['tasks']), 'recover_late_paid task ran: ' . json_encode(array_keys($decoded['tasks'])));
assert_true(array_key_exists('reconcile_transfers', $decoded['tasks']), 'reconcile_transfers task ran');
assert_true(array_key_exists('cleanup_invoices', $decoded['tasks']), 'cleanup task ran');
assert_true(array_key_exists('expire_old_invoices', $decoded['tasks']), 'bulk expiry task ran');
$GLOBALS['test_done'] = true;

fwrite(STDERR, "test_transfers_ledger: all assertions passed\n");
