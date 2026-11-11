<?php
/**
 * Background::cronStaleWarning gating: fresh install grace period, > 24h
 * since last external cron, dismissal-until-next-real-cron behavior.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/background.php';

$threshold = Background::CRON_STALE_THRESHOLD_SECS;
$now = time();

// 1. Fresh install — installed_at within the threshold → no warning even if
//    cron has never fired (the operator hasn't had a chance to set it up).
Config::set('installed_at', $now - 3600); // 1 hour ago
assert_null(Background::cronStaleWarning(), 'fresh install grandfathered');

// 2. Older install + no external cron ever → warning fires.
Config::set('installed_at', $now - ($threshold + 100));
Config::delete('last_external_cron_at');
$w = Background::cronStaleWarning();
assert_not_null($w, 'old install, no external cron → warning');
assert_eq(null, $w['lastExternalCronAt']);

// 3. Recent external cron run (< 24h) → no warning.
Config::set('last_external_cron_at', $now - 60);
assert_null(Background::cronStaleWarning(), 'recent external cron suppresses warning');

// 4. Old external cron (> 24h ago) → warning fires again.
Config::set('last_external_cron_at', $now - ($threshold + 60));
$w = Background::cronStaleWarning();
assert_not_null($w, 'stale external cron → warning');

// 5. Dismissal made AFTER the last external cron silences the warning…
Background::dismissCronWarning();
assert_null(Background::cronStaleWarning(), 'dismissal silences while no fresh cron has landed');

// 6. …but a NEWER external cron run resets the dismissal (next stale window
//    will fire again).
Config::set('last_external_cron_at', $now); // a real cron just landed
// Window is fresh, so no warning right now…
assert_null(Background::cronStaleWarning(), 'fresh cron → no warning');
// …but if we age that to > 24h ago, the dismissal no longer covers it
// because the dismissal is older than this last_external timestamp.
Config::set('last_external_cron_at', $now - ($threshold + 60));
Config::set('cron_warning_dismissed_at', $now - ($threshold + 120));
$w = Background::cronStaleWarning();
assert_not_null($w, 'dismissal cleared by newer cron run');

echo "test_cron_stale_warning: ok\n";
