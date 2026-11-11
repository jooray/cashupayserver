<?php
/**
 * admin.php action=enable_store_mint — enabling a Cashu mint on an existing
 * store from the store settings (the greyed-out Cashu section's dialog).
 *
 * Contract:
 *   - store without mint + reachable mint → mint_url/mint_unit saved,
 *     primary_mint_source stamped 'manual', and when the store had no wallet
 *     seed one is generated and returned ONCE (seedPhrase) for backup;
 *   - a store that already has a seed keeps it (prior balances must stay
 *     recoverable) and the response carries seedPhrase=null;
 *   - the strict-no-mint-fallback pin (set by the mintless setup path so a
 *     mint can't be acquired without an explicit decision) is cleared —
 *     this dialog is that decision; the mint stays the lowest-priority path
 *     because invoices only fall back to it after every Lightning
 *     destination has failed;
 *   - refused when the store is already mint-configured, when the mint is
 *     unreachable (nothing persisted), when the mint doesn't offer the
 *     picked unit, on an unknown unit, and on a missing store.
 *
 * admin.php echoes and exits, so each call runs in a subprocess whose driver
 * fakes a logged-in admin session + CSRF token before requiring admin.php
 * (same shape as test_admin_notifications_capture_save.php). The "mint" is a
 * php -S stub serving GET /v1/info, which is all testMintConnection calls.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// ---------------------------------------------------------------- mock mint

function start_mint_stub(): array {
    $dir = sys_get_temp_dir() . '/mint_stub_' . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    $router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($path === '/__mint_stub') {
    echo json_encode(['dir' => __DIR__]);
    exit;
}
if ($path === '/v1/info') {
    echo json_encode(['name' => 'stub mint', 'version' => 'stub/0.1', 'nuts' => new stdClass()]);
    exit;
}
if ($path === '/v1/keysets') {
    // sat + usd offered; eur deliberately absent (unit-support refusal case).
    echo json_encode(['keysets' => [
        ['id' => '00stubsat0000001', 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
        ['id' => '00stubusd0000001', 'unit' => 'usd', 'active' => true, 'input_fee_ppk' => 0],
    ]]);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'not found']);
PHP;
    file_put_contents($dir . '/router.php', $router);

    $base = 28600 + (getmypid() % 900);
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $port = $base + $attempt;
        $pid = (int) shell_exec(sprintf(
            '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), $port,
            escapeshellarg($dir), escapeshellarg($dir . '/router.php')
        ));
        register_shutdown_function(static function () use ($pid) {
            @posix_kill($pid, 9);
        });
        for ($i = 0; $i < 40; $i++) {
            $resp = @file_get_contents("http://127.0.0.1:{$port}/__mint_stub");
            if ($resp !== false) {
                $health = json_decode($resp, true);
                if (is_array($health) && ($health['dir'] ?? '') === $dir) {
                    return [$pid, $port];
                }
                break; // some other server owns this port — try the next one
            }
            usleep(50000);
        }
        @posix_kill($pid, 9);
    }
    fail('mint stub failed to start on any port');
}

[$stubPid, $stubPort] = start_mint_stub();
$stubUrl = "http://127.0.0.1:{$stubPort}";

// ------------------------------------------------------------ admin runner

Database::insert('users', [
    'id'            => 'user_mint_admin',
    'username'      => 'admin',
    'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'          => 'admin',
    'created_at'    => Database::timestamp(),
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/admin_mint_runner.php';
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
$_SESSION['user_id'] = 'user_mint_admin';
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

/** Run one admin.php POST action in a subprocess. @return array{status:int, json:?array} */
function admin_post(array $post): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_POST=' . escapeshellarg(json_encode($post));
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>>' . escapeshellarg($dataDir . '/runner-stderr.log'), $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

/** A stores row read raw so this process's caches can't mask subprocess writes. */
function raw_store(string $id): ?array {
    return Database::fetchOne(
        "SELECT mint_url, mint_unit, seed_phrase, primary_mint_source,"
        . " strict_no_mint_fallback FROM stores WHERE id = ?",
        [$id]
    );
}

// --- mintless, seedless store: mint enabled + fresh seed returned once -------

Database::insert('stores', [
    'id' => 'store_seedless',
    'name' => 'seedless',
    'created_at' => Database::timestamp(),
]);
// Mirror the mintless setup path: strict-no-mint-fallback pinned ON.
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';
SwapsConfig::setStoreStrictOverride('store_seedless', SwapsConfig::FORCE_ON);
$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_seedless',
    'mint_url' => $stubUrl,
    'mint_unit' => 'sat',
]);
assert_eq(200, $res['status'], 'seedless enable succeeds: ' . json_encode($res['json']));
assert_eq(true, $res['json']['success'] ?? null, 'seedless enable reports success');
assert_eq(true, $res['json']['isConfigured'] ?? null, 'store reports configured after enable');
$seed = $res['json']['seedPhrase'] ?? null;
assert_true(is_string($seed) && count(explode(' ', $seed)) >= 12,
    'a fresh wallet seed is generated and returned once: ' . json_encode($seed));
