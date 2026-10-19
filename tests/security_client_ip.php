<?php
/**
 * Who is the client? Rate limits and the login lockout are keyed on the answer.
 *
 * Proxies append to X-Forwarded-For, so its leftmost entry is whatever the client typed;
 * CF-Connecting-IP is a header any client can send. Trusting either let one attacker
 * rotate through fresh lockout buckets (DS41-04, SPB-SEC-007, OPUS-006). Behind a CDN
 * the opposite failure hits: everyone shares the CDN's address (DS41-31), which the
 * CIDR form of trusted_proxies and the Cloudflare switch address.
 *
 * Also covers the address classification the SSRF gates rely on and the browser
 * redirect check (SPB-SEC-010).
 *
 * Run: php tests/security_client_ip.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-clientip-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/security.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

function clientIp(array $server): string {
    foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_CF_RAY'] as $h) {
        unset($_SERVER[$h]);
    }
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    return Security::getClientIp();
}

Database::initialize();

echo "Direct visitors\n";
check(clientIp(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']) === '203.0.113.9',
    'an untrusted peer cannot claim another address with X-Forwarded-For');
check(clientIp(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1']) === '203.0.113.9',
    'nor with CF-Connecting-IP');
check(Security::proxyMode() === 'auto', 'the default is automatic');
check(clientIp(['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.7.7']) === '198.51.7.7',
    'automatic: a private-network cache or load balancer is a proxy (nginx/Varnish on another box)');
check(clientIp(['REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.7.7']) === '198.51.7.7',
    'automatic: a genuine Cloudflare edge is believed without any setting');
check(clientIp(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.7.7']) === '203.0.113.9',
    'automatic: a non-Cloudflare peer sending the header is not');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_FORWARDED' => 'for=6.6.6.6, for="198.51.7.9"']) === '198.51.7.9',
    'the standard Forwarded header is understood');
Config::set('proxy_mode', 'direct');
check(clientIp(['REMOTE_ADDR' => '192.168.1.20', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']) === '192.168.1.20',
    'not behind a proxy: a LAN neighbour cannot pick its address');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']) === '127.0.0.1',
    'not behind a proxy: forwarded headers are ignored entirely');
Config::set('proxy_mode', 'custom');
check(clientIp(['REMOTE_ADDR' => '192.168.1.20', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']) === '192.168.1.20',
    'custom: a LAN neighbour is not a proxy unless listed');

echo "Same-host reverse proxy (loopback peer)\n";
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.7.7']) === '198.51.7.7',
    'the rightmost hop (what our proxy appended) wins over a spoofed leftmost one');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.7.7']) === '198.51.7.7',
    'a single forwarded address is the client');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.7.7',
                'HTTP_CF_CONNECTING_IP' => '6.6.6.6']) === '198.51.7.7',
    'CF-Connecting-IP is ignored when Cloudflare is not configured');
check(clientIp(['REMOTE_ADDR' => '::1', 'HTTP_X_REAL_IP' => '198.51.7.8']) === '198.51.7.8',
    'X-Real-IP is used when there is no X-Forwarded-For');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.7.7',
                'HTTP_X_REAL_IP' => '6.6.6.6']) === '198.51.7.7',
    'X-Real-IP is only a fallback');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'garbage']) === '127.0.0.1',
    'an unparsable header falls back to the peer');

echo "Configured proxies, including CIDR ranges\n";
Config::set('trusted_proxies', ['10.0.0.0/8', '2001:db8:1::5']);
check(clientIp(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.7.7, 10.9.9.9']) === '198.51.7.7',
    'every trusted hop is skipped, then the first untrusted address is the client');
check(clientIp(['REMOTE_ADDR' => '2001:db8:1::5', 'HTTP_X_FORWARDED_FOR' => '198.51.7.7']) === '198.51.7.7',
    'an exact IPv6 entry is trusted');
check(clientIp(['REMOTE_ADDR' => '11.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.7.7']) === '11.0.0.1',
    'an address outside the range is not');
Config::set('trusted_proxies', []);

echo "Behind Cloudflare\n";
check(clientIp(['REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.7.7']) === '104.16.1.1',
    'without the switch, Cloudflare is just one shared peer');
Config::set('behind_cloudflare', true);
check(clientIp(['REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.7.7']) === '198.51.7.7',
    'with it, a Cloudflare edge peer is believed');
check(clientIp(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.7.7']) === '203.0.113.9',
    'a non-Cloudflare peer sending the header is not');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.7.7, 172.64.0.9',
                'HTTP_CF_CONNECTING_IP' => '198.51.7.7']) === '198.51.7.7',
    'Cloudflare in front of a local proxy: the edge hop vouches for the header');
check(clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '172.64.0.9, 203.0.113.50',
                'HTTP_CF_CONNECTING_IP' => '6.6.6.6']) === '203.0.113.50',
    'someone bypassing Cloudflare to reach the origin gets their own address, not their header');
Config::set('behind_cloudflare', false);
Config::set('proxy_mode', 'auto');
check(Security::normalizeProxyEntry('10.0.0.0/8') === '10.0.0.0/8' && Security::normalizeProxyEntry('10.0.0.0/33') === null
    && Security::normalizeProxyEntry('nonsense') === null && Security::normalizeProxyEntry('2001:db8::1') === '2001:db8::1',
    'proxy list entries are validated');

echo "Address classification\n";
foreach (['100.64.1.1', '198.18.0.1', '224.0.0.1', '10.0.0.1', '127.0.0.1', '169.254.169.254',
          '::1', 'fd00::1', '::ffff:127.0.0.1', '0.0.0.0', '192.0.2.1', '2002:7f00:1::1'] as $ip) {
    check(!Security::isPublicIp($ip), "$ip is not public");
}
foreach (['1.1.1.1', '8.8.8.8', '2606:4700::1111'] as $ip) {
    check(Security::isPublicIp($ip), "$ip is public");
}
check(Security::ipInRange('10.20.30.40', '10.0.0.0/8') && !Security::ipInRange('11.0.0.1', '10.0.0.0/8'),
    'IPv4 CIDR matching');
check(Security::ipInRange('2400:cb00:1::1', '2400:cb00::/32') && !Security::ipInRange('2400:cb01::1', '2400:cb00::/32'),
    'IPv6 CIDR matching');
check(!Security::ipInRange('10.0.0.1', '::/0'), 'mixed families never match');

echo "Browser redirects (SPB-SEC-010)\n";
foreach (['/thanks', '/order/5?x=1', 'https://shop.example/thanks', 'http://shop.example'] as $ok) {
    check(Security::isSafeBrowserRedirect($ok), "allowed: $ok");
}
foreach (['/\\evil.com', '\\\\evil.com', '//evil.com', ' //evil.com', "/\t/evil.com", 'javascript:alert(1)',
          'JaVaScRiPt:alert(1)', 'data:text/html,x', 'thanks', 'https:/evil.com', "https://shop\n.example", ''] as $bad) {
    check(!Security::isSafeBrowserRedirect($bad), 'refused: ' . json_encode($bad));
}

echo "All client-IP and address checks passed.\n";
