<?php
/**
 * WordPress plugin fixes from the 2026-11 audit (AUDIT-KIMIK31):
 *  - #5: deleting the plugin on a staging copy must not revoke production's key or
 *    switch off its webhook in a data folder they share (legacy dirname(ABSPATH)/
 *    cashupay-data, or a copied CASHUPAY_DATA_DIR); this site's own folder still is.
 *  - A revocation a database error prevented is remembered and done on activation.
 *  - UX-A11: cashupay_gateway_url_is_ours() (scheme, case, trailing slash; no prefix match).
 *  - UX-A2: the wp-admin next step asks to connect again while the gateway points at
 *    CashuPay but the webhook that marks orders paid is missing or switched off.
 *  - UX-A7: a readable-link check that could not run says so, with instructions.
 *  - UX-A8: a "soft 404" answering 200 for every address is not a downloadable wallet.
 *
 * Each scenario runs in a child process with the WordPress API stubbed, against the real
 * plugin files laid out the way scripts/build-wordpress-plugin.sh does.
 *
 * Run: php tests/wordpress_2026_11_audit.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);

// --- child: one simulated WordPress request -------------------------------------
if (($argv[1] ?? '') === '--child') {
    $opt = json_decode($argv[2], true);
    define('ABSPATH', $opt['abspath']);
    define('WP_CONTENT_DIR', $opt['abspath'] . 'wp-content');
    define('HOUR_IN_SECONDS', 3600);
    define('MINUTE_IN_SECONDS', 60);
    if (isset($opt['datadir'])) {
        define('CASHUPAY_DATA_DIR', $opt['datadir']);
    }
    $GLOBALS['opt'] = $opt;
    $GLOBALS['wp_options'] = $opt['options'] ?? [];
    $GLOBALS['wp_transients'] = [];

    class WP_Error { public function __construct(public string $code = '', public string $message = '') {} }
    function is_wp_error($v): bool { return $v instanceof WP_Error; }
    function is_multisite(): bool { return false; }
    function get_current_blog_id(): int { return 1; }
    function is_super_admin(): bool { return true; }
    function is_network_admin(): bool { return false; }
    function current_user_can(string $cap, ...$a): bool { return true; }
    function get_current_user_id(): int { return 1; }
    function site_url(string $path = ''): string { return rtrim($GLOBALS['opt']['site'], '/') . $path; }
    function home_url(string $path = ''): string { return site_url($path); }
    function content_url(string $path = ''): string { return site_url('/wp-content' . $path); }
    function get_site_url(?int $blog = null, string $path = ''): string { return site_url($path); }
    function admin_url(string $path = ''): string { return site_url('/wp-admin/' . $path); }
    function wp_parse_url(string $url, int $c = -1) { return parse_url($url, $c); }
    function wp_nonce_url(string $url, string $action = ''): string { return $url . '&_wpnonce=N-' . $action; }
    function check_admin_referer(string $a): bool { return true; }
    function add_query_arg(string $k, string $v): string { return '/wp-admin/?' . $k . '=' . $v; }
    function __(string $s, string $d = ''): string { return $s; }
    function esc_html__(string $s, string $d = ''): string { return htmlspecialchars($s); }
    function esc_html(string $s): string { return htmlspecialchars($s); }
    function esc_url(string $s): string { return htmlspecialchars($s); }
    function get_option(string $k, $d = false) { return $GLOBALS['wp_options'][$k] ?? $d; }
    function update_option(string $k, $v, $autoload = null): bool { $GLOBALS['wp_options'][$k] = $v; return true; }
    function delete_option(string $k): bool { unset($GLOBALS['wp_options'][$k]); return true; }
    function get_transient(string $k) { return $GLOBALS['wp_transients'][$k] ?? false; }
    function set_transient(string $k, $v, int $t = 0): bool { $GLOBALS['wp_transients'][$k] = $v; return true; }
    function delete_transient(string $k): bool { unset($GLOBALS['wp_transients'][$k]); return true; }
    function add_action(...$a): void {}
    function add_filter(...$a): void {}
    function did_action(string $a): int { return 0; }
    function add_rewrite_rule(...$a): void {}
    function flush_rewrite_rules(bool $hard = true): void {}
    function wp_next_scheduled(string $h) { return false; }
    function wp_schedule_event(...$a): bool { return true; }
    function wp_unschedule_event(...$a): bool { return true; }
    function wp_safe_redirect(string $url): void { throw new RuntimeException('redirect'); }
    function get_plugins(): array {
        return ['woocommerce/woocommerce.php' => [], 'btcpay-greenfield-for-woocommerce/btcpay-greenfield-for-woocommerce.php' => []];
    }
    function is_plugin_active(string $f): bool { return true; }

    // Loopback HTTP: 'error' (cannot reach itself), 'json' (some other JSON), 'info'
    // (CashuPay's server info), 'soft404' / 'plain404' (200 for everything), 'real'
    // (the wallet file's own first bytes).
    function wp_remote_get(string $url, array $args = []) {
        $mode = $GLOBALS['opt']['http'] ?? 'error';
        if ($mode === 'error') {
            return new WP_Error('http_request_failed', 'cURL error 7');
        }
        $resp = fn(string $body, string $type) => ['code' => 200, 'body' => $body, 'type' => $type];
        switch ($mode) {
            case 'json': return $resp('{"code":"rest_no_route"}', 'application/json');
            case 'info': return $resp('{"isCashuPayServer":true,"version":"2.0.0"}', 'application/json');
            case 'soft404': return $resp("<!DOCTYPE html>\n<html><body>Not found</body></html>", 'text/html; charset=UTF-8');
            case 'plain404': return $resp('Nothing here, sorry', 'application/octet-stream');
            case 'real':
                $file = cashupay_data_dir() . '/' . basename((string) parse_url($url, PHP_URL_PATH));
                return ['code' => 206, 'body' => (string) file_get_contents($file, false, null, 0, 16), 'type' => 'application/octet-stream'];
        }
        return new WP_Error('x', 'x');
    }
    function wp_remote_retrieve_response_code($r) { return $r['code']; }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    function wp_remote_retrieve_header($r, string $h) { return strtolower($h) === 'content-type' ? $r['type'] : ''; }

    $out = [];
    if ($opt['scenario'] === 'uninstall') {
        define('WP_UNINSTALL_PLUGIN', 'cashupay/cashupay.php');
        require $opt['plugin'] . '/uninstall.php';
        $out['options'] = $GLOBALS['wp_options'];
        echo json_encode($out);
        exit(0);
    }

    require $opt['plugin'] . '/bootstrap.php';
    require $opt['plugin'] . '/activation.php';
    require $opt['plugin'] . '/rewrite-rules.php';
    require $opt['plugin'] . '/admin-menu.php';
    require $opt['plugin'] . '/btcpay-dependency.php';
    require $opt['plugin'] . '/btcpay-integration.php';

    cashupay_activate_unguarded();
    foreach ($opt['sql'] ?? [] as $sql) {
        Database::query($sql);
    }
    switch ($opt['scenario']) {
        case 'activate':
            $out['keys'] = Database::fetchAll('SELECT id FROM api_keys ORDER BY id');
            break;
        case 'connect':
            Config::set('setup_complete', true);
            Database::query("INSERT OR IGNORE INTO stores (id, name, mint_url, mint_unit, seed_phrase, created_at)
                             VALUES ('store_A', 'Yarn', 'https://mint.example', 'sat', 'DUMMY', ?)", [time()]);
            $out['result'] = cashupay_connect_woocommerce_store('store_A');
            $out['step'] = cashupay_btcpay_next_step();
            break;
        case 'next_step':
            $out['step'] = cashupay_btcpay_next_step();
            break;
        case 'pretty_links':
            $out['reachable'] = cashupay_api_reachable();
            try {
                cashupay_enable_pretty_links();
            } catch (RuntimeException $e) {
            }
            $out['result'] = get_transient(CASHUPAY_LINKS_RESULT_TRANSIENT . '1');
            break;
        case 'exposure':
            $out['exposure'] = cashupay_data_exposure();
            ob_start();
            delete_transient('cashupay_data_exposure');
            cashupay_data_exposure_notice();
            $out['notice'] = ob_get_clean();
            break;
    }
    $out['options'] = $GLOBALS['wp_options'];
    echo json_encode($out);
    exit(0);
}

// --- parent ----------------------------------------------------------------------
function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$tmp = sys_get_temp_dir() . '/cashupay-wpaudit2611-' . bin2hex(random_bytes(8));
$plugin = $tmp . '/plugin';
mkdir($plugin, 0700, true);
foreach (glob($root . '/wordpress/*.php') as $file) {
    copy($file, $plugin . '/' . basename($file));
}
foreach (['includes', 'cashu-wallet-php', 'api-keys', 'assets'] as $dir) {
    symlink($root . '/' . $dir, $plugin . '/' . $dir);
}
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

function wp(array $opt): array {
    global $plugin;
    $opt += ['plugin' => $plugin];
    $proc = proc_open([PHP_BINARY, __FILE__, '--child', json_encode($opt)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    $data = json_decode((string)$out, true);
    if ($code !== 0 || !is_array($data)) {
        fwrite(STDERR, "child failed ($code): $out\n$err\n");
        exit(1);
    }
    return $data;
}

/** A minimal wallet database: the rows uninstall.php reads and changes. */
function walletDb(string $dir, ?string $baseUrl, string $keyHash, string $webhookId, ?string $owner): void {
    @mkdir($dir, 0700, true);
    $pdo = new PDO('sqlite:' . $dir . '/cashupay.sqlite');
    $pdo->exec('CREATE TABLE api_keys (id TEXT PRIMARY KEY, key_hash TEXT)');
    $pdo->exec('CREATE TABLE webhooks (id TEXT PRIMARY KEY, enabled INTEGER)');
    $pdo->exec('CREATE TABLE config (key TEXT PRIMARY KEY, value TEXT)');
    $pdo->prepare('INSERT INTO api_keys VALUES (?, ?)')->execute(['key_1', $keyHash]);
    $pdo->prepare('INSERT INTO webhooks VALUES (?, 1)')->execute([$webhookId]);
    if ($baseUrl !== null) {
        $pdo->prepare('INSERT INTO config VALUES (?, ?)')->execute(['base_url', json_encode($baseUrl)]);
    }
    if ($owner !== null) {
        file_put_contents($dir . '/.owner', $owner . "\nblog 1\n");
    }
}
/** @return array{keys: int, webhook_enabled: int} */
function walletState(string $dir): array {
    $pdo = new PDO('sqlite:' . $dir . '/cashupay.sqlite');
    return [
        'keys' => (int) $pdo->query('SELECT COUNT(*) FROM api_keys')->fetchColumn(),
        'webhook_enabled' => (int) $pdo->query('SELECT enabled FROM webhooks')->fetchColumn(),
    ];
}

