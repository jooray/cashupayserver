<?php
/**
 * cron.php integration test: Task 12 (updater) wiring.
 *
 * The updater no longer runs inline inside cron.php. To keep a crash in the
 * heavy modules cron.php loads from disabling updates, Task 12 now just fires
 * a non-blocking self-request to the isolated update.php endpoint (see
 * Updater::triggerSelfCheck) and reports "triggered". The actual
 * download/overlay/verify/rollback flow is covered by test_updater_e2e.php,
 * test_updater_blocked_sha.php and test_update_php_helpers.php.
 *
 * This test verifies the wiring: an authenticated external cron run reaches
 * Task 12, reports "triggered", and does NOT overlay the install inline.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// A minimal install root so we can assert cron.php did not overlay it.
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => 'cronsha-' . str_repeat('b', 35),
    'CHANNEL' => 'main',
    'VERSION' => '0.3-new',
], [
    'admin.php' => 'NEW_ADMIN_FROM_CRON',
]);

Updater::$installRootOverride = $fixture['installRoot'];
Updater::$autoUpdateEnabledOverride = true;
Config::set('update_channel', 'main');
Config::set('updater_last_check', 0);

// triggerSelfCheck() curls Config::getBaseUrl() . '/update.php'. Point it at a
// closed port so the fire-and-forget resolves instantly and nothing is applied
// inline — exactly the production contract (the real work happens server-side
// in the separately-invoked update.php).
Config::set('base_url', 'http://127.0.0.1:1');

// Authenticate as an external cron call (cron_key is seeded by initialize()).
$cronKey = Config::get('cron_key');
assert_true(is_string($cronKey) && $cronKey !== '', 'cron_key seeded');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cron.php';
$_GET = ['key' => $cronKey];

// Run cron.php in-process. It echoes JSON.
ob_start();
require dirname(__DIR__, 2) . '/app/cron.php';
$out = ob_get_clean();

$decoded = json_decode($out, true);
assert_true(is_array($decoded), 'cron.php output is JSON');
assert_true(isset($decoded['tasks']['updater']), 'updater task present');
assert_eq('triggered', $decoded['tasks']['updater'], 'Task 12 reports it triggered the isolated updater');

// cron.php must NOT have applied the update inline — the install is untouched.
assert_eq('OLD_ADMIN', file_get_contents($fixture['installRoot'] . '/admin.php'), 'install not overlaid by cron.php');
$info = Updater::getLocalBuildInfo();
assert_eq('0000000000000000000000000000000000000000', $info['COMMIT_SHA'], 'BUILD_INFO unchanged by cron.php');

Updater::$installRootOverride = null;
Updater::$autoUpdateEnabledOverride = null;

echo "ok\n";
