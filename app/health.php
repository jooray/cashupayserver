<?php
/**
 * CashuPayServer - Health Probe
 *
 * Returns 200 {"ok":true} only when the full server bootstrap loads cleanly
 * and the database is reachable. Any parse/fatal error in a bootstrapped
 * module, or an unreachable DB, yields a non-2xx response.
 *
 * This is the signal update.php uses to decide whether a freshly-applied
 * update is healthy or must be rolled back. Because a bad update can
 * introduce an *uncatchable* PHP parse/fatal error, health must be checked
 * out-of-process over HTTP — update.php can't safely require() possibly-broken
 * code and catch the failure itself.
 *
 * The require list below deliberately MIRRORS cron.php's bootstrap: the
 * auto-updater runs in the cron context, so "does cron.php's module set load"
 * is exactly the question that matters. Keep this list in sync with cron.php.
 *
 * Gated by the cron key (?key= or X-CRON-KEY header) so it isn't a public
 * probe. A genuinely broken bootstrap returns 500 before auth can run — an
 * unauthenticated caller still only ever sees a bare 500 with no detail.
 */

declare(strict_types=1);

// --- Code-cache refresh: FIRST, before any other include ---------------------
//
// The updater replaces files in place while PHP keeps serving. With opcache on
// (the default in the official php-fpm / php-apache images and on most hosts),
// already-compiled scripts stay cached until their revalidation timer fires,
// so for a moment the server runs a MIX of old and new code: a new admin.php
// calling a method the cached old config.php lacks, a new vendor/autoload.php
// naming an autoloader class the cached old autoload_real.php never defined.
// That is a 500 window right after an update the health probe just called
// healthy. The updater resets opcache after its own overlay/rollback, but the
// overlay is performed by the RELEASE BEING REPLACED — and older releases do
// not know to reset. This probe is the one request every updater, however old,
// makes right after an overlay, on the NEW code, so it resets the cache here
// whenever the on-disk build is not the one it last reset for. A plain marker
// file (not the DB) records that, so this runs before the possibly-stale
// bootstrap is even loaded. Best-effort: no opcache means nothing to do.

/**
 * Reset opcache once per on-disk BUILD_INFO. The marker records the build's
 * COMMIT_SHA together with the BUILD_INFO file's mtime and inode: every
 * overlay rewrites BUILD_INFO, so a rollback-then-reapply of the SAME build
 * (channel switched away and back) is a new identity and flushes again, even
 * though the SHA is one it has seen before. Returns 'current' (marker already
 * names this identity), 'reset' (opcache flushed), 'unavailable' (opcache
 * absent or refused; marker still written so the probe does not retry
 * forever) or 'no_build_info' (dev checkout).
 */
function cashupay_health_refresh_opcache(string $root, string $dataDir): string {
    clearstatcache(true, $root . '/BUILD_INFO');
    $info = @file_get_contents($root . '/BUILD_INFO');
    $sha = '';
    if (is_string($info) && preg_match('/^COMMIT_SHA=(.*)$/m', $info, $m)) {
        $sha = trim($m[1]);
    }
    if ($sha === '') {
        return 'no_build_info';
    }
    $stat = @stat($root . '/BUILD_INFO');
    $identity = $sha . ' ' . (int)($stat['mtime'] ?? 0) . ' ' . (int)($stat['ino'] ?? 0);
    $marker = rtrim($dataDir, '/') . '/updates/opcache_reset_sha';
    $last = is_file($marker) ? trim((string)@file_get_contents($marker)) : '';
    if ($last === $identity) {
        return 'current';
    }
    // Per-file invalidation first: it takes effect immediately, whereas
    // opcache_reset() only schedules a restart that PHP defers while other
    // scripts are still running. data/ holds no served code (and the backups).
    $did = false;
    if (function_exists('opcache_invalidate')) {
        $did = true;
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    static function ($current) use ($root): bool {
                        return !($current->isDir() && $current->getPathname() === $root . '/data');
                    }
                )
            );
            foreach ($iter as $file) {
                if (substr($file->getFilename(), -4) === '.php') {
                    @opcache_invalidate($file->getPathname(), true);
                }
            }
        } catch (Throwable $e) {
            // best-effort; the reset below still runs
        }
    }
    if (function_exists('opcache_reset') && @opcache_reset()) {
        $did = true;
    }
    if (!is_dir(dirname($marker))) {
        @mkdir(dirname($marker), 0755, true);
    }
    @file_put_contents($marker, $identity . "\n");
    return $did ? 'reset' : 'unavailable';
}

