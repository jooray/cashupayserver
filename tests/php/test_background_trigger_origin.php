<?php
/**
 * Background::trigger() never sends the internal key to a Host-derived
 * third party (SO-4 / NW-5 / P0-3).
 *
 *  - pinned base_url (CASHUPAY_BASE_URL / DB base_url) is used verbatim;
 *  - otherwise the request-derived URL is pinned via CURLOPT_RESOLVE to
 *    SERVER_ADDR:SERVER_PORT, so a forged Host header still lands on OUR
 *    server (proved with two local listeners: "own" and "attacker");
 *  - a Host header with odd syntax means no self-request at all;
 *  - redirects are never followed (a 302 from our own server to the
 *    attacker listener is not honoured);
 *  - loopback detection and TLS policy matrix.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';
$dir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/background.php';

function reset_server_vars(): void {
    unset($_SERVER['HTTPS'], $_SERVER['SERVER_ADDR'], $_SERVER['SERVER_PORT'], $_SERVER['SERVER_NAME']);
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_HOST'] = 'pay.example';
}
function wait_for_requests(RequestSink $s, int $n, float $timeout = 3.0): array {
    $deadline = microtime(true) + $timeout;
    do {
        $r = $s->requests();
        if (count($r) >= $n) return $r;
        usleep(50000);
    } while (microtime(true) < $deadline);
    return $s->requests();
}

// ---- helpers: syntax + loopback matrix ----------------------------------------
foreach (['pay.example', 'pay.example:8080', '127.0.0.1', '127.0.0.1:8737', '[::1]', '[::1]:8080', 'a-b.c'] as $h) {
    assert_true(Background::isPlainHostHeader($h), "plain host header: $h");
}
foreach (['', 'evil.example/..', 'a b', "x\r\ny", 'pay.example:', 'pay.example:abc', '[::1', '[zz]:1', 'user@pay.example', 'pay.example/path'] as $h) {
    assert_false(Background::isPlainHostHeader($h), "odd host header refused: " . json_encode($h));
}
foreach (['http://localhost/x', 'http://127.0.0.1:8080/x', 'http://127.5.5.5/', 'https://[::1]/', 'http://dev.localhost/'] as $u) {
    assert_true(Background::isLoopbackUrl($u), "loopback: $u");
}
foreach (['https://pay.example/', 'http://10.0.0.5/', 'http://192.168.1.1/', 'http://localhost.example/'] as $u) {
    assert_false(Background::isLoopbackUrl($u), "not loopback: $u");
}
$o = Background::baseCurlOptions('https://pay.example/cron.php');
assert_false($o[CURLOPT_FOLLOWLOCATION], 'never follows redirects');
assert_true($o[CURLOPT_SSL_VERIFYPEER], 'TLS verified off-loopback');
assert_eq(2, $o[CURLOPT_SSL_VERIFYHOST], 'host verified off-loopback');
assert_eq(CURLPROTO_HTTP | CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS], 'http/https only');
$o = Background::baseCurlOptions('https://127.0.0.1:8443/cron.php');
assert_false($o[CURLOPT_SSL_VERIFYPEER], 'loopback: self-signed dev certs allowed');

// ---- selfRequest(): target selection -----------------------------------------
reset_server_vars();
// No pin, no server address -> no self-request.
$_SERVER['HTTP_HOST'] = 'attacker.example';
assert_null(Background::selfRequest('/cron.php?internal=1'), 'no server address known -> no self-request');

// No pin + odd Host -> refused.
$_SERVER['SERVER_ADDR'] = '203.0.113.5';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTP_HOST'] = 'evil.example/..';
assert_null(Background::selfRequest('/cron.php?internal=1'), 'odd Host syntax -> no self-request');

// No pin + plain (attacker) Host -> URL carries the Host but the connection
// is pinned to our own address.
$_SERVER['HTTP_HOST'] = 'attacker.example';
$t = Background::selfRequest('/cron.php?internal=1');
assert_not_null($t, 'request-derived target exists');
assert_eq('http://attacker.example/cron.php?internal=1', $t['url'], 'URL is request-derived');
assert_eq(['attacker.example:80:203.0.113.5'], $t['options'][CURLOPT_RESOLVE], 'pinned to SERVER_ADDR:SERVER_PORT');
assert_false($t['options'][CURLOPT_FOLLOWLOCATION], 'no follow');

// SERVER_PORT wins over the Host header's port (the socket the request came in on).
$_SERVER['HTTP_HOST'] = 'pay.example:9999';
$_SERVER['SERVER_PORT'] = '8080';
$t = Background::selfRequest('/cron.php');
assert_eq('http://pay.example:8080/cron.php', $t['url'], 'port rewritten to SERVER_PORT');
assert_eq(['pay.example:8080:203.0.113.5'], $t['options'][CURLOPT_RESOLVE], 'pin uses SERVER_PORT');

// IPv6 server address and bracketed host.
$_SERVER['HTTP_HOST'] = '[2001:db8::10]:8080';
$_SERVER['SERVER_ADDR'] = '2001:db8::10';
$t = Background::selfRequest('/cron.php');
assert_eq('http://[2001:db8::10]:8080/cron.php', $t['url'], 'v6 URL kept bracketed');
assert_eq(['2001:db8::10:8080:2001:db8::10'], $t['options'][CURLOPT_RESOLVE], 'v6 pin');

// php -S: no SERVER_ADDR, SERVER_NAME is the bind address.
reset_server_vars();
$_SERVER['SERVER_NAME'] = '0.0.0.0';
$_SERVER['SERVER_PORT'] = '8737';
$_SERVER['HTTP_HOST'] = 'whatever.example';
$t = Background::selfRequest('/cron.php');
assert_eq(['whatever.example:8737:127.0.0.1'], $t['options'][CURLOPT_RESOLVE], 'built-in server bound to any -> loopback pin');

// Pinned base_url: used verbatim, no RESOLVE, attacker Host ignored.
Config::set('base_url', 'https://pay.example');
$_SERVER['HTTP_HOST'] = 'attacker.example';
$t = Background::selfRequest('/cron.php?internal=1');
assert_eq('https://pay.example/cron.php?internal=1', $t['url'], 'pinned base_url wins over Host');
assert_false(isset($t['options'][CURLOPT_RESOLVE]), 'no request-derived pin when base_url is configured');
assert_eq('https://pay.example', Background::pinnedBaseUrl(), 'pinnedBaseUrl reports the DB pin');
Database::delete('config', 'key = ?', ['base_url']);
Config::clearCache();
assert_null(Background::pinnedBaseUrl(), 'no pin without configuration');

// ---- trigger(): real sockets --------------------------------------------------
$attacker = RequestSink::start($dir);
// "Own" server answers with a redirect to the attacker, to prove no-follow.
$own = RequestSink::start($dir, 302, 'go away', $attacker->base . '/leak');
$internalKey = Background::getInternalKey();

// (a) Host names the attacker listener (by address:port, so it would really
//     be reachable); SERVER_ADDR/PORT are our own listener's.
reset_server_vars();
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . $attacker->port;
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = (string)$own->port;
Background::trigger();
$ownReqs = wait_for_requests($own, 1);
assert_eq(1, count($ownReqs), 'the self-request landed on our own server');
assert_eq('/cron.php?internal=1', $ownReqs[0]['uri'], 'cron path');
assert_eq($internalKey, $ownReqs[0]['headers']['HTTP_X_CRON_KEY'] ?? null, 'carrying the internal key');
usleep(300000);
assert_eq([], $attacker->requests(), 'the attacker listener never saw a request (no Host-derived connect, no redirect follow)');
// (b) Plain attacker hostname: still lands on our own address.
$_SERVER['HTTP_HOST'] = 'attacker.example';
Background::trigger();
$ownReqs = wait_for_requests($own, 2);
assert_eq(2, count($ownReqs), 'request with a forged hostname still reached our own server');
assert_eq('attacker.example:' . $own->port, $ownReqs[1]['headers']['HTTP_HOST'] ?? null, 'Host header is what the client sent (harmless on our own server)');
assert_eq([], $attacker->requests(), 'attacker listener still empty');

// (c) Odd Host syntax: nothing is sent anywhere.
$_SERVER['HTTP_HOST'] = 'evil.example/..';
Background::trigger();
usleep(300000);
assert_eq(2, count($own->requests()), 'odd Host -> no self-request');
assert_eq([], $attacker->requests(), 'and nothing to the attacker');

// (d) Pinned base_url pointing at our own listener: used as-is.
Config::set('base_url', $own->base);
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . $attacker->port;
Background::trigger();
$ownReqs = wait_for_requests($own, 3);
assert_eq(3, count($ownReqs), 'pinned base_url target used');
assert_eq([], $attacker->requests(), 'attacker listener untouched with a pinned base_url');

$own->stop();
$attacker->stop();
echo "ok\n";
