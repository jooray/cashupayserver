<?php
/**
 * Setup wizard fixes from the 2026-11 ARGON audit, driven through the real setup.php
 * under PHP's built-in server:
 *  - UX-04: choosing the admin password logs this browser in (new session id, same
 *    code path as a login); the counter counts the payout step ("Step 6 of 7"), and the
 *    recovery-phrase button no longer claims to finish setup.
 *  - UX-02: the payout threshold of a euro store is typed and shown in EUR ("20.00")
 *    and a comma is refused instead of read as a thousands separator.
 *  - UX-06.1: step 1 offers the one-click "Move my data" button, with the terminal
 *    instructions folded away.
 *  - UX-06.2: the last step says nothing else needs setting up; the timed job is optional.
 *
 * Run: php tests/setup_wizard_2026_11.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-wizard2611-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);

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

// A fake mint that only answers /v1/keysets, with a euro keyset.
$fakeMint = "$dataDir/fakemint.php";
file_put_contents($fakeMint, '<?php header("Content-Type: application/json"); echo json_encode(["keysets" => ['
    . '["id" => "00aa", "unit" => "sat", "active" => true],'
    . '["id" => "00bb", "unit" => "eur", "active" => true]]]);');
$mintPort = startServer(['php', '-S', '127.0.0.1:{port}', $fakeMint]);

// setup.php, plus a test-only probe that reports whether this session is an admin's.
$router = "$dataDir/router.php";
file_put_contents($router, '<?php define("CASHUPAY_DATA_DIR", ' . var_export($dataDir, true) . ');'
    . ' define("CASHUPAY_ALLOW_PRIVATE_MINTS", true);'
    . ' ini_set("display_errors", "1");'
    . ' $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' if ($path === "/__whoami") { require ' . var_export("$root/includes/auth.php", true) . ';'
    . '   require_once ' . var_export("$root/includes/config.php", true) . ';'
    . '   echo Auth::isLoggedIn() ? "admin" : "anonymous"; return true; }'
    . ' if ($path !== "/setup.php") { return false; }'
    . ' require ' . var_export("$root/setup.php", true) . ';');
$port = startServer(['php', '-S', '127.0.0.1:{port}', '-t', $root, $router]);
$origin = "http://127.0.0.1:$port";
$base = "$origin/setup.php";

/** @return array{0:int,1:string} */
function req(string $jar, string $method, string $url, array $post = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}
function csrf(string $body): string {
    preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m);
    return $m[1] ?? '';
}
function sessionId(string $jar): ?string {
    foreach (file($jar) ?: [] as $line) {
        $f = explode("\t", trim($line));
        if (count($f) === 7 && $f[5] === 'cashupay_session') {
            return $f[6];
        }
    }
    return null;
}
function subtitle(string $body): string {
    preg_match('#<p class="subtitle">(Step \d+ of \d+)</p>#', $body, $m);
    return $m[1] ?? '';
}

$jar = "$dataDir/merchant.jar";

echo "Step 1 offers the one-click move first (UX-06.1)\n";
[, $body] = req($jar, 'GET', $base);
check(subtitle($body) === 'Step 1 of 7', 'the counter counts every step, the payout step included');
// The test's data folder is outside the website (CASHUPAY_DATA_DIR in /tmp).
check(!str_contains($body, 'Recommended: keep your data') && !str_contains($body, 'mkdir -p'),
    'a data folder already outside the website gets no "move it" box nor terminal commands');
// Inside the website (not reproducible here without a second copy of the app), the box
// leads with the button and folds the manual steps away: check the template's order.
$src = (string)file_get_contents($root . '/setup.php');
$box = substr($src, (int)strpos($src, 'Recommended: keep your data outside the website folder'), 4000);
$moveAt = strpos($box, 'value="relocate_data"');
$manualAt = strpos($box, '<summary');
check($moveAt !== false && $manualAt !== false && $moveAt < $manualAt
    && str_contains(substr($box, $manualAt, 200), 'Manual instructions'),
    'inside the website, the "Move my data" button comes first and the manual steps are folded away');

