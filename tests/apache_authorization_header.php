<?php
/**
 * The API key in the Authorization header reaches Auth on Apache.
 *
 * Apache keeps the Authorization header out of the variables it hands to scripts, so
 * $_SERVER['HTTP_AUTHORIZATION'] was empty there and every API request was refused as
 * "unauthenticated". PHP's built-in server, which the other tests use, always passes it
 * on, so they could not see this.
 *
 * 1. Always: Auth reads the header from HTTP_AUTHORIZATION, from the REDIRECT_ copy
 *    some hosts leave after an internal rewrite, and accepts "token" and "Bearer".
 * 2. With CASHUPAY_TEST_APACHE=1 and Docker: the real api.php under Apache with mod_php
 *    (php:8.4-apache, or CASHUPAY_TEST_APACHE_IMAGE), both with the shipped .htaccess
 *    (clean URLs and router.php) and with .htaccess ignored (AllowOverride None, the
 *    "no server configuration" router.php mode). It pulls the image, so it is opt-in.
 *
 * Run: php tests/apache_authorization_header.php
 *      CASHUPAY_TEST_APACHE=1 php tests/apache_authorization_header.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-authheader-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$cleanup = [];
register_shutdown_function(function () use ($dataDir) {
    global $cleanup;
    foreach (array_reverse($cleanup) as $fn) { $fn(); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

/** A store and a key for it, in the current database. @return string the raw key */
function createStoreWithKey(string $storeId): string {
    Database::insert('stores', [
        'id' => $storeId, 'name' => 'Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
        'mint_url' => 'https://mint.example', 'mint_unit' => 'sat',
        'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
        'created_at' => Database::timestamp(),
    ]);
    return Auth::createApiKey($storeId, 'test')['key'];
}

echo "Where Auth finds the header\n";
Database::initialize();
$key = createStoreWithKey('store_1');

/** @param array<string, mixed> $server */
function authWith(array $server): ?array {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    foreach ($server as $name => $value) { $_SERVER[$name] = $value; }
    return Auth::validateApiRequest();
}

check(authWith([]) === null, 'no header: refused');
check(Auth::getAuthorizationHeader() === '', 'no header: empty');
check((authWith(['HTTP_AUTHORIZATION' => "token $key"])['store_id'] ?? null) === 'store_1', 'HTTP_AUTHORIZATION, "token"');
check((authWith(['HTTP_AUTHORIZATION' => "Bearer $key"])['store_id'] ?? null) === 'store_1', 'HTTP_AUTHORIZATION, "Bearer"');
check((authWith(['REDIRECT_HTTP_AUTHORIZATION' => "token $key"])['store_id'] ?? null) === 'store_1',
    'only the REDIRECT_ copy (left by an internal rewrite)');
check((authWith(['HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => "token $key"])['store_id'] ?? null) === 'store_1',
    'an empty HTTP_AUTHORIZATION does not hide the REDIRECT_ copy');
check((authWith(['HTTP_AUTHORIZATION' => "token $key", 'REDIRECT_HTTP_AUTHORIZATION' => 'token wrong'])['store_id'] ?? null) === 'store_1',
    'HTTP_AUTHORIZATION wins over the REDIRECT_ copy');
check(authWith(['HTTP_AUTHORIZATION' => 'token wrong']) === null, 'a wrong key: refused');
check(authWith(['HTTP_AUTHORIZATION' => ['token', $key]]) === null, 'a non-string value is ignored');
check(authWith(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode("user:$key")]) === null, 'Basic is not an API key');
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

if (getenv('CASHUPAY_TEST_APACHE') !== '1') {
    echo "Apache: skipped (set CASHUPAY_TEST_APACHE=1; needs Docker and pulls the image)\n";
    exit(0);
}

$image = getenv('CASHUPAY_TEST_APACHE_IMAGE') ?: 'php:8.4-apache';
echo "Apache with mod_php ($image)\n";

/** @return array{0:int,1:string} exit code, stdout+stderr */
function run(array $cmd): array {
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($p)) { return [127, 'could not start ' . $cmd[0]]; }
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), $out];
}

[$code] = run(['docker', 'info', '--format', '{{.ServerVersion}}']);
check($code === 0, 'Docker is available');

