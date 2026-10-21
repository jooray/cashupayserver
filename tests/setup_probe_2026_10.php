<?php
/**
 * The setup data-exposure self-probe (2026-10 audits):
 *  - C9: every probe connection goes to SERVER_ADDR:SERVER_PORT, whatever form the Host
 *    header takes (numeric IPv4 forms used to slip past the host-keyed CONNECT_TO pin).
 *  - N-1: the data folder is also probed under the URL this script is served from, so an
 *    nginx/Apache alias or ~userdir layout cannot report "outside"/"protected" while the
 *    real URL serves the database.
 *
 * Each case includes the real setup.php from the CLI (the probe is skipped under php -S)
 * with crafted server variables, against a stand-in web server on 127.0.0.1 and a decoy
 * server that must never be contacted.
 *
 * Run: php tests/setup_probe_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/cashupay-probe2610-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700, true);

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
register_shutdown_function(function () use ($tmp) {
    global $procs;
    foreach ($procs as $p) { proc_terminate($p); }
    exec('rm -rf ' . escapeshellarg($tmp));
});

function startServer(string $router): int {
    global $procs, $tmp;
    $port = random_int(19100, 19999);
    $procs[] = proc_open(['php', '-S', "127.0.0.1:$port", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return $port;
}

// The "web server": serves a SQLite header for the paths listed in serve.json, 404 otherwise.
$serveList = "$tmp/serve.json";
$webLog = "$tmp/web.log";
file_put_contents("$tmp/web.php", '<?php $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' file_put_contents(' . var_export($webLog, true) . ', $p . "\n", FILE_APPEND);'
    . ' if (in_array($p, json_decode((string)file_get_contents(' . var_export($serveList, true) . '), true) ?: [], true)) {'
    . '   header("Content-Type: application/octet-stream"); echo "SQLite format 3\0" . str_repeat("x", 64); return true; }'
    . ' http_response_code(404); echo "<html>not found</html>"; return true;');
$webPort = startServer("$tmp/web.php");

// The decoy: anything that reaches it escaped the pin.
$decoyLog = "$tmp/decoy.log";
file_put_contents("$tmp/decoy.php", '<?php file_put_contents(' . var_export($decoyLog, true)
    . ', $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND); http_response_code(404); return true;');
$decoyPort = startServer("$tmp/decoy.php");

$driver = "$tmp/driver.php";
file_put_contents($driver, '<?php define("CASHUPAY_DATA_DIR", $argv[1]);'
    . ' foreach (json_decode($argv[2], true) as $k => $v) { $_SERVER[$k] = $v; }'
    . ' ob_start(); require ' . var_export("$root/setup.php", true) . '; ob_end_clean();'
    . ' echo json_encode(setupProbeDataExposure());');

$case = 0;
/**
 * Run the probe for one layout. $dirs are created under a fresh case folder; paths in
 * $server may use {t} for that folder.
 */
function probe(array $dirs, string $dataRel, array $server, array $serve): array {
    global $tmp, $case, $driver, $serveList, $webLog, $webPort;
    $t = "$tmp/case" . (++$case);
    foreach ($dirs as $d) { mkdir("$t/$d", 0700, true); }
    file_put_contents($serveList, json_encode($serve));
    @unlink($webLog);
    $server = array_map(fn($v) => is_string($v) ? str_replace('{t}', $t, $v) : $v, $server) + [
        'REQUEST_METHOD' => 'GET', 'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => $webPort,
        'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_URI' => '/setup.php',
    ];
    $out = shell_exec('php ' . escapeshellarg($driver) . ' ' . escapeshellarg("$t/$dataRel")
        . ' ' . escapeshellarg(json_encode($server)) . ' 2>&1');
    $res = json_decode((string)$out, true);
    if (!is_array($res)) { fwrite(STDERR, "driver output: $out\n"); }
    return is_array($res) ? $res : ['status' => 'driver-error'];
}
function webHits(): array {
    global $webLog;
    return is_file($webLog) ? array_filter(explode("\n", (string)file_get_contents($webLog))) : [];
}

