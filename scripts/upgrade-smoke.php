<?php
/**
 * Seed / verify driver for the upgrade tests (tests/upgrade/ on Linux,
 * scripts/windows-upgrade-smoke.ps1 on Windows).
 *
 * The upgrade matrix installs REAL past releases and upgrades them to the
 * build under test, so the install being driven may be any release in the
 * window — its onboarding wizard may have more, fewer or differently ordered
 * screens than today's. This driver therefore never assumes a sequence: it
 * answers whichever screen the wizard renders next (the hidden `step` field
 * names it) from a table of known screens, and fails loudly on a screen it
 * does not know, so a new wizard screen is a visible test change rather than
 * a silently half-seeded install.
 *
 *   seed <base-url> --password=P --store=NAME [--mint=URL [--backup-mint=URL]]
 *                   [--unit=sat] [--products=3]
 *       Walks the wizard to completion (mints when given, else rail-less),
 *       logs in, creates N products and an API key.
 *
 *   verify <base-url> --password=P --store-id=ID --api-key=KEY
 *                     [--store-name=NAME] [--products=N] [--product-titles=a|b|c]
 *                     [--invoices=N]
 *       Proves the install still works for its merchant: admin login with the
 *       pre-upgrade password, the store and its products are listed, the
 *       pre-upgrade API key still authenticates, invoices are still there,
 *       and the Auto-update status endpoint answers.
 *
 * Both modes print "ok - ..." progress lines and end with one line
 * `RESULT={json}` carrying what the caller needs (store id, API key, product
 * ids, build info). Exit 0 = pass, 1 = fail (reason on stderr), 2 = usage.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = (string) ($argv[1] ?? '');
$base = rtrim((string) ($argv[2] ?? ''), '/');
$opts = [];
foreach (array_slice($argv, 3) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unrecognised argument: $arg\n");
        exit(2);
    }
}
if (!in_array($mode, ['seed', 'verify'], true) || $base === '' || !preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "usage: php upgrade-smoke.php seed|verify <base-url> [--key=value ...]\n");
    exit(2);
}

function fail(string $msg, string $body = ''): void {
    fwrite(STDERR, "upgrade-smoke FAIL: $msg\n");
    if ($body !== '') {
        fwrite(STDERR, "---- last response body (first 1200 bytes) ----\n"
            . substr($body, 0, 1200) . "\n");
    }
    exit(1);
}

function ok(string $msg): void {
    echo "ok - $msg\n";
}

function opt(array $opts, string $key, ?string $default = null): ?string {
    return array_key_exists($key, $opts) ? (string) $opts[$key] : $default;
}

function need(array $opts, string $key): string {
    $v = opt($opts, $key);
    if ($v === null || $v === '') {
        fwrite(STDERR, "--$key is required\n");
        exit(2);
    }
    return $v;
}

/**
 * One HTTP request on the shared handle; the in-memory cookie jar carries
 * the PHP session (wizard, admin) across calls.
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
        CURLOPT_COOKIEFILE => '',
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
        }
    }
    $body = curl_exec($ch);
    if ($body === false) {
        fail("$method $url: " . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return [$status, (string) $body, $effective];
}

function json_body(string $body): array {
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

// --- Wizard screen parsing ---------------------------------------------------

/** The screen the wizard rendered, named by its hidden `step` field. */
function wizard_step(string $body): ?string {
    if (preg_match('/<input[^>]+name="step"[^>]+value="([a-z_]+)"/', $body, $m)) {
        return $m[1];
    }
    if (preg_match('/<input[^>]+value="([a-z_]+)"[^>]+name="step"/', $body, $m)) {
        return $m[1];
    }
    return null;
}

function wizard_heading(string $body): string {
    if (!preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $body, $m)) {
        return '';
    }
    $text = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Drop the decorative leading emoji/symbol run.
    $text = preg_replace('/^[\s\x{2000}-\x{FFFF}\x{10000}-\x{10FFFF}]+/u', '', $text) ?? $text;
    return trim($text);
}

function wizard_error(string $body): ?string {
    if (preg_match('/<div class="error">(.*?)<\/div>/s', $body, $m)) {
        return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return null;
}

function wizard_csrf(string $body): ?string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return null;
}

/**
 * The answer for one wizard screen, or null for an unknown one. Every screen
 * any release in the window has shipped is listed; the set is a superset, so
 * the walker copes with screens appearing, disappearing or moving.
 */