// The application as a release ships it, twice: /htaccess/ honors .htaccess, /plain/
// does not (AllowOverride None), like a host without server configuration.
$src = "$dataDir/src";
foreach (['htaccess', 'plain'] as $site) {
    $dir = "$src/www/$site";
    mkdir("$dir/cashu-wallet-php", 0755, true);
    exec('cp -R ' . escapeshellarg("$root/includes") . ' ' . escapeshellarg("$root/api-keys") . ' '
        . implode(' ', array_map(fn($f) => escapeshellarg("$root/$f"),
            ['.htaccess', 'api.php', 'router.php', 'admin.php', 'setup.php', 'payment.php', 'receive.php', 'cron.php', 'index.php']))
        . ' ' . escapeshellarg($dir) . '/', $o, $rc);
    check($rc === 0, "copy the application to $site/");
    copy("$root/cashu-wallet-php/CashuWallet.php", "$dir/cashu-wallet-php/CashuWallet.php");
    copy("$root/cashu-wallet-php/bip39-english.txt", "$dir/cashu-wallet-php/bip39-english.txt");
    @unlink("$dir/includes/config.local.php");
    file_put_contents("$dir/includes/config.local.php", '<?php define("CASHUPAY_DATA_DIR", "/data");' . "\n");
    // What Apache itself hands to scripts, apart from the application's own fallback.
    file_put_contents("$dir/probe.php", '<?php echo $_SERVER["HTTP_AUTHORIZATION"] ?? "withheld";');
}
file_put_contents("$src/setup.php", '<?php
require "/var/www/html/htaccess/includes/database.php";
require "/var/www/html/htaccess/includes/config.php";
require "/var/www/html/htaccess/includes/auth.php";
Database::initialize();
Config::set("setup_complete", true);
Database::insert("stores", [
    "id" => "store_1", "name" => "Shop", "wallet_account_id" => Database::generateWalletAccountId(),
    "mint_url" => "https://mint.example", "mint_unit" => "sat",
    "seed_phrase" => "abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about",
    "created_at" => Database::timestamp(),
]);
echo Auth::createApiKey("store_1", "test")["key"];
');
exec('chmod -R a+rX ' . escapeshellarg($src));

$boot = 'a2enmod rewrite headers >/dev/null'
    . ' && printf "<Directory /var/www/html/plain>\n    AllowOverride None\n</Directory>\n" > /etc/apache2/conf-enabled/zz-plain.conf'
    . ' && mkdir -p /data && chown www-data:www-data /data && chmod 700 /data'
    . ' && exec apache2-foreground';
// Pulled on its own: a pull inside "docker run" writes its progress into the output
// the container ID is read from.
[$code, $out] = run(['docker', 'pull', '-q', $image]);
check($code === 0, "pull $image: " . substr(trim($out), -300));
[$code, $out] = run(['docker', 'run', '-d', '--rm', '-p', '127.0.0.1::80',
    '-v', "$src/www:/var/www/html:ro,z", '-v', "$src/setup.php:/setup.php:ro,z",
    $image, 'sh', '-c', $boot]);
// The ID is the last line; anything Docker warns about comes before it.
$lines = preg_split('/\R/', trim($out)) ?: [];
$container = (string)end($lines);
if (preg_match('/^[0-9a-f]{12,}$/', $container) === 1) {
    $cleanup[] = fn() => run(['docker', 'rm', '-f', $container]);
}
check($code === 0 && preg_match('/^[0-9a-f]{12,}$/', $container) === 1, 'start the container: ' . substr(trim($out), -300));

[$code, $out] = run(['docker', 'port', $container, '80/tcp']);
check($code === 0 && preg_match('/:(\d+)\s*$/m', $out, $m) === 1, "published port (" . trim($out) . ")");
$base = "http://127.0.0.1:{$m[1]}";
for ($i = 0; $i < 100 && @file_get_contents("$base/htaccess/probe.php") === false; $i++) { usleep(100000); }

[$code, $apacheKey] = run(['docker', 'exec', '-u', 'www-data', $container, 'php', '/setup.php']);
check($code === 0 && preg_match('/^[0-9a-f]{64}$/', $apacheKey) === 1, 'a store and its key in the container: ' . substr($apacheKey, 0, 300));

/** @return array{0:int,1:string} */
function get(string $url, ?string $authorization = null): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20,
        'header' => $authorization === null ? '' : "Authorization: $authorization\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $mm)) { $code = (int)$mm[1]; }
    }
    return [$code, (string)$body];
}

$current = '/api/v1/api-keys/current';
foreach ([
    "htaccess$current" => 'clean URL, with .htaccess',
    "htaccess/router.php$current" => 'router.php, with .htaccess',
    "plain/router.php$current" => 'router.php, without .htaccess',
] as $path => $what) {
    [$code, $body] = get("$base/$path", "token $apacheKey");
    check($code === 200 && str_contains($body, 'store_1'), "$what: \"token\" key accepted (HTTP $code " . substr($body, 0, 120) . ')');
    [$code] = get("$base/$path", "Bearer $apacheKey");
    check($code === 200, "$what: \"Bearer\" key accepted (HTTP $code)");
    [$code] = get("$base/$path", 'token ' . str_repeat('0', 64));
    check($code === 401, "$what: a wrong key refused (HTTP $code)");
    [$code] = get("$base/$path");
    check($code === 401, "$what: no key refused (HTTP $code)");
}

// Each layer on its own. The .htaccess rule is what PHP-FPM and CGI behind Apache depend
// on (getallheaders() does not see the header there); the fallback is what mod_php
// without .htaccess depends on, as the router.php check above showed.
[, $body] = get("$base/htaccess/probe.php", "token $apacheKey");
check($body === "token $apacheKey", ".htaccess puts the header in \$_SERVER");
// The rule copies the value as it is: rewrite syntax inside it is not expanded again.
$odd = 'token x$1%1%{HTTP_HOST}${HOME}\\';
[, $body] = get("$base/htaccess/probe.php", $odd);
check($body === $odd, "a header with rewrite syntax in it arrives unchanged ($body)");
[$code] = get("$base/htaccess$current", $odd);
check($code === 401, "and is refused like any wrong key (HTTP $code)");
[, $body] = get("$base/plain/probe.php", "token $apacheKey");
check($body === 'withheld', "without .htaccess Apache withholds it, which is the bug ($body)");
[$code] = get("$base/plain$current", "token $apacheKey");
check($code === 404, "and /plain/ really ignores .htaccess (clean URL: HTTP $code)");

echo "done\n";
