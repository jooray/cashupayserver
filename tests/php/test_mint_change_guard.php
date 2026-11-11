<?php
/**
 * MC-2: admin.php update_store / remove_backup_mint money guards, driven in a
 * subprocess (admin.php echoes and exits) against two php -S mint stubs.
 *
 *   - mint change on a funded store without confirm_stranding → 409
 *     mint_change_strands_funds carrying the balance; with confirm_stranding=1
 *     → 200 and the old namespace keeps its proofs;
 *   - while a melt journal / an exported token / a pending fee melt is
 *     outstanding → 409 mint_change_blocked (even with confirm);
 *   - the same change with the old mint kept as a backup → not blocked;
 *   - seed_phrase in the POST is ignored (never written);
 *   - remove_backup_mint on a backup holding UNSPENT proofs → 409
 *     backup_mint_not_empty; on an empty one → 200.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;

$mintA = start_mock_mint();
$mintB = start_mock_mint();
$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('77', 32);

Database::insert('users', [
    'id' => 'user_mc_admin', 'username' => 'admin', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'role' => 'admin', 'created_at' => Database::timestamp(),
]);
$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/admin_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require %s;
require %s;
require %s;
Auth::initSession();
$_SESSION['user_id'] = 'user_mc_admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['login_time'] = time();
$_POST = json_decode((string)getenv('T_POST'), true);
$_POST['csrf_token'] = Auth::generateCsrfToken();
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP,
    var_export($root . '/includes/database.php', true),
    var_export($root . '/includes/config.php', true),
    var_export($root . '/includes/auth.php', true),
    var_export($root . '/admin.php', true)
));

function admin_post(array $post): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir) . ' T_POST=' . escapeshellarg(json_encode($post));
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}
function raw_store(string $id): array {
    return Database::fetchOne("SELECT mint_url, mint_unit, seed_phrase FROM stores WHERE id = ?", [$id]);
}

make_store('s1', $mintA['url']);
$seed = raw_store('s1')['seed_phrase'];
$sa = new WalletStorage(Database::getDbPath(), $mintA['url'], 'sat');
$sa->storeProofs([new Proof($ks, 32, 'a-1', $C), new Proof($ks, 32, 'a-2', $C)]);

// --- funded store: warn, then allow with consent ------------------------------------
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url']]);
assert_eq(409, $res['status'], 'mint change on a funded store is refused: ' . json_encode($res['json']));
assert_eq('mint_change_strands_funds', $res['json']['error'] ?? null, 'error code');
assert_eq(64, $res['json']['balance'] ?? null, 'balance that would be stranded');
assert_eq($mintA['url'], raw_store('s1')['mint_url'], 'mint unchanged');

// seed_phrase in the POST is ignored entirely.
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'name' => 'renamed', 'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about']);
assert_eq(200, $res['status'], 'name update passes: ' . json_encode($res['json']));
assert_eq($seed, raw_store('s1')['seed_phrase'], 'posted seed_phrase is never written');

// --- blockers: exported token ----------------------------------------------------------
Invoice::markProofsExported('s1', ['a-2'], $mintA['url'], 'sat');
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url'], 'confirm_stranding' => '1']);
assert_eq(409, $res['status'], 'blocked while an exported token is outstanding');
assert_eq('mint_change_blocked', $res['json']['error'] ?? null, 'error code: ' . json_encode($res['json']));
assert_true(count($res['json']['blockers'] ?? []) >= 1, 'blockers listed');
$sa->updateProofsState(['a-2'], ProofState::UNSPENT);

// --- blockers: melt journal -----------------------------------------------------------------
$sa->savePendingOperation('melt:q1', 'melt', ['counter_start' => 0, 'keyset_id' => $ks, 'amounts' => [], 'input_secrets' => ['a-1']]);
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url'], 'confirm_stranding' => '1']);
assert_eq('mint_change_blocked', $res['json']['error'] ?? null, 'blocked while a melt journal is open');
// … unless the old mint stays on as a backup.
add_backup_mint('s1', $mintA['url']);
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url']]);
assert_eq(409, $res['status'], 'retained-as-backup still needs the stranding confirmation');
assert_eq('mint_change_strands_funds', $res['json']['error'] ?? null, 'not the hard block when the old mint is a backup');
Database::delete('store_mints', 'store_id = ?', ['s1']);
$sa->deletePendingOperation('melt:q1');

// --- blockers: pending fee melt / open quote ----------------------------------------------
Database::insert('melts', ['store_id' => 's1', 'amount_sats' => 10, 'network_fee_sats' => 0, 'destination' => 'x', 'status' => 'pending', 'melt_quote_id' => 'mq', 'created_at' => time()]);
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url'], 'confirm_stranding' => '1']);
assert_eq('mint_change_blocked', $res['json']['error'] ?? null, 'blocked by a pending fee melt');
Database::delete('melts', 'melt_quote_id = ?', ['mq']);
Database::insert('invoices', ['id' => 'inv_open', 'store_id' => 's1', 'status' => 'New', 'amount' => '1', 'currency' => 'sat', 'amount_sats' => 1, 'quote_id' => 'qq', 'created_at' => time(), 'expiration_time' => time() + 900]);
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url'], 'confirm_stranding' => '1']);
assert_eq('mint_change_blocked', $res['json']['error'] ?? null, 'blocked by an open quote');
Database::update('invoices', ['status' => 'Expired'], 'id = ?', ['inv_open']);

// --- consented change goes through; funds stay in the old namespace ---------------------
$res = admin_post(['action' => 'update_store', 'store_id' => 's1', 'mint_url' => $mintB['url'], 'confirm_stranding' => '1']);
assert_eq(200, $res['status'], 'consented change succeeds: ' . json_encode($res['json']));
assert_eq($mintB['url'], raw_store('s1')['mint_url'], 'mint changed');
assert_eq(64, $sa->getBalance(), 'old namespace keeps its proofs');
assert_eq(0, Invoice::getBalance('s1'), 'new primary is empty');
assert_eq(1, count(Invoice::scanStrandedNamespaces()), 'old namespace now shows as stranded');

// --- remove_backup_mint guard -----------------------------------------------------------------
add_backup_mint('s1', $mintA['url']); // funded
$funded = Database::fetchOne("SELECT id FROM store_mints WHERE store_id = 's1' AND mint_url = ?", [$mintA['url']]);
$res = admin_post(['action' => 'remove_backup_mint', 'id' => (string)$funded['id']]);
assert_eq(409, $res['status'], 'removing a funded backup is refused: ' . json_encode($res['json']));
assert_eq('backup_mint_not_empty', $res['json']['error'] ?? null, 'error code');
assert_not_null(Database::fetchOne("SELECT id FROM store_mints WHERE id = ?", [$funded['id']]), 'row kept');

add_backup_mint('s1', 'https://empty-backup.example');
$empty = Database::fetchOne("SELECT id FROM store_mints WHERE mint_url = 'https://empty-backup.example'");
$res = admin_post(['action' => 'remove_backup_mint', 'id' => (string)$empty['id']]);
assert_eq(200, $res['status'], 'removing an empty backup succeeds: ' . json_encode($res['json']));
assert_null(Database::fetchOne("SELECT id FROM store_mints WHERE id = ?", [$empty['id']]), 'row removed');

fwrite(STDERR, "test_mint_change_guard: all assertions passed\n");
