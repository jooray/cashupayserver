<?php
/**
 * Startup problems on the operator's pages give a plain explanation and a message for the
 * hosting provider, never a blank white page (A1, 2026-10-28).
 *
 * Runs a scratch copy of the application under PHP's built-in server, so the broken
 * config file and the read-only data folder never touch this checkout.
 *
 * Run: php tests/preflight_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$app = sys_get_temp_dir() . '/cashupay-preflight-' . bin2hex(random_bytes(8));
mkdir($app, 0700, true);
foreach (['includes', 'assets', 'api-keys', 'cashu-wallet-php'] as $dir) {
    exec('cp -R ' . escapeshellarg("$root/$dir") . ' ' . escapeshellarg("$app/$dir"));
}
foreach (['setup.php', 'admin.php', 'index.php', 'router.php'] as $file) {
    copy("$root/$file", "$app/$file");
}
mkdir("$app/data", 0700);

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$proc = null;
register_shutdown_function(function () use (&$proc, $app) {
    if ($proc) { proc_terminate($proc); }
    @chmod("$app/data", 0700);
    exec('rm -rf ' . escapeshellarg($app));
});
$port = random_int(19100, 19999);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", "$app/router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $app);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$get = function (string $path) use ($port): array {
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false,
        stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));
    $code = (int)substr((string)($http_response_header[0] ?? ''), 9, 3);
    return [$code, $body];
};

echo "A typo in config.local.php is explained, not a white page\n";
// Curly quotes, as a word processor would type them.
file_put_contents("$app/includes/config.local.php", "<?php\ndefine(\u{2018}CASHUPAY_DATA_DIR\u{2019}, \u{2018}/tmp/x\u{2019});\n");
[$code, $body] = $get('/setup.php');
check($code === 503 && str_contains($body, 'There is a mistake in includes/config.local.php'), "setup explains it (HTTP $code)");
check(str_contains($body, 'line 2') && str_contains($body, 'curly'), 'and names the line and the likely cause');
[$code, $body] = $get('/admin.php');
check($code === 503 && str_contains($body, 'config.local.php'), 'so does the admin page');
unlink("$app/includes/config.local.php");

echo "A data folder PHP cannot write to is explained\n";
chmod("$app/data", 0555);
[$code, $body] = $get('/setup.php');
check($code === 503 && str_contains($body, 'cannot save anything') && str_contains($body, 'data'),
    "setup says the data folder cannot be written (HTTP $code)");
check(str_contains($body, 'please make the folder data'), 'with a message for the host');
chmod("$app/data", 0700);

echo "A healthy install is not affected\n";
[$code, $body] = $get('/setup.php');
check($code === 200 && !str_contains($body, 'cannot start'), "setup renders normally (HTTP $code)");

echo "A copy unpacked inside an existing installation refuses to start setup (A6)\n";
touch("$app/data/cashupay.sqlite");
$inner = "$app/cashupayserver";
mkdir($inner, 0700);
foreach (['includes', 'assets', 'api-keys', 'cashu-wallet-php'] as $dir) {
    exec('cp -R ' . escapeshellarg("$app/$dir") . ' ' . escapeshellarg("$inner/$dir"));
}
foreach (['setup.php', 'admin.php', 'index.php', 'router.php'] as $file) {
    copy("$app/$file", "$inner/$file");
}
mkdir("$inner/data", 0700);
$innerPort = random_int(19100, 19999);
$innerProc = proc_open(['php', '-S', "127.0.0.1:$innerPort", "$inner/router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $inner);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $innerPort); $i++) {
    usleep(100000);
}
$body = (string)@file_get_contents("http://127.0.0.1:$innerPort/setup.php", false,
    stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));
proc_terminate($innerProc);
check(str_contains($body, 'This is a second copy of CashuPayServer'), 'the nested copy says what happened');
check(!is_file("$inner/data/cashupay.sqlite"), 'and creates no database of its own');

echo "preflight_2026_10: OK\n";
