<?php
/**
 * The signed emergency shutdown ("kill switch").
 *
 * Only a notice signed by a pinned key and naming this version shuts the install down;
 * once shut down, every entry point answers 503 (HTML for people, Greenfield JSON for
 * shop plugins) and the background runner does nothing; upgrading past the range ends
 * it. Nothing reachable over the web can switch it off.
 *
 * Interoperability with real Nostr tooling was checked by hand with `nak` (events
 * signed here verify with `nak verify`; events from `nak event` verify here).
 *
 * Run: php tests/safe_mode.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-shutdown-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

// BIP-340 test vector key 3; its x-only public key is the official one.
const TEST_SECKEY = '0000000000000000000000000000000000000000000000000000000000000003';
const TEST_PUBKEY = 'f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9';
const OTHER_SECKEY = '0000000000000000000000000000000000000000000000000000000000000007';
define('CASHUPAY_SAFE_MODE_PUBKEYS', [TEST_PUBKEY]);

$root = dirname(__DIR__);
require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/safe_mode.php';
require_once $root . '/includes/background_runner.php';

use Cashu\Secp256k1;
use Cashu\BigInt;

$server = null;
register_shutdown_function(function () use ($dataDir, &$server) {
    if ($server) { proc_terminate($server); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

function signedNotice(array $content, string $sec = TEST_SECKEY, array $overrides = []): array {
    $point = Secp256k1::scalarMult(BigInt::fromHex($sec), Secp256k1::getGenerator());
    $event = array_merge([
        'pubkey' => substr(bin2hex(Secp256k1::compressPoint($point)), 2),
        'created_at' => time() - 60,
        'kind' => SafeMode::EVENT_KIND,
        'tags' => [['d', SafeMode::EVENT_D_TAG]],
        'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ], $overrides);
    $event['id'] = SafeMode::eventId($event);
    $event['sig'] = Secp256k1::schnorrSign($sec, hex2bin($event['id']));
    return $event;
}

function get(string $url, array $headers = []): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => implode("\r\n", $headers)]]);
    $body = (string)@file_get_contents($url, false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body];
}

Database::initialize();
$affectsUs = ['affected' => [['from' => '0.5.0-alpha', 'fixed' => '9.0.0']], 'reason' => 'Test problem.',
    'url' => 'https://github.com/jooray/cashupayserver/releases/tag/v9.0.0'];

echo "Only a valid notice from a pinned key counts\n";
check(count(SafeMode::DEFAULT_PUBKEYS) === 2, 'the two maintainer keys are pinned by default');
check(SafeMode::verify(signedNotice($affectsUs)) !== null, 'a notice signed by the pinned key verifies');
check(SafeMode::verify(signedNotice($affectsUs, OTHER_SECKEY)) === null, 'a notice signed by any other key is ignored');
$tampered = signedNotice($affectsUs);
$tampered['content'] = str_replace('9.0.0', '9.9.9', $tampered['content']);
check(SafeMode::verify($tampered) === null, 'changed content no longer matches the signature');
$tampered = signedNotice($affectsUs);
$tampered['sig'] = str_repeat('0', 128);
check(SafeMode::verify($tampered) === null, 'a bad signature is rejected');
check(SafeMode::verify(signedNotice($affectsUs, TEST_SECKEY, ['tags' => [['d', 'something-else']]])) === null,
    'an event for another application (different d-tag) is ignored');
check(SafeMode::verify(signedNotice($affectsUs, TEST_SECKEY, ['created_at' => time() + 86400])) === null,
    'a notice dated in the future is ignored');
$badLink = $affectsUs;
$badLink['url'] = 'https://github.com/someone-else/cashupayserver/releases';
check(SafeMode::verify(signedNotice($badLink))['url'] === null, 'an upgrade link outside the project is dropped');

echo "Only the versions it names are shut down\n";
SafeMode::ingest(['version' => '9.0.0', 'safe_mode' => signedNotice($affectsUs, OTHER_SECKEY)]);
check(SafeMode::shutdown() === null, 'a forged notice shuts nothing down');
SafeMode::ingest(['version' => '9.0.0', 'safe_mode' => signedNotice(
    ['affected' => [['from' => '0.1.0', 'fixed' => '0.2.0']], 'reason' => 'Old problem.'])]);
check(SafeMode::shutdown() === null, 'a notice for other versions leaves this one running');
SafeMode::ingest(['version' => '9.0.0', 'safe_mode' => signedNotice($affectsUs)]);
check(SafeMode::shutdown() !== null, 'a notice naming this version shuts it down');
check(is_file($dataDir . '/' . SafeMode::FLAG_FILE), 'as a local flag in the data folder');
check(SafeMode::shutdown()['fixed'] === '9.0.0', 'which says what to upgrade to');

echo "Withdrawing the notice does not restart it (manual intervention)\n";
SafeMode::ingest(['version' => '9.0.0']);
check(SafeMode::shutdown() !== null, 'a manifest without the notice leaves it shut down');

echo "Nothing runs while shut down\n";
check(BackgroundRunner::run(5)['skipped'] === 'emergency shutdown', 'the background runner does nothing');

$docroot = $dataDir . '/www';
mkdir($docroot);
file_put_contents($docroot . '/entry.php', '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' define("CASHUPAY_SAFE_MODE_PUBKEYS", [' . var_export(TEST_PUBKEY, true) . ']);'
    . ' require ' . var_export($root . '/includes/entrypoint_guard.php', true) . '; echo "SERVED";');
$port = random_int(18100, 18999);
$server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $docroot],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }

[$code, $body] = get("http://127.0.0.1:$port/entry.php");
check($code === 503 && str_contains($body, 'shut itself down') && !str_contains($body, 'SERVED'),
    'a page request stops at the top with a 503 and an explanation');
check(str_contains($body, 'Test problem.') && str_contains($body, 'version 9.0.0 or later'),
    'the page says why and what to upgrade to');
[$code, $body] = get("http://127.0.0.1:$port/entry.php/api/v1/stores/x/invoices");
check($code === 503 && (json_decode($body, true)['code'] ?? null) === 'service-unavailable',
    'an API request gets Greenfield-shaped JSON, so the shop shows "unavailable"');

echo "A release that retires the notice ends it, even without a fixed version\n";
$openEnded = signedNotice(['affected' => [['from' => '0.5.0-alpha']], 'reason' => 'No fix yet.']);
check(SafeMode::matchingRange(SafeMode::verify($openEnded)['ranges'], '99.0.0') !== null,
    'an open-ended notice covers every later version');
check(is_array(SafeMode::RETIRED_NOTICES), 'releases can list notices they fix (SafeMode::RETIRED_NOTICES)');
[$code, $body] = get("http://127.0.0.1:$port/entry.php");
check(str_contains($body, 'data</code> folder'), 'the page tells the operator where the flag file is');

echo "A flag that was not written from a signed notice is ignored\n";
$genuineFlag = file_get_contents($dataDir . '/' . SafeMode::FLAG_FILE);
$flag = json_decode($genuineFlag, true);
check(isset($flag['event']['sig'], $flag['event']['pubkey'], $flag['event']['content']),
    'the flag keeps the signed event (pubkey, signature, raw content)');
$flag['event']['content'] = str_replace('Test problem.', 'Go to attacker.example.', $flag['event']['content']);
$flag['url'] = 'https://attacker.example/upgrade';
file_put_contents($dataDir . '/' . SafeMode::FLAG_FILE, json_encode($flag));
[$code, $body] = get("http://127.0.0.1:$port/entry.php");
check($code === 200 && $body === 'SERVED', 'a tampered flag does not shut the server down');
file_put_contents($dataDir . '/' . SafeMode::FLAG_FILE, json_encode([
    'id' => str_repeat('0', 64), 'ranges' => [['from' => '0.0.1', 'fixed' => '9.9.9']],
    'reason' => 'Security problem. Upgrade now.', 'url' => 'https://attacker.example/upgrade']));
[$code, $body] = get("http://127.0.0.1:$port/entry.php");
check($code === 200 && !str_contains($body, 'attacker.example'), 'nor does an unsigned one, and its link is never shown');
file_put_contents($dataDir . '/' . SafeMode::FLAG_FILE, $genuineFlag);
[$code, $body] = get("http://127.0.0.1:$port/entry.php");
check($code === 503, 'the genuine flag still shuts it down');

echo "Upgrading past the range ends it\n";
// A genuine flag whose range no longer covers this install, as after an upgrade.
$past = signedNotice(['affected' => [['from' => '0.1.0', 'fixed' => '0.2.0']], 'reason' => 'Old problem.']);
file_put_contents($dataDir . '/' . SafeMode::FLAG_FILE,
    json_encode(SafeMode::verify($past) + ['event' => $past]));
[$code, $body] = get("http://127.0.0.1:$port/entry.php");
check($code === 200 && $body === 'SERVED', 'a version outside the range serves normally again');
check(!is_file($dataDir . '/' . SafeMode::FLAG_FILE), 'and the stale flag is removed');

echo "Pre-release versions match the release line they belong to\n";
$r = fn(?string $from, ?string $fixed) => [['from' => $from, 'fixed' => $fixed]];
check(SafeMode::matchingRange($r('0.5.4-alpha', '0.5.6'), '0.5.6-alpha') === null,
    'fixed: 0.5.6 counts 0.5.6-alpha as fixed');
check(SafeMode::matchingRange($r('0.5.4-alpha', '0.5.6'), '0.5.5-alpha') !== null, 'and 0.5.5-alpha as affected');
check(SafeMode::matchingRange($r('0.5.5', '0.5.6'), '0.5.5-alpha') !== null, 'from: 0.5.5 includes 0.5.5-alpha');
check(SafeMode::matchingRange($r('0.5.5', null), '0.5.4-alpha') === null, 'but not the release before it');
check(SafeMode::matchingRange($r('0.5.5-alpha', '0.5.6-alpha'), '0.5.6-alpha') === null,
    'an exact pre-release bound still works');
check(SafeMode::matchingRange($r('0.5.5-alpha', '0.5.6-beta'), '0.5.6-alpha') !== null,
    'and orders pre-releases of the same version (alpha < beta)');
check(SafeMode::matchingRange($r('0.5.0', '9.0.0'), '9.0.0') === null && SafeMode::matchingRange($r('0.5.0', '9.0.0'), '0.5.0') !== null,
    'bare versions behave as before');

echo "All emergency-shutdown checks passed.\n";
