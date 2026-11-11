<?php
/**
 * Global sign-in budget: 20 password attempts per lockout window, across all
 * addresses, so rotating addresses (botnet, forged forwarded header behind a
 * mis-set proxy) buys no more guesses than one address does times four.
 *
 *  - 25 reserves from 25 distinct IPv4 addresses with $countGlobal=true ->
 *    exactly 20 granted;
 *  - a re-auth reserve (countGlobal=false) from a 26th address is still
 *    granted: the authenticated prompts do not spend, nor are they paused by,
 *    the global budget;
 *  - getLockoutRemaining($ip, true) > 0 while paused, 0 without includeGlobal
 *    for an address that never failed;
 *  - clearLoginAttempts('any') (a successful sign-in) lifts the pause.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

$granted = 0;
for ($i = 1; $i <= 25; $i++) {
    if (Security::reserveLoginAttempt("203.0.113.$i", true)) {
        $granted++;
    }
}
assert_eq(20, $granted, 'exactly MAX_GLOBAL_LOGIN_ATTEMPTS of 25 address-rotating sign-ins granted');

// Paused for password sign-in, from any address — even one never seen.
assert_false(Security::reserveLoginAttempt('203.0.113.99', true), 'a fresh address is paused too');
assert_true(Security::getLockoutRemaining('203.0.113.99', true) > 0, 'remaining reported with includeGlobal');
assert_eq(0, Security::getLockoutRemaining('203.0.113.99', false), 'per-address remaining is 0 for a clean address');
assert_false(Security::isLockedOut('203.0.113.99'), 'per-address lockout is not what paused it');

// The authenticated re-auth prompts use the per-address counter only.
assert_true(Security::reserveLoginAttempt('203.0.113.100', false), 're-auth reserve is not blocked by the global pause');

// A successful sign-in clears the budget: the person who proved the password
// is the operator and must not stay locked out.
Security::clearLoginAttempts('203.0.113.5');
assert_true(Security::reserveLoginAttempt('203.0.113.101', true), 'global budget is fresh after clearLoginAttempts');
assert_eq(0, Security::getLockoutRemaining('203.0.113.101', true), 'no remaining pause after clear');

echo "ok\n";
