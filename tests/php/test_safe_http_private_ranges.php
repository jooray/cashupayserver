<?php
/**
 * SafeHttp::isPrivateIp() covers the non-public ranges PHP's filter flags
 * miss: CGNAT, 192.0.0.0/24, benchmarking, multicast, 0/8, and the IPv6
 * transition prefixes that embed an IPv4 address (NAT64, 6to4, Teredo,
 * SIIT, IPv4-mapped). Also: validateUrl() refuses userinfo.
 * Literals only — no network needed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/safe_http.php';

$private = [
    // previously covered
    '127.0.0.1', '10.1.2.3', '172.16.5.5', '192.168.1.1', '169.254.169.254', '0.0.0.0', '::1', '::',
    'fc00::1', 'fd12::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1',
    // previously allowed (the gap)
    '100.64.0.1', '100.127.255.254',      // CGNAT
    '192.0.0.1',                          // IETF protocol assignments
    '198.18.0.1', '198.19.255.255',       // benchmarking
    '224.0.0.1', '239.255.255.250',       // multicast
    '0.1.2.3',                            // 0/8
    '240.0.0.1',                          // reserved
    '64:ff9b::7f00:1', '64:ff9b::a00:1',  // NAT64 well-known prefix
    '64:ff9b:1::1',                       // NAT64 local-use
    '2002:a00:1::',                       // 6to4 embedding 10.0.0.1
    '2001::1',                            // Teredo
    '2001:10::1',                         // ORCHID (2001::/23)
    '100::1',                             // discard
    'fec0::1',                            // site-local
    'ff02::1',                            // IPv6 multicast
    '::ffff:0:7f00:1',                    // SIIT IPv4-translated
    '2001:db8::1',                        // documentation
    'not-an-ip', '',                      // unparsable -> refuse
];
foreach ($private as $ip) {
    assert_true(SafeHttp::isPrivateIp($ip), "private/reserved refused: '$ip'");
}

$public = [
    '8.8.8.8', '1.1.1.1', '203.0.114.1', '100.128.0.1', '100.63.255.255', '192.0.1.1', '198.17.255.255',
    '198.20.0.1', '223.255.255.255', '2606:4700::1', '2a00:1450:4001::1', '2001:4860:4860::8888',
    '::ffff:8.8.8.8', '2003::1', '101::1',
];
foreach ($public as $ip) {
    assert_false(SafeHttp::isPrivateIp($ip), "public allowed: '$ip'");
}

// ipInRange / normalizeIp helpers
assert_true(SafeHttp::ipInRange('10.255.0.1', '10.0.0.0/8'), 'v4 CIDR');
assert_false(SafeHttp::ipInRange('11.0.0.1', '10.0.0.0/8'), 'v4 CIDR miss');
assert_true(SafeHttp::ipInRange('64:ff9b::1', '64:ff9b::/96'), 'v6 CIDR');
assert_false(SafeHttp::ipInRange('10.0.0.1', '64:ff9b::/96'), 'family mismatch never matches');
assert_true(SafeHttp::ipInRange('203.0.113.9', '203.0.113.9'), 'single address range');
assert_false(SafeHttp::ipInRange('203.0.113.9', '203.0.113.0/x'), 'bad prefix length');
assert_eq('8.8.8.8', SafeHttp::normalizeIp('::ffff:8.8.8.8'), 'mapped unwrapped');
assert_eq('2001:db8::1', SafeHttp::normalizeIp('2001:0db8:0000:0000:0000:0000:0000:0001'), 'canonical v6');
assert_null(SafeHttp::normalizeIp('example.com'), 'hostnames are not IPs');

// validateUrl: userinfo refused regardless of host policy
foreach (['https://u:p@example.com/', 'https://user@8.8.8.8/x', 'http://a:b@127.0.0.1/'] as $u) {
    $threw = null;
    try {
        SafeHttp::validateUrl($u, true);
    } catch (\RuntimeException $e) {
        $threw = $e->getMessage();
    }
    assert_not_null($threw, "userinfo refused: $u");
    assert_true(stripos((string)$threw, 'credential') !== false, "userinfo message: $threw");
}

// validateUrl: a literal in a newly covered range is refused even though it
// parses as an IP (no DNS involved).
$threw = false;
try {
    SafeHttp::validateUrl('http://100.64.0.1/hook');
} catch (\RuntimeException $e) {
    $threw = true;
}
assert_true($threw, 'CGNAT literal refused by validateUrl');
$threw = false;
try {
    SafeHttp::validateUrl('http://[64:ff9b::7f00:1]/hook');
} catch (\RuntimeException $e) {
    $threw = true;
}
assert_true($threw, 'NAT64 literal refused by validateUrl');
// ...and allowed with the operator opt-in.
$v = SafeHttp::validateUrl('http://100.64.0.1:8080/hook', true);
assert_eq(['100.64.0.1:8080:100.64.0.1'], $v['resolve'], 'allowPrivate pins the literal');

echo "ok\n";
