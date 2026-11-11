<?php
/**
 * Store "Payment order" setting (stores.invoice_first_method): which method
 * the customer invoice page shows first when an invoice offers both on-chain
 * and Lightning.
 *
 * Layers covered:
 *   1. Resolution — Invoice::preferredFirstMethod / firstPaymentMethod /
 *      paymentMethodTabOrder: NULL, '', unknown and explicit 'onchain' all
 *      resolve to the historical on-chain-first behaviour; 'lightning' flips
 *      the default and the tab order; a missing preferred method falls back
 *      to the other of the pair, then Cashu, which is never preferred.
 *   2. Persistence — the column defaults to NULL on a fresh store and the
 *      admin save_payment_order action writes exactly 'onchain'|'lightning',
 *      rejecting anything else, and 404s an unknown store.
 *
 * The page-level rendering (tab order + active block) is pinned by
 * test_payment_page_default_method.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

// ---------------------------------------------------------------------------
// 1. Resolution
// ---------------------------------------------------------------------------

foreach ([[], ['invoice_first_method' => null], ['invoice_first_method' => ''],
          ['invoice_first_method' => 'onchain'], ['invoice_first_method' => 'bogus'],
          ['invoice_first_method' => 0]] as $store) {
    assert_eq('onchain', Invoice::preferredFirstMethod($store),
        'preferredFirstMethod -> onchain for ' . json_encode($store));
    assert_eq(['onchain', 'lightning', 'cashu'], Invoice::paymentMethodTabOrder($store),
        'tab order on-chain first for ' . json_encode($store));
}
foreach (['lightning', 'Lightning', ' lightning '] as $v) {
    assert_eq('lightning', Invoice::preferredFirstMethod(['invoice_first_method' => $v]),
        'preferredFirstMethod -> lightning for ' . var_export($v, true));
}
assert_eq(['lightning', 'onchain', 'cashu'],
    Invoice::paymentMethodTabOrder(['invoice_first_method' => 'lightning']),
    'tab order lightning first');

$oc = ['invoice_first_method' => null];
$ln = ['invoice_first_method' => 'lightning'];
// (store, hasOnchain, hasLightning, hasCashu) => expected
$cases = [
    // On-chain first (default)
    [$oc, true,  true,  true,  'onchain',   'default: both + cashu -> onchain'],
    [$oc, true,  true,  false, 'onchain',   'default: both -> onchain'],
    [$oc, false, true,  true,  'lightning', 'default: no on-chain -> lightning'],
    [$oc, true,  false, true,  'onchain',   'default: no lightning -> onchain'],
    [$oc, false, false, true,  'cashu',     'default: cashu only -> cashu'],
    [$oc, false, false, false, 'cashu',     'default: nothing offered -> cashu (pre-setting rule)'],
    // Lightning first
    [$ln, true,  true,  true,  'lightning', 'ln-first: both + cashu -> lightning'],
    [$ln, true,  true,  false, 'lightning', 'ln-first: both -> lightning'],
    [$ln, true,  false, true,  'onchain',   'ln-first: no lightning -> falls back to onchain, not cashu'],
    [$ln, false, true,  true,  'lightning', 'ln-first: no on-chain -> lightning'],
    [$ln, false, false, true,  'cashu',     'ln-first: cashu only -> cashu'],
    [$ln, false, false, false, 'cashu',     'ln-first: nothing offered -> cashu'],
];
foreach ($cases as [$store, $hasOc, $hasLn, $hasCashu, $expected, $msg]) {
    assert_eq($expected, Invoice::firstPaymentMethod($store, $hasOc, $hasLn, $hasCashu), $msg);
}

// ---------------------------------------------------------------------------
// 2. Persistence — column default + admin save_payment_order
// ---------------------------------------------------------------------------

make_store('store_po');
$row = Database::fetchOne("SELECT invoice_first_method FROM stores WHERE id = ?", ['store_po']);
assert_true(array_key_exists('invoice_first_method', $row), 'column exists on a fresh install');
assert_null($row['invoice_first_method'], 'fresh store defaults to NULL (on-chain first)');
assert_eq('onchain', Invoice::preferredFirstMethod(Config::getStore('store_po')),
    'fresh store resolves to on-chain first');

// The session needs a real admin row so anything resolving currentUser works.
Database::insert('users', [
    'id'            => 'user_po_admin',
    'username'      => 'admin',
    'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'          => 'admin',
    'created_at'    => Database::timestamp(),
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/admin_po_runner.php';
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
$_SESSION['user_id'] = 'user_po_admin';
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

/**
 * Run one admin.php POST action in a subprocess.
 *
 * @return array{status:int, json:?array}
 */
function admin_post(array $post): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_POST=' . escapeshellarg(json_encode($post));
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

function stored_first_method(string $storeId): ?string {
    $r = Database::fetchOne("SELECT invoice_first_method FROM stores WHERE id = ?", [$storeId]);
    return $r['invoice_first_method'] ?? null;
}

// Lightning first persists.
$r = admin_post(['action' => 'save_payment_order', 'store_id' => 'store_po', 'first_method' => 'lightning']);
assert_eq(200, $r['status'], 'save lightning -> 200');
assert_true((bool)($r['json']['success'] ?? false), 'save lightning -> success');
assert_eq('lightning', $r['json']['firstMethod'] ?? null, 'response echoes the saved value');
assert_eq('lightning', stored_first_method('store_po'), 'lightning persisted');

// Back to on-chain persists as the explicit value (not NULL) and still
// resolves to on-chain first.
$r = admin_post(['action' => 'save_payment_order', 'store_id' => 'store_po', 'first_method' => 'onchain']);
assert_eq(200, $r['status'], 'save onchain -> 200');
assert_eq('onchain', stored_first_method('store_po'), 'onchain persisted');
assert_eq('onchain', Invoice::preferredFirstMethod(Config::getStore('store_po')),
    'explicit onchain resolves to on-chain first');

// Input is normalised (case/whitespace) before validation.
$r = admin_post(['action' => 'save_payment_order', 'store_id' => 'store_po', 'first_method' => ' Lightning ']);
assert_eq(200, $r['status'], 'save " Lightning " -> 200 (normalised)');
assert_eq('lightning', stored_first_method('store_po'), 'normalised value persisted as lowercase');

// Anything else is refused and leaves the row untouched.
foreach (['cashu', 'bogus', '', null, '1'] as $bad) {
    $post = ['action' => 'save_payment_order', 'store_id' => 'store_po'];
    if ($bad !== null) { $post['first_method'] = $bad; }
    $r = admin_post($post);
    assert_eq(400, $r['status'], 'first_method=' . var_export($bad, true) . ' -> 400');
    assert_true(!empty($r['json']['error']), 'first_method=' . var_export($bad, true) . ' -> error message');
    assert_eq('lightning', stored_first_method('store_po'),
        'first_method=' . var_export($bad, true) . ' leaves the row untouched');
}

// Unknown / missing store.
$r = admin_post(['action' => 'save_payment_order', 'store_id' => 'store_nope', 'first_method' => 'lightning']);
assert_eq(400, $r['status'], 'unknown store -> 400');
assert_eq('Store not found', $r['json']['error'] ?? null, 'unknown store -> error');
$r = admin_post(['action' => 'save_payment_order', 'first_method' => 'lightning']);
assert_eq(400, $r['status'], 'missing store -> 400');

// The setting is per store: a second store is untouched by the first's saves.
make_store('store_po2');
assert_null(stored_first_method('store_po2'), 'other store still NULL');

echo "test_payment_order_setting: ok\n";