function wizard_answer(string $step, array $cfg): ?array {
    switch ($step) {
        case 'terms':
            return ['terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1'];
        case 'analytics':
            return ['usage_stats' => '0'];
        case 'install_type':
            return ['install_type' => 'other']; // keeps every rail screen
        case 'security':
            return ['security_acknowledged' => '1'];
        case 'password':
            return ['password' => $cfg['password'], 'confirm_password' => $cfg['password']];
        case 'email':
            return ['email_action' => 'skip'];
        case 'store':
            return ['store_name' => $cfg['store'], 'default_currency' => $cfg['currency']];
        case 'onchain':
            return ['onchain_action' => 'skip']; // regtest xpub is wired by the test, not the wizard
        case 'zeroconf':
            return ['zeroconf' => '0'];
        case 'lightning':
            return ['lightning_action' => 'skip'];
        case 'strike':
            return ['strike_action' => 'skip'];
        case 'swaps':
            return ['swaps_enabled' => '0'];
        case 'mints':
            if ($cfg['mint'] === '') {
                return ['mints_enabled' => '0'];
            }
            return [
                'mints_enabled' => '1',
                'mint_url' => $cfg['mint'],
                'backup_mint_url' => $cfg['backup_mint'],
                'mint_unit' => $cfg['unit'],
            ];
        case 'wallet_tabs':
            return ['wallet_tabs_action' => 'skip'];
        case 'seed':
            return ['seed_saved' => '1'];
        case 'updates':
            return ['auto_update' => '0'];
        case 'cron':
            return [];
        default:
            return null;
    }
}

/**
 * Walk the wizard from wherever it starts to its completion screen. Returns
 * the ordered list of screens answered.
 */
function walk_wizard(CurlHandle $ch, string $base, array $cfg): array {
    [$status, $body, $effective] = request($ch, 'GET', $base . '/setup.php', null, [], true);
    if ($status !== 200) {
        fail("GET /setup.php -> $status", $body);
    }
    if (strpos($effective, 'setup') === false && wizard_step($body) === null) {
        fail("install is already configured (setup redirected to $effective); seed needs a fresh install");
    }
    $walked = [];
    for ($i = 0; $i < 40; $i++) {
        $step = wizard_step($body);
        $heading = wizard_heading($body);
        if ($step === null || $step === 'done') {
            if (stripos($heading, 'all set') !== false || stripos($body, 'all set') !== false) {
                return $walked;
            }
            fail("wizard rendered a screen without a step form (heading '$heading') before completing", $body);
        }
        $fields = wizard_answer($step, $cfg);
        if ($fields === null) {
            fail("unknown wizard screen '$step' (heading '$heading') — teach scripts/upgrade-smoke.php how to answer it", $body);
        }
        $fields['step'] = $step;
        $csrf = wizard_csrf($body);
        if ($csrf !== null) {
            $fields['csrf_token'] = $csrf;
        }
        [$status, $next] = request($ch, 'POST', $base . '/setup.php', $fields);
        if ($status >= 400) {
            fail("wizard step '$step' -> HTTP $status", $next);
        }
        $err = wizard_error($next);
        if ($err !== null) {
            fail("wizard step '$step' rejected: $err", $next);
        }
        $walked[] = $step;
        if (wizard_step($next) === $step) {
            fail("wizard step '$step' did not advance (heading '" . wizard_heading($next) . "')", $next);
        }
        $body = $next;
    }
    fail('wizard did not complete within 40 screens; walked: ' . implode(' -> ', $walked));
    return []; // unreachable
}

// --- Admin helpers ------------------------------------------------------------

function admin_login(CurlHandle $ch, string $base, string $password): string {
    [$status, $body] = request($ch, 'POST', $base . '/admin.php', [
        'action' => 'login', 'username' => 'admin', 'password' => $password,
    ]);
    $login = json_body($body);
    if ($status !== 200 || ($login['success'] ?? false) !== true) {
        fail("admin login failed ($status)", $body);
    }
    $csrf = (string) ($login['csrfToken'] ?? '');
    if ($csrf === '') {
        [, $adminHtml] = request($ch, 'GET', $base . '/admin.php');
        if (!preg_match('/name="csrf-token"\s+content="([^"]+)"/', $adminHtml, $m)) {
            fail('no csrfToken in the login response and none in the admin page meta');
        }
        $csrf = $m[1];
    }
    return $csrf;
}

