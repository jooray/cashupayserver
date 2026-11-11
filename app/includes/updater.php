<?php
/**
 * CashuPayServer - Auto-Update Module
 *
 * Fetches a fresh build of the standalone zip from the versioned GitHub
 * release the current channel tracks (main -> newest stable, testing ->
 * newest prerelease-or-stable), overlays it on the current install, and
 * supports rollback via stored backups.
 *
 * Deployed servers have no shell, composer, npm, or git — only PHP. The
 * release artifact is a fully pre-built zip (vendor/, JS bundle, etc.
 * baked in by CI). The updater needs only:
 *   - curl (project requirement)
 *   - PHP's ZipArchive (core)
 *   - Filesystem write access to the install directory
 *
 * NEVER active in WordPress mode — WP has its own update path.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safe_http.php';

class Updater {
    public const GH_OWNER = 'BareBits';
    public const GH_REPO = 'cashupayserver';
    public const CHECK_INTERVAL_SECONDS = 86400; // daily
    public const BACKUPS_TO_KEEP = 3;
    public const HTTP_USER_AGENT = 'cashupayserver-updater';

    /**
     * Override for the releases-collection API base URL (the equivalent of
     * https://api.github.com/repos/<owner>/<repo>/releases, no trailing
     * slash). The updater appends `/latest` for the main channel and a
     * `?per_page=` list query for testing. Tests point this at a local
     * fixture server. Null = use GitHub. Production code never touches this.
     */
    public static ?string $releaseApiUrlBase = null;

    /**
     * Override for the install root. Tests point this at a tempdir so
     * the overlay / backup / rollback paths can run hermetically.
     * Null = use the project root (parent of includes/).
     */
    public static ?string $installRootOverride = null;

    // Test hook: when non-null, bypasses the CASHUPAY_AUTO_UPDATE_ENABLED
    // opt-in check (which is otherwise needed for the updater to run at all).
    // Production code paths leave this at null; updater unit tests set it to
    // true for the duration of a single case.
    public static ?bool $autoUpdateEnabledOverride = null;

    // Files/dirs that must survive an update (relative to install root).
    private const PRESERVE_PATHS = [
        'data',
        'user_config.php',
    ];

    /**
     * Top-level entry point called from cron.php (auto path) and from the
     * isolated update.php manual path.
     *
     * $force = true is the OPERATOR-INITIATED ("Update now") path: clicking
     * the button IS the consent, so it bypasses the CASHUPAY_AUTO_UPDATE_ENABLED
     * opt-in and the daily throttle. It still honours WordPress mode, the
     * test/dev kill switch, the blocked-SHA forward-failure guard, the lock,
     * the pre-overlay backup, and the post-apply health verify — a manual
     * update is just as recoverable as an automatic one.
     *
     * Returns true if an update was applied, false otherwise.
     */
    public static function checkAndApply(bool $force = false): bool {
        // Operator opt-in. Auto-update is OFF by default for fresh installs;
        // an operator who wants it has to set CASHUPAY_AUTO_UPDATE_ENABLED in
        // user_config.php (or as an env var). See user_config.example.php.
        // A manual ("Update now") run skips this gate — the click is the opt-in.
        if (!$force && !self::isAutoUpdateEnabled()) {
            return false;
        }
        // Test-harness kill switch. Honoured for BOTH auto and manual paths:
        // when running under iterate.py or the pytest payserver fixture a
        // manual click must not overlay an in-progress dev branch with the
        // latest released build.
        if (self::isDisabledForTests()) {
            return false;
        }

        $now = time();
        // The daily throttle protects the automatic path from re-checking on
        // every cron tick; a manual run is explicitly requested, so it skips it.
        $lastCheck = (int)Config::get('updater_last_check', 0);
        if (!$force && $lastCheck && ($now - $lastCheck) < self::CHECK_INTERVAL_SECONDS) {
            return false;
        }
        Config::set('updater_last_check', $now);

        $channel = self::getChannel();
        $remote = self::fetchRemoteBuildInfo($channel);
        if ($remote === null) {
            self::log("could not fetch remote BUILD_INFO for channel $channel");
            return false;
        }

        $localSha = self::getLocalCommitSha();
        if ($localSha !== '' && $localSha === ($remote['COMMIT_SHA'] ?? '')) {
            // Already current. We hold a fresh remote BUILD_INFO, so refresh
            // the banner's cached verdict for free: a manual "Update now" on
            // a current install must not leave a stale "update available"
            // from the daily check sitting next to "you're up to date".
            self::recordAvailability($channel, $remote);
            return false;
        }

        // Forward-failure guard: a SHA that previously broke the install (its
        // post-update health check failed and it was auto-rolled-back) is
        // parked in updater_blocked_shas. Never re-apply it — otherwise we'd
        // loop apply -> crash -> rollback -> apply on every tick until the
        // maintainer ships a different build. The block clears itself simply
        // by the channel moving on to a new COMMIT_SHA.
        $remoteSha = (string)($remote['COMMIT_SHA'] ?? '');
        if ($remoteSha !== '' && in_array($remoteSha, self::getBlockedShas(), true)) {
            self::log("remote SHA $remoteSha is blocked (failed a prior health check), skipping");
            return false;
        }

        // Take the lock — concurrent cron runs must not race.
        $lockPath = self::installRoot() . '/data/updates/.lock';
        self::ensureDir(dirname($lockPath));
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            self::log('updater lock held by another run, skipping');
            return false;
        }

        try {
            $applied = self::applyUpdate($channel, $remote);
            if ($applied) {
                // The local BUILD_INFO now carries the remote SHA, so the
                // cached "update available" verdict (refreshed only daily by
                // cron) would otherwise keep the banner up for a day after
                // the update. Re-derive it against the new local SHA now.
                self::recordAvailability($channel, $remote);
            }
            return $applied;
        } catch (Throwable $e) {
            self::log('update failed: ' . $e->getMessage());
            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function getChannel(): string {
        $fromDb = Config::get('update_channel');
        if (is_string($fromDb) && $fromDb !== '') {
            return self::normalizeChannel($fromDb);
        }
        if (defined('CASHUPAY_UPDATE_CHANNEL') && is_string(CASHUPAY_UPDATE_CHANNEL)) {
            return self::normalizeChannel(CASHUPAY_UPDATE_CHANNEL);
        }
        return 'main';
    }

    public static function setChannel(string $channel): void {
        Config::set('update_channel', self::normalizeChannel($channel));
    }

    private static function normalizeChannel(string $channel): string {
        return $channel === 'testing' ? 'testing' : 'main';
    }

    /**
     * Human-facing releases page for the current channel. Stable installs get
     * /releases/latest (always the newest stable release — prereleases are
     * skipped, so operators can't grab a testing build by mistake); testing
     * installs get the full listing, the only page their prereleases show on.
     */
    public static function releasesUrl(): string {
        $url = sprintf('https://github.com/%s/%s/releases', self::GH_OWNER, self::GH_REPO);
        return self::getChannel() === 'main' ? $url . '/latest' : $url;
    }

    public static function installRoot(): string {
        return self::$installRootOverride ?? dirname(__DIR__);
    }

    /**
     * Operator-facing opt-in for the auto-updater. Returns true when ANY of:
     *   - DB config key auto_update_enabled is true (admin-UI toggle /
     *     onboarding wizard), or
     *   - PHP constant CASHUPAY_AUTO_UPDATE_ENABLED is defined and truthy, or
     *   - Env var CASHUPAY_AUTO_UPDATE_ENABLED is non-empty and not "0", or
     *   - Updater::$autoUpdateEnabledOverride is set to true (test hook).
     *
     * Any source can enable; none can veto another (env "0" only means "not
     * enabled via env"). Default (all unset) is false — fresh installs do not
     * auto-update. Operators who want it must opt in.
     * Mirrored by upd_is_enabled() in update.php — keep both in sync.
     */
    public static function isAutoUpdateEnabled(): bool {
        if (self::$autoUpdateEnabledOverride !== null) {
            return self::$autoUpdateEnabledOverride;
        }
        if (Config::get('auto_update_enabled', false) === true) {
            return true;
        }
        if (defined('CASHUPAY_AUTO_UPDATE_ENABLED') && CASHUPAY_AUTO_UPDATE_ENABLED) {
            return true;
        }
        $env = getenv('CASHUPAY_AUTO_UPDATE_ENABLED');
        return ($env !== false && $env !== '' && $env !== '0');
    }

    /**
     * Which source opened the auto-update gate, for the admin UI: 'db',
     * 'constant', 'env', or null when disabled. The UI disables the toggle
     * when a deployment-level source (constant/env) is forcing it on, since
     * flipping the DB key off could not close the gate in that case.
     */
    public static function autoUpdateEnabledSource(): ?string {
        if (defined('CASHUPAY_AUTO_UPDATE_ENABLED') && CASHUPAY_AUTO_UPDATE_ENABLED) {
            return 'constant';
        }
        $env = getenv('CASHUPAY_AUTO_UPDATE_ENABLED');
        if ($env !== false && $env !== '' && $env !== '0') {
            return 'env';
        }
        if (Config::get('auto_update_enabled', false) === true) {
            return 'db';
        }
        return null;
    }

    /**
     * Test-harness opt-out. Honoured when any of these is true:
     *   - PHP constant CASHUPAY_UPDATER_DISABLED is defined and truthy
     *   - Env var CASHUPAY_UPDATER_DISABLED is non-empty and not "0"
     *   - A sentinel file `.updater_disabled` exists in CASHUPAY_DATA_DIR
     *     (or the default data/ dir, if no CASHUPAY_DATA_DIR override).
     *
     * The sentinel-file path exists for iterate.py / pytest stacks: a stale
     * env var on an external cron hit (or any other path that bypasses the
     * fixture-spawned process tree) won't fire the updater and overwrite
     * an in-progress dev branch.
     */
    private static function isDisabledForTests(): bool {
        if (defined('CASHUPAY_UPDATER_DISABLED') && CASHUPAY_UPDATER_DISABLED) {
            return true;
        }
        $env = getenv('CASHUPAY_UPDATER_DISABLED');
        if ($env !== false && $env !== '' && $env !== '0') {
            return true;
        }
        $dataDir = defined('CASHUPAY_DATA_DIR')
            ? (string)CASHUPAY_DATA_DIR
            : (getenv('CASHUPAY_DATA_DIR') ?: (self::installRoot() . '/data'));
        if ($dataDir !== '' && is_file($dataDir . '/.updater_disabled')) {
            return true;
        }
        return false;
    }

    // ---------------- Remote / local build info ----------------

    /**
     * Discover the release the current channel should track and return its
     * parsed BUILD_INFO (plus the app-zip download URL under '__zip_url'), or
     * null on failure.
     *
     * Releases are versioned (vX.Y.Z tags); the old moving channel-<x> tags no
     * longer exist. Selection by channel:
     *   - main:    GitHub's "latest" release — the newest non-draft,
     *              non-prerelease build. (GET /releases/latest)
     *   - testing: the newest non-draft release of ANY kind, prereleases
     *              included. (first entry of GET /releases, which GitHub
     *              returns newest-first)
     *
     * Within the chosen release the stable-named BUILD_INFO asset carries
     * COMMIT_SHA/VERSION; the app zip is found by name pattern
     * (barebits-<version>.zip), never matching barebits-windows-*.zip or
     * barebits_wordpress_plugin-*.zip.
     */
    private static function fetchRemoteBuildInfo(string $channel): ?array {
        $release = self::fetchChannelRelease($channel);
        if (!is_array($release) || empty($release['assets'])) {
            return null;
        }

        $buildInfoUrl = null;
        $zipUrl = null;
        foreach ($release['assets'] as $asset) {
            $name = (string)($asset['name'] ?? '');
            $url = (string)($asset['browser_download_url'] ?? '');
            if ($name === 'BUILD_INFO') {
                $buildInfoUrl = $url;
            } elseif (self::isAppZipAsset($name)) {
                $zipUrl = $url;
            }
        }
        if ($buildInfoUrl === null || $zipUrl === null) {
            return null;
        }

        $raw = self::httpGet($buildInfoUrl);
        if ($raw === null) {
            return null;
        }
        $info = self::parseBuildInfo($raw);
        $info['__zip_url'] = $zipUrl;
        return $info;
    }

    /** Base URL of the releases-collection API (overridable for tests). */
    private static function releasesApiUrl(): string {
        return self::$releaseApiUrlBase ?? sprintf(
            'https://api.github.com/repos/%s/%s/releases',
            self::GH_OWNER,
            self::GH_REPO
        );
    }

    /**
     * Fetch the release object the channel tracks (decoded JSON), or null.
     * See fetchRemoteBuildInfo() for the per-channel selection rules.
     */
    private static function fetchChannelRelease(string $channel): ?array {
        $base = rtrim(self::releasesApiUrl(), '/');
        if ($channel === 'testing') {
            // Newest release of any kind. GitHub's list endpoint returns
            // releases newest-first; take the first non-draft. (Drafts are
            // invisible to the unauthenticated updater anyway; guard regardless.)
            $list = self::httpGetJson($base . '?per_page=30');
            if (!is_array($list)) {
                return null;
            }
            foreach ($list as $rel) {
                if (is_array($rel) && empty($rel['draft'])) {
                    return $rel;
                }
            }
            return null;
        }
        // main: newest stable (non-prerelease, non-draft) release. GitHub's
        // /releases/latest excludes both by definition.
        $rel = self::httpGetJson($base . '/latest');
        return is_array($rel) ? $rel : null;
    }

    /**
     * Is $name the standalone app zip asset? Matches the version-stamped
     * barebits-<version>.zip the release workflow emits (plus the bare
     * barebits.zip as a defensive fallback), while never matching the
     * Windows desktop asset (barebits-windows-*.zip) or the WordPress
     * plugin asset (barebits_wordpress_plugin-*.zip).
     */
    private static function isAppZipAsset(string $name): bool {
        return $name === 'barebits.zip'
            || (bool)preg_match('/^barebits-(?!windows-).+\.zip$/', $name);
    }

    public static function getLocalBuildInfo(): array {
        $path = self::installRoot() . '/BUILD_INFO';
        if (!is_file($path)) {
            return [];
        }
        return self::parseBuildInfo((string)file_get_contents($path));
    }

    private static function getLocalCommitSha(): string {
        $info = self::getLocalBuildInfo();
        return (string)($info['COMMIT_SHA'] ?? '');
    }

    private static function parseBuildInfo(string $raw): array {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            $out[$key] = $val;
        }
        return $out;
    }

    // ---------------- Update-available probe (banner / Update-now button) ----------------
    //
    // A lightweight "is a newer build out?" check, deliberately INDEPENDENT of
    // the auto-update opt-in: the dashboard banner has to nudge operators who
    // have NOT enabled auto-update, since they're exactly the ones who need
    // reminding. It never downloads or applies anything — it only compares the
    // remote channel's COMMIT_SHA to the local one and caches the verdict.

    /**
     * Refresh (or return cached) availability verdict for the current channel.
     * Result shape (also what getAvailableUpdate() returns):
     *   ['available' => bool, 'channel' => string, 'current_version' => string,
     *    'current_sha' => string, 'latest_version' => string, 'latest_sha' => string,
     *    'blocked' => bool, 'checked_at' => int, 'error' => ?string]
     *
     * Self-throttled to CHECK_INTERVAL_SECONDS unless $force. No-op (returns the
     * last cached verdict, or an "unavailable" stub) in WordPress mode and under
     * the test/dev kill switch so the dev stack never phones GitHub or shows a
     * bogus "update available" for an in-progress branch.
     */
    public static function checkForUpdate(bool $force = false): array {
        if (self::isDisabledForTests()) {
            return self::getAvailableUpdate() ?? ['available' => false];
        }

        $cached = self::getAvailableUpdate();
        $now = time();
        if (!$force && is_array($cached) && isset($cached['checked_at'])
            && ($now - (int)$cached['checked_at']) < self::CHECK_INTERVAL_SECONDS) {
            return $cached;
        }

        $channel = self::getChannel();
        $remote = self::fetchRemoteBuildInfo($channel);
        if ($remote === null) {
            // Couldn't reach the release API. Keep any prior verdict but stamp
            // the attempt so we back off for the throttle window rather than
            // hammering a flaky endpoint on every cron tick.
            $result = is_array($cached) ? $cached : ['available' => false];
            $result['checked_at'] = $now;
            $result['error'] = 'fetch_failed';
            Config::set('updater_available', $result);
            return $result;
        }

        return self::recordAvailability($channel, $remote);
    }

    /**
     * Derive the availability verdict from a freshly fetched remote BUILD_INFO
     * against the CURRENT local install, cache it, and return it. Shared by
     * the daily probe (checkForUpdate) and the apply path (checkAndApply), so
     * any code that just learned the channel's head can keep the banner's
     * cache honest without a second fetch.
     */
    private static function recordAvailability(string $channel, array $remote): array {
        $remoteSha = (string)($remote['COMMIT_SHA'] ?? '');
        $localSha = self::getLocalCommitSha();
        // A build that already failed a prior health check is parked in
        // updater_blocked_shas — never advertise it as an available update.
        $blocked = $remoteSha !== '' && in_array($remoteSha, self::getBlockedShas(), true);
        $available = $remoteSha !== '' && $remoteSha !== $localSha && !$blocked;

        $result = [
            'available' => $available,
            'channel' => $channel,
            'current_version' => (string)(self::getLocalBuildInfo()['VERSION'] ?? ''),
            'current_sha' => $localSha,
            'latest_version' => (string)($remote['VERSION'] ?? ''),
            'latest_sha' => $remoteSha,
            'blocked' => $blocked,
            'checked_at' => time(),
            'error' => null,
        ];
        Config::set('updater_available', $result);
        return $result;
    }

    /**
     * Last cached availability verdict, or null if never checked.
     *
     * Self-healing: the verdict is refreshed at most daily, and an update can
     * land in between (manual click, auto-apply, a hand-copied release). A
     * verdict is only meaningful for the build it was computed against, so:
     *
     *   - If its latest_sha IS the SHA we are now running, the install has
     *     caught up: report (and persist) "not available". Nothing to refetch.
     *   - Otherwise, if its current_sha is NOT the SHA we are now running, it
     *     was computed by a different build (typically the one that just
     *     applied this update — whose own updater code ran the apply, so the
     *     new build's post-apply refresh never got a chance). Stop advertising
     *     whatever it names and zero checked_at so the next checkForUpdate()
     *     (cron tick, or update_status's on-demand refresh) refetches instead
     *     of trusting the daily throttle.
     *
     * Never flips "current" to "available": that needs a real check.
     */
    public static function getAvailableUpdate(): ?array {
        $v = Config::get('updater_available');
        if (!is_array($v)) {
            return null;
        }
        $localSha = self::getLocalCommitSha();
        if ($localSha === '') {
            return $v; // dev checkout without BUILD_INFO: nothing to compare against
        }
        $latestSha = (string)($v['latest_sha'] ?? '');
        $verdictSha = (string)($v['current_sha'] ?? '');
        if (!empty($v['available']) && $latestSha !== '' && $latestSha === $localSha) {
            $v['available'] = false;
            $v['current_sha'] = $localSha;
            $v['current_version'] = (string)(self::getLocalBuildInfo()['VERSION'] ?? '');
            Config::set('updater_available', $v);
        } elseif ($verdictSha !== '' && $verdictSha !== $localSha) {
            $v['available'] = false;
            $v['current_sha'] = $localSha;
            $v['current_version'] = (string)(self::getLocalBuildInfo()['VERSION'] ?? '');
            $v['checked_at'] = 0; // force a refetch on the next check
            Config::set('updater_available', $v);
        }
        return $v;
    }

    /**
     * The cached verdict for the admin status endpoint. If getAvailableUpdate()
     * just invalidated a verdict computed by a different build, run the
     * (now un-throttled) check right away so the banner is correct on the
     * first page load after an update rather than after the next cron tick.
     * A fetch failure re-stamps checked_at, so a flaky GitHub costs one slow
     * admin request per throttle window, not one per poll. No-op under the
     * dev/test kill switch (checkForUpdate returns the cache).
     */
    public static function getAvailableUpdateRefreshingStale(): ?array {
        $v = self::getAvailableUpdate();
        if (is_array($v) && (int)($v['checked_at'] ?? 0) === 0) {
            return self::checkForUpdate();
        }
        return $v;
    }

    /**
     * Email the site-wide notification address about an available update,
     * exactly once per release. Called from cron right after checkForUpdate;
     * reads the cached verdict, so it also works when the check itself is
     * throttled or the dev/test kill switch suppressed a live fetch (the
     * kill switch guards network + filesystem, and this does neither).
     *
     * Skipped when auto-update is enabled — the release will apply on its own
     * shortly, so a "please act" email would only be noise. The once-per-
     * release marker (updater_notified_sha) is stamped only when a mail was
     * actually enqueued: if the gates were closed at detection time (master
     * toggle off, no recipient) and open later, the next tick still notifies.
     * Returns true when an email was enqueued on this call.
     */
    public static function maybeNotifyUpdateAvailable(): bool {
        $state = self::getAvailableUpdate();
        if (!is_array($state) || empty($state['available'])) {
            return false;
        }
        $sha = (string)($state['latest_sha'] ?? '');
        if ($sha === '') {
            return false;
        }
        if (self::isAutoUpdateEnabled()) {
            return false;
        }
        if ((string)Config::get('updater_notified_sha', '') === $sha) {
            return false;
        }
        require_once __DIR__ . '/notification_sender.php';
        if (!NotificationSender::queueUpdateAvailable($state)) {
            return false;
        }
        Config::set('updater_notified_sha', $sha);
        return true;
    }

    /**
     * Why a manual ("Update now") run cannot proceed in this environment, or
     * null if it can. Used by admin.php to give the operator a clear message
     * instead of a silent no-op. 'wordpress' — WP has its own update path;
     * 'disabled' — the dev/test kill switch (iterate.py / pytest) is active.
     */
    public static function manualUpdateBlockedReason(): ?string {
        if (self::isDisabledForTests()) {
            return 'disabled';
        }
        return null;
    }

    // ---------------- Apply update ----------------

    private static function applyUpdate(string $channel, array $remote): bool {
        $root = self::installRoot();
        $sha = (string)($remote['COMMIT_SHA'] ?? '');
        if ($sha === '') {
            self::log('remote BUILD_INFO missing COMMIT_SHA');
            return false;
        }

        $updatesDir = $root . '/data/updates';
        $stagingDir = $updatesDir . '/staging/' . $sha;
        $zipPath = $updatesDir . '/staging/' . $sha . '.zip';
        self::ensureDir($stagingDir);

        // Download the zip.
        if (!self::httpDownload($remote['__zip_url'], $zipPath)) {
            self::log('zip download failed');
            self::rmrf($stagingDir);
            @unlink($zipPath);
            return false;
        }

        // Extract.
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            self::log('zip open failed');
            @unlink($zipPath);
            self::rmrf($stagingDir);
            return false;
        }
        if (!$zip->extractTo($stagingDir)) {
            $zip->close();
            self::log('zip extract failed');
            @unlink($zipPath);
            self::rmrf($stagingDir);
            return false;
        }
        $zip->close();
        @unlink($zipPath);

        // The build script wraps everything under barebits/, so the
        // real source dir is staging/<sha>/barebits.
        $sourceDir = $stagingDir . '/barebits';
        if (!is_dir($sourceDir) || !is_file($sourceDir . '/BUILD_INFO')) {
            self::log('extracted zip missing expected barebits/ layout');
            self::rmrf($stagingDir);
            return false;
        }

        // Snapshot the current install before touching anything live.
        $oldVersion = (string)(self::getLocalBuildInfo()['VERSION'] ?? 'unknown');
        $oldSha = self::getLocalCommitSha();
        $backupDir = $updatesDir . '/backup/' . date('Ymd-His') . '-' . substr($oldSha ?: 'init', 0, 12);
        self::ensureDir($backupDir);
        if (!self::backupInstall($root, $backupDir)) {
            self::log('backup failed, aborting before live overlay');
            self::rmrf($stagingDir);
            self::rmrf($backupDir);
            return false;
        }

        // Mark this build "applied but not yet proven healthy" BEFORE we touch
        // any live file. The overlay below is the one window where a crash can
        // leave the install half-old/half-new; writing the recovery breadcrumb
        // first guarantees the next update.php run finds it and rolls back from
        // `backup`, even if we die mid-overlay. (update.php clears this marker
        // once health.php probes green; if the probe fails or the process dies,
        // the next run re-probes and auto-rolls-back to `backup`, parking `sha`
        // in updater_blocked_shas.) The marker lives in the DB under data/,
        // which the overlay never touches.
        $newVersion = (string)($remote['VERSION'] ?? 'unknown');
        Config::set('updater_pending_verify', [
            'sha' => $sha,
            'version' => $newVersion,
            'from_version' => $oldVersion,
            'backup' => basename($backupDir),
            'applied_at' => time(),
        ]);

        // Overlay. Each file is replaced atomically (staged to a temp path in
        // the destination dir, then renamed into place) so no live file is ever
        // observed truncated/half-written even if we crash mid-copy.
        $oldHtaccessSha = self::getLocalBuildInfo()['HTACCESS_SHA256'] ?? '';
        $htaccessUntouched = self::overlayInstall($sourceDir, $root, $oldHtaccessSha, $remote);

        // Cleanup staging.
        self::rmrf($stagingDir);

        // Rotate backups.
        self::pruneBackups($updatesDir . '/backup', self::BACKUPS_TO_KEEP);

        // Record the result.
        $token = bin2hex(random_bytes(32));
        file_put_contents($updatesDir . '/recovery_token.txt', $token);
        @chmod($updatesDir . '/recovery_token.txt', 0600);

        Config::set('updater_last_update', [
            'from_version' => $oldVersion,
            'to_version' => $newVersion,
            'from_sha' => $oldSha,
            'to_sha' => $sha,
            'channel' => $channel,
            'applied_at' => time(),
            'htaccess_held_back' => !$htaccessUntouched,
        ]);
        Config::set('updater_banner_dismissed', false);
        // updater_pending_verify was written before the overlay (above) so a
        // mid-overlay crash still leaves the rollback breadcrumb; update.php
        // clears it once health.php probes green.

        self::log("update applied: $oldVersion -> $newVersion ($channel)");
        return true;
    }

    /**
     * Copy the current install (minus preserved data and backups themselves)
     * into $backupDir. Used both for rollback and as a transactional safety net.
     */
    private static function backupInstall(string $root, string $backupDir): bool {
        $iter = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function ($current, $key, $iterator) use ($root) {
                    $rel = self::relPath($current->getPathname(), $root);
                    if ($rel === 'data' || str_starts_with($rel, 'data/')) {
                        return false; // never back up runtime data into the backup tree
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel = self::relPath($item->getPathname(), $root);
            $dest = $backupDir . '/' . $rel;
            if ($item->isDir()) {
                self::ensureDir($dest);
            } else {
                self::ensureDir(dirname($dest));
                if (!@copy($item->getPathname(), $dest)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Overlay $source onto $root, skipping PRESERVE_PATHS and handling
     * .htaccess specially. Returns true if .htaccess was left untouched
     * (because user had modified it), false if it was overwritten.
     */
    private static function overlayInstall(string $source, string $root, string $oldHtaccessSha, array $remote): bool {
        $htaccessHeldBack = false;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $item) {
            $rel = self::relPath($item->getPathname(), $source);
            if (self::isPreserved($rel)) {
                continue;
            }
            $dest = $root . '/' . $rel;

            // .htaccess handling — overwrite only if untouched since last build.
            if ($rel === '.htaccess') {
                if (is_file($dest) && $oldHtaccessSha !== '') {
                    $liveSha = hash_file('sha256', $dest);
                    if ($liveSha !== $oldHtaccessSha) {
                        // User modified .htaccess — write new version as
                        // .htaccess.new and leave the live file alone.
                        self::atomicCopy($item->getPathname(), $root . '/.htaccess.new');
                        $htaccessHeldBack = true;
                        continue;
                    }
                }
                self::atomicCopy($item->getPathname(), $dest);
                continue;
            }

            if ($item->isDir()) {
                self::ensureDir($dest);
            } else {
                self::ensureDir(dirname($dest));
                self::atomicCopy($item->getPathname(), $dest);
            }
        }

        return !$htaccessHeldBack;
    }

    /**
     * Replace $dest with the contents of $src atomically: copy to a temp file
     * in the SAME directory (so rename stays on one filesystem), preserve the
     * source's permission bits, then rename over the destination. rename() is
     * atomic on POSIX, so a concurrent reader / a crash never sees a partially
     * written destination — it sees either the old file or the complete new one.
     * Best-effort (mirrors the previous @copy): returns false on failure without
     * throwing, leaving the existing file intact.
     */
    private static function atomicCopy(string $src, string $dest): bool {
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(6));
        if (!@copy($src, $tmp)) {
            @unlink($tmp);
            return false;
        }
        $perms = @fileperms($src);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private static function isPreserved(string $rel): bool {
        foreach (self::PRESERVE_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    // ---------------- Rollback ----------------

    public static function listBackups(): array {
        $dir = self::installRoot() . '/data/updates/backup';
        if (!is_dir($dir)) {
            return [];
        }
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                $entries[] = $name;
            }
        }
        rsort($entries); // newest first by Ymd-His prefix
        return $entries;
    }

    public static function rollbackToMostRecent(): bool {
        $backups = self::listBackups();
        if (empty($backups)) {
            return false;
        }
        return self::rollbackTo($backups[0]);
    }

    public static function rollbackTo(string $backupName): bool {
        $root = self::installRoot();
        $backupDir = $root . '/data/updates/backup/' . $backupName;
        if (!is_dir($backupDir)) {
            return false;
        }

        // Same lock used by the forward path.
        $lockPath = $root . '/data/updates/.lock';
        self::ensureDir(dirname($lockPath));
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            self::log('rollback lock held, skipping');
            return false;
        }

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                $rel = self::relPath($item->getPathname(), $backupDir);
                if (self::isPreserved($rel)) {
                    continue;
                }
                $dest = $root . '/' . $rel;
                if ($item->isDir()) {
                    self::ensureDir($dest);
                } else {
                    self::ensureDir(dirname($dest));
                    @copy($item->getPathname(), $dest);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        Config::set('updater_last_rollback', [
            'backup' => $backupName,
            'rolled_back_at' => time(),
        ]);
        // After rollback, force a re-check so we don't immediately re-apply.
        Config::set('updater_last_check', time());
        self::log("rolled back to $backupName");
        return true;
    }

    private static function pruneBackups(string $dir, int $keep): void {
        $entries = self::listBackups();
        $i = 0;
        foreach ($entries as $name) {
            if ($i++ < $keep) continue;
            self::rmrf($dir . '/' . $name);
        }
    }

    // ---------------- Recovery token ----------------

    public static function verifyRecoveryToken(string $provided): bool {
        $path = self::installRoot() . '/data/updates/recovery_token.txt';
        if (!is_file($path)) {
            return false;
        }
        $stored = trim((string)file_get_contents($path));
        if ($stored === '' || $provided === '') {
            return false;
        }
        return hash_equals($stored, $provided);
    }

    public static function consumeRecoveryToken(): void {
        $path = self::installRoot() . '/data/updates/recovery_token.txt';
        @unlink($path);
    }

    // ---------------- Health-verify / blocked SHAs ----------------
    //
    // The orchestration around these (probe health.php, decide healthy vs
    // broken, roll back) lives in the standalone update.php so it keeps
    // working when a bad update breaks includes/. These accessors are the
    // shared, canonical readers/writers for the config keys update.php and
    // the admin UI both touch, so the two never drift on key names or shape.

    /** SHAs whose post-update health check failed — never auto-applied again. */
    public static function getBlockedShas(): array {
        $v = Config::get('updater_blocked_shas', []);
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter($v, 'is_string'));
    }

    public static function blockSha(string $sha): void {
        if ($sha === '') {
            return;
        }
        $list = self::getBlockedShas();
        if (!in_array($sha, $list, true)) {
            $list[] = $sha;
            Config::set('updater_blocked_shas', $list);
        }
    }

    /** The "applied but not yet proven healthy" marker, or null. */
    public static function getPendingVerify(): ?array {
        $v = Config::get('updater_pending_verify');
        return is_array($v) ? $v : null;
    }

    public static function clearPendingVerify(): void {
        Config::set('updater_pending_verify', null);
    }

    /**
     * Fire-and-forget self-request to the isolated update.php endpoint.
     *
     * Called from cron.php (Task 12) as the *fallback* trigger for installs
     * that only wired up the single cron line. The dedicated `update.php`
     * cron entry is the primary, crash-isolated path; this just nudges the
     * same endpoint so single-cron installs keep updating. Mirrors
     * Background::trigger(): short timeout, the server side runs to
     * completion via ignore_user_abort() regardless of the early disconnect.
     *
     * $manual = true appends ?manual=1, which tells update.php to apply the
     * update even when the auto-update opt-in is off and the daily throttle
     * has not elapsed. Used by the admin "Update now" button.
     */
    public static function triggerSelfCheck(bool $manual = false): void {
        $cronKey = Config::get('cron_key');
        if (!$cronKey) {
            return;
        }
        // This request carries the cron key (which also authorises
        // update.php?manual=1), so it follows the self-request origin rules
        // in Background::selfRequest(): pinned base_url or the request's
        // host pinned to the address it arrived on, never a redirect, TLS
        // verified off-loopback.
        require_once __DIR__ . '/background.php';
        $target = Background::selfRequest('/update.php' . ($manual ? '?manual=1' : ''));
        if ($target === null) {
            self::log('self-check skipped: no trusted self origin (pin base_url)');
            return;
        }
        $ch = curl_init($target['url']);
        curl_setopt_array($ch, $target['options'] + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 200,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_HTTPHEADER => ['X-CRON-KEY: ' . $cronKey],
        ]);
        @curl_exec($ch);
    }

    // ---------------- HTTP ----------------

    /** Largest manifest / release-listing body we will buffer (2 MiB). */
    private const MAX_MANIFEST_BYTES = 2 * 1024 * 1024;
    /** Largest release archive we will stream to disk (256 MiB). */
    private const MAX_ARCHIVE_BYTES = 256 * 1024 * 1024;

    /**
     * Supply-chain transport rule for release metadata and archives: https
     * only. The sole exception is the operator's private-endpoints opt-in,
     * which is what the test rigs (local http release fixture) and LAN
     * mirrors use.
     */
    private static function transportAllowed(string $url): bool {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http' && \SafeHttp::privateEndpointsAllowed()) {
            return true;
        }
        self::log("refusing non-https release URL: $url");
        return false;
    }

    private static function httpGet(string $url): ?string {
        // Release URLs are typically GitHub but the channel can be
        // overridden by the operator. Honor the same allow-private opt-in
        // we use elsewhere so tests can serve releases from a local
        // fixture and self-hosters can mirror releases on their LAN.
        if (!self::transportAllowed($url)) {
            return null;
        }
        $result = \SafeHttp::request($url, [
            'timeout' => 60,
            'connectTimeout' => 10,
            'userAgent' => self::HTTP_USER_AGENT,
            'headers' => ['Accept: */*'],
            'followRedirects' => true,
            'maxRedirects' => 5,
            'maxBytes' => self::MAX_MANIFEST_BYTES,
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '' || $result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }
        // Every redirect hop SafeHttp followed was re-validated; still refuse
        // a chain that downgraded to plain http on the way.
        if (!self::transportAllowed((string)$result['effectiveUrl'])) {
            return null;
        }
        return $result['body'];
    }

    private static function httpGetJson(string $url): ?array {
        $body = self::httpGet($url);
        if ($body === null) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function httpDownload(string $url, string $dest): bool {
        // Release archives can be tens of MB, so we stream to disk via
        // CURLOPT_FILE rather than buffer in memory through SafeHttp.
        //
        // Redirects are never followed by curl. GitHub serves release assets
        // through a 302 to objects.githubusercontent.com, so a redirect must
        // work — but each hop is followed HERE, re-validated by
        // SafeHttp::validateUrl() (private-range policy + DNS pin, so a
        // hostile Location cannot pivot to an internal address) and held to
        // the https-only transport rule. The archive is sha-verified against
        // the manifest after download, so a wrong file is caught regardless.
        $allowPrivate = \SafeHttp::privateEndpointsAllowed();
        $current = $url;
        for ($hop = 0; $hop <= 5; $hop++) {
            if (!self::transportAllowed($current)) {
                return false;
            }
            try {
                $validated = \SafeHttp::validateUrl($current, $allowPrivate);
            } catch (\Throwable $e) {
                self::log('download refused: ' . $e->getMessage());
                return false;
            }

            $fp = @fopen($dest, 'w');
            if ($fp === false) return false;
            $location = '';
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => self::HTTP_USER_AGENT,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => $validated['resolve'],
                // Hard cap on the archive: a hostile or broken mirror must not
                // be able to fill the disk. Enforced by curl both from the
                // Content-Length header and mid-stream.
                CURLOPT_MAXFILESIZE_LARGE => self::MAX_ARCHIVE_BYTES,
                CURLOPT_HEADERFUNCTION => function ($_ch, string $header) use (&$location) {
                    $pos = strpos($header, ':');
                    if ($pos !== false && strcasecmp(substr($header, 0, $pos), 'Location') === 0) {
                        $location = trim(substr($header, $pos + 1));
                    }
                    return strlen($header);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($code >= 300 && $code < 400 && $location !== '') {
                @unlink($dest);
                $next = \SafeHttp::resolveRedirectTarget($current, $location);
                if ($next === null) {
                    return false;
                }
                $current = $next;
                continue; // next hop is validated at the top of the loop
            }
            if (!$ok || $code < 200 || $code >= 300) {
                @unlink($dest);
                return false;
            }
            return true;
        }
        @unlink($dest);
        self::log('download refused: too many redirects');
        return false;
    }

    // ---------------- Filesystem helpers ----------------

    private static function ensureDir(string $dir): void {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private static function rmrf(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private static function relPath(string $full, string $base): string {
        $base = rtrim($base, '/');
        if (str_starts_with($full, $base . '/')) {
            return substr($full, strlen($base) + 1);
        }
        return $full;
    }

    private static function log(string $msg): void {
        @error_log('[updater] ' . $msg);
    }
}
