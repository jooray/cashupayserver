<?php
/**
 * The setup wizard, driven through the real setup.php under PHP's built-in server with
 * two cookie jars (the merchant and a stranger): who may claim, the recovery takeover,
 * resuming a half-finished setup, the duplicate-seed guard, the unit filter and the
 * mint-address gate.
 *
 * Run: php tests/setup_wizard.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-wizard-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
register_shutdown_function(function () use ($dataDir) {
    global $procs;
    foreach ($procs as $p) { proc_terminate($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

function startServer(array $cmd): int {
    global $procs, $root;
    $port = random_int(18100, 18999);
    $cmd = str_replace('{port}', (string)$port, $cmd);
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return $port;
}

// A fake mint that only answers /v1/keysets, advertising one hostile unit name.
$fakeMint = "$dataDir/fakemint.php";
file_put_contents($fakeMint, '<?php header("Content-Type: application/json"); echo json_encode(["keysets" => ['
    . '["id" => "00aa", "unit" => "sat", "active" => true],'
    . '["id" => "00bb", "unit" => "usd", "active" => true],'
    . '["id" => "00cc", "unit" => "sat<img src=x onerror=alert(1)>", "active" => true]]]);');
$mintPort = startServer(['php', '-S', '127.0.0.1:{port}', $fakeMint]);

// The wizard itself, pointed at the throwaway data dir. The test header opts in to a
// mint on 127.0.0.1 (CASHUPAY_ALLOW_PRIVATE_MINTS), so the gate can be tested both ways.
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' if (!empty($_SERVER["HTTP_X_TEST_ALLOW_PRIVATE"])) { define("CASHUPAY_ALLOW_PRIVATE_MINTS", true); }'
    . ' ini_set("display_errors", "1");'
    . ' if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) !== "/setup.php") { return false; }'
    . ' require ' . var_export("$root/setup.php", true) . ';');
$port = startServer(['php', '-S', '127.0.0.1:{port}', '-t', $root, $router]);
$base = "http://127.0.0.1:$port/setup.php";

/** @return array{0:int,1:string} */
function req(string $jar, string $method, string $url, array $post = [], array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}
function csrf(string $body): string {
    preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m);
    return $m[1] ?? '';
}
function claimRow(): ?string {
    $pdo = new PDO('sqlite:' . CASHUPAY_DATA_DIR . '/cashupay.sqlite');
    $v = $pdo->query("SELECT value FROM config WHERE key = 'setup_claim'")->fetchColumn();
    return $v === false || $v === 'null' ? null : (string)$v;
}
function noFatal(string $body): bool {
    return !str_contains($body, 'Fatal error') && !str_contains($body, 'Uncaught');
}

$merchant = "$dataDir/merchant.jar";
$stranger = "$dataDir/stranger.jar";

echo "A page view claims nothing\n";
[$code, $body] = req($stranger, 'GET', $base);
check($code === 200 && str_contains($body, 'Start setup'), 'the first page renders for anyone');
check(claimRow() === null, 'a GET (bot, link preview) does not claim the installation');
check(glob("$dataDir/setup-token-*.txt") === [], 'and writes no recovery code');
check(!str_contains($body, $dataDir), 'the page does not print the server path of the data folder');

echo "Starting setup claims it\n";
[$code, $body] = req($merchant, 'GET', $base);
[$code, $body] = req($merchant, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password'), 'the merchant moves to the password step');
check(claimRow() !== null, 'and now holds the installation');
$tokenFiles = glob("$dataDir/setup-token-*.txt");
check(count($tokenFiles) === 1, 'a recovery code file with a random name exists');
$recoveryCode = trim((string)file_get_contents($tokenFiles[0]));

echo "Someone else is locked out\n";
[$code, $body] = req($stranger, 'GET', $base);
check(str_contains($body, 'already started setting up'), 'the stranger is told setup was started elsewhere');
check(!str_contains($body, basename($tokenFiles[0])) && !str_contains($body, $dataDir),
    'without the token file name or the server path');
[$code, $body] = req($stranger, 'POST', "$base?setup_token=" . urlencode($recoveryCode),
    ['step' => '2', 'csrf_token' => csrf($body), 'password' => 'attacker-pass', 'confirm_password' => 'attacker-pass']);
check($code === 403 && !str_contains($body, 'Create Store'), 'a recovery code in the URL is not accepted');

echo "The wizard resumes where it was\n";
[$code, $body] = req($merchant, 'GET', $base);
[$code, $body] = req($merchant, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'merchant-pass', 'confirm_password' => 'merchant-pass']);
check(str_contains($body, 'Create Store'), 'the merchant sets a password and reaches the store step');
[$code, $body] = req($merchant, 'GET', $base);
check(str_contains($body, 'Create Store'), 'reloading the page without ?step resumes at the store step');
[$code, $body] = req($merchant, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'My Store']);
check(str_contains($body, 'Connect Cashu Mint'), 'creating the store moves on to the mint');
[$code, $body] = req($merchant, 'GET', "$base?step=4");
[$code, $body] = req($merchant, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'my store']);
check(str_contains($body, 'Connect Cashu Mint') && !str_contains($body, 'already exists'),
    'submitting the same store name again continues that store instead of failing');