function admin_action(CurlHandle $ch, string $base, string $csrf, string $action, array $fields): array {
    [$status, $body] = request(
        $ch, 'POST', $base . '/admin.php',
        ['action' => $action] + $fields,
        ['X-CSRF-Token: ' . $csrf]
    );
    if ($status >= 400) {
        fail("admin action $action -> HTTP $status", $body);
    }
    return json_body($body);
}

function admin_api(CurlHandle $ch, string $base, string $api, array $params = []): array {
    $url = $base . '/admin.php?' . http_build_query(['api' => $api] + $params);
    [$status, $body] = request($ch, 'GET', $url);
    if ($status !== 200) {
        fail("admin api $api -> HTTP $status", $body);
    }
    return json_body($body);
}

function find_store(array $dash, ?string $id, ?string $name): ?array {
    foreach ($dash['stores'] ?? [] as $store) {
        if ($id !== null && (string) ($store['id'] ?? '') === $id) {
            return $store;
        }
        if ($id === null && $name !== null && (string) ($store['name'] ?? '') === $name) {
            return $store;
        }
    }
    return null;
}

function greenfield(CurlHandle $ch, string $base, string $apiKey, string $path): array {
    [$status, $body] = request($ch, 'GET', $base . $path, null, ['Authorization: token ' . $apiKey]);
    return [$status, json_body($body), $body];
}

// =============================================================================

$ch = curl_init();
$password = need($opts, 'password');

