<?php
/**
 * Setup's "Move my data to a safe place" moves a fresh install's data folder out of
 * the website and every later request finds it there — no file for the operator to
 * edit. Runs on a throwaway copy of the application, never this checkout's data/.
 *
 * Run: php tests/data_relocation.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$root = dirname(__DIR__);
$home = sys_get_temp_dir() . '/cashupay-relocate-' . bin2hex(random_bytes(6));
$web = $home . '/public_html';
$app = $web . '/cashupayserver';
mkdir($app, 0700, true);
$home = realpath($home);
$web = $home . '/public_html';
$app = $web . '/cashupayserver';
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($home)));

// The application as a merchant uploads it: code only, no data.
foreach (['includes', 'cashu-wallet-php/CashuWallet.php'] as $part) {
    exec('mkdir -p ' . escapeshellarg(dirname("$app/$part")) . ' && cp -R '
        . escapeshellarg("$root/$part") . ' ' . escapeshellarg("$app/$part"));
}
@unlink("$app/includes/config.local.php");
@unlink("$app/includes/data-location.txt");

/** Run PHP inside the copy, as a request whose document root is the website. */
function inApp(string $code): string {
    global $app, $web;
    $script = "<?php \$_SERVER['DOCUMENT_ROOT'] = " . var_export($web, true) . ";\n"
        . "chdir(" . var_export($app, true) . ");\n"
        . "require 'includes/database.php'; require 'includes/config.php';\n" . $code;
    $file = $app . '/probe-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, $script);
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>/dev/null');
    unlink($file);
    return trim($out);
}

echo "A fresh install inside the website is moved next to it\n";
$start = inApp("Database::initialize(); Config::set('marker', 'kept'); echo Database::getDataDir();");
check(str_ends_with($start, '/includes/../data'), "it starts in the default data folder ($start)");
@mkdir("$app/data/sessions", 0700, true);
file_put_contents("$app/data/sessions/sess_abc", 'session');
file_put_contents("$app/data/setup-token-0123456789abcdef.txt", "CODE\n");

$result = inApp('echo json_encode(Database::relocateOutsideWebroot());');
$moved = json_decode($result, true);
check(is_array($moved) && $moved['ok'] === true, "relocation succeeds ($result)");
check(($moved['path'] ?? '') === "$home/cashupay-data", 'to a folder next to the website folder');
check(trim((string)@file_get_contents("$app/includes/data-location.txt")) === "$home/cashupay-data",
    'the new place is recorded by the app itself');

echo "Later requests use it, with everything carried over\n";
check(inApp("echo Database::getDataDir();") === "$home/cashupay-data", 'the next request finds the new folder');
check(inApp("echo Config::get('marker');") === 'kept', 'the database came along');
check(is_file("$home/cashupay-data/sessions/sess_abc") && is_file("$home/cashupay-data/setup-token-0123456789abcdef.txt"),
    'sessions and the recovery code came along');
check(inApp("echo Database::isDataDirOutsideWebroot() ? 'yes' : 'no';") === 'yes', 'it is outside the website');
check(!file_exists("$app/data/cashupay.sqlite") && !file_exists("$app/data/sessions/sess_abc")
    && !file_exists("$app/data/setup-token-0123456789abcdef.txt"), 'the downloadable copies are gone');

echo "It is never done twice, nor once a wallet exists\n";
$again = json_decode(inApp('echo json_encode(Database::relocateOutsideWebroot());'), true);
check(($again['ok'] ?? true) === false, 'a second move is refused');

echo "Losing includes/data-location.txt does not lose the wallet (A5)\n";
check(trim((string)@file_get_contents("$app/data/.cashupay-location")) === "$home/cashupay-data",
    'a second record is kept in the website\'s data folder, which upgrades keep');
unlink("$app/includes/data-location.txt");
check(inApp("echo Database::getDataDir();") === "$home/cashupay-data", 'the second record finds it');
check(inApp("echo Config::get('marker');") === 'kept', 'with the database');
check(is_file("$app/includes/data-location.txt"), 'and the first record is written back');

echo "Replacing the whole folder (both records gone) still finds it (A5)\n";
exec('rm -rf ' . escapeshellarg("$app/data"));
unlink("$app/includes/data-location.txt");
check(inApp("echo Database::getDataDir();") === "$home/cashupay-data", 'the folder that names this install is found');
check(inApp("echo Config::get('marker');") === 'kept', 'so setup does not start over on an empty database');

echo "Another site's moved folder is never adopted\n";
file_put_contents("$home/cashupay-data/.cashupay-app", "/some/other/site\n");
exec('rm -rf ' . escapeshellarg("$app/data"));
@unlink("$app/includes/data-location.txt");
check(inApp("echo Database::getDataDir();") !== "$home/cashupay-data", 'a folder owned by another install is ignored');

echo "data_relocation: OK\n";
