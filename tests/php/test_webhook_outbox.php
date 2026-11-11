<?php
/**
 * WebhookSender outbox: enqueue-then-drain delivery with retry/backoff.
 *
 * Covers the Theme 4 rewrite:
 *   - fireEvent() persists a `pending` delivery row and does NOT send inline.
 *   - drainPending() delivers on 2xx and marks the row delivered.
 *   - a 5xx response re-queues the row with an incremented attempt count and a
 *     future next_retry_at (backoff), and a row that isn't yet due is skipped.
 *   - delivery gives up (status=failed) once MAX_ATTEMPTS is reached.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Suppress the opportunistic Background::trigger() inside fireEvent — this test
// drives drainPending() directly and wants to stay hermetic (no self-request).
define('CASHUPAY_IN_CRON', true);

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/webhook_sender.php';

// Grab a free port by binding :0 and reading the assigned port, then release
// it for the built-in server — avoids the random-port collisions that make a
// fixed-range pick flaky when the whole suite is running.
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$name = stream_socket_get_name($probe, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($probe);

$serverDir = sys_get_temp_dir() . '/wh_sink_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

/** Mock webhook sink: returns the HTTP code in $serverDir/code, logs each hit. */
function start_sink(int $port, string $serverDir): int {
    $router = <<<'PHP'
<?php
$code = (int)@trim(@file_get_contents(__DIR__ . '/code')) ?: 200;
file_put_contents(__DIR__ . '/hits', "1\n", FILE_APPEND);
http_response_code($code);
header('Content-Type: application/json');
echo json_encode(['ok' => $code < 400]);
PHP;
    file_put_contents($serverDir . '/router.php', $router);
    file_put_contents($serverDir . '/code', '200');
    file_put_contents($serverDir . '/hits', '');

    $pid = (int) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY), $port,
        escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
    ));
    for ($i = 0; $i < 120; $i++) { // up to ~6s; the suite can be busy
        $h = @fopen("http://127.0.0.1:$port/", 'r');
        if ($h) { fclose($h); return $pid; }
        usleep(50000);
    }
    fail("webhook sink failed to start on port $port");
}

function sink_set_code(string $serverDir, int $code): void {
    file_put_contents($serverDir . '/code', (string)$code);
}
function sink_hits(string $serverDir): int {
    return substr_count((string)@file_get_contents($serverDir . '/hits'), "1\n");
}

$pid = start_sink($port, $serverDir);
register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });
// Discard the readiness-probe hit so the counter reflects only real deliveries.
file_put_contents($serverDir . '/hits', '');

$sinkUrl = "http://127.0.0.1:$port/hook";

make_store('s1', 'https://mint.example');
Database::insert('webhooks', [
    'id' => 'wh1',
    'store_id' => 's1',
    'url' => $sinkUrl,
    'secret' => 'shh',
    'events' => json_encode([]), // empty = all events
    'enabled' => 1,
    'created_at' => Database::timestamp(),
]);

$invoice = [
    'id' => 'inv1',
    'store_id' => 's1',
    'status' => 'Settled',
    'amount' => '500',
    'currency' => 'sat',
    'amount_sats' => 500,
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 900,
];

// --- 1. fireEvent enqueues, does NOT send inline ---------------------------
WebhookSender::fireEvent('s1', 'InvoiceSettled', $invoice);
$rows = Database::fetchAll("SELECT * FROM webhook_deliveries WHERE invoice_id = 'inv1'");
assert_eq(1, count($rows), 'fireEvent enqueues exactly one delivery');
assert_eq('pending', $rows[0]['status'], 'enqueued row is pending');
assert_eq(0, (int)$rows[0]['attempts'], 'enqueued row has 0 attempts');
assert_eq(0, sink_hits($serverDir), 'fireEvent did NOT send inline (no sink hit yet)');

// --- 2. drainPending delivers on 200 ---------------------------------------
sink_set_code($serverDir, 200);
$res = WebhookSender::drainPending();
assert_eq(1, $res['sent'], 'drain reports one sent');
$row = Database::fetchOne("SELECT * FROM webhook_deliveries WHERE id = ?", [$rows[0]['id']]);
assert_eq('delivered', $row['status'], 'row marked delivered');
assert_eq(200, (int)$row['status_code'], 'status_code recorded');
assert_eq(1, (int)$row['attempts'], 'one attempt recorded');
assert_not_null($row['delivered_at'], 'delivered_at stamped');
assert_eq(1, sink_hits($serverDir), 'sink received exactly one delivery');

// A second drain must NOT re-send a delivered row.
WebhookSender::drainPending();
assert_eq(1, sink_hits($serverDir), 'delivered row not re-sent on next drain');

// --- 3. 5xx → retry with backoff, and not-yet-due rows are skipped ---------
sink_set_code($serverDir, 503);
WebhookSender::fireEvent('s1', 'InvoiceProcessing', $invoice);
$pending = Database::fetchOne(
    "SELECT * FROM webhook_deliveries WHERE event_type = 'InvoiceProcessing'"
);
$res = WebhookSender::drainPending();
assert_eq(1, $res['failed'], 'drain reports one failed (will retry)');
$row = Database::fetchOne("SELECT * FROM webhook_deliveries WHERE id = ?", [$pending['id']]);
assert_eq('pending', $row['status'], '5xx keeps row pending for retry');
assert_eq(1, (int)$row['attempts'], 'attempt counted');
assert_eq(503, (int)$row['status_code'], 'failure code recorded');
assert_true((int)$row['next_retry_at'] > Database::timestamp(), 'backoff pushed next_retry_at into the future');

$hitsBefore = sink_hits($serverDir);
WebhookSender::drainPending(); // immediate re-drain: row not due yet
$rowAfter = Database::fetchOne("SELECT attempts FROM webhook_deliveries WHERE id = ?", [$pending['id']]);
assert_eq(1, (int)$rowAfter['attempts'], 'not-yet-due row is skipped (no extra attempt)');
assert_eq($hitsBefore, sink_hits($serverDir), 'not-yet-due row not re-sent');

// --- 4. give up after MAX_ATTEMPTS -----------------------------------------
// Fast-forward: set attempts to one below the cap and make it due now.
Database::update(
    'webhook_deliveries',
    ['attempts' => 5, 'next_retry_at' => Database::timestamp() - 1],
    'id = ?',
    [$pending['id']]
);
$res = WebhookSender::drainPending();
assert_eq(1, $res['gave_up'], 'drain reports one given up');
$row = Database::fetchOne("SELECT * FROM webhook_deliveries WHERE id = ?", [$pending['id']]);
assert_eq('failed', $row['status'], 'row marked failed after max attempts');
assert_eq(6, (int)$row['attempts'], 'attempts reached the cap');
assert_null($row['next_retry_at'], 'failed row has no further retry scheduled');

fwrite(STDERR, "test_webhook_outbox: all assertions passed\n");
