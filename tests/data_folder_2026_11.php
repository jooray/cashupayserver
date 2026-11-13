<?php
/**
 * Data-folder work from the browser (2026-11 audit, UX-A4 and UX-A10).
 *
 *  - Moving a funded wallet's data folder out of the website (DataFolder::move()): the
 *    balance, invoices and seed are the same afterwards, the next request opens the new
 *    place, the old files are gone; refused while a payment, a withdrawal or a
 *    background run is under way; the dashboard asks for the password.
 *    WordPress: a wallet the plugin put in wp-content moves to its own folder next to
 *    WordPress, which bootstrap.php then picks by itself.
 *  - Starting a fresh installation from a downloaded backup (setup.php): only the
 *    browser holding setup may upload; a newer backup and a file that is not a backup
 *    are refused; every wallet in the backup must be re-checked with its mint before it
 *    takes a payment; the browser is not logged in until it types the backup's password.
 *
 * Runs on throwaway copies of the application, never this checkout's data/.
 *
 * Run: php tests/data_folder_2026_11.php
 */

declare(strict_types=1);

require_once __DIR__ . '/support/processes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$root = dirname(__DIR__);
$home = sys_get_temp_dir() . '/cashupay-datafolder-' . bin2hex(random_bytes(6));
$web = $home . '/public_html';
$app = $web . '/cashupayserver';
mkdir($app, 0700, true);
$home = realpath($home);
$web = $home . '/public_html';
$app = $web . '/cashupayserver';
$procs = [];
register_shutdown_function(function () use ($home) {
    global $procs;
    foreach ($procs as $p) { testStopProcess($p); }
    exec('rm -rf ' . escapeshellarg($home));
});

// The application as a merchant uploads it: code only, no data.
foreach (['includes', 'cashu-wallet-php/CashuWallet.php', 'cashu-wallet-php/bip39-english.txt'] as $part) {
    exec('mkdir -p ' . escapeshellarg(dirname("$app/$part")) . ' && cp -R '
        . escapeshellarg("$root/$part") . ' ' . escapeshellarg("$app/$part"));
}
@unlink("$app/includes/config.local.php");
@unlink("$app/includes/data-location.txt");

/** Run PHP inside the copy, as a request whose document root is the website. */
function inApp(string $code): string {
    global $app, $web;
    $script = "<?php \$_SERVER['DOCUMENT_ROOT'] = " . var_export($web, true) . ";\n"
        . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
        . "chdir(" . var_export($app, true) . ");\n"
        . "require 'includes/database.php'; require 'includes/config.php'; require 'includes/data_folder.php';\n" . $code;
    $file = $app . '/probe-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, $script);
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>/dev/null');
    unlink($file);
    return trim($out);
}

const SEED = 'half depart obvious quality work element tank gorilla view sugar picture humble';

