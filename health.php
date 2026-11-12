<?php
/**
 * CashuPayServer - Health endpoint for uptime monitoring
 *
 *   GET /health          (also /health.php, /router.php/health, /cashupay/health in WordPress)
 *
 * Without credentials: {"status":"ok"|"degraded"|"unavailable"}, HTTP 200 or 503.
 * With the monitoring key (X-Health-Key header, or ?key= for monitors that can only
 * call a URL), the cron key, or a logged-in admin session: the individual checks and
 * the background heartbeat as well. A key that is given but wrong is a 403, so a typo
 * in the monitor's configuration does not look like a healthy server.
 *
 * GET and HEAD only, no side effects (see includes/health.php).
 */

// A stranger must never see a PHP warning, a path or a stack trace from here.
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/entrypoint_guard.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/health.php';

/** Send a JSON answer (no body for HEAD) and stop. */
function cashupay_health_respond(int $code, array $body): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
        // Answers differ by credentials and change from minute to minute: never cache.
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        if ($code === 503) {
            header('Retry-After: 60');
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('CashuPayServer: health check failed: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . $e->getFile() . ':' . $e->getLine());
    cashupay_health_respond(503, ['status' => Health::UNAVAILABLE]);
});

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    cashupay_health_respond(405, ['status' => 'method-not-allowed']);
}

// Credentials, if any. Read as plain strings: ?key[]=x must not reach hash_equals().
$providedKey = $_SERVER['HTTP_X_HEALTH_KEY'] ?? '';
if (!is_string($providedKey) || $providedKey === '') {
    $providedKey = $_GET['key'] ?? '';
}
$providedKey = is_string($providedKey) ? trim($providedKey) : '';

$now = time();
$report = Health::report($now);
$detailed = false;

if ($providedKey !== '') {
    // A key could only be checked against a working database.
    $keyUsable = $report['databaseOk'];
    if (!$keyUsable || !Health::keyMatches($providedKey)) {
        if ($keyUsable) {
            cashupay_health_respond(403, ['status' => 'forbidden']);
        }
        // The database is down: the public answer (503) is the useful one.
    } else {
        $detailed = true;
    }
} elseif ($report['databaseOk']) {
    // The operator looking at it in the browser. Only when a session cookie is present,
    // so an anonymous probe does not start (and write) a session on every call.
    $isWordPress = defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS;
    if ($isWordPress || isset($_COOKIE['cashupay_session'])) {
        require_once __DIR__ . '/includes/auth.php';
        $detailed = Auth::isLoggedIn();
    }
}

cashupay_health_respond(
    Health::httpCode($report['status']),
    $detailed ? Health::detailedView($report, $now) : Health::publicView($report)
);
