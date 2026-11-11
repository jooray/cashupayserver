<?php
/**
 * Payer email capture gate on the payment page.
 *
 * Deployments with capture disabled (managed single-shop installs by
 * default — the shop platform owns customer emails) don't render the email
 * form, and the send_receipt endpoint must reject the POST outright (404)
 * rather than trusting the client not to submit. An operator's explicit
 * payer_email_capture_enabled setting overrides the deployment default in
 * both directions (see Config::isPayerEmailCaptureEnabled, whose unit matrix
 * lives in test_managed_install.php — this file pins the endpoint + render
 * behavior).
 *
 * payment.php echoes and exits, so each scenario runs in a subprocess; the
 * managed declaration travels as the CASHUPAY_MANAGED_INSTALL env var scoped
 * to that one command. The runner appends the final http_response_code() on
 * shutdown (CLI reports false until a code is set — that reads as 200).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

make_store('store_gate');
Database::insert('invoices', [
    'id' => 'inv_settled',
    'store_id' => 'store_gate',
    'status' => 'Settled',
    'amount' => '21',
    'currency' => 'sat',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
    'paid_at' => Database::timestamp(),
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/payment_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD') ?: 'GET';
$_GET['id'] = getenv('T_INVOICE');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['action'] = 'send_receipt';
    $_POST['email'] = 'payer@example.com';
    $_POST['newsletter'] = '0';
}
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP, var_export($root . '/payment.php', true)));

/**
 * Run payment.php once in a subprocess.
 *
 * @param bool $managed declare CASHUPAY_MANAGED_INSTALL=1 for this run only
 * @return array{status:int, body:string, json:?array}
 */
function run_payment(string $method, bool $managed): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_INVOICE=inv_settled'
        . ' T_METHOD=' . escapeshellarg($method)
        . ($managed ? ' CASHUPAY_MANAGED_INSTALL=1' : '');
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("payment runner produced unparseable output (rc=$rc): $raw");
    }
    $body = trim($m[1]);
    return ['status' => (int)$m[2], 'body' => $body, 'json' => json_decode($body, true)];
}

// --- Standalone default: the endpoint accepts and the form renders -----------
$res = run_payment('POST', false);
assert_eq(200, $res['status'], 'standalone default accepts send_receipt');
assert_eq(true, $res['json']['success'] ?? null, 'the email is captured: ' . $res['body']);
$row = Database::fetchOne("SELECT customer_email FROM invoices WHERE id = 'inv_settled'");
assert_eq('payer@example.com', $row['customer_email'], 'the email was persisted');

$res = run_payment('GET', false);
assert_true(str_contains($res['body'], 'id="receipt-form"'), 'standalone render offers the email form');

// --- Managed default: capture is off — endpoint 404s, form absent ------------
Database::update('invoices', ['customer_email' => null], "id = 'inv_settled'", []);
$res = run_payment('POST', true);
assert_eq(404, $res['status'], 'a managed install rejects send_receipt outright');
assert_eq(['error' => 'Not available.'], $res['json'], 'with the not-available body');
$row = Database::fetchOne("SELECT customer_email FROM invoices WHERE id = 'inv_settled'");
assert_eq(null, $row['customer_email'], 'the refused POST persisted nothing');

$res = run_payment('GET', true);
assert_eq(200, $res['status'], 'the payment page itself still renders');
assert_false(str_contains($res['body'], 'id="receipt-form"'), 'the managed render has no email form');

// --- Explicit override beats the deployment default in both directions -------
Config::set('payer_email_capture_enabled', true);
$res = run_payment('POST', true);
assert_eq(200, $res['status'], 'explicit ON re-enables the endpoint on a managed install');
assert_eq(true, $res['json']['success'] ?? null, 'and the capture succeeds: ' . $res['body']);

Config::set('payer_email_capture_enabled', false);
$res = run_payment('POST', false);
assert_eq(404, $res['status'], 'explicit OFF disables the endpoint on a standalone install');
assert_eq(['error' => 'Not available.'], $res['json'], 'with the same not-available body');

echo "test_payment_page_email_gate: ok\n";
