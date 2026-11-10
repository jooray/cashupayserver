<?php
/**
 * Functional smoke for the Windows desktop package: drives the app's real
 * HTTP surface end to end, the way a merchant's first launch does.
 *
 *   1. A fresh install redirects to the onboarding wizard (200 down the chain).
 *   2. The wizard walks its DESKTOP shape (terms straight to password, a
 *      9-step counter, no cron screen) to completion with mints declined —
 *      no payment rails, so the smoke needs no external services.
 *   3. The admin session works: login, CSRF token, dashboard lists the store.
 *   4. An API key can be minted and authenticates against /api/v1.
 *   5. Invoice creation on the rail-less store fails with the clean
 *      "no payment methods" validation error — the whole API stack (auth,
 *      routing, JSON handling, DB writes) proves out without needing a mint.
 *
 * Run by scripts/windows-smoke.ps1 against the real package in CI, by
 * tests/e2e/test_desktop_smoke_driver.py against Linux desktop-mode and
 * server-mode instances so the driver itself cannot rot unnoticed between
 * releases, and by .github/workflows/webserver-smoke.yml (server shape)
 * against real Apache and nginx containers.
 *
 * The optional shape argument selects which wizard the target shows:
 *   desktop (default)  terms -> analytics -> install type -> password,
 *                      "of 11", no security or cron screens
 *   server             terms -> analytics -> install type -> security ->
 *                      password, "of 13" (data dir inside the webroot), cron
 *                      screen with a crontab line
 *
 * Usage: php desktop-smoke.php <base-url> [desktop|server]   (exit 0 = pass)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = rtrim((string) ($argv[1] ?? ''), '/');
$shape = (string) ($argv[2] ?? 'desktop');
if ($base === '' || !preg_match('#^https?://#', $base)
        || !in_array($shape, ['desktop', 'server'], true)) {
    fwrite(STDERR, "usage: php desktop-smoke.php <base-url> [desktop|server]\n");
    exit(2);
}

function fail(string $msg, string $body = ''): void {
    fwrite(STDERR, "desktop-smoke FAIL: $msg\n");
    if ($body !== '') {
        fwrite(STDERR, "---- last response body (first 800 bytes) ----\n"
            . substr($body, 0, 800) . "\n");
    }
    exit(1);
}

function ok(string $msg): void {
    echo "ok - $msg\n";
}

/**
 * One HTTP request on the shared handle. curl_reset() clears options but
 * keeps the handle's in-memory cookies, so the PHP session (wizard, admin)
 * carries across calls.
 *
 * @return array{0:int,1:string,2:string} [status, body, effective url]
 */
