<?php
/**
 * Per-operation file locks.
 *
 * Every path that moves a store's money — manual withdrawal, token export, reclaim,
 * and the cron's automatic withdrawal — takes the same 'funds_<storeId>' lock, so two
 * of them can never select and spend the same proofs at once.
 */

require_once __DIR__ . '/database.php';

/**
 * Acquire a non-blocking exclusive lock (flock) for a named operation, e.g. a per-store
 * withdrawal. Prevents concurrent fund-moving requests (double-click / refresh / two tabs,
 * or the cron and the operator) from each melting a different set of proofs and paying
 * twice. Returns a handle, or null if already locked.
 */
function cashupay_acquire_lock(string $name) {
    $path = Database::getDataDir() . '/.' . preg_replace('/[^a-zA-Z0-9_]/', '', $name) . '.lock';
    $fp = @fopen($path, 'c');
    if (!$fp) {
        return null;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

function cashupay_release_lock($fp): void {
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}