$key = 'staging-and-production-share-this-key';
$keyHash = hash('sha256', $key);
$gatewayOptions = fn(string $site) => [
    'btcpay_gf_url' => "$site/cashupay",
    'btcpay_gf_api_key' => $key,
    'btcpay_gf_store_id' => 'store_A',
    'btcpay_gf_webhook' => ['id' => 'wh_1', 'url' => "$site/?wc-api=btcpaygf_default", 'secret' => 's'],
];

echo "Uninstalling a staging copy leaves production's wallet alone (#5)\n";
$hosting = "$tmp/hosting";
$prodRoot = "$hosting/prod/";
$stagingRoot = "$hosting/staging/";
mkdir($prodRoot . 'wp-content', 0700, true);
mkdir($stagingRoot . 'wp-content', 0700, true);
$legacy = "$hosting/cashupay-data";
walletDb($legacy, 'https://shop.example/cashupay', $keyHash, 'wh_1', $prodRoot);
$out = wp(['scenario' => 'uninstall', 'abspath' => $stagingRoot, 'site' => 'https://staging.shop.example',
    'options' => $gatewayOptions('https://staging.shop.example')]);
check(walletState($legacy) === ['keys' => 1, 'webhook_enabled' => 1],
    'production\'s marked legacy folder keeps its key and its webhook');
