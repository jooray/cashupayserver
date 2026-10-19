<?php
/**
 * Webhook targets: who the server will POST to, and how a broken subscription behaves.
 *
 * - The address that is checked is the address that is used: one lookup, pinned
 *   (DS41-03, SPB-SEC-005, OPUS-008). The rebinding resolver below answers the first
 *   lookup with a public address and every later one with 127.0.0.1.
 * - "Our own origin" means same host *and* port, not any port on the box (DS41-19).
 * - CGNAT and other ranges PHP's filter misses are refused; public hosts only on 80/443
 *   (SPB-SEC-004).
 * - A malformed stored event list never throws inside settlement (DS41-05).
 *
 * Run: php tests/webhook_ssrf.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-webhook-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/webhook_sender.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Config::set('base_url', 'https://pay.example.com');

$dns = [
    'shop.example.com' => ['93.184.216.34'],
    'pay.example.com' => ['10.0.0.5'],          // split-horizon: our own name is private
    'cgnat.example.com' => ['100.64.3.4'],
    'mixed.example.com' => ['93.184.216.34', '192.168.1.1'],
];
$lookups = [];
Security::setResolverForTesting(function (string $host) use (&$dns, &$lookups): array {
    $lookups[$host] = ($lookups[$host] ?? 0) + 1;
    if ($host === 'rebind.example.com') {
        return $lookups[$host] === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
    }
    return $dns[$host] ?? [];
});

echo "Pinning\n";
$plan = WebhookSender::planDelivery('https://rebind.example.com/hook');
check($plan === ['rebind.example.com:443:93.184.216.34'], 'the connection is pinned to the address that was checked');
check($lookups['rebind.example.com'] === 1, 'and the name was resolved exactly once');
check(WebhookSender::planDelivery('https://rebind.example.com/hook') === null,
    'the next lookup (now 127.0.0.1) is refused, not pinned');
check(Security::resolveOptionFor('https://rebind.example.com/x') === [],
    'resolveOptionFor() no longer pins an unchecked answer');
check(WebhookSender::planDelivery('https://shop.example.com/?wc-api=btcpaygf_default')
    === ['shop.example.com:443:93.184.216.34'], 'a normal shop URL with a query string is allowed and pinned');

echo "Refused targets\n";
foreach ([
    'https://cgnat.example.com/' => 'a host in carrier-grade NAT space',
    'https://mixed.example.com/' => 'a host with one private address among public ones',
    'http://100.64.0.1/' => 'a literal CGNAT address',
    'http://198.18.0.1/' => 'the benchmarking range',
    'http://169.254.169.254/latest/meta-data/' => 'cloud metadata',
    'http://[::ffff:127.0.0.1]/' => 'IPv4-mapped loopback',
    'https://shop.example.com:6379/' => 'a public host on a non-web port',
    'https://nxdomain.example.com/' => 'a name that does not resolve',
    'ftp://shop.example.com/' => 'a non-http scheme',
    'https://user:pw@shop.example.com/' => 'credentials in the URL',
] as $url => $what) {
    check(!WebhookSender::isAllowedTarget($url), "refused: $what");
}

echo "Own origin\n";
check(WebhookSender::isOwnOrigin('https://pay.example.com/?wc-api=btcpaygf_default'), 'same scheme, host and port');
check(WebhookSender::isOwnOrigin('http://pay.example.com/hook'), 'the http twin on the default port');
check(!WebhookSender::isOwnOrigin('http://pay.example.com:9200/'), 'another port on our host is not our origin');
check(!WebhookSender::isOwnOrigin('https://pay.example.com:8443/'), 'nor is another TLS port');
check(WebhookSender::planDelivery('https://pay.example.com/?wc-api=x') === ['pay.example.com:443:10.0.0.5'],
    'our own private origin is allowed, and still pinned');
check(WebhookSender::planDelivery('http://pay.example.com:6379/') === null, 'but not its other ports');

echo "Operator opt-in for private receivers\n";
Config::set('webhook_allow_private_targets', true);
check(WebhookSender::isAllowedTarget('http://cgnat.example.com:8080/hook'), 'allowed once the operator opts in');
Config::set('webhook_allow_private_targets', false);

echo "Event subscriptions never throw (DS41-05)\n";
$row = fn($events) => ['id' => 'wh_test', 'events' => $events];
check(WebhookSender::subscribesTo($row('"InvoiceSettled"'), 'InvoiceSettled'), 'a scalar list means every event, without a TypeError');
check(!WebhookSender::subscribesTo($row('{"a":1}'), 'InvoiceSettled'), 'an object with no event names does not throw');
check(WebhookSender::subscribesTo($row('not json'), 'InvoiceCreated'), 'garbage means every event');
check(WebhookSender::subscribesTo($row('[]'), 'InvoiceCreated'), 'an empty list means every event');
check(WebhookSender::subscribesTo($row('["InvoiceSettled"]'), 'InvoiceSettled'), 'a listed event matches');
check(!WebhookSender::subscribesTo($row('["InvoiceSettled"]'), 'InvoiceCreated'), 'an unlisted one does not');
check(!WebhookSender::subscribesTo($row('[1, ["InvoiceCreated"]]'), 'InvoiceCreated'), 'non-string entries are ignored');

Database::insert('stores', [
    'id' => 'store_w', 'name' => 'W', 'wallet_account_id' => 'wa_w', 'created_at' => Database::timestamp(),
]);
Database::insert('webhooks', [
    'id' => 'wh_bad', 'store_id' => 'store_w', 'url' => 'https://shop.example.com/hook', 'secret' => 's',
    'events' => '"InvoiceSettled"', 'enabled' => 1, 'created_at' => Database::timestamp(),
]);
$invoice = ['id' => 'inv_1', 'store_id' => 'store_w', 'status' => 'Settled', 'amount' => '1',
            'currency' => 'SAT', 'created_at' => time(), 'expiration_time' => time() + 60];
WebhookSender::fireEvent('store_w', 'InvoiceSettled', $invoice);
$queued = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE webhook_id = 'wh_bad'");
check((int)$queued['c'] === 1, 'settlement with a malformed legacy webhook still enqueues the event');

echo "All webhook target checks passed.\n";
