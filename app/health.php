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