check(!isset($out['options']['btcpay_gf_url']), 'the staging site\'s own gateway options are still removed');

unlink("$legacy/.owner");
wp(['scenario' => 'uninstall', 'abspath' => $stagingRoot, 'site' => 'https://staging.shop.example',
    'options' => $gatewayOptions('https://staging.shop.example')]);
check(walletState($legacy) === ['keys' => 1, 'webhook_enabled' => 1],
    'an unmarked legacy folder whose database remembers production\'s address is left alone too');

$copied = "$tmp/private/cashupay-db";
walletDb($copied, 'https://shop.example/cashupay', $keyHash, 'wh_1', $prodRoot);
wp(['scenario' => 'uninstall', 'abspath' => $stagingRoot, 'site' => 'https://staging.shop.example', 'datadir' => $copied,
    'options' => $gatewayOptions('https://staging.shop.example')]);
check(walletState($copied) === ['keys' => 1, 'webhook_enabled' => 1],
    'a CASHUPAY_DATA_DIR copied from production\'s wp-config.php is left alone');

echo "Uninstalling the site that owns the folder revokes its key\n";
exec('rm -rf ' . escapeshellarg($legacy));
walletDb($legacy, 'https://shop.example/cashupay', $keyHash, 'wh_1', $prodRoot);
$out = wp(['scenario' => 'uninstall', 'abspath' => $prodRoot, 'site' => 'https://shop.example',
    'options' => $gatewayOptions('https://shop.example')]);