/** PHP that builds a finished installation with one funded store. */
function fundedInstallCode(string $password): string {
    return "Database::initialize();
        Config::set('setup_complete', true);
        Config::set('admin_password_hash', password_hash(" . var_export($password, true) . ", PASSWORD_DEFAULT));
        \$acct = 'acct_test';
        Database::insert('stores', ['id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => \$acct,
            'mint_url' => 'https://mint.example', 'mint_unit' => 'sat', 'seed_phrase' => " . var_export(SEED, true) . ",
            'created_at' => time()]);
        \$wid = \\Cashu\\WalletStorage::deriveWalletId('https://mint.example', 'sat', \$acct);
        Database::query(\"INSERT INTO cashu_wallet_metadata (wallet_id, seed_fingerprint, ready, created_at, init_reason, ready_reason)
            VALUES (?, ?, 1, ?, 'fresh_seed', 'fresh_seed')\", [\$wid, \\Cashu\\Wallet::calculateSeedFingerprint(" . var_export(SEED, true) . "), time()]);
        foreach ([[64, 'UNSPENT'], [32, 'UNSPENT'], [8, 'SPENT'], [4, 'PENDING']] as \$i => [\$amount, \$state]) {
            Database::query(\"INSERT INTO cashu_proofs (wallet_id, keyset_id, amount, secret, C, state, created_at)
                VALUES (?, '00ab', ?, ?, '02c', ?, ?)\", [\$wid, \$amount, 'secret' . \$i, \$state, time()]);
        }
        Database::query(\"INSERT INTO cashu_counters (wallet_id, keyset_id, counter) VALUES (?, '00ab', 17)\", [\$wid]);
        foreach (['inv1', 'inv2', 'inv3'] as \$id) {
            Database::insert('invoices', ['id' => \$id, 'store_id' => 's1', 'amount' => '10', 'currency' => 'SAT',
                'amount_sats' => 10, 'status' => 'Settled', 'expiration_time' => time(), 'created_at' => time()]);
        }";
}

/** Balance, invoices and seed as the next request sees them. */
$snapshot = fn() => inApp("echo json_encode([
    'dir' => Database::getDataDir(),
    'unspent' => (int)Database::fetchOne(\"SELECT SUM(amount) AS s FROM cashu_proofs WHERE state = 'UNSPENT'\")['s'],
    'proofs' => (int)Database::fetchOne('SELECT COUNT(*) AS n FROM cashu_proofs')['n'],
    'counter' => (int)Database::fetchOne('SELECT counter FROM cashu_counters')['counter'],
    'invoices' => (int)Database::fetchOne('SELECT COUNT(*) AS n FROM invoices')['n'],
    'seed' => Database::fetchOne('SELECT seed_phrase FROM stores')['seed_phrase'],
    'ready' => (int)Database::fetchOne('SELECT ready FROM cashu_wallet_metadata')['ready'],
    'lease' => (string)Config::get('cron_lease_until'),
]);");

echo "A funded wallet inside the website can be moved out of it (UX-A10)\n";
$out = inApp(fundedInstallCode('merchant-pass') . " echo Database::getDataDir();");
check(str_ends_with($out, '/includes/../data'), "it starts in the website's data folder ($out)");
$before = json_decode($snapshot(), true);
check(is_array($before) && $before['unspent'] === 96 && $before['invoices'] === 3, 'with 96 sat and three invoices');
$status = json_decode(inApp('echo json_encode(DataFolder::status());'), true);
check(($status['insideWebsite'] ?? null) === true && ($status['canMove'] ?? null) === true
    && ($status['target'] ?? '') === "$home/cashupay-data", 'the dashboard offers to move it next to the website');

echo "Never mid-payment: busy work makes it wait\n";
$lockFile = "$app/data/.funds_s1.lock";
$fp = fopen($lockFile, 'c');
flock($fp, LOCK_EX);
$r = json_decode(inApp('echo json_encode(DataFolder::move());'), true);
check(($r['ok'] ?? true) === false && ($r['busy'] ?? false) === true && str_contains((string)$r['error'], 'Try again in a minute'),
    'a withdrawal holding the store\'s money lock: "try again in a minute"');
flock($fp, LOCK_UN);
fclose($fp);

inApp("Config::set('cron_lease_until', (string)(time() + 300));");
$r = json_decode(inApp('echo json_encode(DataFolder::move());'), true);
check(($r['busy'] ?? false) === true, 'a background run in progress: refused as busy');
inApp("Config::set('cron_lease_until', '0');");

inApp("Database::update('invoices', ['status' => 'Processing', 'processing_since' => time()], 'id = ?', ['inv1']);");
$r = json_decode(inApp('echo json_encode(DataFolder::move());'), true);
check(($r['busy'] ?? false) === true, 'a payment being collected right now: refused as busy');
inApp("Database::update('invoices', ['status' => 'Settled', 'processing_since' => null], 'id = ?', ['inv1']);");
check(is_file("$app/data/cashupay.sqlite") && !file_exists("$home/cashupay-data"), 'nothing was moved by the refusals');
check(json_decode($snapshot(), true)['lease'] === '0', 'and a refusal leaves background work free to run');

echo "The move itself\n";
@mkdir("$app/data/sessions", 0700, true);
file_put_contents("$app/data/sessions/sess_abc", 'session');
file_put_contents("$app/data/backup-20261101-000000-abcd.sqlite", 'leftover');
// A request that opened the database before the move and writes after it.
$raw = inApp('$stale = new PDO("sqlite:" . Database::getDbPath());
    $stale->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stale->query("SELECT COUNT(*) FROM invoices")->fetchAll();
    $r = DataFolder::move();
    try {
        $stale->exec("INSERT INTO invoices (id, store_id, amount, currency, created_at, expiration_time) VALUES (\'late\', \'s1\', \'1\', \'SAT\', 1, 1)");
        $r["late"] = "written";
    } catch (Throwable $e) {
        $r["late"] = "refused";
    }
    echo json_encode($r);');
$r = json_decode($raw, true);
check(($r['ok'] ?? false) === true && ($r['path'] ?? '') === "$home/cashupay-data", 'it succeeds: ' . $raw);
check(($r['late'] ?? '') === 'refused', 'a request still holding the old file cannot write money into it');
$after = json_decode($snapshot(), true);
check($after['dir'] === "$home/cashupay-data", 'the next request opens the new folder');
check($after['unspent'] === $before['unspent'] && $after['proofs'] === $before['proofs'] && $after['counter'] === 17,
    'with the same balance and wallet');
check($after['invoices'] === 3 && $after['seed'] === SEED, 'the same invoices and the same seed');
check($after['ready'] === 1, 'the wallet stays ready (nothing about it changed)');
check($after['lease'] === '0', 'background work is free to run again');
check(trim((string)file_get_contents("$app/includes/data-location.txt")) === "$home/cashupay-data"
    && trim((string)file_get_contents("$app/data/.cashupay-location")) === "$home/cashupay-data",
    'both records of the new place were written');
check(trim((string)file_get_contents("$home/cashupay-data/.cashupay-app")) === $app, 'the folder names its installation');
foreach (['cashupay.sqlite', 'cashupay.sqlite-wal', 'cashupay.sqlite-shm', 'backup-20261101-000000-abcd.sqlite', 'sessions/sess_abc'] as $name) {
    check(!file_exists("$app/data/$name"), "the old $name is gone");
}
check(is_file("$home/cashupay-data/sessions/sess_abc"), 'logged-in browsers stay logged in');
check(substr(sprintf('%o', fileperms("$home/cashupay-data")), -4) === '0700'
    && substr(sprintf('%o', fileperms("$home/cashupay-data/cashupay.sqlite")), -4) === '0600', 'the new folder is private');
$status = json_decode(inApp('echo json_encode(DataFolder::status());'), true);
check($status['insideWebsite'] === false && $status['canMove'] === false, 'nothing more to offer afterwards');

echo "A folder the host created for it is filled, not refused\n";
// A second installation whose parent folder PHP may not write: the host made the folder.
$home2 = "$home/second";
$web2 = "$home2/public_html";
mkdir("$web2/cashupayserver", 0700, true);
exec('cp -R ' . escapeshellarg("$app/includes") . ' ' . escapeshellarg("$app/cashu-wallet-php") . ' ' . escapeshellarg("$web2/cashupayserver/"));
@unlink("$web2/cashupayserver/includes/data-location.txt");
$app1 = $app; $web1 = $web;
$app = "$web2/cashupayserver"; $web = $web2;
inApp(fundedInstallCode('pw2'));
chmod($home2, 0500);
$status = json_decode(inApp('echo json_encode(DataFolder::status());'), true);
check($status['canMove'] === false && str_contains((string)$status['hostHelp'], "create an empty folder $home2/cashupay-data"),
    'a parent PHP cannot write: a ready-made message for the hosting provider');
chmod($home2, 0700);
mkdir("$home2/cashupay-data", 0700);
chmod($home2, 0500);
$r = json_decode(inApp('echo json_encode(DataFolder::move());'), true);
chmod($home2, 0700);
check(($r['ok'] ?? false) === true && inApp('echo Database::getDataDir();') === "$home2/cashupay-data",
    'once the host created it, the move fills it');
check(json_decode($snapshot(), true)['unspent'] === 96, 'with the wallet in it');
$app = $app1; $web = $web1;

echo "data folder move: OK\n";

// ---------------------------------------------------------------------------
echo "The dashboard asks for the password (UX-A10)\n";
$dataDir = "$home/dash-data";
mkdir($dataDir, 0700);
function startServer(array $cmd): int {
    global $procs, $root;
    $port = testFreePort();
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
    $procs[] = $proc;
    testWaitForServer($proc, $port);
    return $port;
}
$router = "$home/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' chdir(' . var_export($root, true) . ');'
    . ' return require ' . var_export("$root/router.php", true) . ';');
$probe = "$home/seed-dash.php";
file_put_contents($probe, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' require ' . var_export("$root/includes/database.php", true) . '; require ' . var_export("$root/includes/config.php", true) . ';'
    . fundedInstallCode('correct horse') . " Config::set('update_check_enabled', false);"
    . " Config::set('last_background_trigger', (string)(time() + 3600));");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe));
$port = startServer([PHP_BINARY, '-S', '127.0.0.1:{port}', $router]);
$base = "http://127.0.0.1:$port";
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' require ' . var_export("$root/includes/database.php", true) . '; require ' . var_export("$root/includes/config.php", true) . ';'
    . ' Config::set("base_url", ' . var_export($base, true) . ');'));
