<?php
/**
 * One-shot verification that every users.password_hash row is a modern
 * bcrypt hash (PASSWORD_BCRYPT). Flags anything that fails password_get_info().
 *
 * Usage:  php scripts/verify_password_hashes.php [path/to/database.db]
 *
 * Exit code 0: all rows OK.
 * Exit code 1: one or more rows are not bcrypt — see stderr for details.
 */

require_once __DIR__ . '/../app/includes/database.php';

$dbPath = $argv[1] ?? Database::getDbPath();
if (!file_exists($dbPath)) {
    fwrite(STDERR, "ERROR: database not found at {$dbPath}\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query("SELECT id, username, password_hash FROM users")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No users in database — nothing to verify.\n";
    exit(0);
}

$bad = [];
foreach ($rows as $row) {
    $hash = (string)($row['password_hash'] ?? '');
    if ($hash === '') {
        $bad[] = "{$row['username']} ({$row['id']}): empty password_hash";
        continue;
    }
    $info = password_get_info($hash);
    $algo = $info['algo'] ?? null;
    // PHP 7.4+ returns the integer algo constant; PHP 8.4+ returns the string
    // name. Accept either form of bcrypt.
    $isBcrypt = ($algo === PASSWORD_BCRYPT) || ($algo === 'bcrypt') || ($algo === '2y');
    if (!$isBcrypt) {
        $algoName = $info['algoName'] ?? var_export($algo, true);
        $bad[] = "{$row['username']} ({$row['id']}): algo={$algoName}, hash starts with " . substr($hash, 0, 8);
    }
}

$total = count($rows);
$okCount = $total - count($bad);
echo "Checked {$total} user row(s): {$okCount} bcrypt, " . count($bad) . " non-bcrypt.\n";

if ($bad) {
    fwrite(STDERR, "Non-bcrypt rows found:\n");
    foreach ($bad as $line) {
        fwrite(STDERR, "  - {$line}\n");
    }
    exit(1);
}

echo "All password hashes are bcrypt (PASSWORD_BCRYPT) with built-in salt — OK.\n";
exit(0);