check(walletState($legacy) === ['keys' => 0, 'webhook_enabled' => 0], 'a marked folder: key revoked, webhook off');
check(!isset($out['options']['btcpay_gf_api_key']) && !isset($out['options']['cashupay_pending_key_revocations']),
    'the key option is gone and nothing is left pending');
exec('rm -rf ' . escapeshellarg($legacy));
walletDb($legacy, 'http://SHOP.example/cashupay/', $keyHash, 'wh_1', null);
wp(['scenario' => 'uninstall', 'abspath' => $prodRoot, 'site' => 'https://shop.example',
    'options' => $gatewayOptions('https://shop.example')]);
check(walletState($legacy) === ['keys' => 0, 'webhook_enabled' => 0],
    'an unmarked folder remembering this site\'s address (other scheme and case) is revoked too');

echo "A revocation a database error prevented is done on the next activation\n";
exec('rm -rf ' . escapeshellarg($legacy));
mkdir($legacy, 0700, true);
file_put_contents("$legacy/.owner", $prodRoot . "\nblog 1\n");
file_put_contents("$legacy/cashupay.sqlite", str_repeat('not a database ', 100));
$out = wp(['scenario' => 'uninstall', 'abspath' => $prodRoot, 'site' => 'https://shop.example',
    'options' => $gatewayOptions('https://shop.example')]);
check(!isset($out['options']['btcpay_gf_api_key']), 'the key option is removed as before');
check(($out['options']['cashupay_pending_key_revocations'] ?? null) === [$keyHash],
    'but its digest is kept for later');
$fresh = "$tmp/fresh-data";
$siteOpt = ['abspath' => $prodRoot, 'site' => 'https://shop.example', 'datadir' => $fresh];
// The wallet as it was before the uninstall: the gateway's key and an unrelated one.
wp($siteOpt + ['scenario' => 'activate', 'sql' => [
    "INSERT INTO stores (id, name, created_at) VALUES ('store_A', 'Yarn', 0)",
    "INSERT INTO api_keys (id, store_id, key_hash, label, permissions, created_at) VALUES ('k_old', 'store_A', '$keyHash', 'old', '[]', 0)",
    "INSERT INTO api_keys (id, store_id, key_hash, label, permissions, created_at) VALUES ('k_keep', 'store_A', '" . hash('sha256', 'other') . "', 'keep', '[]', 0)",
]]);
$out = wp($siteOpt + ['scenario' => 'activate', 'options' => ['cashupay_pending_key_revocations' => [$keyHash]]]);
check(array_column($out['keys'], 'id') === ['k_keep'], 'activation revokes the remembered key and only that one');
check(!isset($out['options']['cashupay_pending_key_revocations']), 'and forgets it');

echo "The gateway address is recognised as ours exactly (UX-A11)\n";
define('ABSPATH', $prodRoot);
// Conditional, so the child's own site_url() stub is not a redeclaration of this one.
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string { return 'https://shop.example' . $path; }
}
require $root . '/wordpress/site-identity.php';
check(cashupay_gateway_url_is_ours('https://shop.example/cashupay'), 'the same address');
check(cashupay_gateway_url_is_ours('http://shop.example/cashupay'), 'http instead of https');
check(cashupay_gateway_url_is_ours('https://SHOP.example/cashupay/'), 'a trailing slash and another case');
check(cashupay_gateway_url_is_ours('https://shop.example:443/cashupay'), 'https\'s default port spelled out');
check(!cashupay_gateway_url_is_ours('https://shop.example/cashupay-copy'), '/cashupay-copy is not ours');
check(!cashupay_gateway_url_is_ours('https://shop.example/cashupay/other'), 'nor a folder inside it');
check(!cashupay_gateway_url_is_ours('https://shop.example:8443/cashupay'), 'nor another port');
check(!cashupay_gateway_url_is_ours('https://btcpay.example/cashupay'), 'nor another host');
check(!cashupay_gateway_url_is_ours(''), 'nor nothing');

