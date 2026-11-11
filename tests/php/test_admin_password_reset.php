<?php
/**
 * Admin password recovery (two mechanisms):
 *   1. Emailed reset link  — users.email + single-use, time-boxed tokens in
 *      password_reset_tokens (Auth::createPasswordResetToken / peek / reset).
 *   2. File-based reset     — data/reset-admin-password trigger file
 *      (Auth::fileResetRequested / completeFileReset).
 *
 * This exercises the Auth layer directly: token single-use + expiry +
 * newest-link-wins, email lookup, password-strength enforcement, and the
 * file-trigger lifecycle (set password, delete file, old password invalid).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

/** The stored bcrypt hash for the seed 'admin' user. */
function admin_hash(): string {
    $row = Database::fetchOne("SELECT password_hash FROM users WHERE username = 'admin'");
    return $row['password_hash'];
}

// =========================================================================
// Setup: seed the admin with a recovery email (as the setup wizard does).
// =========================================================================
Auth::setAdminPassword('origpass123', 'Admin@Example.com');

$admin = Auth::getUserByUsername('admin');
assert_not_null($admin, 'seed admin created');
assert_eq('Admin@Example.com', $admin['email'], 'recovery email stored on setup');
assert_eq('Admin@Example.com', Auth::getAdminEmail(), 'getAdminEmail returns the address');

// Lookup is case-insensitive and role-scoped.
assert_not_null(Auth::findAdminByEmail('admin@example.com'), 'findAdminByEmail is case-insensitive');
assert_null(Auth::findAdminByEmail('nobody@example.com'), 'unknown email returns null');
assert_null(Auth::findAdminByEmail(''), 'empty email returns null');

// =========================================================================
// Email validation + setUserEmail (the Settings → Recovery email path).
// =========================================================================
assert_null(Auth::validateEmail('a@b.co'), 'valid email accepted');
assert_not_null(Auth::validateEmail('not-an-email'), 'invalid email rejected');

$threw = false;
try { Auth::setUserEmail($admin['id'], 'bogus'); } catch (\InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'setUserEmail rejects an invalid address');

Auth::setUserEmail($admin['id'], 'new@example.com');
assert_eq('new@example.com', Auth::getAdminEmail(), 'recovery email updated');
Auth::setUserEmail($admin['id'], null);
assert_null(Auth::getAdminEmail(), 'recovery email cleared with null');
// Restore for the token tests below.
Auth::setUserEmail($admin['id'], 'admin@example.com');

// =========================================================================
// Mechanism 1: reset token lifecycle.
// =========================================================================
$token = Auth::createPasswordResetToken($admin['id']);
assert_true(strlen($token) >= 32, 'token is a long random string');

// The raw token is never stored — only its hash.
$stored = Database::fetchOne(
    "SELECT token_hash FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL",
    [$admin['id']]
);
assert_not_null($stored, 'a token row exists');
assert_neq($token, $stored['token_hash'], 'raw token is not stored verbatim');
assert_eq(hash('sha256', $token), $stored['token_hash'], 'only the SHA-256 hash is stored');

// peek validates without consuming.
$peeked = Auth::peekPasswordResetToken($token);
assert_not_null($peeked, 'valid token peeks to a user');
assert_eq($admin['id'], $peeked['id'], 'peek returns the right user');
assert_null(Auth::peekPasswordResetToken('deadbeef'), 'garbage token peeks to null');

// Minting a new token invalidates the previous unused one (newest link wins).
$token2 = Auth::createPasswordResetToken($admin['id']);
assert_null(Auth::peekPasswordResetToken($token), 'older token invalidated by a newer one');
assert_not_null(Auth::peekPasswordResetToken($token2), 'newest token still valid');

// A weak password is rejected and does NOT consume the token.
$threw = false;
try { Auth::resetPasswordWithToken($token2, 'short'); } catch (\InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'weak password rejected');
assert_not_null(Auth::peekPasswordResetToken($token2), 'token survives a rejected weak password');

// Successful reset changes the password and consumes the token (single use).
$before = admin_hash();
assert_true(Auth::resetPasswordWithToken($token2, 'brandnewpass1'), 'reset succeeds');
assert_neq($before, admin_hash(), 'password hash changed');
assert_true(password_verify('brandnewpass1', admin_hash()), 'new password verifies');
assert_true(!password_verify('origpass123', admin_hash()), 'old password no longer verifies');
assert_false(Auth::resetPasswordWithToken($token2, 'anotherpass1'), 'token cannot be reused');
assert_null(Auth::peekPasswordResetToken($token2), 'consumed token no longer peeks');

// Expired tokens are rejected (insert one with a past expiry directly).
$now = Database::timestamp();
$expiredRaw = bin2hex(random_bytes(32));
Database::insert('password_reset_tokens', [
    'user_id'    => $admin['id'],
    'token_hash' => hash('sha256', $expiredRaw),
    'created_at' => $now - 7200,
    'expires_at' => $now - 3600,
]);
assert_null(Auth::peekPasswordResetToken($expiredRaw), 'expired token peeks to null');
assert_false(Auth::resetPasswordWithToken($expiredRaw, 'whatever12345'), 'expired token cannot reset');

// =========================================================================
// Mechanism 2: file-based reset.
// =========================================================================
$flag = Auth::fileResetPath();
assert_eq(rtrim(CASHUPAY_DATA_DIR, '/') . '/reset-admin-password', $flag, 'flag lives in the data dir');

// No file → no reset.
assert_false(Auth::fileResetRequested(), 'no trigger file by default');
assert_false(Auth::completeFileReset('willnotapply1'), 'completeFileReset is a no-op without the file');
assert_true(password_verify('brandnewpass1', admin_hash()), 'password untouched without the trigger file');

// Drop the trigger file → reset is offered.
file_put_contents($flag, '');
assert_true(Auth::fileResetRequested(), 'trigger file detected');

// Weak password rejected and the file is preserved (operator can retry).
$threw = false;
try { Auth::completeFileReset('weak'); } catch (\InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'file reset enforces password strength');
assert_true(is_file($flag), 'trigger file preserved after a rejected weak password');

// Successful file reset: password changes and the file is deleted.
assert_true(Auth::completeFileReset('filereset12345'), 'file reset succeeds');
assert_true(password_verify('filereset12345', admin_hash()), 'admin password set by file reset');
assert_true(!password_verify('brandnewpass1', admin_hash()), 'previous password invalidated');
assert_false(is_file($flag), 'trigger file deleted after a successful reset');
assert_false(Auth::fileResetRequested(), 'reset no longer requested once the file is gone');

echo "test_admin_password_reset: ok\n";
