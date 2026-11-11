<?php
/**
 * api-keys/authorize.php store chooser — which stores a pairing request can
 * be approved for.
 *
 * The chooser used to list only stores with mint_url + seed_phrase set, so a
 * merchant whose store runs without a mint (the wizard's "No thanks, run
 * without mints" answer — Lightning-only or on-chain-only) was told
 * "No stores found" and could not pair the WordPress plugin at all. Pinned
 * here:
 *
 *   - every store is listed, whatever rails it has;
 *   - a store with no payment rail at all is flagged inline
 *     ("(no payment methods yet)", data-has-rail="0") and the warning box
 *     explaining that checkouts will fail ships with the page;
 *   - rail-having stores (mint or Lightning-only) carry no flag;
 *   - "No stores found" renders only when the server truly has no stores.
 *
 * authorize.php renders and exits, so each request runs in a subprocess with
 * a pre-seeded admin session against the parent's database.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/authorize_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/api-keys/authorize.php';
$_SERVER['REQUEST_URI'] = '/api-keys/authorize.php?applicationName=Test+Shop';
$_SERVER['QUERY_STRING'] = 'applicationName=Test+Shop';
$_GET = ['applicationName' => 'Test Shop'];
// Pre-seed a signed-in admin session: start the session before authorize.php
// so Auth::initSession() adopts it instead of starting its own.
ini_set('session.save_path', getenv('T_DATA_DIR'));
session_start();
$_SESSION['user_id'] = 'admin';
$_SESSION['user_role'] = 'admin';
require %s;
PHP, var_export($root . '/api-keys/authorize.php', true)));

/** Render the authorize page once in a subprocess; returns the HTML. */
function authorize_page(): string {
    global $dataDir, $runner;
    $out = [];
    $rc = 0;
    exec(
        'T_DATA_DIR=' . escapeshellarg($dataDir) . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>/dev/null',
        $out, $rc
    );
    assert_eq(0, $rc, 'authorize runner exit code');
    return implode("\n", $out);
}

/** The rendered <option> tag for a store id, or null when not listed. */
function option_for(string $html, string $storeId): ?string {
    if (!preg_match(
        '/<option value="' . preg_quote($storeId, '/') . '"[^>]*>.*?<\/option>/s',
        $html, $m
    )) {
        return null;
    }
    return $m[0];
}

// --- No stores at all: the create-a-store-first empty state ------------------
$html = authorize_page();
assert_true(str_contains($html, 'No stores found'), 'empty server shows the no-stores state');
assert_true(!str_contains($html, '<option'), 'and no store options');

// --- Three rail shapes -------------------------------------------------------
// A mint-configured store, a Lightning-only store (no mint, no seed — the
// shape the old mint-column filter wrongly hid), and a zero-rail store.
make_store('s_mint', 'https://mint.example.test');
Database::insert('stores', ['id' => 's_ln', 'name' => 'ln only', 'created_at' => Database::timestamp()]);
Database::insert('store_ln_addresses', [
    'store_id' => 's_ln', 'position' => 0,
    'address' => 'tips@ln.example.test', 'type' => StoreLnAddresses::TYPE_LNADDRESS,
]);
Database::insert('stores', ['id' => 's_none', 'name' => 'no rails', 'created_at' => Database::timestamp()]);

$html = authorize_page();

$mintOpt = option_for($html, 's_mint');
assert_not_null($mintOpt, 'mint store is listed');
assert_true(str_contains($mintOpt, 'data-has-rail="1"'), 'mint store marked has-rail');
assert_false(str_contains($mintOpt, 'no payment methods'), 'mint store carries no flag');

$lnOpt = option_for($html, 's_ln');
assert_not_null($lnOpt, 'Lightning-only store is listed (the old filter hid it)');
assert_true(str_contains($lnOpt, 'data-has-rail="1"'), 'Lightning-only store marked has-rail');
assert_false(str_contains($lnOpt, 'no payment methods'), 'Lightning-only store carries no flag');

$noneOpt = option_for($html, 's_none');
assert_not_null($noneOpt, 'zero-rail store is still listed (pair now, add a rail after)');
assert_true(str_contains($noneOpt, 'data-has-rail="0"'), 'zero-rail store marked rail-less');
assert_true(str_contains($noneOpt, '(no payment methods yet)'), 'zero-rail store flagged inline');

assert_true(str_contains($html, 'id="no-rail-warning"'), 'the no-rail warning box ships with the chooser');
assert_false(str_contains($html, 'No stores found'), 'no empty state once stores exist');

echo "OK\n";