$row = raw_store('store_seedless');
assert_eq($stubUrl, $row['mint_url'], 'mint_url persisted');
assert_eq('sat', $row['mint_unit'], 'mint_unit persisted');
assert_eq($seed, $row['seed_phrase'], 'returned seed is the stored seed');
assert_eq('manual', $row['primary_mint_source'], 'primary_mint_source stamped manual');
assert_eq(0, (int)$row['strict_no_mint_fallback'],
    'the mintless-setup strict pin is cleared so invoices can fall back to the mint');

// --- already-configured store: refused, nothing changed ----------------------

$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_seedless',
    'mint_url' => $stubUrl,
    'mint_unit' => 'sat',
]);
assert_eq(400, $res['status'], 'second enable on the same store is refused');
assert_true(stripos((string)($res['json']['error'] ?? ''), 'already has a Cashu mint') !== false,
    'refusal names the reason: ' . json_encode($res['json']));

// --- store with an existing seed but no mint: seed kept, not re-shown --------

make_store('store_seeded', null, 'sat'); // harness default seed, mint_url NULL
$keptSeed = raw_store('store_seeded')['seed_phrase'];
$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_seeded',
    'mint_url' => $stubUrl,
    'mint_unit' => 'usd',
]);
assert_eq(200, $res['status'], 'seeded enable succeeds: ' . json_encode($res['json']));
assert_true(array_key_exists('seedPhrase', $res['json']) && $res['json']['seedPhrase'] === null,
    'existing seed is never echoed back: ' . json_encode($res['json']));
$row = raw_store('store_seeded');
assert_eq($keptSeed, $row['seed_phrase'], 'existing seed kept (prior balance stays recoverable)');
assert_eq($stubUrl, $row['mint_url'], 'mint_url persisted on seeded store');
assert_eq('usd', $row['mint_unit'], 'unit persisted on seeded store');

// --- unreachable mint: refused, nothing persisted ----------------------------

Database::insert('stores', [
    'id' => 'store_unreachable',
    'name' => 'unreachable',
    'created_at' => Database::timestamp(),
]);
$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_unreachable',
    // Closed port on localhost — fails fast without leaving the machine.
    'mint_url' => 'http://127.0.0.1:1/',
    'mint_unit' => 'sat',
]);
assert_eq(400, $res['status'], 'unreachable mint refused');
assert_true(stripos((string)($res['json']['error'] ?? ''), 'Cannot connect') !== false,
    'refusal mentions the connection: ' . json_encode($res['json']));
$row = raw_store('store_unreachable');
assert_null($row['mint_url'], 'nothing persisted after a failed probe');
assert_null($row['seed_phrase'], 'no seed generated after a failed probe');

// --- mint doesn't offer the picked unit: refused ------------------------------

$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_unreachable',
    'mint_url' => $stubUrl,
    'mint_unit' => 'eur', // stub keysets offer sat + usd only
]);
assert_eq(400, $res['status'], 'unsupported-by-mint unit refused');
assert_true(stripos((string)($res['json']['error'] ?? ''), "doesn't offer") !== false,
    'refusal says the mint lacks the unit: ' . json_encode($res['json']));
$row = raw_store('store_unreachable');
assert_null($row['mint_url'], 'nothing persisted after a unit-support refusal');

// --- validation refusals ------------------------------------------------------

$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_unreachable',
    'mint_url' => $stubUrl,
    'mint_unit' => 'chf',
]);
assert_eq(400, $res['status'], 'unknown unit refused');
assert_true(stripos((string)($res['json']['error'] ?? ''), 'unit') !== false,
    'unit refusal names the field: ' . json_encode($res['json']));

$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_unreachable',
    'mint_url' => '',
    'mint_unit' => 'sat',
]);
assert_eq(400, $res['status'], 'empty mint URL refused');

$res = admin_post([
    'action' => 'enable_store_mint',
    'store_id' => 'store_missing',
    'mint_url' => $stubUrl,
    'mint_unit' => 'sat',
]);
assert_eq(400, $res['status'], 'unknown store refused');
assert_true(stripos((string)($res['json']['error'] ?? ''), 'not found') !== false,
    'unknown store refusal: ' . json_encode($res['json']));

echo "test_enable_store_mint: OK\n";
