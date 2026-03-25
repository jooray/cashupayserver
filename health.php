<?php
/**
 * CashuPayServer - Health Endpoint
 */

require_once __DIR__ . '/includes/health.php';

header('Content-Type: application/json');

try {
    $report = SystemHealth::buildReport();
    http_response_code($report['ok'] ? 200 : 503);
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'health-check-failed',
        'message' => $e->getMessage(),
        'timestamp' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
