<?php
/**
 * CashuPayServer - Recovery Endpoint
 *
 * Token-gated rollback for the case where an auto-update broke the
 * install. The token rotates after each successful update and is sent
 * to the admin via email (if configured). Visit:
 *
 *   https://your-site/recover.php?token=<token>
 *
 * On success, the most recent backup is restored over the live install
 * (preserving data/ and user_config.php) and the token is consumed.
 *
 * This file MUST keep working even when an update has broken
 * includes/. It deliberately only requires the minimum to find and
 * restore from data/updates/backup/.
 */

declare(strict_types=1);


require_once __DIR__ . '/includes/http_status.php';
// Hard-coded minimal recovery logic — does not require includes/updater.php
// in case the update broke it. Mirrors Updater::rollbackToMostRecent().

const PRESERVE_PATHS = ['data', 'user_config.php'];

function recover_install_root(): string {
    return __DIR__;
}

function recover_list_backups(): array {
    $dir = recover_install_root() . '/data/updates/backup';
    if (!is_dir($dir)) return [];
    $entries = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (is_dir($dir . '/' . $name)) $entries[] = $name;
    }
    rsort($entries);
    return $entries;
}

function recover_verify_token(string $provided): bool {
    $path = recover_install_root() . '/data/updates/recovery_token.txt';
    if (!is_file($path) || $provided === '') return false;
    $stored = trim((string)file_get_contents($path));
    return $stored !== '' && hash_equals($stored, $provided);
}

function recover_ensure_dir(string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

function recover_is_preserved(string $rel): bool {
    foreach (PRESERVE_PATHS as $p) {
        if ($rel === $p || str_starts_with($rel, $p . '/')) return true;
    }
    return false;
}

function recover_rel(string $full, string $base): string {
    $base = rtrim($base, '/');
    if (str_starts_with($full, $base . '/')) return substr($full, strlen($base) + 1);
    return $full;
}

function recover_apply(string $backupName): bool {
    $root = recover_install_root();
    $backupDir = $root . '/data/updates/backup/' . $backupName;
    if (!is_dir($backupDir)) return false;

    $lockPath = $root . '/data/updates/.lock';
    recover_ensure_dir(dirname($lockPath));
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return false;

    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel = recover_rel($item->getPathname(), $backupDir);
            if (recover_is_preserved($rel)) continue;
            $dest = $root . '/' . $rel;
            if ($item->isDir()) {
                recover_ensure_dir($dest);
            } else {
                recover_ensure_dir(dirname($dest));
                @copy($item->getPathname(), $dest);
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    // Consume the token so it can't be replayed.
    @unlink($root . '/data/updates/recovery_token.txt');
    return true;
}

// ---------------- Request handling ----------------

// Token comes via GET on the confirmation step, via POST when the form
// submits — accept either, but don't fall back to $_REQUEST (cookies can
// override unintentionally).
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if (!is_string($token) || !recover_verify_token($token)) {
    cashupay_status(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Invalid or missing recovery token.\n";
    exit;
}

$backups = recover_list_backups();
if (empty($backups)) {
    cashupay_status(409);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No backups available to roll back to.\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show a confirmation page so a stale email link doesn't roll back
    // accidentally when the URL is opened by a link previewer.
    $latest = htmlspecialchars($backups[0], ENT_QUOTES, 'UTF-8');
    $tokEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html><head><title>Recover previous version</title>
<style>body{font-family:sans-serif;max-width:560px;margin:4em auto;padding:0 1em;line-height:1.5}
button{font-size:1.1em;padding:0.6em 1.2em;cursor:pointer}</style>
</head><body>
<h1>Roll back to previous version</h1>
<p>This will restore the most recent backup ($latest) over your current install,
preserving your data directory and user_config.php.</p>
<p>This token can only be used once.</p>
<form method="POST">
  <input type="hidden" name="token" value="$tokEsc">
  <button type="submit">Roll back now</button>
</form>
</body></html>
HTML;
    exit;
}

// POST: actually perform rollback. Token already verified at top of file.
$ok = recover_apply($backups[0]);
header('Content-Type: text/plain; charset=UTF-8');
if ($ok) {
    echo "Rolled back to {$backups[0]}.\n";
} else {
    cashupay_status(500);
    echo "Rollback failed. Check server error log.\n";
}