echo "The next step offers Connect while the webhook is missing (UX-A2)\n";
$failed = wp(['abspath' => $prodRoot, 'site' => 'https://shop.example', 'datadir' => "$tmp/shop-unreachable",
    'scenario' => 'connect']);
check($failed['result']['success'] === true && ($failed['result']['webhook']['success'] ?? true) === false
    && ($failed['step']['label'] ?? '') === 'Connect my shop to CashuPay',
    'the gateway points at CashuPay but the webhook could not be registered: Connect is still offered');
// 127.0.0.1: the site's own (pinned) address is an allowed webhook target there.
$shop = ['abspath' => $prodRoot, 'site' => 'http://127.0.0.1', 'datadir' => "$tmp/shop-data"];
$out = wp($shop + ['scenario' => 'connect']);
check(($out['result']['webhook']['success'] ?? false) === true && $out['step'] === null,
    'connected with a webhook: nothing more to do');
$connected = $out['options'];
$step = wp($shop + ['scenario' => 'next_step', 'options' => $connected, 'sql' => ['UPDATE webhooks SET enabled = 0']])['step'];
check(($step['label'] ?? '') === 'Connect my shop to CashuPay' && str_contains((string)$step['why'], 'Pending payment'),
    'a switched-off webhook: Connect, with the business reason');
$step = wp($shop + ['scenario' => 'next_step', 'options' => array_diff_key($connected, ['btcpay_gf_webhook' => 1]),
    'sql' => ['UPDATE webhooks SET enabled = 1']])['step'];
check(($step['label'] ?? '') === 'Connect my shop to CashuPay', 'a gateway that lost its webhook settings: Connect');
$step = wp($shop + ['scenario' => 'next_step', 'options' => ['btcpay_gf_url' => 'https://127.0.0.1/cashupay/'] + $connected])['step'];
check($step === null, 'the same address over https with a trailing slash is still ours and ready');
$step = wp($shop + ['scenario' => 'next_step', 'options' => ['btcpay_gf_url' => 'https://btcpay.example'] + $connected,
    'sql' => ['UPDATE webhooks SET enabled = 0']])['step'];
check($step === null, 'a real BTCPay Server is left alone');
$setup = (string) file_get_contents($root . '/setup.php');
check(str_contains($setup, "&& (\$configResult['webhook']['success'] ?? false)")
    && str_contains($setup, 'cashupay_webhook_failure_message('),
    'setup says "configured" only with the webhook, and otherwise shows the hosting-provider text');

echo "A readable-link check that could not run says so (UX-A7)\n";
$links = $shop + ['scenario' => 'pretty_links', 'options' => ['permalink_structure' => '/%postname%/']];
$out = wp($links + ['http' => 'error']);
check($out['reachable'] === null && $out['result']['ok'] === false
    && str_contains($out['result']['text'], 'could not check automatically')
    && str_contains($out['result']['text'], '/cashupay/api/v1/server/info')
    && str_contains($out['result']['text'], 'Page not found'),
    'request failed: "could not check automatically", the address to open and what to do');
$out = wp($links + ['http' => 'json']);
check($out['reachable'] === false && str_contains($out['result']['text'], 'does not pass them on'),
    'another JSON answer is not CashuPay');
$out = wp($links + ['http' => 'info']);
check($out['reachable'] === true && $out['result']['ok'] === true, 'CashuPay\'s own answer is success');

echo "A soft 404 is not a downloadable wallet (UX-A8)\n";
$inside = ['abspath' => $prodRoot, 'site' => 'https://shop.example', 'datadir' => $prodRoot . 'wp-content/cashupay-data'];
$out = wp($inside + ['scenario' => 'exposure', 'http' => 'soft404']);
check(is_array($out['exposure']) && $out['exposure']['exposed'] === [] && $out['notice'] === '',
    'an HTML page answering 200 for everything: not exposed, no alarm');
$out = wp($inside + ['scenario' => 'exposure', 'http' => 'plain404']);
check($out['exposure']['exposed'] === [] && in_array('cashupay.sqlite', $out['exposure']['inconclusive'], true),
    'other bytes than the database\'s own: inconclusive, not exposed');
$out = wp($inside + ['scenario' => 'exposure', 'http' => 'real']);
check(in_array('cashupay.sqlite', $out['exposure']['exposed'], true)
    && str_contains($out['notice'], 'anyone on the internet can download your wallet'),
    'the database\'s own first bytes: exposed, with the warning');

echo "All 2026-11 WordPress audit checks passed.\n";
