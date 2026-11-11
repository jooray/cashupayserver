<?php
/**
 * SSO login-token handoff (sso.php).
 *
 * The invariants pinned here: the endpoint is a 404 on deployments that never
 * provisioned an SSO key hash, minting requires the plaintext key (constant
 * time, wrong key 403), nothing is minted before the DB and the admin account
 * exist ({"status":"pending"} lets the orchestrator poll), a mint stores only
 * the token's sha256 with a ~60 s expiry, and the GET leg consumes on match
 * (before the session starts, so a redeemed URL can never be replayed) and
 * on expiry — while a MISMATCHED guess burns nothing, so outsiders hammering
 * the public endpoint cannot deny the operator SSO.
 *
 * sso.php echoes and exits, so each scenario runs in a subprocess through a
 * generated driver that defines the constants, fakes the request
 * superglobals, and appends the final http_response_code() on shutdown (CLI
 * reports false until a code is set — that reads as the default 200). The
 * happy GET leg starts a real session + emits a Location header; CLI can't
 * show the header itself but the implicit 302 does reach
 * http_response_code(), so the redirect is asserted as 302 + empty body +
 * token consumed — the full browser flow lives in the e2e tier.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

$ssoKey = bin2hex(random_bytes(32));
$ssoKeyHash = hash('sha256', $ssoKey);

/**
 * Run sso.php once in a subprocess.
 *
 * @param ?string $keyHash value for CASHUPAY_SSO_KEY_HASH (null = undefined)
 * @param ?string $header  X-SSO-KEY header value
 * @param ?string $postKey POST body 'sso_key' field value
 * @param ?string $token   GET ?token= value
 * @param ?string $dbDir   data dir override (null = the initialized one)
 * @return array{status:int, body:string, json:?array}
 */
function run_sso(
    ?string $keyHash, string $method, ?string $header = null,
    ?string $postKey = null, ?string $token = null, ?string $dbDir = null
): array {
    global $dataDir;
    static $n = 0;
    $driver = $dataDir . '/sso_driver_' . (++$n) . '.php';
    $code = "<?php\n";
    $code .= "define('CASHUPAY_DATA_DIR', " . var_export($dbDir ?? $dataDir, true) . ");\n";
    if ($keyHash !== null) {
        $code .= "define('CASHUPAY_SSO_KEY_HASH', " . var_export($keyHash, true) . ");\n";
    }
    $code .= "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n";
    $code .= "\$_SERVER['HTTP_HOST'] = 'pay.test';\n";
    $code .= "\$_SERVER['SCRIPT_NAME'] = '/sso.php';\n";
    if ($header !== null) {
        $code .= "\$_SERVER['HTTP_X_SSO_KEY'] = " . var_export($header, true) . ";\n";
    }
    if ($postKey !== null) {
        $code .= "\$_POST['sso_key'] = " . var_export($postKey, true) . ";\n";
    }
    if ($token !== null) {
        $code .= "\$_GET['token'] = " . var_export($token, true) . ";\n";
    }
    $code .= "register_shutdown_function(function () {\n"
        . "    \$c = http_response_code();\n"
        . "    echo \"\\nHTTP_STATUS:\" . (\$c === false ? 200 : \$c);\n"
        . "});\n";
    $code .= "require " . var_export(dirname(__DIR__, 2) . '/app/sso.php', true) . ";\n";
    file_put_contents($driver, $code);

    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driver) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("sso driver produced unparseable output (rc=$rc): $raw");
    }
    $body = trim($m[1]);
    return ['status' => (int)$m[2], 'body' => $body, 'json' => json_decode($body, true)];
}

/** The stored token state, read raw so this process's Config cache can't mask subprocess writes. */
function stored_token(): array {
    $hash = Database::fetchOne("SELECT value FROM config WHERE key = 'sso_token_hash'");
    $exp = Database::fetchOne("SELECT value FROM config WHERE key = 'sso_token_expires'");
    return [
        'hash' => $hash === null ? null : (string)$hash['value'],
        'expires' => $exp === null ? null : (int)$exp['value'],
    ];
}

// --- (a) No key hash provisioned: the endpoint does not exist ----------------
//
// Ordinary installs never define CASHUPAY_SSO_KEY_HASH, so even a well-formed
// request with the right plaintext must see a plain 404.
$res = run_sso(null, 'POST', $ssoKey);
assert_eq(404, $res['status'], 'no key hash answers 404');
assert_eq(['error' => 'Not found'], $res['json'], 'and a JSON not-found body');
$res = run_sso(null, 'GET', null, null, 'deadbeef');
assert_eq(404, $res['status'], 'the GET leg is a 404 too when unarmed');

// A malformed hash constant must disarm the endpoint, not misvalidate: the
// accessor rejects anything that is not 64 hex, so a truncated write during
// install reads as "not provisioned".
$res = run_sso('deadbeef', 'POST', $ssoKey);
assert_eq(404, $res['status'], 'a malformed hash constant reads as unprovisioned (404)');

// --- (b) Wrong key ----------------------------------------------------------
$res = run_sso($ssoKeyHash, 'POST', bin2hex(random_bytes(32)));
assert_eq(403, $res['status'], 'a wrong key is rejected');
assert_eq(['error' => 'Invalid SSO key'], $res['json'], 'with the invalid-key body');
$res = run_sso($ssoKeyHash, 'POST');
assert_eq(403, $res['status'], 'a missing key is rejected');

