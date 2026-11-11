<?php
/**
 * The authenticated re-auth prompts (reveal_store_seed, change_own_password)
 * reserve a per-address attempt BEFORE the password check, so a hijacked
 * session cannot brute-force the admin password through them.
 *
 * Exercises the exact helper sequence admin.php runs:
 *   reserveLoginAttempt(ip) [per-address, no global] -> verifyCurrentUserPassword
 * Six wrong passwords: the first five reach bcrypt, the sixth is refused
 * before it. The global sign-in budget is untouched throughout, so the
 * operator can still sign in from elsewhere; a correct re-auth after the
 * window would succeed (asserted by clearing the counter).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

Auth::setAdminPassword('correct-horse-battery');
$admin = Auth::getUserByUsername('admin');
assert_not_null($admin, 'admin user exists');

// Simulate the (hijacked) admin session.
Auth::initSession();
$_SESSION['user_id'] = $admin['id'];
$_SESSION['user_role'] = Auth::ROLE_ADMIN;

$ip = '198.51.100.77';
$_SERVER['REMOTE_ADDR'] = $ip;

$reachedBcrypt = 0;
$refusedBefore = 0;
for ($i = 0; $i < 6; $i++) {
    if (!Security::reserveLoginAttempt(Security::getClientIp())) {
        $refusedBefore++;
        continue;
    }
    $reachedBcrypt++;
    assert_false(Auth::verifyCurrentUserPassword("wrong-$i"), 'wrong password rejected');
}
assert_eq(5, $reachedBcrypt, 'five wrong re-auth answers reach the password check');
assert_eq(1, $refusedBefore, 'the sixth is refused before any password check');

// Even the RIGHT password is refused while the address is locked out — the
// prompt checks the reservation first.
assert_false(Security::reserveLoginAttempt($ip), 'locked-out address gets no check at all');

// The global sign-in budget was not spent by the prompt.
assert_true(Security::reserveLoginAttempt('203.0.113.50', true), 'password sign-in from elsewhere still has budget');
assert_eq(0, Security::getLockoutRemaining('203.0.113.50', true), 'no global pause');

// Once the window passes (simulated via clear) the correct password works.
Security::clearLoginAttempts($ip);
assert_true(Security::reserveLoginAttempt($ip), 'fresh budget after the lockout ends');
assert_true(Auth::verifyCurrentUserPassword('correct-horse-battery'), 'correct re-auth succeeds');

echo "ok\n";
