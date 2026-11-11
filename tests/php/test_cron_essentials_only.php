<?php
/**
 * cron.php: when triggered as an internal self-request AND external cron is
 * fresh, only essential (latency-sensitive, customer-facing) tasks run; the
 * housekeeping tasks are deferred to the next real cron pass.
 *
 * Also covers the underlying Background::isExternalCronFresh() helper and the
 * fresh-install / stale / external-call fallbacks.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/background.php';

// cron.php's refusal paths end in a bare `exit` (status 0). A case that trips
// one kills this process mid-file "successfully", and run.php counts the
// silently truncated test as green — exactly what happened when cron_key
// seeding landed in Database::initialize and the external-call cases stopped
// authenticating: everything from case 4 on simply never ran. Turn any early
// exit into a failure (an exit() inside a shutdown function overrides the
// process's exit status).
$GLOBALS['test_done'] = false;
register_shutdown_function(function () {
    if (empty($GLOBALS['test_done'])) {
        fwrite(STDERR, "test exited before completion (a cron.php refusal path fired)\n");
        exit(1);
    }
});

$threshold = Background::EXTERNAL_CRON_FRESH_THRESHOLD_SECS;
assert_eq(3600, $threshold, 'fresh threshold is 1h');

// ---------------------------------------------------------------------------
// Background::isExternalCronFresh()
// ---------------------------------------------------------------------------

// Fresh install — no stamp → not fresh, so internal calls still do full work.
Config::delete('last_external_cron_at');
assert_eq(false, Background::isExternalCronFresh(), 'fresh install not considered fresh');

// Stale (older than threshold) → not fresh.
Config::set('last_external_cron_at', time() - ($threshold + 60));
assert_eq(false, Background::isExternalCronFresh(), 'stale stamp not considered fresh');

// Recent (within threshold) → fresh.
Config::set('last_external_cron_at', time() - 60);
assert_eq(true, Background::isExternalCronFresh(), 'recent stamp considered fresh');

// ---------------------------------------------------------------------------
// cron.php end-to-end
//
// The tasks themselves touch a lot of subsystems (mint HTTP, LNURL, swap
// pollers) we don't want to spin up here. cron.php catches exceptions per-
// task and reports them as `error: ...` strings, which is fine for this
// test — we're checking *which task keys appear*, not their success.
// ---------------------------------------------------------------------------

// All tasks that gate themselves with `!$skipNonEssential`. If essentials-only
// mode is active, these keys must NOT appear in the response. Auto-melt isn't
// here because checkAutoMelt() returns null when no store has it configured,
// reporting "skipped" rather than absence.
$nonEssentialKeys = [
    'settle_fees',
    'auto_melt',
    'clean_cache',
    'sync_proofs',
    'expire_old_invoices',
    'cleanup_invoices',
    'cleanup_pending_ops',
    'cleanup_webhooks',
    'trusted_mints',
    'notifications',
];

// Tasks that must always run (essentials + always-on swap polling).
$essentialKeys = [
    'poll_quotes',
    'expire_invoices',
    'poll_onchain',
    'poll_swaps',
    'expire_swaps',
    'recover_orphaned',
];

/** Invoke cron.php in-process with the given $_GET state, return decoded JSON. */
function run_cron(array $get): array {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/cron.php';
    $_GET = $get;
    ob_start();
    require dirname(__DIR__, 2) . '/app/cron.php';
    $out = ob_get_clean();
    $decoded = json_decode($out, true);
    assert_true(is_array($decoded), 'cron.php output is JSON');
    assert_true(isset($decoded['tasks']), 'tasks array present');
    return $decoded;
}

// Case 1: internal call + fresh external cron → essentials-only mode, non-
// essential task keys absent, essentials present.
Config::set('last_external_cron_at', time() - 60);
$internalKey = Background::getInternalKey();
$res = run_cron(['internal' => '1', 'key' => $internalKey]);
assert_eq('essentials-only', $res['mode'], 'internal+fresh → essentials-only');
foreach ($nonEssentialKeys as $k) {
    assert_true(!isset($res['tasks'][$k]), "non-essential '$k' must be absent in essentials-only mode");
}
foreach ($essentialKeys as $k) {
    assert_true(isset($res['tasks'][$k]), "essential '$k' must be present in essentials-only mode");
}

// Case 2: internal call + stale external cron → full run.
Config::set('last_external_cron_at', time() - ($threshold + 60));
$res = run_cron(['internal' => '1', 'key' => $internalKey]);
assert_eq('all', $res['mode'], 'internal+stale → all');
foreach ($nonEssentialKeys as $k) {
    assert_true(isset($res['tasks'][$k]), "non-essential '$k' must be present in all-mode (stale external)");
}

// Case 3: internal call + no external cron stamp (fresh install) → full run.
Config::delete('last_external_cron_at');
$res = run_cron(['internal' => '1', 'key' => $internalKey]);
assert_eq('all', $res['mode'], 'internal+fresh-install → all');
foreach ($nonEssentialKeys as $k) {
    assert_true(isset($res['tasks'][$k]), "non-essential '$k' must be present in all-mode (fresh install)");
}

// Case 4: external call (no ?internal=1) always runs everything, even when
// last_external_cron_at is recent. (And of course the call itself re-stamps
// it, so we measure mode before that matters.)
Config::set('last_external_cron_at', time() - 60);
// External calls authenticate against the cron_key Database::initialize
// seeded (raw hex in the config table, so Config::get returns it verbatim).
$seededCronKey = (string)Config::get('cron_key');
assert_true($seededCronKey !== '', 'Database::initialize seeded a cron_key');
$res = run_cron(['key' => $seededCronKey]);
assert_eq('all', $res['mode'], 'external call → all regardless of stamp');
foreach ($nonEssentialKeys as $k) {
    assert_true(isset($res['tasks'][$k]), "non-essential '$k' must be present on external call");
}

// Case 5: ?only=swaps remains swaps-only regardless of staleness.
Config::set('last_external_cron_at', time() - 60);
$res = run_cron(['only' => 'swaps', 'key' => $seededCronKey]);
assert_eq('swaps-only', $res['mode'], 'swaps-only mode unchanged');
assert_true(isset($res['tasks']['poll_swaps']), 'poll_swaps runs in swaps-only');
foreach ($nonEssentialKeys as $k) {
    assert_true(!isset($res['tasks'][$k]), "non-essential '$k' absent in swaps-only");
}
foreach (['poll_quotes', 'expire_invoices', 'poll_onchain', 'recover_orphaned'] as $k) {
    assert_true(!isset($res['tasks'][$k]), "non-swap essential '$k' absent in swaps-only");
}

$GLOBALS['test_done'] = true;
echo "test_cron_essentials_only: ok\n";
