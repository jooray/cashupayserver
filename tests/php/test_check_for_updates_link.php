<?php
/**
 * The admin settings footer's "Check for updates" link must point at the same
 * GitHub repo the in-app updater actually pulls from (Updater::GH_OWNER/GH_REPO)
 * — not the upstream jooray fork — and must be channel-aware: stable installs
 * go to /releases/latest (newest stable release, prereleases skipped) while
 * testing installs go to the full listing, the only page showing prereleases.
 *
 * The anchor delegates to Updater::releasesUrl(), so the source check pins the
 * delegation and the behavior checks exercise the method per channel.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// The footer anchor must build its href from Updater::releasesUrl() — a
// hardcoded URL could silently disagree with the updater's repo or channel.
$admin = file_get_contents(dirname(__DIR__, 2) . '/app/admin.php');
assert_true($admin !== false, 'admin.php readable');
assert_true(
    (bool) preg_match(
        '/href="<\?= htmlspecialchars\(Updater::releasesUrl\(\)\) \?>"[^>]*>\s*Check for updates/i',
        $admin
    ),
    'the "Check for updates" anchor delegates to Updater::releasesUrl()'
);

// Default channel is main -> /releases/latest.
$base = sprintf('https://github.com/%s/%s/releases', Updater::GH_OWNER, Updater::GH_REPO);
assert_eq($base . '/latest', Updater::releasesUrl(), 'main channel (default) links to /releases/latest');

// Testing channel -> the full listing, where prereleases are visible.
Updater::setChannel('testing');
assert_eq($base, Updater::releasesUrl(), 'testing channel links to the full releases listing');

// Back to main -> /latest again.
Updater::setChannel('main');
assert_eq($base . '/latest', Updater::releasesUrl(), 'main channel links to /releases/latest');

assert_false(stripos(Updater::releasesUrl(), 'jooray') !== false, 'link does not point at the upstream fork');

echo "test_check_for_updates_link: ok\n";