echo "Choosing the password logs this browser in (UX-04)\n";
[, $body] = req($jar, 'POST', $base, ['step' => '1', 'csrf_token' => csrf($body), 'security_acknowledged' => '1']);
check(str_contains($body, 'Admin Password') && subtitle($body) === 'Step 2 of 7', 'the password step is step 2 of 7');
[, $who] = req($jar, 'GET', "$origin/__whoami");
check($who === 'anonymous', '(not logged in before the password exists)');
$before = sessionId($jar);
[, $body] = req($jar, 'POST', $base, ['step' => '2', 'csrf_token' => csrf($body),
    'password' => 'merchant-pass', 'confirm_password' => 'merchant-pass']);
check(str_contains($body, 'Create Store'), 'the password is accepted');
check(sessionId($jar) !== null && sessionId($jar) !== $before, 'with a new session id');
[, $who] = req($jar, 'GET', "$origin/__whoami");
check($who === 'admin', 'and this browser is now logged in');

echo "The euro store's payout amount is in EUR (UX-02)\n";
[, $body] = req($jar, 'POST', $base, ['step' => '4', 'csrf_token' => csrf($body), 'store_name' => 'Yarn Shop']);
check(str_contains($body, 'Connect Cashu Mint'), 'the new token from the login works for the next form');
$mintUrl = "http://127.0.0.1:$mintPort";
[, $body] = req($jar, 'POST', $base, ['step' => '5', 'csrf_token' => csrf($body), 'mint_url' => $mintUrl, 'mint_unit' => 'eur']);
[, $body] = req($jar, 'POST', $base, ['step' => '6', 'csrf_token' => csrf($body), 'action' => 'generate']);
check(str_contains($body, '>Continue</button>') && !str_contains($body, 'Complete Setup'),
    'the recovery-phrase button says Continue, not Complete Setup');
[, $body] = req($jar, 'POST', $base, ['step' => '6', 'csrf_token' => csrf($body), 'action' => 'confirm', 'seed_confirmed' => '1']);
check(str_contains($body, 'Where should your money go?'), 'the payout step follows');
check(subtitle($body) === 'Step 6 of 7', 'as step 6 of 7, not "Step 8 of 6"');
check(str_contains($body, 'balance reaches (EUR)') && str_contains($body, 'value="20.00"') && str_contains($body, 'step="0.01"'),
    'the amount is asked in EUR, suggesting 20.00');
[, $body] = req($jar, 'POST', $base, ['step' => '8', 'csrf_token' => csrf($body), 'action' => 'save',
    'payout_address' => 'shop@wallet.example', 'payout_threshold' => '20,50']);
check(str_contains($body, 'with a dot for decimals') && str_contains($body, 'value="20,50"'),
    'a comma is refused in plain words, keeping what was typed');
[, $body] = req($jar, 'POST', $base, ['step' => '8', 'csrf_token' => csrf($body), 'action' => 'save',
    'payout_address' => 'not an address', 'payout_threshold' => '20.50']);
check(!str_contains($body, 'with a dot for decimals') && str_contains($body, 'Where should your money go?'),
    '"20.50" is a valid amount (the address is what is refused)');

echo "The last step needs nothing else (UX-06.2)\n";
[, $body] = req($jar, 'POST', $base, ['step' => '8', 'csrf_token' => csrf($body), 'action' => 'skip']);
check(str_contains($body, 'Setup Complete') && subtitle($body) === 'Step 7 of 7', 'setup completes on step 7 of 7');
check(str_contains($body, 'Nothing else to set up') && !str_contains($body, 'Recommended: set up a cron job'),
    'it says nothing else needs setting up');
check((bool)preg_match('#<details>\s*<summary[^>]*>Optional, for shops with few visitors#', $body)
    && !str_contains($body, '* * * * *'), 'the timed job is optional, folded away, without crontab syntax');
[$code, $who] = req($jar, 'GET', "$origin/__whoami");
check($who === 'admin', 'and the dashboard does not ask for the password just chosen');

echo "All 2026-11 setup wizard checks passed.\n";
