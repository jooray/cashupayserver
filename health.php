<?php
/**
 * CashuPayServer - Health Endpoint
 */

require_once __DIR__ . '/includes/health.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

try {
    $report = SystemHealth::buildReport();

    $cronKey = (string)Config::get('cron_key', '');
    $providedKey = (string)($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
    $isCronAuthorized = $cronKey !== '' && hash_equals($cronKey, $providedKey);
    $includeDetailed = (($_GET['full'] ?? '0') === '1') && (Auth::isLoggedIn() || $isCronAuthorized);

    if (!$includeDetailed) {
        $report = [
            'ok' => (bool)($report['ok'] ?? false),
            'timestamp' => (int)($report['timestamp'] ?? time()),
            'setupComplete' => (bool)($report['setupComplete'] ?? false),
            'databaseInitialized' => (bool)($report['databaseInitialized'] ?? false),
        ];
    }

    http_response_code(($report['ok'] ?? false) ? 200 : 503);
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('Health check failed: ' . $e);
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'health-check-failed',
        'message' => 'Health check failed',
        'timestamp' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
