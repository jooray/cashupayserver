<?php
/**
 * Library — LP-4: ConnectGuard address policy, mint URL canonicalization,
 * protocol restriction, and LNURL hardening.
 *
 *   - a guard refusal is a plain CashuException raised BEFORE any socket is
 *     opened (the stub sees no request), never CashuNetworkException; a guard that
 *     throws CashuNetworkException (DNS down) passes through unchanged;
 *   - CURLOPT_RESOLVE pins returned by the guard are applied;
 *   - canonicalizeMintUrl: http(s) only, no userinfo/fragment, case/port/slash folding;
 *     the storage identity keeps the stored URL spelling;
 *   - LNURL resolve: a 302 is not followed; an http callback is refused unless the
 *     CASHU_LNURL_URL_TEMPLATE seam is set; getInvoice refuses a BOLT11 whose
 *     amount differs from the request; Bolt11::amountSats parsing.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
require __DIR__ . '/lib_mint_stub.php';

use Cashu\Bolt11;
use Cashu\CashuException;
use Cashu\CashuNetworkException;
use Cashu\ConnectGuard;
use Cashu\LightningAddress;
use Cashu\MintClient;
use Cashu\Wallet;
use Cashu\WalletStorage;

$stub = lib_mint_stub_start();

// ---- canonicalizeMintUrl ----------------------------------------------------------------
assert_eq('https://mint.example', MintClient::canonicalizeMintUrl('HTTPS://Mint.Example:443/'), 'case + default port + trailing slash folded');
assert_eq('http://127.0.0.1:8085/path', MintClient::canonicalizeMintUrl('http://127.0.0.1:8085//path/'), 'duplicate slashes folded, explicit port kept');
foreach (['file:///etc/passwd', 'gopher://x', 'https://user:pw@mint.example', 'https://mint.example/#frag', 'mint.example', ''] as $bad) {
    $threw = false;
    try { MintClient::canonicalizeMintUrl($bad); } catch (CashuException $e) { $threw = true; }
    assert_true($threw, "refused mint URL: '$bad'");
}
$threw = false;
try { new Wallet('file:///etc/passwd', 'sat'); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'Wallet refuses a non-http mint URL');
// Storage identity is derived from the URL as given (not the canonical form).
$sA = new WalletStorage(Database::getDbPath(), 'https://Mint.Example/', 'sat');
$sB = new WalletStorage(Database::getDbPath(), 'https://mint.example', 'sat');
assert_eq(substr(hash('sha256', 'https://Mint.Example/:sat'), 0, 16), $sA->getWalletId(), 'wallet id = sha256(url-as-passed:unit)');
assert_neq($sA->getWalletId(), $sB->getWalletId(), 'existing namespaces do not move');
$opts = MintClient::curlProtocolOptions();
assert_true(isset($opts[CURLOPT_PROTOCOLS_STR]) || isset($opts[CURLOPT_PROTOCOLS]), 'protocol restriction options present');

// ---- guard refusal before connect -------------------------------------------------------
$calls = [];
ConnectGuard::set(function (string $url, string $purpose) use (&$calls) {
    $calls[] = [$url, $purpose];
    if (str_contains($url, 'refuse-me')) {
        throw new \RuntimeException('private address');
    }
    if (str_contains($url, 'dns-down')) {
        throw new CashuNetworkException('DNS resolution failed');
    }
    return [];
});
assert_true(ConnectGuard::isSet(), 'guard installed');
lib_mint_stub_reset_requests($stub);
$client = new MintClient('http://refuse-me.invalid:1');
$threw = null;
try { $client->get('info'); } catch (\Throwable $e) { $threw = $e; }
assert_true($threw instanceof CashuException && !($threw instanceof CashuNetworkException), 'refusal is a plain CashuException: ' . get_class($threw));
assert_true(str_contains($threw->getMessage(), 'Connection refused'), 'refusal message: ' . $threw->getMessage());
assert_eq([['http://refuse-me.invalid:1/v1/info', 'mint']], $calls, 'guard saw the full URL and purpose mint');
assert_eq(0, count(lib_mint_stub_requests($stub)), 'no request reached any server');
// DNS-down from the guard stays a network error (offline fallback keeps working).
$threw = null;
try { (new MintClient('http://dns-down.invalid'))->get('info'); } catch (\Throwable $e) { $threw = $e; }
assert_true($threw instanceof CashuNetworkException, 'guard CashuNetworkException passes through: ' . get_class($threw));
// A guard that returns no pins lets the request through to the stub.
$calls = [];
$info = (new MintClient($stub['url']))->get('info');
assert_eq('lib stub mint', $info['name'], 'allowed request served');
assert_eq('mint', $calls[0][1], 'purpose mint');
// CURLOPT_RESOLVE pins from the guard are applied: pin a fake host to the stub's address.
ConnectGuard::set(fn(string $url, string $purpose) => ['pinned-mint.invalid:' . $stub['port'] . ':127.0.0.1']);
$info = (new MintClient('http://pinned-mint.invalid:' . $stub['port']))->get('info');
assert_eq('lib stub mint', $info['name'], 'resolve pin honoured (fake host reached the stub)');
// A guard returning garbage refuses.
ConnectGuard::set(fn() => 'nope');
$threw = false;
try { (new MintClient($stub['url']))->get('info'); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'non-array pin list refuses');
ConnectGuard::set(null);
assert_false(ConnectGuard::isSet(), 'guard cleared');
$info = (new MintClient($stub['url']))->get('info');
assert_eq('lib stub mint', $info['name'], 'no guard: behaves as before');

// Redirects from a mint are never followed (3xx is reported, not chased).
lib_mint_stub_script($stub, ['overrides' => ['GET /v1/info' => ['status' => 302, 'body' => null, 'raw' => '']]]);
// php -S cannot set Location from the stub router without header(); a 302 with no
// body is enough to prove the client did not follow anything.
lib_mint_stub_reset_requests($stub);
try { (new MintClient($stub['url']))->get('info'); } catch (CashuException $e) { }
assert_eq(1, count(lib_mint_stub_requests($stub)), 'exactly one request on a 3xx');
lib_mint_stub_script($stub, []);

// ---- LNURL stub -------------------------------------------------------------------------
$lnDir = sys_get_temp_dir() . '/lib_lnurl_' . bin2hex(random_bytes(4));
mkdir($lnDir, 0750, true);
file_put_contents($lnDir . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/json');
$port = $_SERVER['SERVER_PORT'];
if ($path === '/__ln') { echo json_encode(['dir' => __DIR__]); exit; }
if ($path === '/.well-known/lnurlp/redirect') { http_response_code(302); header("Location: http://127.0.0.1:$port/.well-known/lnurlp/good"); echo '{}'; exit; }
if ($path === '/.well-known/lnurlp/good') {
    echo json_encode(['callback' => "http://127.0.0.1:$port/callback", 'minSendable' => 1000, 'maxSendable' => 100000000, 'tag' => 'payRequest', 'metadata' => '[]']); exit;
}
if ($path === '/.well-known/lnurlp/httpsonly') {
    echo json_encode(['callback' => "https://127.0.0.1:$port/callback", 'minSendable' => 1000, 'maxSendable' => 100000000, 'tag' => 'payRequest']); exit;
}
if ($path === '/.well-known/lnurlp/evil') {
    echo json_encode(['callback' => "file:///etc/passwd", 'minSendable' => 1000, 'maxSendable' => 100000000, 'tag' => 'payRequest']); exit;
}
if ($path === '/callback') {
    $amt = (int)($_GET['amount'] ?? 0);
    $mode = (string)($_GET['mode'] ?? '');
    // 100 sat = 1u; the "wrong" mode returns a 150 sat invoice (1500n) regardless.
    $pr = $mode === 'wrong' ? 'lnbcrt1500n1fakefakefake' : ($amt === 100000 ? 'lnbcrt1u1fakefakefake' : 'lnbcrt' . intdiv($amt, 100) . 'n1fakefakefake');
    if ($mode === 'array') { echo json_encode(['pr' => ['oops']]); exit; }
    echo json_encode(['pr' => $pr, 'routes' => []]); exit;
}
http_response_code(404); echo '{}';
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$lnPort = (int)substr(stream_socket_get_name($probe, false), strrpos(stream_socket_get_name($probe, false), ':') + 1);
fclose($probe);
$lnPid = (int)shell_exec(sprintf('%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!', escapeshellarg(PHP_BINARY), $lnPort, escapeshellarg($lnDir), escapeshellarg($lnDir . '/router.php')));
register_shutdown_function(function () use ($lnPid, $lnDir) { @posix_kill($lnPid, 9); foreach (glob("$lnDir/*") ?: [] as $f) { @unlink($f); } @rmdir($lnDir); });
$up = false;
for ($i = 0; $i < 120; $i++) { if (@file_get_contents("http://127.0.0.1:$lnPort/__ln") !== false) { $up = true; break; } usleep(50000); }
assert_true($up, 'lnurl stub up');
$lnLog = fn() => array_values(array_filter(explode("\n", (string)@file_get_contents($lnDir . '/requests.log'))));
$lnReset = fn() => file_put_contents($lnDir . '/requests.log', '');

// Without the template seam: the well-known URL is https-only -> the stub is never contacted.
putenv('CASHU_LNURL_URL_TEMPLATE');
$lnReset();
assert_null(LightningAddress::resolve("good@127.0.0.1:$lnPort"), 'plain-http LNURL host refused without the seam');
assert_eq([], $lnLog(), 'no request made');
assert_false(LightningAddress::isSafeCallbackUrl('http://10.0.0.5/x'), 'http callback unsafe');
assert_true(LightningAddress::isSafeCallbackUrl('https://pay.example/x'), 'https callback safe');
assert_true(LightningAddress::isSafeCallbackUrl('http://abc.onion/x'), 'http .onion callback allowed');
assert_false(LightningAddress::isSafeCallbackUrl('https://u:p@pay.example/x'), 'userinfo refused');

// With the seam: template URL and its http callback are allowed; the guard still runs.
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$lnPort/.well-known/lnurlp/{user}");
$guardSeen = [];
ConnectGuard::set(function (string $url, string $purpose) use (&$guardSeen) { $guardSeen[] = $purpose; return []; });
$meta = LightningAddress::resolve('good@example.com');
assert_not_null($meta, 'resolved through the template');
assert_eq("http://127.0.0.1:$lnPort/callback", $meta['callback'], 'http callback accepted under the seam');
assert_eq(['lnurl'], array_unique($guardSeen), 'guard consulted with purpose lnurl');
// 302 is not followed.
$lnReset();
assert_null(LightningAddress::resolve('redirect@example.com'), '302 from the LNURL host is not followed');
assert_eq(1, count($lnLog()), 'exactly one request (no redirect chase)');
// A file:// callback is refused even under the seam.
assert_null(LightningAddress::resolve('evil@example.com'), 'file:// callback refused');
// Guard refusal on LNURL is a plain CashuException and happens before the request.
ConnectGuard::set(function () { throw new \RuntimeException('private'); });
$lnReset();
assert_null(LightningAddress::resolve('good@example.com'), 'guard refusal -> resolve returns null');
assert_eq([], $lnLog(), 'guard refusal: no request');
$threw = null;
try { LightningAddress::getInvoice('good@example.com', 100); } catch (\Throwable $e) { $threw = $e; }
assert_true($threw instanceof CashuException && !($threw instanceof CashuNetworkException), 'getInvoice guard refusal is not a network error');
ConnectGuard::set(null);

// getInvoice: matching amount accepted, wrong amount refused, non-string pr refused.
$pr = LightningAddress::getInvoice('good@example.com', 100);
assert_eq('lnbcrt1u1fakefakefake', $pr, '100 sat invoice accepted');
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$lnPort/.well-known/lnurlp/{user}?mode=wrong");
// (the query string on the well-known URL is harmless; the callback mode is what matters)
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$lnPort/.well-known/lnurlp/{user}");
// Make the callback return a 150-sat invoice for a 100-sat request by pointing the
// callback at mode=wrong through a second well-known entry.
file_put_contents($lnDir . '/router.php', str_replace(
    "'/.well-known/lnurlp/httpsonly'",
    "'/.well-known/lnurlp/wrong') {\n    echo json_encode(['callback' => \"http://127.0.0.1:\$port/callback?mode=wrong\", 'minSendable' => 1000, 'maxSendable' => 100000000, 'tag' => 'payRequest']); exit;\n}\nif (\$path === '/.well-known/lnurlp/arr') {\n    echo json_encode(['callback' => \"http://127.0.0.1:\$port/callback?mode=array\", 'minSendable' => 1000, 'maxSendable' => 100000000, 'tag' => 'payRequest']); exit;\n}\nif (\$path === '/.well-known/lnurlp/httpsonly'",
    file_get_contents($lnDir . '/router.php')
));
$threw = null;
try { LightningAddress::getInvoice('wrong@example.com', 100); } catch (CashuException $e) { $threw = $e; }
assert_true($threw !== null && str_contains($threw->getMessage(), '150 sats, expected 100'), 'wrong-amount BOLT11 refused: ' . ($threw ? $threw->getMessage() : 'no throw'));
$threw = false;
try { LightningAddress::getInvoice('arr@example.com', 100); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'non-string pr refused (CashuException, not TypeError)');
$threw = false;
try { LightningAddress::getInvoice('good@example.com', 0); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'non-positive amount refused');
putenv('CASHU_LNURL_URL_TEMPLATE');

// ---- Bolt11::amountSats ----------------------------------------------------------------
assert_eq(100, Bolt11::amountSats('lnbcrt1u1xyz'), '1u = 100 sat');
assert_eq(150, Bolt11::amountSats('LNBCRT1500N1xyz'), '1500n = 150 sat (case-insensitive)');
assert_eq(1, Bolt11::amountSats('lnbc10n1xyz'), '10n = 1 sat');
assert_eq(1, Bolt11::amountSats('lnbc1n1xyz'), 'sub-satoshi rounds up');
assert_eq(100000000, Bolt11::amountSats('lnbc11xyz'), '1 BTC');
assert_eq(2500, Bolt11::amountSats('lntb25u1xyz'), 'testnet 25u');
assert_eq(0, Bolt11::amountSats('lnbc1xyz'), 'amountless = 0');
assert_eq(0, Bolt11::amountSats('not an invoice'), 'garbage = 0');
assert_eq(100, Bolt11::amountSats('lightning:lnbcrt1u1xyz'), 'lightning: prefix stripped');

echo "PASS test_lib_connect_guard\n";
exit(0);
