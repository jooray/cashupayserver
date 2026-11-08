<?php
/**
 * WordPress plugin fixes from the 2026-11 ARGON audit:
 *  - CPS-02: "Configure WooCommerce" after an uninstall/reinstall turns the webhook the
 *    uninstaller disabled back on (and restores its event list); it used to report
 *    success while no order was ever marked paid.
 *  - UX-05: the wp-admin notice offers the same one-click connection as the wizard
 *    (cashupay_connect_woocommerce_store), which mints no key when a real BTCPay Server
 *    is configured, and links to the gateway's real settings tab.
 *  - UX-01: with Plain permalinks, wp-admin explains the problem and offers a button,
 *    and Tools → CashuPay no longer forwards to a page that 404s.
 *  - UX-06.3: the pairing page of a logged-out WordPress admin offers the WordPress
 *    login, not a CashuPay password form that cannot succeed.
 *
 * Each scenario runs in a child process with the WordPress API stubbed, against the real
 * plugin files laid out the way scripts/build-wordpress-plugin.sh does.
 *
 * Run: php tests/wordpress_setup_2026_11.php
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
    define('CASHUPAY_DATA_DIR', $opt['datadir']);
    $GLOBALS['opt'] = $opt;
    $GLOBALS['wp_options'] = $opt['options'] ?? [];
    $GLOBALS['wp_transients'] = [];

    function is_multisite(): bool { return false; }
    function get_current_blog_id(): int { return 1; }
    function is_super_admin(): bool { return true; }
    function is_network_admin(): bool { return false; }
    function current_user_can(string $cap, ...$a): bool { return !empty($GLOBALS['opt']['admin']); }
    function is_user_logged_in(): bool { return !empty($GLOBALS['opt']['logged_in']); }
    function get_current_user_id(): int { return 1; }
    function site_url(string $path = ''): string { return rtrim($GLOBALS['opt']['site'], '/') . $path; }
    function home_url(string $path = ''): string { return site_url($path); }
    function get_site_url(?int $blog = null, string $path = ''): string { return site_url($path); }
    function admin_url(string $path = ''): string { return site_url('/wp-admin/' . $path); }
    function wp_login_url(string $redirect = ''): string { return site_url('/wp-login.php?redirect_to=' . rawurlencode($redirect)); }
    function wp_nonce_url(string $url, string $action = ''): string { return $url . '&_wpnonce=N-' . $action; }
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
    function get_user_meta(...$a) { return ''; }
    function add_action(...$a): void {}
    function add_filter(...$a): void {}
    function did_action(string $a): int { return 0; }
    function add_rewrite_rule(...$a): void {}
    function flush_rewrite_rules(bool $hard = true): void {}
    function wp_next_scheduled(string $h) { return 1; }
    function wp_schedule_event(...$a): bool { return true; }
    function wp_redirect(string $url): void { echo json_encode(['redirect' => $url]); exit(0); }
    function get_plugins(): array { return $GLOBALS['opt']['plugins'] ?? []; }
    function is_plugin_active(string $f): bool { return true; }

    require $opt['plugin'] . '/bootstrap.php';
    require $opt['plugin'] . '/activation.php';
    require $opt['plugin'] . '/rewrite-rules.php';
    require $opt['plugin'] . '/admin-menu.php';
    require $opt['plugin'] . '/btcpay-dependency.php';
    require $opt['plugin'] . '/btcpay-integration.php';

    $out = [];
    switch ($opt['scenario']) {
        case 'connect':
            cashupay_activate_unguarded();
            Config::set('setup_complete', true);
            if (!Database::fetchOne("SELECT id FROM stores WHERE id = 'store_A'")) {
                Database::query("INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, created_at)
                                 VALUES ('store_A', 'Yarn', 'https://mint.example', 'sat', 'DUMMY', ?)", [time()]);
            }
            foreach ($opt['sql'] ?? [] as $sql) {
                Database::query($sql);
            }
            $out['store'] = cashupay_woocommerce_store();
            $out['result'] = cashupay_connect_woocommerce_store('store_A');
            $out['options'] = $GLOBALS['wp_options'];
            $out['webhooks'] = Database::fetchAll('SELECT id, enabled, events FROM webhooks');
            $out['keys'] = (int)Database::fetchOne("SELECT COUNT(*) AS n FROM api_keys WHERE application_identifier = 'woocommerce'")['n'];
            break;
        case 'next_step':
            cashupay_activate_unguarded();
            $out['step'] = cashupay_btcpay_next_step();
            break;
        case 'permalinks':
            ob_start();
            cashupay_plain_permalinks_notice();
            cashupay_admin_notice();
            $out['notice'] = ob_get_clean();
            ob_start();
            cashupay_admin_redirect(); // exits with the target when it forwards
            cashupay_admin_page();
            $out['page'] = ob_get_clean();
            break;
        case 'authorize':
            cashupay_activate_unguarded();
            Config::set('setup_complete', true);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/cashupay/api-keys/authorize?applicationName=Shop';
            $_SERVER['QUERY_STRING'] = 'applicationName=Shop';
            $_GET = ['applicationName' => 'Shop'];
            ob_start();
            require $opt['plugin'] . '/api-keys/authorize.php';
            $out['page'] = ob_get_clean();
            break;
    }
    echo json_encode($out);
    exit(0);
}

// --- parent ----------------------------------------------------------------------
function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$tmp = sys_get_temp_dir() . '/cashupay-wp2611-' . bin2hex(random_bytes(8));
$plugin = $tmp . '/plugin';
mkdir($plugin, 0700, true);
foreach (glob($root . '/wordpress/*.php') as $file) {
    copy($file, $plugin . '/' . basename($file));
}
foreach (['includes', 'cashu-wallet-php', 'api-keys', 'assets'] as $dir) {
    symlink($root . '/' . $dir, $plugin . '/' . $dir);
}
mkdir("$tmp/site/wp-content", 0700, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

function wp(array $opt): array {
    global $plugin, $tmp;
    $opt += ['plugin' => $plugin, 'admin' => true, 'abspath' => "$tmp/site/", 'site' => 'http://127.0.0.1',
        'datadir' => "$tmp/data"];
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

echo "Reconnecting after an uninstall turns the webhook back on (CPS-02)\n";
$first = wp(['scenario' => 'connect']);
check($first['result']['success'] === true && ($first['result']['webhook']['success'] ?? false) === true,
    'the first connection succeeds and registers a webhook');
check(count($first['webhooks']) === 1 && (int)$first['webhooks'][0]['enabled'] === 1, 'one enabled webhook');
check($first['options']['btcpay_gf_url'] === 'http://127.0.0.1/cashupay' && $first['keys'] === 1,
    'the gateway points at CashuPay with one dedicated key');
$whId = $first['webhooks'][0]['id'];
// What uninstall.php leaves behind: the row disabled (deleted_at NULL), options gone.
$second = wp(['scenario' => 'connect', 'sql' => [
    "UPDATE webhooks SET enabled = 0, events = '[\"InvoiceCreated\"]'",
]]);
check($second['result']['success'] === true && ($second['result']['webhook']['existing'] ?? false) === true,
    'reconnecting reuses the old webhook');
check(count($second['webhooks']) === 1 && (int)$second['webhooks'][0]['enabled'] === 1,
    'and switches it back on');
check(in_array('InvoiceSettled', json_decode($second['webhooks'][0]['events'], true), true),
    'with the full event list again');
check(($second['options']['btcpay_gf_webhook']['id'] ?? null) === $whId, 'the gateway gets the same webhook id');
check($second['keys'] === 1, 'the earlier key was replaced, not added to');

echo "The connect button refuses a real BTCPay Server without leaving a key (UX-05)\n";
$real = wp(['scenario' => 'connect', 'options' => ['btcpay_gf_url' => 'https://btcpay.example.com']]);
check($real['result']['success'] === false && str_contains($real['result']['message'], 'BTCPay Settings'),
    'refused, with the settings tab named');
check($real['options']['btcpay_gf_url'] === 'https://btcpay.example.com', 'the real server stays configured');
check($real['keys'] === 1, 'and no extra key was minted');

$plugins = ['woocommerce/woocommerce.php' => [], 'btcpay-greenfield-for-woocommerce/btcpay-greenfield-for-woocommerce.php' => []];
$step = wp(['scenario' => 'next_step', 'plugins' => $plugins])['step'];
check(($step['label'] ?? '') === 'Connect my shop to CashuPay'
    && str_contains((string)$step['url'], 'admin-post.php?action=cashupay_connect_woocommerce')
    && str_contains((string)$step['url'], '_wpnonce=N-cashupay_connect_woocommerce'),
    'a not-yet-connected gateway gets a one-click connect button (with a nonce)');
check(!str_contains((string)$step['why'], 'Generate API key'), 'instead of copy-and-paste instructions');
check(!str_contains((string)file_get_contents($root . '/setup.php'), 'section=btcpay_greenfield'),
    'setup links to the gateway\'s real settings tab (tab=btcpay_settings)');

echo "Plain permalinks are explained, with a button (UX-01)\n";
// A fresh site (nothing set up), so Tools → CashuPay would forward to setup.
$plain = wp(['scenario' => 'permalinks', 'datadir' => "$tmp/data-fresh", 'options' => ['permalink_structure' => '']]);
check(str_contains($plain['notice'], 'readable web addresses')
    && str_contains($plain['notice'], 'admin-post.php?action=cashupay_enable_pretty_links'),
    'the notice offers "Switch to readable web addresses"');
check(!str_contains($plain['notice'], 'configure the plugin here'), 'instead of a setup link that would 404');
check(!isset($plain['redirect']) && str_contains($plain['page'], 'First switch your site'),
    'Tools → CashuPay stays in wp-admin instead of forwarding to a 404');
$pretty = wp(['scenario' => 'permalinks', 'datadir' => "$tmp/data-fresh", 'options' => ['permalink_structure' => '/%postname%/']]);
check(!str_contains($pretty['notice'] ?? '', 'readable web addresses'), 'no notice with readable addresses');
check(($pretty['redirect'] ?? '') === 'http://127.0.0.1/cashupay-setup/', 'and Tools → CashuPay forwards to setup');

echo "Pairing while logged out of WordPress offers the WordPress login (UX-06.3)\n";
$out = wp(['scenario' => 'authorize', 'admin' => false, 'logged_in' => false]);
check(str_contains($out['page'], 'Sign in to WordPress') && str_contains($out['page'], 'wp-login.php?redirect_to=%2Fcashupay%2Fapi-keys%2Fauthorize%3FapplicationName%3DShop'),
    'a WordPress login link that comes back to this page');
check(!str_contains($out['page'], 'name="password"'), 'no CashuPay password form');
$out = wp(['scenario' => 'authorize', 'admin' => false, 'logged_in' => true]);
check(str_contains($out['page'], 'not allowed to connect apps'), 'a signed-in non-admin is told to ask the administrator');

echo "All WordPress setup checks passed.\n";
