<?php
/**
 * CashuPayServer - Cron Endpoint
 *
 * Background task processing for quote polling, sync, recovery, and cleanup.
 *
 * Can be called in two ways:
 * 1. External cron: curl -s https://your-domain.com/cron.php?key=YOUR_CRON_KEY
 * 2. Internal self-request: Triggered automatically by Background::trigger()
 *
 * Example cron entry (optional - system works without it):
 * * * * * * curl -s https://your-domain.com/cron.php?key=YOUR_CRON_KEY
 */

require_once __DIR__ . '/includes/entrypoint_guard.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/lightning_address.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/background_runner.php';

// Check if setup is complete
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo 'Not configured';
    exit;
}

// Ensure script continues even if client disconnects (fire-and-forget from Background::trigger)
ignore_user_abort(true);

// Verify authorization
$providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$isInternal = isset($_GET['internal']) && $_GET['internal'] === '1';

if ($isInternal) {
    // Internal self-request - verify internal key
    if (!Background::verifyInternalKey($providedKey)) {
        http_response_code(403);
        echo 'Invalid internal key';
        exit;
    }
} else {
    // External cron request - a cron key is REQUIRED. Auto-generate on first use so
    // upgrades don't silently leave the endpoint open. Without a valid key, external
    // callers cannot trigger background tasks (incl. auto-melt / cleanup).
    $cronKey = Config::get('cron_key');
    if (!$cronKey) {
        $cronKey = bin2hex(random_bytes(16));
        Config::set('cron_key', $cronKey);
    }
    if (!hash_equals($cronKey, (string)$providedKey)) {
        http_response_code(403);
        echo 'Invalid cron key';
        exit;
    }
}

// Set content type
header('Content-Type: application/json');

// One leased, time-budgeted runner shared with WP-Cron and the admin "Run now" action.
// A run stops when its budget is spent and the next one continues from that point, so a
// slow mint early in the list cannot starve webhook delivery at the end of it.
$budget = (int)($_GET['budget'] ?? 0);
if ($budget < 5 || $budget > 300) {
    $budget = BackgroundRunner::DEFAULT_BUDGET;
}

echo json_encode(BackgroundRunner::run($budget), JSON_PRETTY_PRINT);