function request(
    CurlHandle $ch,
    string $method,
    string $url,
    ?array $form = null,
    array $headers = [],
    bool $follow = false,
    ?string $rawBody = null
): array {
    curl_reset($ch);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => '',      // enable the in-memory cookie engine
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    } elseif ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        fail("$method $url: " . curl_error($ch));
    }
    return [
        (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        (string) $body,
        (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
}

/** The screen's <h2>, entity-decoded, with the decorative leading emoji
 *  stripped (mirrors tests/fixtures/setup_helpers.py::wizard_heading). */
function heading(string $body): string {
    if (!preg_match('#<h2[^>]*>(.*?)</h2>#s', $body, $m)) {
        return '';
    }
    $t = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    while ($t !== '') {
        $first = mb_substr($t, 0, 1, 'UTF-8');
        if ($first !== ' ' && mb_ord($first, 'UTF-8') < 0x2000) {
            break;
        }
        $t = mb_substr($t, 1, null, 'UTF-8');
    }
    return trim($t);
}

/** The wizard's validation message, or null. It returns 200 with an error
 *  <div> rather than a 4xx, so this is how a failed step is detected. */
function wizard_error(string $body): ?string {
    if (preg_match('#<div class="error"(?:\s+id="setup-error")?>(.*?)</div>#s', $body, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return null;
}

$ch = curl_init();

// --- 1. Fresh install redirects to the wizard -------------------------------
[$status, $body, $effective] = request($ch, 'GET', $base . '/', null, [], true);
if ($status !== 200) {
    fail("GET / (following redirects) -> $status, expected 200", $body);
}
if (stripos($effective, 'setup') === false) {
    fail("expected the fresh-install redirect to land on the wizard, landed on $effective", $body);
}
$setupUrl = strtok($effective, '?');
ok("fresh install serves the onboarding wizard ($effective)");

/** The session's CSRF token from a rendered wizard screen (every wizard POST
 *  must carry it; mirrors tests/fixtures/setup_helpers.py::wizard_csrf_token). */
function wizard_csrf_token(string $body): ?string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return null;
}

$wizardCsrf = wizard_csrf_token($body);
if ($wizardCsrf === null) {
    fail('the wizard screen rendered no csrf_token field', $body);
}

$wpost = function (array $data) use ($ch, $setupUrl, &$wizardCsrf): string {
    $data['csrf_token'] = $wizardCsrf;
    [$status, $body] = request($ch, 'POST', $setupUrl, $data);
    $step = $data['step'] ?? '?';
    if ($status >= 400) {
        fail("wizard step '$step' -> $status", $body);
    }
    $err = wizard_error($body);
    if ($err !== null) {
        fail("wizard step '$step' rejected: $err");
    }
    $next = wizard_csrf_token($body);
    if ($next !== null) {
        $wizardCsrf = $next;
    }
    return $body;
};

// --- 2. The wizard, in the requested shape, to completion --------------------
$body = $wpost(['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1']);
// Both shapes ask about anonymous usage statistics on screen two; decline
// (the default), then pick the "something else" installation type, which
// keeps every rail screen.
if (heading($body) !== 'Anonymous usage statistics') {
    fail("accepting the terms must land on the usage-statistics screen, landed on '" . heading($body) . "'", $body);
}
$body = $wpost(['step' => 'analytics', 'usage_stats' => '0']);
if (heading($body) !== 'Choose your installation type') {
    fail("declining usage statistics must land on the installation-type screen, landed on '" . heading($body) . "'", $body);
}
$body = $wpost(['step' => 'install_type', 'install_type' => 'other']);
if ($shape === 'desktop') {
    if (heading($body) !== 'Create your admin password') {
        fail("desktop mode must go straight from the installation type to password, landed on '" . heading($body) . "'", $body);
    }
    if (strpos($body, 'of 11') === false) {
        fail('step counter must show the desktop count ("of 11")', $body);
    }
} else {
    if (heading($body) !== 'Quick safety check') {
        fail("server mode must show the security screen after the installation type, landed on '" . heading($body) . "'", $body);
    }
    // "of 13": full sequence minus zeroconf (no on-chain rail in this walk),
    // with the security screen present (data dir inside the webroot).
    if (strpos($body, 'of 13') === false) {
        fail('step counter must show the server count ("of 13")', $body);
    }
    $body = $wpost(['step' => 'security', 'security_acknowledged' => '1']);
    if (heading($body) !== 'Create your admin password') {
        fail("acknowledging the security screen must land on password, landed on '" . heading($body) . "'", $body);
    }
}

$password = 'desktop-smoke-pw-1234';
$body = $wpost(['step' => 'password', 'password' => $password, 'confirm_password' => $password]);
if (heading($body) !== 'E-mail notifications') {
    fail("the password must land on the e-mail screen, landed on '" . heading($body) . "'", $body);
}
// No SMTP server in the smoke rig: skip the e-mail screen.
$wpost(['step' => 'email', 'email_action' => 'skip']);
$wpost(['step' => 'store', 'store_name' => 'Smoke Store', 'default_currency' => 'sat']);
$wpost(['step' => 'onchain', 'onchain_action' => 'skip']);
$wpost(['step' => 'lightning', 'lightning_action' => 'skip']);
$body = $wpost(['step' => 'mints', 'mints_enabled' => '0']);
// Both shapes ask the auto-update question after mints; decline it (the
// safe default) the way a cautious merchant would.
if (heading($body) !== 'Enable automatic updates?') {
    fail("declining mints must land on the auto-update screen, landed on '" . heading($body) . "'", $body);
}
if (strpos($body, 'wizard-back-link') === false) {
    fail('the auto-update screen must carry a Back link', $body);
}
$body = $wpost(['step' => 'updates', 'auto_update' => '0']);

if ($shape === 'desktop') {
    if (heading($body) !== "You're all set!") {
        fail("declining auto-update must land on the completion screen, landed on '" . heading($body) . "'", $body);
    }
    if (strpos($body, 'Background jobs run automatically') === false) {
        fail('completion screen must carry the desktop background-jobs note', $body);
    }
    if (stripos($body, 'crontab') !== false) {
        fail('crontab instructions must not appear on a desktop install', $body);
    }
    ok('onboarding wizard completes in its desktop shape (no security or cron screens)');
} else {
    if (heading($body) !== 'Enable cron') {
        fail("declining auto-update must land on the cron screen, landed on '" . heading($body) . "'", $body);
    }
    if (strpos($body, 'wizard-back-link') === false) {
        fail('the cron screen must carry a Back link', $body);
    }
    if (stripos($body, 'crontab') === false) {
        fail('server cron screen must show the crontab instructions', $body);
    }
    if (stripos($body, 'schtasks') !== false) {
        fail('Windows schtasks instructions must not appear on a Linux server install', $body);
    }
    $body = $wpost(['step' => 'cron']);
    if (heading($body) !== "You're all set!") {
        fail("the cron step must land on the completion screen, landed on '" . heading($body) . "'", $body);
    }
    if (strpos($body, 'Background jobs run automatically') !== false) {
        fail('the desktop background-jobs note must not appear on a server install', $body);
    }
    ok('onboarding wizard completes in its server shape (security + cron screens)');
}

// --- 3. Admin session -------------------------------------------------------
[$status, $body] = request($ch, 'POST', $base . '/admin', [
    'action' => 'login', 'username' => 'admin', 'password' => $password,
]);
$login = json_decode($body, true);
if ($status !== 200 || !is_array($login) || ($login['success'] ?? false) !== true) {
    fail("admin login failed ($status)", $body);
}
$csrf = (string) ($login['csrfToken'] ?? '');
if ($csrf === '') {
    [, $adminHtml] = request($ch, 'GET', $base . '/admin');
    if (!preg_match('/name="csrf-token"\s+content="([^"]+)"/', $adminHtml, $m)) {
        fail('no csrfToken in the login response and none in the admin page meta');
    }
    $csrf = $m[1];
}
ok('admin login succeeds and yields a CSRF token');

// --- 4. Dashboard + API key -------------------------------------------------
[$status, $body] = request($ch, 'GET', $base . '/admin?api=dashboard');
$dash = json_decode($body, true);
$stores = is_array($dash) ? ($dash['stores'] ?? []) : [];
if ($status !== 200 || count($stores) !== 1) {
    fail("dashboard must list exactly the one wizard-created store ($status)", $body);
}
if (($stores[0]['name'] ?? '') !== 'Smoke Store') {
    fail("store name mismatch: expected 'Smoke Store', got '" . ($stores[0]['name'] ?? '') . "'");
}
$storeId = (string) $stores[0]['id'];

[$status, $body] = request(
    $ch,
    'POST',
    $base . '/admin',
    ['action' => 'create_api_key', 'store_id' => $storeId, 'label' => 'desktop-smoke'],
    ['X-CSRF-Token: ' . $csrf]
);
$keyResp = json_decode($body, true) ?: [];
$apiKey = (string) ($keyResp['key'] ?? $keyResp['apiKey'] ?? $keyResp['token'] ?? '');
if ($status !== 200 || $apiKey === '') {
    fail("create_api_key failed ($status)", $body);
}
ok('dashboard lists the store; API key minted');

// --- 5. Greenfield API surface ----------------------------------------------
$auth = 'Authorization: token ' . $apiKey;

[$status, $body] = request($ch, 'GET', $base . '/api/v1/server/info', null, [$auth]);
if ($status !== 200 || !is_array(json_decode($body, true))) {
    fail("GET /api/v1/server/info -> $status, expected 200 JSON", $body);
}

// This store deliberately has no rails; creation must fail with the clean
// validation error, not a 500 — that path exercises auth, routing, the store
// lookup and the rail-selection logic.
[$status, $body] = request(
    $ch,
    'POST',
    $base . "/api/v1/stores/$storeId/invoices",
    null,
    [$auth, 'Content-Type: application/json'],
    false,
    json_encode(['amount' => '21', 'currency' => 'sat'])
);
$err = json_decode($body, true) ?: [];
if ($status !== 400 || ($err['code'] ?? '') !== 'invoice-error') {
    fail("rail-less invoice creation must 400 with code invoice-error, got $status", $body);
}
if (stripos((string) ($err['message'] ?? ''), 'no payment methods') === false) {
    fail('expected the "no payment methods" message, got: ' . $body);
}
ok('API auth works; rail-less invoice creation fails with the clean validation error');

echo "desktop-smoke: all checks passed\n";
