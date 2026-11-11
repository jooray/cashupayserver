<?php
/**
 * IPv6 lockout keys aggregate per /64: an IPv6 client usually holds a whole
 * /64, so counting per address would hand it 2^64 fresh budgets.
 *
 *  - five reserves from five different addresses in one /64 exhaust the
 *    shared budget; the sixth (yet another address in that /64) is refused;
 *  - a different /64 is unaffected;
 *  - IPv4 keys are per address (unchanged);
 *  - an IPv4-mapped IPv6 address keys on the embedded IPv4, not on the
 *    mapped /64 (which would fold every v4 client together);
 *  - lockoutKey() formats are stable.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

assert_eq('login_attempts_2001:db8:1:2::/64', Security::lockoutKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'), '/64 key');
assert_eq('login_attempts_2001:db8:1:2::/64', Security::lockoutKey('[2001:db8:1:2::1]'), 'brackets stripped, same /64');
assert_eq('login_attempts_203.0.113.9', Security::lockoutKey('203.0.113.9'), 'IPv4 verbatim');
assert_eq('login_attempts_203.0.113.9', Security::lockoutKey('::ffff:203.0.113.9'), 'IPv4-mapped keys on the embedded IPv4');
assert_eq('login_attempts_admin', Security::lockoutKey('admin'), 'non-IP identifiers verbatim');

$granted = 0;
for ($i = 1; $i <= 6; $i++) {
    if (Security::reserveLoginAttempt("2001:db8:1:2::$i")) {
        $granted++;
    }
}
assert_eq(5, $granted, 'six addresses in one /64 share a single 5-attempt budget');
assert_true(Security::isLockedOut('2001:db8:1:2:ffff::1'), 'any other address in that /64 is locked out');
assert_false(Security::isLockedOut('2001:db8:1:3::1'), 'a different /64 is unaffected');
assert_true(Security::reserveLoginAttempt('2001:db8:1:3::1'), 'and still gets attempts');

Security::clearLoginAttempts('2001:db8:1:2::9');
assert_false(Security::isLockedOut('2001:db8:1:2::1'), 'clearing any address in the /64 clears the prefix');

echo "ok\n";