// --- (c) Right key, but nothing to log into yet: pending ---------------------
//
// First against a data dir whose database was never initialized (the
// orchestrator polls before the operator ever opened the wizard)...
$emptyDir = $dataDir . '/uninitialized';
mkdir($emptyDir, 0750, true);
$res = run_sso($ssoKeyHash, 'POST', $ssoKey, null, null, $emptyDir);
assert_eq(200, $res['status'], 'pending is a 200 — the orchestrator should keep polling');
assert_eq(['status' => 'pending'], $res['json'], 'an uninitialized database answers pending');

// ...then against the initialized database with no admin account yet.
$res = run_sso($ssoKeyHash, 'POST', $ssoKey);
assert_eq(['status' => 'pending'], $res['json'], 'no admin account yet still answers pending');
assert_eq(null, stored_token()['hash'], 'pending mints nothing');

// --- (d) Right key + seeded admin: a token is minted -------------------------
Database::insert('users', [
    'id'            => 'user_sso_admin',
    'username'      => 'admin',
    'password_hash' => password_hash('irrelevant here', PASSWORD_DEFAULT),
    'role'          => 'admin',
    'created_at'    => Database::timestamp(),
]);
$before = time();
$res = run_sso($ssoKeyHash, 'POST', $ssoKey);
assert_eq(200, $res['status'], 'minting succeeds');
assert_eq('ready', $res['json']['status'] ?? null, 'status is ready');
$token = (string)($res['json']['token'] ?? '');
assert_true((bool)preg_match('/^[0-9a-f]{64}$/', $token), 'the token is 64 hex chars (256-bit)');
$stored = stored_token();
assert_eq(hash('sha256', $token), $stored['hash'], 'only the sha256 of the token is stored');
assert_true(
    $stored['expires'] >= $before + 55 && $stored['expires'] <= time() + 65,
    'the expiry is ~60 s out (got ' . var_export($stored['expires'], true) . ')'
);

// --- (e) GET with the WRONG token: 403, and the real token SURVIVES ----------
//
// A mismatch must not burn the outstanding token: the token is 256-bit
// random (a guess proves nothing about the real one), while burning on
// mismatch would let anyone who can reach this public endpoint deny the
// operator SSO by hammering it with garbage. Single use is enforced at
// redemption instead — the match consumes the token before the session
// starts.
$outstanding = stored_token()['hash'];
$res = run_sso($ssoKeyHash, 'GET', null, null, bin2hex(random_bytes(32)));
assert_eq(403, $res['status'], 'a wrong token is refused');
assert_true(str_contains($res['body'], 'expired'), 'with the payer-facing expired copy');
assert_eq($outstanding, stored_token()['hash'], 'the failed guess did NOT burn the outstanding token');

// A malformed guess (not even token-shaped) changes nothing either.
$res = run_sso($ssoKeyHash, 'GET', null, null, 'garbage');
assert_eq(403, $res['status'], 'a malformed token is refused');
assert_eq($outstanding, stored_token()['hash'], 'and still burns nothing');

// The real token remains redeemable after any number of failed guesses.
$res = run_sso($ssoKeyHash, 'GET', null, null, $token);
assert_eq(302, $res['status'], 'the real token still redeems after the failed guesses');
assert_eq('', stored_token()['hash'], 'redemption consumed it (single use)');

// --- (f) Mint again, redeem, replay ------------------------------------------
//
// The key travels as the sso_key form field this time, covering the body
// fallback. A new mint replaces the old state entirely.
$res = run_sso($ssoKeyHash, 'POST', null, $ssoKey);
assert_eq('ready', $res['json']['status'] ?? null, 'the form-field key mints too');
$token2 = (string)($res['json']['token'] ?? '');
assert_true((bool)preg_match('/^[0-9a-f]{64}$/', $token2), 'the second token is well-formed');
assert_neq($token, $token2, 'each mint is a fresh token');
assert_eq(hash('sha256', $token2), stored_token()['hash'], 'the new hash replaced the old');

// Redeem: the Location header sets the implicit 302 (visible even in CLI
// via http_response_code), and success echoes nothing.
$res = run_sso($ssoKeyHash, 'GET', null, null, $token2);
assert_eq(302, $res['status'], 'a valid token redirects into the admin: ' . $res['body']);
assert_eq('', $res['body'], 'the redirect has no body');
assert_eq('', stored_token()['hash'], 'redemption consumed the token');

// Replay of the very same URL: single use means 403 now.
$res = run_sso($ssoKeyHash, 'GET', null, null, $token2);
assert_eq(403, $res['status'], 'replaying a redeemed token is refused');
assert_true(str_contains($res['body'], 'expired'), 'the replay gets the expired copy');

// --- Expiry: a stale token is refused even when the hash still matches -------
$res = run_sso($ssoKeyHash, 'POST', $ssoKey);
$token3 = (string)($res['json']['token'] ?? '');
assert_true($token3 !== '', 'a third token minted');
Config::set('sso_token_expires', time() - 5); // age it past the 60 s window
$res = run_sso($ssoKeyHash, 'GET', null, null, $token3);
assert_eq(403, $res['status'], 'an expired token is refused');
assert_eq('', stored_token()['hash'], 'the expired attempt still burned the stored hash');

// --- (g) Any other method ----------------------------------------------------
$res = run_sso($ssoKeyHash, 'PUT', $ssoKey);
assert_eq(405, $res['status'], 'PUT is refused');
assert_eq(['error' => 'GET or POST required'], $res['json'], 'with the method-not-allowed body');

echo "test_sso_endpoint: ok\n";