$pdo = new PDO('sqlite:' . "$dataDir/cashupay.sqlite");
check((int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn() === 1, 'and no second store was created');
[$code, $body] = req($merchant, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => ['x']]);
check($code === 200 && noFatal($body), 'an array-shaped field is an ordinary error, not a fatal');

echo "The mint address and its units are checked\n";
$mintUrl = "http://127.0.0.1:$mintPort";
[$code, $body] = req($merchant, 'POST', $base, ['step' => '5', 'csrf_token' => csrf($body), 'mint_url' => $mintUrl]);
check(str_contains($body, 'private or local network') && !str_contains($body, 'Currency Unit'),
    'a mint address inside the server\'s own network is refused');
[$code, $body] = req($merchant, 'POST', $base, ['action' => 'test_mint_expiry', 'csrf_token' => csrf($body),
    'mint_url' => 'http://169.254.169.254/latest/meta-data/']);
$json = json_decode($body, true);
check(is_array($json) && $json['success'] === false && str_contains($json['error'], 'private or local network'),
    'the mint expiry test refuses a cloud metadata address');
[$code, $body] = req($merchant, 'GET', "$base?step=5");
[$code, $body] = req($merchant, 'POST', $base, ['step' => '5', 'csrf_token' => csrf($body), 'mint_url' => $mintUrl],
    ['X-Test-Allow-Private: 1']);
check(str_contains($body, 'Currency Unit') && str_contains($body, 'value="usd"'), 'the units of an allowed mint are listed');
check(!str_contains($body, 'onerror') && !str_contains($body, '&lt;img'), 'a unit name that is not a plain code is dropped');
[$code, $body] = req($merchant, 'POST', $base, ['step' => '5', 'csrf_token' => csrf($body), 'mint_url' => $mintUrl,
    'mint_unit' => 'sat<img src=x onerror=alert(1)>'], ['X-Test-Allow-Private: 1']);
check(!str_contains($body, 'Store Wallet Seed'), 'and cannot be chosen by posting it');
[$code, $body] = req($merchant, 'POST', $base, ['step' => '5', 'csrf_token' => csrf($body), 'mint_url' => $mintUrl,
    'mint_unit' => 'sat'], ['X-Test-Allow-Private: 1']);
check(str_contains($body, 'Store Wallet Seed'), 'choosing a listed unit moves on to the seed');

echo "A recovery phrase already used by another store is refused\n";
$otherSeed = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
$pdo->exec("INSERT INTO stores (id, name, wallet_account_id, mint_url, mint_unit, seed_phrase, created_at)
            VALUES ('store_other', 'Other Shop', 'wa_other', 'https://mint.example', 'sat', '$otherSeed', 0)");
[$code, $body] = req($merchant, 'POST', $base, ['step' => '6', 'csrf_token' => csrf($body), 'action' => 'confirm',
    'existing_seed' => strtoupper($otherSeed), 'seed_confirmed' => '1'], ['X-Test-Allow-Private: 1']);
check(str_contains($body, 'already used by your store') && str_contains($body, 'Other Shop'),
    'the merchant is told which store already uses it');
$seed = $pdo->query("SELECT seed_phrase FROM stores WHERE id != 'store_other'")->fetchColumn();
check($seed === null || $seed === '', 'and nothing was written to the new store');

echo "The recovery code takes over and evicts the previous browser\n";
[$code, $body] = req($stranger, 'GET', $base);
[$code, $body] = req($stranger, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'setup_token' => 'WRONG00000',
    'security_acknowledged' => '1']);
check(str_contains($body, 'recovery code is not right'), 'a wrong code is refused with a plain message');
[$code, $body] = req($stranger, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body),
    'setup_token' => strtolower($recoveryCode), 'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password'), 'the right code continues setup in this browser');
[$code, $body] = req($merchant, 'GET', $base);
check(str_contains($body, 'already started setting up'), 'the browser that held the claim before is locked out');

echo "All setup wizard checks passed.\n";
