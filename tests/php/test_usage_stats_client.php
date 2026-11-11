<?php
/**
 * UsageStats (includes/usage_stats.php) against the REAL endpoint script
 * under php -S: the first report lands on opt-in, the weekly cadence and
 * hourly retry throttle in cronTick, every failure mode the client must
 * survive (down / 500 / garbage / not-ok / 400 / 429 / timeout) recorded
 * for the admin card and never thrown, level changes re-sent, opt-out
 * stopping sends, and the pure parseResponse contract.
 */
declare(strict_types=1);
require __DIR__ . '/analytics_endpoint_driver.php';
$ep = AnalyticsEndpointDriver::start();
register_shutdown_function(static fn() => $ep->stop());
// Point the client at the local endpoint BEFORE the constant is defined.
putenv('CASHUPAY_USAGE_STATS_ENDPOINT=' . $ep->url);
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/usage_stats.php';
make_store('s1', 'https://mint.example.com');
$NOW = 1_800_000_000;

assert_eq($ep->url, CASHUPAY_USAGE_STATS_ENDPOINT, 'env override reaches the constant');
assert_false(UsageStats::isDisabled(), 'explicit endpoint enables the client even on a test rig');

// ---- parseResponse: the pure contract ---------------------------------------------------
UsageStats::parseResponse(200, '{"ok":true}');
foreach ([
    [0, '', 'Failed to connect', 'endpoint unreachable: Failed to connect'],
    [0, '', '', 'endpoint unreachable: no response'],
    [500, 'boom', '', 'endpoint returned HTTP 500'],
    [400, '{"error":"unknown_field","field":"properties.x"}', '', 'endpoint returned HTTP 400 (unknown_field)'],
    [400, '{"error":"<script>alert(1)</script>"}', '', 'endpoint returned HTTP 400 (scriptalert1script)'],
    [429, '{"error":"rate_limited","retry_after":1200}', '', 'endpoint rate-limited this address'],
    [200, '<html>', '', 'endpoint returned an unparseable body'],
    [200, '{"ok":false}', '', 'endpoint returned an unparseable body'],
    [200, '{"ok":"true"}', '', 'endpoint returned an unparseable body'],
    [202, '{"ok":true}', '', 'endpoint returned HTTP 202'],
] as [$status, $body, $err, $expected]) {
    try {
        UsageStats::parseResponse($status, $body, $err);
        fail("parseResponse({$status}, {$body}) should throw");
    } catch (\RuntimeException $e) {
        assert_eq($expected, $e->getMessage(), "message for HTTP {$status} {$body}");
    }
}

// ---- off: nothing leaves -------------------------------------------------------------------
assert_eq('off', UsageStats::cronTick($NOW));
assert_false(UsageStats::sendNow($NOW));
$ep->request('GET'); // creates the endpoint's database so it can be inspected
assert_eq(0, (int)$ep->db()->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'no report while off');
assert_null(Config::get(UsageStats::CONFIG_LAST_ATTEMPT_AT, null));

// ---- opt-in + first report ---------------------------------------------------------------
UsageStats::setChoice(true, 2);
assert_true(UsageStats::sendNow($NOW), 'first report accepted');
$id = UsageStats::installId();
$rows = $ep->db()->query('SELECT * FROM reports')->fetchAll();
assert_eq(1, count($rows));
assert_eq($id, $rows[0]['install_id'], 'the report carries the stored install id');
assert_eq(2, (int)$rows[0]['level']);
$stored = json_decode($rows[0]['payload'], true);
assert_eq(UsageStats::buildPayload(2, $NOW, $id), $stored, 'what the endpoint stored is exactly buildPayload()');
assert_eq($NOW, Config::get(UsageStats::CONFIG_LAST_SENT_AT));
assert_eq($NOW, Config::get(UsageStats::CONFIG_LAST_ATTEMPT_AT));
assert_null(Config::get(UsageStats::CONFIG_LAST_ERROR, null));
$st = UsageStats::status(false);
assert_eq($NOW, $st['last_sent_at']);
assert_eq(7, $st['interval_days']);

// ---- weekly cadence ------------------------------------------------------------------------
assert_eq('not_due', UsageStats::cronTick($NOW + 60), 'just sent -> not due');
assert_eq('not_due', UsageStats::cronTick($NOW + 6 * 86400), '6 days -> not due');
assert_eq('sent', UsageStats::cronTick($NOW + 7 * 86400 + 1), '7 days -> sent');
assert_eq(1, (int)$ep->db()->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'still one row per install');
assert_eq($NOW + 7 * 86400 + 1, Config::get(UsageStats::CONFIG_LAST_SENT_AT));

