<?php
/**
 * Security::getClientIp() must NOT trust forwarding headers (X-Forwarded-For,
 * CF-Connecting-IP, X-Real-IP) unless the request actually came from a
 * configured trusted proxy. Otherwise any client spoofs its IP with a header
 * and defeats the login lockout and every per-IP rate limit.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

fresh_db(); // Config::get reads the DB; trusted_proxies comes from env here.

function reset_server(): void {
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'],
          $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP']);
}

// ---- No trusted proxies: headers are ignored entirely ----------------------
putenv('CASHUPAY_TRUSTED_PROXIES');           // unset
reset_server();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
assert_eq('203.0.113.9', Security::getClientIp(), 'untrusted remote: spoofed headers ignored, REMOTE_ADDR wins');

// ---- Request from an untrusted remote, even with a real-looking XFF ---------
reset_server();
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
assert_eq('198.51.100.7', Security::getClientIp(), 'no proxy configured -> XFF ignored');

// ---- Trusted proxy: single-IP allowlist ------------------------------------
putenv('CASHUPAY_TRUSTED_PROXIES=127.0.0.1, ::1');
reset_server();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.5.5.5';
assert_eq('5.5.5.5', Security::getClientIp(), 'trusted proxy: XFF client honored');

// CF-Connecting-IP wins when present (single real client IP from Cloudflare).
reset_server();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '4.4.4.4';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.5.5.5';
assert_eq('4.4.4.4', Security::getClientIp(), 'trusted proxy: CF-Connecting-IP preferred');

// ---- Trusted proxy via CIDR + right-most-untrusted-hop walk -----------------
putenv('CASHUPAY_TRUSTED_PROXIES=10.0.0.0/8');
reset_server();
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';                                // trusted (CIDR)
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 10.0.0.9';             // last hop is our proxy
assert_eq('8.8.8.8', Security::getClientIp(),
    'walk right-to-left: skip trusted 10.0.0.9, return real client 8.8.8.8');

// Spoof attempt: attacker prepends a fake hop. The right-most untrusted hop is
// still the genuine client appended by the proxy, NOT the attacker's [0].
reset_server();
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 7.7.7.7';             // 1.1.1.1 is attacker-written
assert_eq('7.7.7.7', Security::getClientIp(), 'left-most spoof not taken; right-most untrusted hop wins');

// ---- Wildcard trust-all -----------------------------------------------------
putenv('CASHUPAY_TRUSTED_PROXIES=*');
reset_server();
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
assert_eq('6.6.6.6', Security::getClientIp(), 'wildcard trusts all proxies');

// ---- Garbage REMOTE_ADDR falls back to 0.0.0.0 ------------------------------
putenv('CASHUPAY_TRUSTED_PROXIES');
reset_server();
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
assert_eq('0.0.0.0', Security::getClientIp(), 'invalid REMOTE_ADDR -> 0.0.0.0');

putenv('CASHUPAY_TRUSTED_PROXIES');
reset_server();
echo "PASS test_client_ip_trusted_proxy\n";
exit(0);
