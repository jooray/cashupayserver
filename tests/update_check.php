<?php
/**
 * The update notice is the one thing in here that reaches out to a machine the
 * operator does not run, and the one thing that puts a link in front of them and
 * says "click this to upgrade". Both of those deserve pinning down.
 *
 * What this covers: that an out-of-date instance is told so, that a current one is
 * left alone, that the opt-out really stops both the fetch and the notice, that a
 * six-hour cache means a few requests a day rather than one a minute, and that nothing a
 * hostile or broken manifest can say turns into a bad link or a bad version string.
 *
 * Run: php tests/update_check.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$passed = 0;
$failed = 0;
function check(bool $cond, string $what): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok: $what\n"; }
    else { $failed++; fwrite(STDERR, "  FAIL: $what\n"); }
}

// UpdateCheck needs only these two from the application, so the test supplies them
// rather than booting a database it has no other use for.
define('CASHUPAY_VERSION', '0.5.4-alpha');
class Config {
    public static array $kv = [];
    public static function get(string $k, mixed $d = null): mixed { return self::$kv[$k] ?? $d; }
    public static function set(string $k, mixed $v): void { self::$kv[$k] = $v; }
    public static function delete(string $k): void { unset(self::$kv[$k]); }
}

// Point the endpoint at the test's own server before the class is loaded, so run()
// is exercised through exactly the path a deployment takes, and so no test ever
// reaches the real internet.
$dir = sys_get_temp_dir() . '/cps-update-' . bin2hex(random_bytes(4));
mkdir($dir);
$port = random_int(18100, 18999);
$base = "http://127.0.0.1:$port/";
define('CASHUPAY_UPDATE_URL', $base . 'newer.json');
require_once dirname(__DIR__) . '/includes/update_check.php';

file_put_contents("$dir/current.json", json_encode([
    'version' => '0.5.4-alpha', 'url' => 'https://github.com/jooray/cashupayserver/releases',
]));
file_put_contents("$dir/newer.json", json_encode([
    'version' => '0.9.9', 'released' => '2026-09-22', 'severity' => 'security',
    'min_supported' => '0.6.0', 'notes' => 'Fixes a fund-loss bug.',
    'url' => 'https://github.com/jooray/cashupayserver/releases/tag/v0.9.9',
]));
file_put_contents("$dir/bad-version.json", '{"version":"<script>alert(1)</script>"}');
file_put_contents("$dir/not-json.json", 'not json at all');
file_put_contents("$dir/huge.json", json_encode(['version' => '1.0.0', 'notes' => str_repeat('x', 20000)]));
file_put_contents("$dir/plain-link.json", '{"version":"1.0.0","url":"http://github.com/jooray/cashupayserver"}');
file_put_contents("$dir/other-host.json", '{"version":"1.0.0","url":"https://evil.example.com/download"}');
file_put_contents("$dir/odd-fields.json", json_encode([
    'version' => '1.0.0', 'severity' => 'APOCALYPSE', 'notes' => str_repeat('y', 900),
    'min_supported' => 'not-a-version',
]));

$server = proc_open(
    ['php', '-S', "127.0.0.1:$port", '-t', $dir],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
check(is_resource($server), 'manifest server started');
register_shutdown_function(function () use ($server, $dir) {
    proc_terminate($server);
    array_map('unlink', glob("$dir/*.json") ?: []);
    @rmdir($dir);
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    if (@file_get_contents($base . 'current.json') !== false) { $up = true; break; }
    usleep(100_000);
}
check($up, 'manifest server responds');

$fetch = new ReflectionMethod('UpdateCheck', 'fetch');
$read = fn(string $file) => $fetch->invoke(null, $base . $file);

// --- what a hostile or broken manifest cannot do ---------------------------------
echo "A manifest we did not write\n";
check($read('bad-version.json') === null, 'a version that is not a version is refused outright');
check($read('not-json.json') === null, 'a body that is not JSON is refused');
check($read('missing.json') === null, 'a 404 is refused');
check($read('huge.json') === null, 'an oversized manifest is refused before it is parsed');

$plain = $read('plain-link.json');
check(is_array($plain) && !isset($plain['url']),
    'an http:// upgrade link is dropped, while the version is still read');
$other = $read('other-host.json');
check(is_array($other) && !isset($other['url']),
    'so is an https link to a host that is neither the manifest host nor github');

$odd = $read('odd-fields.json');
check($odd['severity'] === 'normal', 'an unknown severity is treated as normal, not as an alarm');
check(strlen($odd['notes']) <= 300, 'notes cannot grow without limit');
check(!isset($odd['min_supported']), 'a malformed min_supported is dropped rather than compared');

// --- the behaviour an operator sees ----------------------------------------------
echo "Being told, and not being told\n";
Config::$kv = [];
check(UpdateCheck::endpoint() === $base . 'newer.json', 'the endpoint is configurable, so a fork can host its own');
check(UpdateCheck::run() === 'update available', 'a real run fetches and reports an update');

$status = UpdateCheck::status();
check($status['outdated'] === true, '0.5.4-alpha is told that 0.9.9 exists');
check($status['latest'] === '0.9.9', 'and which version that is');
check($status['severity'] === 'security', 'a security release is not shown like a feature release');
check($status['unsupported'] === true, 'min_supported 0.6.0 marks this install as unsupported');
check($status['url'] === 'https://github.com/jooray/cashupayserver/releases/tag/v0.9.9',
    'with the link the manifest gave');

$same = $read('current.json');
$same['at'] = time();
Config::set('update_check_state', $same);
$status = UpdateCheck::status();
check($status['outdated'] === false, 'an instance on the current version is told nothing');
check($status['unsupported'] === false, 'and is not called unsupported');

echo "The opt-out\n";
Config::set('update_check_enabled', false);
check(UpdateCheck::run() === 'disabled', 'opting out stops the fetch');
check(UpdateCheck::status()['latest'] === null, 'and stops the notice, cache or no cache');
Config::set('update_check_enabled', true);
check(UpdateCheck::status()['latest'] !== null, 'opting back in shows it again');

echo "Not asking more than every six hours\n";
Config::set('update_check_state', ['latest' => '0.9.9', 'at' => time()]);
check(UpdateCheck::run() === 'skipped', 'a fresh answer is not re-fetched');
Config::set('update_check_state', ['latest' => '0.9.9', 'at' => time() - 21601]);
check(UpdateCheck::run() === 'update available', 'a six-hour-old answer is asked again');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