$cookies = [];
$admin = function (string $method, array $post = [], string $query = '') use ($base, &$cookies): array {
    $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($post);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 60,
        'header' => implode("\r\n", $headers), 'content' => $content]]);
    $body = (string)@file_get_contents("$base/admin.php$query", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = $m[2]; }
    }
    return [$code, json_decode($body, true), $body];
};
[, , $page] = $admin('GET');
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
[$code, $login] = $admin('POST', ['action' => 'login', 'password' => 'correct horse', 'csrf_token' => $m[1] ?? '']);
$csrf = (string)($login['csrfToken'] ?? '');
check($code === 200 && $csrf !== '', 'admin login');
[$code, $r] = $admin('POST', ['action' => 'move_data_folder', 'password' => 'wrong', 'csrf_token' => $csrf]);
check($code === 403 && ($r['error'] ?? '') === 'Incorrect password', 'a wrong password moves nothing');
[$code, $r] = $admin('POST', ['action' => 'move_data_folder', 'password' => 'correct horse', 'csrf_token' => $csrf]);
check($code === 400 && str_contains((string)($r['error'] ?? ''), 'config.local.php') && str_contains((string)($r['hostHelp'] ?? ''), 'CASHUPAY_DATA_DIR'),
    'a folder set in config.local.php is not moved, with a message for the hosting provider');
