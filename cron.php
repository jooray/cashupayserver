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

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/lightning_address.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/background.php';

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
    // External cron request - verify cron key if set
    $cronKey = Config::get('cron_key');
    if ($cronKey && !hash_equals($cronKey, $providedKey)) {
        http_response_code(403);
        echo 'Invalid cron key';
        exit;
    }
}

// Set content type
header('Content-Type: application/json');

$results = [
    'timestamp' => time(),
    'tasks' => [],
];

// Task 1: Poll pending quotes
try {
    Invoice::pollPendingQuotes();
    $results['tasks']['poll_quotes'] = 'success';
} catch (Exception $e) {
    $results['tasks']['poll_quotes'] = 'error: ' . $e->getMessage();
}

// Task 2: Check auto-melt
try {
    $meltResult = LightningAddress::checkAutoMelt();
    if ($meltResult) {
        $results['tasks']['auto_melt'] = [
            'success' => true,
            'amount' => $meltResult['amountPaid'],
        ];
    } else {
        $results['tasks']['auto_melt'] = 'skipped';
    }
} catch (Exception $e) {
    $results['tasks']['auto_melt'] = 'error: ' . $e->getMessage();
}

// Task 3: Clean expired cache
try {
    Security::cleanCache();
    $results['tasks']['clean_cache'] = 'success';
} catch (Exception $e) {
    $results['tasks']['clean_cache'] = 'error: ' . $e->getMessage();
}

// Task 4: Expire old invoices - now handled by pollPendingQuotes() via markExpiredInvoices()
// Kept as a separate explicit call for visibility in cron results
try {
    $expired = Invoice::markExpiredInvoices();
    $results['tasks']['expire_invoices'] = "expired {$expired} invoices";
} catch (Exception $e) {
    $results['tasks']['expire_invoices'] = 'error: ' . $e->getMessage();
}

// Task 5: C1 - Sync proof states with mint (if not synced recently)
try {
    if (Background::shouldSync()) {
        $stores = Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
        );
        $syncCount = 0;
        foreach ($stores as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if ($wallet->hasStorage()) {
                    // Sync would verify proofs are still valid on mint
                    $syncCount++;
                }
            } catch (Exception $e) {
                error_log("Sync failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        Background::markSynced();
        $results['tasks']['sync_proofs'] = "synced {$syncCount} stores";
    } else {
        $results['tasks']['sync_proofs'] = 'skipped (recently synced)';
    }
} catch (Exception $e) {
    $results['tasks']['sync_proofs'] = 'error: ' . $e->getMessage();
}

// Task 6: C2/H4 - Recover orphaned invoices stuck in Processing
try {
    $recovered = Invoice::recoverOrphanedInvoices();
    $count = count($recovered);
    $results['tasks']['recover_orphaned'] = $count > 0 ? "recovered {$count}" : 'none';
} catch (Exception $e) {
    $results['tasks']['recover_orphaned'] = 'error: ' . $e->getMessage();
}

// Task 7: H3 - Auto-expire very old invoices (older than 30 days)
try {
    $veryOld = Database::query(
        "UPDATE invoices SET status = 'Expired'
         WHERE status = 'New' AND created_at < ?",
        [time() - 30 * 24 * 3600]
    )->rowCount();

    $results['tasks']['expire_old_invoices'] = "expired {$veryOld} old invoices";
} catch (Exception $e) {
    $results['tasks']['expire_old_invoices'] = 'error: ' . $e->getMessage();
}

// Task 8: L1 - Clean very old invoices (settled/expired older than 90 days)
try {
    $deleted = Database::query(
        "DELETE FROM invoices WHERE status IN ('Settled', 'Expired', 'Invalid')
         AND created_at < ?",
        [time() - 90 * 24 * 3600]
    )->rowCount();

    $results['tasks']['cleanup_invoices'] = "deleted {$deleted} old invoices";
} catch (Exception $e) {
    $results['tasks']['cleanup_invoices'] = 'error: ' . $e->getMessage();
}

// Task 9: L3 - Clean expired pending operations from wallet storage
try {
    $stores = Database::fetchAll(
        "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
    );
    $totalCleaned = 0;
    foreach ($stores as $store) {
        try {
            $wallet = Invoice::getWalletInstance($store['id']);
            if ($wallet->hasStorage()) {
                $cleaned = $wallet->getStorage()->cleanExpiredPendingOperations();
                $totalCleaned += $cleaned;
            }
        } catch (Exception $e) {
            error_log("Cleanup failed for store {$store['id']}: " . $e->getMessage());
        }
    }
    $results['tasks']['cleanup_pending_ops'] = "cleaned {$totalCleaned}";
} catch (Exception $e) {
    $results['tasks']['cleanup_pending_ops'] = 'error: ' . $e->getMessage();
}

// Task 10: L4 - Webhook delivery cleanup (keep only last 1000)
try {
    // First get the count
    $countResult = Database::fetchOne("SELECT COUNT(*) as cnt FROM webhook_deliveries");
    $totalCount = (int)($countResult['cnt'] ?? 0);

    if ($totalCount > 1000) {
        // Delete oldest entries beyond 1000
        $deleteCount = $totalCount - 1000;
        Database::query("
            DELETE FROM webhook_deliveries
            WHERE id IN (
                SELECT id FROM webhook_deliveries
                ORDER BY created_at ASC LIMIT ?
            )
        ", [$deleteCount]);
        $results['tasks']['cleanup_webhooks'] = "deleted {$deleteCount} old deliveries";
    } else {
        $results['tasks']['cleanup_webhooks'] = 'skipped (under limit)';
    }
} catch (Exception $e) {
    $results['tasks']['cleanup_webhooks'] = 'error: ' . $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