// Test hook: when defined, this file only DEFINES the helper above and does
// not run the probe (mirrors update.php's CASHUPAY_UPDATE_PHP_NO_RUN).
if (defined('CASHUPAY_HEALTH_NO_RUN') && CASHUPAY_HEALTH_NO_RUN) {
    return;
}

// Operator constants (CASHUPAY_DATA_DIR) live in files the overlay preserves,
// so they are safe to load before the rest of the code. Same order as
// database.php / update.php.
if (file_exists(__DIR__ . '/includes/config.local.php')) {
    require_once __DIR__ . '/includes/config.local.php';
}
if (file_exists(__DIR__ . '/user_config.php')) {
    require_once __DIR__ . '/user_config.php';
}
cashupay_health_refresh_opcache(
    __DIR__,
    defined('CASHUPAY_DATA_DIR') ? (string)CASHUPAY_DATA_DIR : __DIR__ . '/data'
);

require_once __DIR__ . '/includes/http_status.php';
// Pessimistic default: only an explicit success path flips this to 200.
cashupay_status(503);
header('Content-Type: application/json');

// Catch fatals/parse errors that abort the require chain below. The response
// code is already non-2xx; this just emits a clean JSON body instead of a
// blank page (and re-asserts 500 in case something flipped it).
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array(
        $err['type'],
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
        true
    )) {
        if (!headers_sent()) {
            cashupay_status(500);
        }
        echo json_encode(['ok' => false, 'error' => 'bootstrap_fatal']);
    }
});

// --- Minimal bootstrap for auth. If even this fatals, the install is broken
//     and the pessimistic 503/500 above is the correct (unhealthy) answer. ---
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';

$providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$cronKey = Config::get('cron_key');
if (!$cronKey || !is_string($providedKey) || !hash_equals((string)$cronKey, (string)$providedKey)) {
    cashupay_status(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// --- Full bootstrap: the real health signal. Mirrors cron.php's require set.
//     A parse/fatal in any of these is what a bad update looks like, and the
//     shutdown handler above turns that into a non-2xx {ok:false}. ---
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/lightning_address.php';
require_once __DIR__ . '/includes/dev_fee.php';
require_once __DIR__ . '/includes/free_trial.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/onchain/payments.php';
require_once __DIR__ . '/includes/swap/poller.php';
require_once __DIR__ . '/includes/swap/auto_melt.php';
require_once __DIR__ . '/includes/offline_cashu.php';
require_once __DIR__ . '/includes/trusted_mints.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/notification_sender.php';

// --- DB reachability: a loaded-but-unusable database is still unhealthy. ---
try {
    $pdo = Database::getInstance();
    $row = $pdo->query('SELECT 1 AS ok')->fetch();
    if (!$row) {
        throw new RuntimeException('db read returned nothing');
    }
} catch (Throwable $e) {
    cashupay_status(500);
    echo json_encode(['ok' => false, 'error' => 'db_unreachable']);
    exit;
}

cashupay_status(200);
echo json_encode([
    'ok' => true,
    'version' => Updater::getLocalBuildInfo()['VERSION'] ?? CASHUPAY_VERSION,
    'sha' => Updater::getLocalBuildInfo()['COMMIT_SHA'] ?? '',
]);