[$code, $r] = $admin('GET', [], '?api=data_folder');
check($code === 200 && ($r['path'] ?? '') === realpath($dataDir) && array_key_exists('canMove', $r), 'the dashboard reads the folder status');
check(is_file("$dataDir/cashupay.sqlite"), 'the wallet stayed where it was');

// ---------------------------------------------------------------------------
echo "Start from a backup file in a fresh setup (UX-A4)\n";
// The old server: a funded installation, as the dashboard's backup download saves it.
$oldDir = "$home/old-server";
mkdir($oldDir, 0700);
$backup = "$home/cashupay-backup-20261110-120000.sqlite";
$build = "$home/build-backup.php";
file_put_contents($build, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($oldDir, true) . ');'
    . ' require ' . var_export("$root/includes/database.php", true) . '; require ' . var_export("$root/includes/config.php", true) . ';'
    . fundedInstallCode('old-pass')
    . " Config::set('base_url', 'https://old.example/pay');"
    . " Config::set('cron_lease_until', (string)(time() + 300));"
    . " Config::set('admin_password_version', 'pv_old');"
    . " Config::set('wallet_recovery_error_abc', ['at' => time(), 'n' => 5]);"
    . ' Database::getInstance()->exec("VACUUM INTO " . Database::getInstance()->quote(' . var_export($backup, true) . '));');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($build));
