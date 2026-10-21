<?php
/**
 * Outbound address policy, 2026-10 audits.
 *
 * - Hosts outside LDH / IP-literal syntax are refused before resolving, so a pin is
 *   always keyed on the name libcurl connects to (OPUS N6). SIIT ::ffff:0:0:0/96 is
 *   not public.
 * - The wallet library's connect guard is registered by config.php: mint requests are
 *   re-checked and pinned at connect time (OPUS N2), LNURL requests are held to the
 *   public rule with no opt-out (OPUS N1).
 * - Config::testMintConnection applies the private-mint policy itself and does not
 *   echo cURL errors (OPUS C7).
 * - isSafeAppBaseUrl refuses '//' paths, backslashes, quotes and angle brackets (DS41 M-2).
 * - WordPress: the internal cron key goes only to a site_url() that matches the pinned
 *   base_url (OPUS N4).
 *
 * No network: DNS goes through the resolver seam, refused connections never open a
 * socket, and the one "unreachable mint" check uses a closed loopback port.
 *
 * Run: php tests/network_guard_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);

// Child mode: WordPress stubs must not leak into the standalone checks below.
if (($argv[1] ?? '') === 'wp-cron') {
    define('CASHUPAY_DATA_DIR', $argv[2]);
    define('CASHUPAY_WORDPRESS', true);
    $GLOBALS['siteUrl'] = $argv[3];
    function site_url(string $path = ''): string { return rtrim($GLOBALS['siteUrl'], '/') . $path; }
    require_once $root . '/includes/database.php';
    require_once $root . '/includes/config.php';
    require_once $root . '/includes/background.php';
    $m = new ReflectionMethod(Background::class, 'selfCronUrl');
    echo json_encode($m->invoke(null));
    exit(0);
}

$dataDir = sys_get_temp_dir() . '/cashupay-netguard-' . bin2hex(random_bytes(6));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/mint_helpers.php';
require_once $root . '/includes/webhook_sender.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

function thrown(callable $fn): ?Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    return null;
}

Database::initialize();
Config::set('setup_complete', true);

$lookups = [];
Security::setResolverForTesting(function (string $host) use (&$lookups): array {
    $lookups[] = $host;
    return match (strtolower($host)) {
        'mint.example.com', 'l%6fcalhost', 'pay.example.com', 'x.example.com' => ['93.184.216.34'],
        'rebind.example.com' => ['127.0.0.1'],
        'lan.example.com', 'cashu_mint' => ['10.0.0.5'],
        default => [],
    };
});

echo "Host syntax (OPUS N6)\n";
foreach ([
    'http://l%6fcalhost/hook' => 'a percent-encoded host',
    "http://bücher.example/hook" => 'a non-ASCII host',
    'http://x..example.com/' => 'an empty label',
    'http://.example.com/' => 'a leading empty label',
    'http://example.com./' => 'a trailing dot',
    'http://-x.example.com/' => 'a label starting with a hyphen',
    'http://2130706433/' => 'a decimal IPv4 address',
    'http://0x7f.1/' => 'a hex IPv4 address',
    'http://127.1/' => 'a shortened IPv4 address',
    'http://[fe80::1%25eth0]/' => 'an IPv6 zone id',
] as $url => $what) {
    check(Security::resolveTarget($url) === null, "refused: $what");
}
check(!in_array('l%6fcalhost', $lookups, true), 'a refused name is never looked up');
check(WebhookSender::planDelivery('http://l%6fcalhost/internal-admin?x=1') === null,
    'the audit\'s webhook URL gets no pin any more');
check(Security::resolveTarget('http://cashu_mint:3338/') !== null, 'a Docker-style name with an underscore resolves');
$ok = Security::resolveTarget('https://Mint.Example.com:3338/v1');
check($ok !== null && $ok['resolve'] === ['Mint.Example.com:3338:93.184.216.34'], 'an ordinary name resolves and is pinned');
check(Security::resolveTarget('http://93.184.216.34/') !== null, 'an IPv4 literal is accepted');
check(Security::resolveTarget('http://[2606:4700::1]/') !== null, 'a bracketed IPv6 literal is accepted');
check(!Security::isPublicIp('::ffff:0:7f00:1'), 'a SIIT-translated loopback (::ffff:0:0:0/96) is not public');
check(!Security::isPublicIp('::ffff:0:5db8:d822'), 'nor any SIIT-translated address');
check(Security::isPublicIp('2606:4700::1'), 'an ordinary public IPv6 address still is');

echo "Connect guard (OPUS N1, N2)\n";
$reflection = new ReflectionProperty(\Cashu\ConnectGuard::class, 'guard');
check($reflection->getValue() !== null, 'config.php registered the guard');

$opts = \Cashu\ConnectGuard::curlOptions('https://mint.example.com/v1/keysets', 'mint');
check(($opts[CURLOPT_RESOLVE] ?? null) === ['mint.example.com:443:93.184.216.34'], 'a public mint is pinned to the checked address');
$e = thrown(fn() => \Cashu\ConnectGuard::curlOptions('https://rebind.example.com/v1/keysets', 'mint'));
check($e instanceof \Cashu\CashuException && str_starts_with($e->getMessage(), 'Connection refused:'),
    'a mint name that now resolves to 127.0.0.1 is refused at connect time');
$e = thrown(fn() => (new \Cashu\MintClient('http://127.0.0.1:1'))->get('info'));
check($e instanceof \Cashu\CashuException && str_starts_with($e->getMessage(), 'Connection refused:'),
    'MintClient refuses a loopback mint before opening a connection');

Config::set('allow_private_mints', true);
$opts = \Cashu\ConnectGuard::curlOptions('http://lan.example.com:3338/v1/info', 'mint');
check(($opts[CURLOPT_RESOLVE] ?? null) === ['lan.example.com:3338:10.0.0.5'],
    'with private mints allowed, a LAN mint is accepted and still pinned');
$e = thrown(fn() => \Cashu\ConnectGuard::curlOptions('https://lan.example.com/cb?amount=1000', 'lnurl'));
check($e instanceof \Cashu\CashuException, 'an LNURL callback on a private address is refused even then');
$e = thrown(fn() => \Cashu\ConnectGuard::curlOptions('https://127.0.0.1:18443/internal?x=1', 'lnurl'));
check($e instanceof \Cashu\CashuException, 'and so is a loopback literal');
Config::set('allow_private_mints', false);

$opts = \Cashu\ConnectGuard::curlOptions('https://pay.example.com/.well-known/lnurlp/alice', 'lnurl');
check(($opts[CURLOPT_RESOLVE] ?? null) === ['pay.example.com:443:93.184.216.34'], 'a public LNURL host is pinned');
$e = thrown(fn() => \Cashu\ConnectGuard::curlOptions('http://abcdefghijklmnop.onion/cb', 'lnurl'));
check($e instanceof \Cashu\CashuException,
    '.onion LNURL hosts are refused: this server has no Tor proxy (MINIMAX 4.26)');
$e = thrown(fn() => \Cashu\LightningAddress::getInvoice('alice@10.0.0.5', 1000));
check($e !== null, 'paying user@10.0.0.5 fails without connecting');

echo "Mint connection test (OPUS C7)\n";
$r = Config::testMintConnection('http://127.0.0.1:1');
check($r['success'] === false && str_contains((string)$r['error'], 'private or local network'),
    'a loopback mint is refused with the private-network message');
Config::set('allow_private_mints', true);
$r = Config::testMintConnection('http://127.0.0.1:1');
check($r['success'] === false && !str_contains(strtolower((string)$r['error']), 'connect to')
    && str_contains((string)$r['error'], 'Could not reach the mint'),
    'an unreachable mint gets a generic error, not the cURL text');
Config::set('allow_private_mints', false);

echo "Base URL syntax (DS41 M-2)\n";
foreach ([
    'https://shop.example.com//evil.example' => 'a path starting with //',
    'https://shop.example.com/\\evil' => 'a backslash',
    'https://shop.example.com/a"b' => 'a double quote',
    "https://shop.example.com/a'b" => 'a single quote',
    'https://shop.example.com/<x>' => 'angle brackets',
] as $url => $what) {
    check(!Security::isSafeAppBaseUrl($url), "refused: $what");
}
check(Security::isSafeAppBaseUrl('https://shop.example.com/cashupay'), 'an ordinary subdirectory base URL is accepted');
check(Security::isSafeAppBaseUrl('http://localhost:8080'), 'and a local one');

echo "WordPress self-cron origin (OPUS N4)\n";
function wpCron(string $dataDir, string $siteUrl): ?string {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' wp-cron '
        . escapeshellarg($dataDir) . ' ' . escapeshellarg($siteUrl) . ' 2>&1');
    return json_decode((string)$out, true);
}
Config::set('base_url', 'https://shop.example.com/cashupay');
check(wpCron($dataDir, 'https://shop.example.com') === 'https://shop.example.com/cashupay/cron',
    'site_url() matching the pinned base_url gets the key');
check(wpCron($dataDir, 'https://127.0.0.1:9999') === null, 'a Host-derived site_url() for another host does not');
check(wpCron($dataDir, 'http://shop.example.com') === 'http://shop.example.com/cashupay/cron',
    'a site that only changed scheme since activation still gets it');
check(wpCron($dataDir, 'https://shop.example.com:8443') === null, 'nor one on another port');
Config::delete('base_url');
check(wpCron($dataDir, 'https://shop.example.com') === null, 'without a pinned base_url nothing is sent');

echo "All network-guard checks passed.\n";
