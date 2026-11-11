<?php
/**
 * CashuPayServer - Per-operation file locks
 *
 * Every path that moves a store's money — manual withdrawal, token export,
 * reclaiming an export, the cron's automatic cashout and the fee melt — takes
 * the same 'funds_<storeId>' lock, so two of them can never select and spend
 * the same proofs at once (a double-click, two tabs, or the operator racing
 * the cron tick).
 *
 * The lock is a non-blocking flock() on a file next to the database, which
 * the kernel releases when the request ends — on a fatal, a timeout or a kill
 * alike — so a crashed request can never wedge a store.
 */

require_once __DIR__ . '/database.php';

/**
 * Path of the lock file for a named operation. The name is reduced to
 * [A-Za-z0-9_] so a caller-supplied id can never escape the data directory.
 */
function cashupay_lock_path(string $name): string {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    return dirname(Database::getDbPath()) . '/.' . $safe . '.lock';
}

/**
 * Acquire a non-blocking exclusive lock for a named operation (e.g. a
 * per-store withdrawal). Returns the handle to hand to cashupay_release_lock(),
 * or null when another request already holds it.
 *
 * @return resource|null
 */
function cashupay_acquire_lock(string $name) {
    $fp = @fopen(cashupay_lock_path($name), 'c');
    if (!$fp) {
        return null;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

/**
 * Release a lock taken with cashupay_acquire_lock(). Safe to call with null
 * (the acquire failed) or twice.
 *
 * @param resource|null $fp
 */
function cashupay_release_lock($fp): void {
    if (is_resource($fp)) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}