check(is_file($backup), 'a backup of a funded installation');
$newer = "$home/newer.sqlite";
copy($backup, $newer);
(new PDO("sqlite:$newer"))->exec('PRAGMA user_version = 99');
$notDb = "$home/notes.sqlite";
file_put_contents($notDb, "my shopping list\n");
$tooBig = "$home/huge.sqlite";
file_put_contents($tooBig, "SQLite format 3\0" . str_repeat('x', 1600 * 1024));

// The new server, fresh. Uploads up to 1 MB, requests up to 2 MB.
$setupData = "$home/new-server";
mkdir($setupData, 0700);
$setupRouter = "$home/setup-router.php";
file_put_contents($setupRouter, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($setupData, true) . ');'
    . ' $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' if ($path === "/__whoami") { require ' . var_export("$root/includes/auth.php", true) . ';'
    . '   require_once ' . var_export("$root/includes/config.php", true) . ';'
    . '   echo Auth::isLoggedIn() ? "admin" : "anonymous"; return true; }'
    . ' if ($path !== "/setup.php") { return false; }'
    . ' require ' . var_export("$root/setup.php", true) . ';');
$sport = startServer([PHP_BINARY, '-d', 'upload_max_filesize=1M', '-d', 'post_max_size=2M',
    '-S', '127.0.0.1:{port}', '-t', $root, $setupRouter]);
$sorigin = "http://127.0.0.1:$sport";

/** @return array{0:int,1:string} */
function sreq(string $jar, string $method, string $url, array $post = [], ?string $file = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($file !== null) {
            $post['backup'] = new CURLFile($file, 'application/octet-stream', basename($file));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
    }
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}
function scsrf(string $body): string {
    preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m);
    return $m[1] ?? '';
}
$inNew = function (string $code) use ($setupData, $root): string {
    $file = "$GLOBALS[home]/probe-new.php";
    file_put_contents($file, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($setupData, true) . ');'
        . ' require ' . var_export("$root/includes/database.php", true) . '; require ' . var_export("$root/includes/config.php", true) . ';' . $code);
    return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>/dev/null'));
};
$restore = fn(string $jar, string $body, string $file) => sreq($jar, 'POST', "$sorigin/setup.php",
    ['step' => '2', 'csrf_token' => scsrf($body), 'action' => 'restore_backup'], $file);

$owner = "$home/owner.jar";
$stranger = "$home/stranger.jar";
[, $body] = sreq($owner, 'GET', "$sorigin/setup.php");
check(!str_contains($body, 'Start from a backup file'), 'not offered before this browser starts setup');
[, $body] = sreq($owner, 'POST', "$sorigin/setup.php", ['step' => '1', 'csrf_token' => scsrf($body),
    'security_acknowledged' => '1', 'detected_base_url' => $sorigin]);
check(str_contains($body, 'Admin Password') && str_contains($body, 'Start from a backup file')
    && str_contains($body, 'files up to 1 MB'), 'offered next to the password step, with the upload limit');

[, $sbody] = sreq($stranger, 'GET', "$sorigin/setup.php");
[$code, $sbody] = $restore($stranger, $sbody, $backup);
check($code === 403 && str_contains($sbody, 'Only the browser that started this setup'), 'another browser cannot upload a backup');

[, $body] = $restore($owner, $body, $notDb);
check(str_contains($body, 'is not a CashuPayServer backup'), 'a file that is not a backup is refused in plain words');
[, $body] = $restore($owner, $body, $newer);
check(str_contains($body, 'from a newer version of CashuPayServer') && str_contains($body, 'Update this installation'),
    'a backup from a newer version is refused: update first');