if ($mode === 'seed') {
    $cfg = [
        'password' => $password,
        'store' => need($opts, 'store'),
        'currency' => opt($opts, 'currency', 'sat'),
        'mint' => (string) opt($opts, 'mint', ''),
        'backup_mint' => (string) opt($opts, 'backup-mint', ''),
        'unit' => (string) opt($opts, 'unit', 'sat'),
    ];
    $productCount = (int) opt($opts, 'products', '3');

    $walked = walk_wizard($ch, $base, $cfg);
    ok('onboarding wizard completed: ' . implode(' -> ', $walked));

    $csrf = admin_login($ch, $base, $password);
    ok('admin login succeeds');

    $dash = admin_api($ch, $base, 'dashboard');
    $store = find_store($dash, null, $cfg['store']);
    if ($store === null) {
        fail("dashboard does not list the store '{$cfg['store']}' the wizard created", json_encode($dash));
    }
    $storeId = (string) $store['id'];
    ok("dashboard lists the store ($storeId)");

    $products = [];
    for ($i = 1; $i <= $productCount; $i++) {
        $title = "Upgrade product $i";
        $price = (string) (1000 * $i);
        $resp = admin_action($ch, $base, $csrf, 'create_product', [
            'store_id' => $storeId, 'title' => $title, 'price' => $price, 'image_type' => 'none',
        ]);
        if (($resp['success'] ?? false) !== true || empty($resp['product']['id'])) {
            fail("create_product #$i failed", json_encode($resp));
        }
        $products[] = ['id' => (string) $resp['product']['id'], 'title' => $title, 'price' => $price];
    }
    $listed = admin_api($ch, $base, 'products', ['store_id' => $storeId]);
    if (count($listed['products'] ?? []) !== $productCount) {
        fail("expected $productCount products listed, got " . count($listed['products'] ?? []), json_encode($listed));
    }
    ok("$productCount products created and listed");

    $keyResp = admin_action($ch, $base, $csrf, 'create_api_key', [
        'store_id' => $storeId, 'label' => 'upgrade-smoke',
    ]);
    $apiKey = (string) ($keyResp['key'] ?? $keyResp['apiKey'] ?? $keyResp['token'] ?? '');
    if ($apiKey === '') {
        fail('create_api_key returned no key', json_encode($keyResp));
    }
    [$status, $info] = greenfield($ch, $base, $apiKey, '/api/v1/server/info');
    if ($status !== 200 || $info === []) {
        fail("GET /api/v1/server/info with the new key -> $status");
    }
    ok('API key minted and authenticates');

    $updStatus = admin_api($ch, $base, 'update_status');
    echo 'RESULT=' . json_encode([
        'store_id' => $storeId,
        'store_name' => $cfg['store'],
        'api_key' => $apiKey,
        'products' => $products,
        'wizard_steps' => $walked,
        'current_version' => (string) ($updStatus['current_version'] ?? ''),
        'current_sha' => (string) ($updStatus['current_sha'] ?? ''),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// ---- verify -----------------------------------------------------------------

$storeId = need($opts, 'store-id');
$apiKey = need($opts, 'api-key');
$storeName = opt($opts, 'store-name');
$expectProducts = opt($opts, 'products');
$expectTitles = opt($opts, 'product-titles');
$expectInvoices = opt($opts, 'invoices');

// An install whose database upgrade failed renders a dedicated error page
// instead of the login form; catch that before anything else.
[$status, $body] = request($ch, 'GET', $base . '/admin.php', null, [], true);
if ($status >= 500) {
    fail("GET /admin.php -> $status (bootstrap broken)", $body);
}
if (stripos($body, 'database upgrade failed') !== false || stripos($body, 'migration') !== false
        && stripos($body, 'failed') !== false) {
    fail('admin page reports a failed database upgrade', $body);
}
ok('admin page boots');

$csrf = admin_login($ch, $base, $password);
ok('admin login succeeds with the pre-upgrade password');

$dash = admin_api($ch, $base, 'dashboard');
$store = find_store($dash, $storeId, null);
if ($store === null) {
    fail("dashboard no longer lists store $storeId", json_encode($dash));
}
if ($storeName !== null && (string) ($store['name'] ?? '') !== $storeName) {
    fail("store $storeId is now named '" . ($store['name'] ?? '') . "', expected '$storeName'");
}
ok('dashboard still lists the store');

$listed = admin_api($ch, $base, 'products', ['store_id' => $storeId]);
$titles = array_map(static fn($p) => (string) ($p['title'] ?? ''), $listed['products'] ?? []);
if ($expectProducts !== null && count($titles) !== (int) $expectProducts) {
    fail("expected $expectProducts products, got " . count($titles) . ': ' . implode(', ', $titles), json_encode($listed));
}
if ($expectTitles !== null) {
    $want = explode('|', $expectTitles);
    sort($want);
    $have = $titles;
    sort($have);
    if ($want !== $have) {
        fail('product titles changed: expected ' . implode(', ', $want) . ' got ' . implode(', ', $have));
    }
}
ok('products survived (' . count($titles) . ')');

[$status, $info] = greenfield($ch, $base, $apiKey, '/api/v1/server/info');
if ($status !== 200 || $info === []) {
    fail("pre-upgrade API key no longer authenticates: GET /api/v1/server/info -> $status");
}
[$status, $storeJson, $raw] = greenfield($ch, $base, $apiKey, "/api/v1/stores/$storeId");
if ($status !== 200 || (string) ($storeJson['id'] ?? '') !== $storeId) {
    fail("GET /api/v1/stores/$storeId -> $status", $raw);
}
ok('pre-upgrade API key still authenticates');

[$status, $invoices, $raw] = greenfield($ch, $base, $apiKey, "/api/v1/stores/$storeId/invoices");
if ($status !== 200) {
    fail("GET invoices -> $status", $raw);
}
$invoiceIds = array_map(static fn($inv) => (string) ($inv['id'] ?? ''), $invoices);
if ($expectInvoices !== null && count($invoiceIds) < (int) $expectInvoices) {
    fail("expected at least $expectInvoices invoices, got " . count($invoiceIds), $raw);
}
ok('invoices listed (' . count($invoiceIds) . ')');

$updStatus = admin_api($ch, $base, 'update_status');
if (!array_key_exists('current_version', $updStatus)) {
    fail('update_status did not answer', json_encode($updStatus));
}
ok('update status answers (version ' . ($updStatus['current_version'] ?? '?') . ')');

echo 'RESULT=' . json_encode([
    'store_id' => $storeId,
    'products' => $titles,
    'invoice_ids' => $invoiceIds,
    'current_version' => (string) ($updStatus['current_version'] ?? ''),
    'current_sha' => (string) ($updStatus['current_sha'] ?? ''),
    'pending_verify' => $updStatus['pending_verify'] ?? null,
    'last_auto_rollback' => $updStatus['last_auto_rollback'] ?? null,
    'last_update' => $updStatus['last_update'] ?? null,
    'backups' => $updStatus['backups'] ?? [],
], JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