echo "Numeric Host forms stay pinned to this server (C9)\n";
foreach (['2130706433', '127.1', '0x7f.1', '127.0.0.1'] as $hostForm) {
    @unlink($decoyLog);
    $res = probe(['webroot/app/data'], 'webroot/app/data', [
        'HTTP_HOST' => "$hostForm:$decoyPort", 'DOCUMENT_ROOT' => '{t}/webroot',
        'SCRIPT_FILENAME' => '{t}/webroot/app/setup.php', 'SCRIPT_NAME' => '/app/setup.php',
    ], []);
    check(!is_file($decoyLog), "Host $hostForm:$decoyPort sends nothing to the port it names");
    check($res['status'] === 'protected' && count(webHits()) === 3,
        'the three files were asked of this server itself and refused');
}
$res = probe(['webroot/app/data'], 'webroot/app/data', [
    'HTTP_HOST' => "127.0.0.1:$decoyPort", 'DOCUMENT_ROOT' => '{t}/webroot', 'SERVER_PORT' => 1,
    'SCRIPT_FILENAME' => '{t}/webroot/app/setup.php', 'SCRIPT_NAME' => '/app/setup.php',
], []);
check($res['status'] === 'inconclusive', 'when this server does not answer itself, the check is inconclusive');

echo "The URL this script is served from is probed too (N-1)\n";
// nginx `alias /cashupay/ -> {t}/app/`: the data folder is not under DOCUMENT_ROOT at all.
$res = probe(['otherroot', 'app/data'], 'app/data', [
    'HTTP_HOST' => "127.0.0.1:$webPort", 'DOCUMENT_ROOT' => '{t}/otherroot',
    'SCRIPT_FILENAME' => '{t}/app/setup.php', 'SCRIPT_NAME' => '/cashupay/setup.php',
], ['/cashupay/data/cashupay.sqlite']);
check($res['status'] === 'exposed' && $res['exposed'] === ['cashupay.sqlite'],
    'an aliased data folder that serves the database is "exposed", not "outside"');
$res = probe(['otherroot', 'app/data'], 'app/data', [
    'HTTP_HOST' => "127.0.0.1:$webPort", 'DOCUMENT_ROOT' => '{t}/otherroot',
    'SCRIPT_FILENAME' => '{t}/app/setup.php', 'SCRIPT_NAME' => '/cashupay/setup.php',
], []);
check($res['status'] === 'protected' && count(webHits()) === 3, 'the same layout refusing everything is "protected"');

// ~userdir style: the docroot mapping names /app/data (404), the real URL is /~shop/data.
$res = probe(['app/data'], 'app/data', [
    'HTTP_HOST' => "127.0.0.1:$webPort", 'DOCUMENT_ROOT' => '{t}',
    'SCRIPT_FILENAME' => '{t}/app/setup.php', 'SCRIPT_NAME' => '/~shop/setup.php',
], ['/~shop/data/cashupay.sqlite', '/~shop/data/cashupay.sqlite-wal']);
check($res['status'] === 'exposed' && $res['exposed'] === ['cashupay.sqlite', 'cashupay.sqlite-wal'],
    'a 404 on the docroot path does not hide the real URL serving the files');
check(in_array('/app/data/cashupay.sqlite', webHits(), true) && in_array('/~shop/data/cashupay.sqlite', webHits(), true),
    'both candidate URLs were asked');

// A data folder moved out of the website (and out of the app folder) has no URL to probe.
$res = probe(['webroot/app', 'cashupay-data'], 'cashupay-data', [
    'HTTP_HOST' => "127.0.0.1:$webPort", 'DOCUMENT_ROOT' => '{t}/webroot',
    'SCRIPT_FILENAME' => '{t}/webroot/app/setup.php', 'SCRIPT_NAME' => '/app/setup.php',
], []);
check($res['status'] === 'outside' && webHits() === [], 'a relocated data folder is "outside" without any request');

echo "All 2026-10 setup probe checks passed.\n";