[, $body] = $restore($owner, $body, $tooBig);
check(str_contains($body, 'larger than this hosting accepts') && str_contains($body, 'upload_max_filesize'),
    'a file over the upload limit: explained, with a message for the hosting provider');
file_put_contents($tooBig, str_repeat('y', 1024 * 1024), FILE_APPEND);
[, $body] = $restore($owner, $body, $tooBig);
check(str_contains($body, 'larger than this hosting accepts') && !str_contains($body, 'session expired'),
    'a request over post_max_size too, not "your session expired"');
check($inNew("echo Database::fetchOne('SELECT COUNT(*) AS n FROM stores')['n'];") === '0', 'nothing was restored by the refusals');

[, $body] = $restore($owner, $body, $backup);
check(str_contains($body, 'Your backup is in place') && str_contains($body, '1 store') && str_contains($body, '3 invoices'),
    'the backup is restored, with what it holds');
check(str_contains($body, 'Log in with the admin password of the old installation'), 'and the operator is told which password to use');
[, $who] = sreq($owner, 'GET', "$sorigin/__whoami");
check($who === 'anonymous', 'this browser is not logged in until it proves the backup\'s password');

$state = json_decode($inNew("echo json_encode([
    'setup' => Config::isSetupComplete(),
    'pw' => password_verify('old-pass', (string)Config::getAdminPasswordHash()),
    'version' => Config::get('admin_password_version'),
    'meta' => Database::fetchAll('SELECT ready, init_reason, ready_reason, restore_status FROM cashu_wallet_metadata'),
    'unspent' => (int)Database::fetchOne(\"SELECT COUNT(*) AS n FROM cashu_proofs WHERE state = 'UNSPENT'\")['n'],
    'unknown' => (int)Database::fetchOne(\"SELECT SUM(amount) AS s FROM cashu_proofs WHERE state = 'UNKNOWN'\")['s'],
    'other' => Database::fetchAll(\"SELECT state, amount FROM cashu_proofs WHERE state NOT IN ('UNSPENT', 'UNKNOWN') ORDER BY amount\"),
    'counter' => (int)Database::fetchOne('SELECT counter FROM cashu_counters')['counter'],
    'lease' => (string)Config::get('cron_lease_until'),
    'backoff' => Config::get('wallet_recovery_error_abc'),
    'claim' => Config::get('setup_claim'),
    'base' => Config::get('base_url'),
    'invoices' => (int)Database::fetchOne('SELECT COUNT(*) AS n FROM invoices')['n'],
    'schema' => (int)Database::getInstance()->query('PRAGMA user_version')->fetchColumn(),
    'files' => array_map('basename', glob(Database::getDataDir() . '/restore-*') ?: []),
]);"), true);
check($state['setup'] === true && $state['pw'] === true && $state['invoices'] === 3, 'the installation is the backup\'s, with its password');
check($state['version'] !== 'pv_old', 'every login the old server knew of has ended');
check(count($state['meta']) === 1 && (int)$state['meta'][0]['ready'] === 0 && $state['meta'][0]['init_reason'] === 'restore_required'
    && $state['meta'][0]['ready_reason'] === null && $state['meta'][0]['restore_status'] === null,
    'the wallet must be recovered from its seed before it takes a payment');
check($state['unspent'] === 0 && $state['unknown'] === 96, 'every proof the snapshot thought unspent is re-checked with the mint');
check($state['other'] === [['state' => 'PENDING', 'amount' => 4], ['state' => 'SPENT', 'amount' => 8]],
    'spent and in-flight proofs are left to their own machinery');
check($state['counter'] === 17, 'the counters only go up from the snapshot (the scan raises them)');
check($state['lease'] === '0' && $state['backoff'] === null && $state['claim'] === null,
    'no stale background lease, recovery backoff or setup claim came along');
check($state['base'] === $sorigin, 'the address of this server (pinned in step 1) replaces the old one');
check($state['schema'] === (int)$inNew('echo (new ReflectionClassConstant("Database", "SCHEMA_VERSION"))->getValue();'), 'at this version\'s schema');
check($state['files'] === [] && glob("$setupData/restore-*") === [], 'no staging copy of the upload is left in the data folder');
$recovering = $inNew("\$w = new Cashu\\Wallet('https://mint.example', 'sat', Database::getDbPath(), 'acct_test');"
    . " \$w->initFromMnemonic(" . var_export(SEED, true) . "); echo \$w->requiresRecovery() ? 'recovering' : 'ready';");
check($recovering === 'recovering', 'the wallet library refuses to mint or spend until its seed scan completes');

[$code] = sreq($owner, 'GET', "$sorigin/setup.php");
check($code === 302, 'setup is finished: it sends the browser to the dashboard login');

echo "backup restore: OK\n";

// ---------------------------------------------------------------------------
echo "WordPress: a wallet in wp-content is moved to the plugin's own folder (UX-A10)\n";
$wpHost = "$home/wphost";
$abspath = "$wpHost/site/";
mkdir("$abspath/wp-content", 0700, true);
$plugin = "$home/wpplugin";
mkdir($plugin, 0700);
foreach (glob($root . '/wordpress/*.php') as $file) {
    copy($file, $plugin . '/' . basename($file));
}
symlink($root . '/includes', $plugin . '/includes');
symlink($root . '/cashu-wallet-php', $plugin . '/cashu-wallet-php');
$inWp = function (string $code) use ($abspath, $plugin, $home): string {
    $file = "$home/wp-request.php";
    file_put_contents($file, '<?php define("ABSPATH", ' . var_export($abspath, true) . ');'
        . ' define("WP_CONTENT_DIR", ABSPATH . "wp-content");'
        . ' $_SERVER["DOCUMENT_ROOT"] = rtrim(ABSPATH, "/"); $_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
        . ' function is_multisite(): bool { return false; }'
        . ' function site_url(string $p = ""): string { return "https://shop.example" . $p; }'
        . ' function get_site_url($b = null, string $p = ""): string { return site_url($p); }'
        . ' require ' . var_export("$plugin/bootstrap.php", true) . ';'
        . ' require_once CASHUPAY_PLUGIN_DIR . "/includes/database.php"; require_once CASHUPAY_PLUGIN_DIR . "/includes/config.php";'
        . ' require_once CASHUPAY_PLUGIN_DIR . "/includes/data_folder.php";' . $code);
    return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>/dev/null'));
};
chmod($wpHost, 0500); // the first install could not create a folder next to WordPress
$first = $inWp(fundedInstallCode('unused') . ' echo cashupay_data_dir();');
chmod($wpHost, 0700);
check($first === "{$abspath}wp-content/cashupay-data", "the wallet started inside wp-content ($first)");
$status = json_decode($inWp('echo json_encode(DataFolder::status());'), true);
$own = $inWp('echo cashupay_own_data_dir();');
check($status['insideWebsite'] === true && $status['canMove'] === true && $status['target'] === $own,
    'the dashboard offers to move it to the plugin\'s own folder next to WordPress');
$r = json_decode($inWp('echo json_encode(DataFolder::move());'), true);
check(($r['ok'] ?? false) === true, 'the move succeeds');
$after = json_decode($inWp("echo json_encode(['dir' => cashupay_data_dir(),
    'unspent' => (int)Database::fetchOne(\"SELECT SUM(amount) AS s FROM cashu_proofs WHERE state = 'UNSPENT'\")['s'],
    'invoices' => (int)Database::fetchOne('SELECT COUNT(*) AS n FROM invoices')['n']]);"), true);
check($after['dir'] === $own && $after['unspent'] === 96 && $after['invoices'] === 3,
    'the next request opens the new folder, with the same balance and invoices');
check(!file_exists("{$abspath}wp-content/cashupay-data/cashupay.sqlite"), 'the copy inside the website is gone');

echo "data_folder_2026_11: OK\n";