// ---- failure modes: recorded, never thrown, retried after an hour ---------------------------
$t = $NOW + 14 * 86400 + 10;
foreach ([
    'http500'   => 'endpoint returned HTTP 500',
    'garbage'   => 'endpoint returned an unparseable body',
    'notok'     => 'endpoint returned an unparseable body',
    'reject'    => 'endpoint returned HTTP 400 (unknown_field)',
    'ratelimit' => 'endpoint rate-limited this address',
] as $mode => $expectedError) {
    $ep->setMode($mode);
    Config::delete(UsageStats::CONFIG_LAST_ATTEMPT_AT);
    assert_eq('error: ' . $expectedError, UsageStats::cronTick($t), "cron reports the {$mode} failure");
    assert_eq($expectedError, Config::get(UsageStats::CONFIG_LAST_ERROR), "{$mode}: last_error recorded for the card");
    assert_eq($t, Config::get(UsageStats::CONFIG_LAST_ATTEMPT_AT), "{$mode}: attempt stamped");
    assert_eq($NOW + 7 * 86400 + 1, Config::get(UsageStats::CONFIG_LAST_SENT_AT), "{$mode}: last_sent untouched");
    assert_eq('not_due', UsageStats::cronTick($t + 1800), "{$mode}: retry held for an hour");
    assert_eq('error: ' . $expectedError, UsageStats::cronTick($t + 3601), "{$mode}: retried after an hour");
}
// endpoint hanging past the client's timeout
$ep->setMode('hang');
Config::delete(UsageStats::CONFIG_LAST_ATTEMPT_AT);
$t0 = microtime(true);
assert_false(UsageStats::sendNow($t, ['timeout' => 2, 'connectTimeout' => 2]), 'a hung endpoint is a failed send');
assert_true(microtime(true) - $t0 < 6, 'the client gave up at its timeout, not the endpoint\'s sleep');
assert_true(str_starts_with((string)Config::get(UsageStats::CONFIG_LAST_ERROR), 'endpoint unreachable:'), 'timeout -> unreachable: ' . Config::get(UsageStats::CONFIG_LAST_ERROR));
// endpoint down entirely
$ep->setMode('normal');
$port = $ep->port;
$ep->stop();
Config::delete(UsageStats::CONFIG_LAST_ATTEMPT_AT);
$res = UsageStats::cronTick($t + 7200);
assert_true(str_starts_with($res, 'error: endpoint unreachable:'), "down endpoint -> unreachable, got {$res}");
assert_true(str_starts_with((string)Config::get(UsageStats::CONFIG_LAST_ERROR), 'endpoint unreachable:'));
assert_eq('not_due', UsageStats::cronTick($t + 7200 + 60), 'retry held after the outage error too');

// back up on the same port (the client constant is fixed for this process):
// the next due tick clears the error and refreshes the row
$ep = AnalyticsEndpointDriver::start($port);
register_shutdown_function(static fn() => $ep->stop());
Config::delete(UsageStats::CONFIG_LAST_ATTEMPT_AT);
assert_eq('sent', UsageStats::cronTick($t + 86400), 'endpoint back -> sent');
assert_null(Config::get(UsageStats::CONFIG_LAST_ERROR, null), 'success clears last_error');
assert_eq(1, (int)$ep->db()->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'fresh endpoint db: one row');
assert_eq($id, $ep->db()->query('SELECT install_id FROM reports')->fetchColumn(), 'same install id across outages');

// ---- level change re-sends at the new level ----------------------------------------------
UsageStats::setChoice(true, 3);
assert_true(UsageStats::sendNow($t + 86400 + 5));
assert_eq(3, (int)$ep->db()->query('SELECT level FROM reports')->fetchColumn(), 'endpoint now holds the level-3 report');
UsageStats::setChoice(true, 1);
assert_true(UsageStats::sendNow($t + 86400 + 10));
assert_eq(1, (int)$ep->db()->query('SELECT level FROM reports')->fetchColumn(), 'sharing less replaces the richer row');
assert_eq(1, (int)$ep->db()->query('SELECT COUNT(*) FROM reports')->fetchColumn());

// ---- opt-out stops sending; nothing deleted upstream --------------------------------------
UsageStats::setChoice(false);
Config::set(UsageStats::CONFIG_LAST_SENT_AT, $NOW - 365 * 86400);
Config::delete(UsageStats::CONFIG_LAST_ATTEMPT_AT);
assert_eq('off', UsageStats::cronTick($t + 2 * 86400), 'opted out -> off even when long overdue');
assert_false(UsageStats::sendNow($t + 2 * 86400));
assert_eq(1, (int)$ep->db()->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'the last row stays on the endpoint');

echo "test_usage_stats_client: ok\n";
