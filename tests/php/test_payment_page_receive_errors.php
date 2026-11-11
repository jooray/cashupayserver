<?php
/**
 * Payer-facing receive-error banner on the payment page.
 *
 * An invoice whose receive_errors column carries sanitized create-time
 * direct-receive failures renders the warning banner inside the pending
 * state, naming the wallet type and the fixed reason; the ?json=1 poll
 * mirrors the entries. Invoices without failures render no banner, junk in
 * the column is ignored, and reason text is HTML-escaped (the page is
 * public, so even a hand-edited DB row must not inject markup).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

make_store('store_re');

$base = [
    'store_id' => 'store_re',
    'status' => 'New',
    'amount' => '21000',
    'currency' => 'sat',
    'amount_sats' => 21000,
    'bolt11' => 'lnbc210u1pexamplebolt11string',
    'payment_rail' => 'mint',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
];

Database::insert('invoices', $base + [
    'id' => 'inv_re_banner',
    'receive_errors' => json_encode([
        ['type' => 'nwc', 'reason' => 'no response from the wallet (timed out)'],
        ['type' => 'lnurl', 'reason' => 'the Lightning address service could not be reached'],
    ]),
]);
Database::insert('invoices', $base + ['id' => 'inv_re_clean']);
Database::insert('invoices', $base + ['id' => 'inv_re_junk', 'receive_errors' => 'not-json{']);
Database::insert('invoices', $base + [
    'id' => 'inv_re_xss',
    'receive_errors' => json_encode([
        ['type' => 'nwc', 'reason' => '<script>alert(1)</script>'],
    ]),
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/payment_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = getenv('T_INVOICE');
if (getenv('T_JSON') === '1') { $_GET['json'] = '1'; }
require %s;
PHP, var_export($root . '/payment.php', true)));

/** Run payment.php in a subprocess; returns its full output. */
function run_payment_page(string $invoiceId, bool $json = false): string {
    global $dataDir, $runner;
    $env = sprintf(
        'T_DATA_DIR=%s T_INVOICE=%s T_JSON=%s',
        escapeshellarg($dataDir), escapeshellarg($invoiceId), $json ? '1' : '0'
    );
    return (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');
}

// --- failures recorded: banner names the wallet types + fixed reasons --------
$html = run_payment_page('inv_re_banner');
assert_true(str_contains($html, 'class="receive-errors"'), 'banner rendered');
assert_true(str_contains($html, 'wallet connections had a problem'), 'banner title present');
assert_true(str_contains($html, '<strong>NWC wallet</strong>: no response from the wallet (timed out)'),
    'NWC entry shows type label + reason');
assert_true(str_contains($html, '<strong>Lightning address</strong>: the Lightning address service could not be reached'),
    'LNURL entry shows type label + reason');
assert_true(str_contains($html, 'You can still pay using the option'),
    'banner reassures the payer the invoice is still payable');

// --- JSON poll mirrors the entries ------------------------------------------
$json = json_decode(run_payment_page('inv_re_banner', true), true);
assert_true(is_array($json['receiveErrors'] ?? null), 'json poll carries receiveErrors');
assert_eq(2, count($json['receiveErrors']), 'both entries in the json payload');
assert_eq('nwc', $json['receiveErrors'][0]['type'], 'json entry keeps the type');

// --- clean invoice: no banner ------------------------------------------------
$html = run_payment_page('inv_re_clean');
assert_false(str_contains($html, 'class="receive-errors"'), 'no failures -> no banner');
$json = json_decode(run_payment_page('inv_re_clean', true), true);
assert_eq([], $json['receiveErrors'], 'json poll returns empty list for clean invoice');

// --- junk column content is ignored ------------------------------------------
$html = run_payment_page('inv_re_junk');
assert_false(str_contains($html, 'class="receive-errors"'), 'unparseable column -> no banner');

// --- reasons are escaped ------------------------------------------------------
$html = run_payment_page('inv_re_xss');
assert_false(str_contains($html, '<script>alert(1)</script>'), 'reason text is HTML-escaped');
assert_true(str_contains($html, '&lt;script&gt;'), 'escaped entities rendered instead');

// --- site-wide suppress switch hides the banner and the JSON mirror -----------
require_once dirname(__DIR__, 2) . '/app/includes/admin_log.php';
Config::set(AdminLog::SUPPRESS_CONFIG_KEY, true);
$html = run_payment_page('inv_re_banner');
assert_false(str_contains($html, 'class="receive-errors"'), 'suppressed: no banner');
$json = json_decode(run_payment_page('inv_re_banner', true), true);
assert_eq([], $json['receiveErrors'], 'suppressed: json mirror empty');
Config::set(AdminLog::SUPPRESS_CONFIG_KEY, false);
$html = run_payment_page('inv_re_banner');
assert_true(str_contains($html, 'class="receive-errors"'), 'switch off: banner returns');

echo "test_payment_page_receive_errors: ok\n";
