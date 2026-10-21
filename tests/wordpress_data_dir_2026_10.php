<?php
/**
 * Every WordPress installation, and every site of a multisite network, gets a wallet
 * of its own (2026-10 audit, C5 and C6).
 *
 * - Sibling installs (cPanel addon domains, Plesk vhosts) used to share
 *   dirname(ABSPATH)/cashupay-data, so site B's admin opened site A's wallet and
 *   B's activation overwrote A's base_url.
 * - An unmarked shared folder from an older version is adopted (and marked) only by
 *   the site whose address its database remembers; any other site gets its own folder
 *   and an admin notice.
 * - On multisite, sites other than site 1 use site-<id>, also under a wp-config
 *   CASHUPAY_DATA_DIR, and only a super admin passes the CashuPay admin gate.
 * - Upgrading a network whose shop is not site 1: the wallet all sites shared stays
 *   with the site whose address it remembers (and the marker records it); the other
 *   sites get site-<id> and a super admin is told where the shared wallet is.
 * - A site moved to a new folder on the same server keeps a wallet marked with its
 *   old path, because the database remembers its address; a sibling still does not.
 *
 * Each scenario runs in a child process with the WordPress API stubbed (constants
 * such as ABSPATH and CASHUPAY_DATA_DIR cannot change within one process), against
 * the real plugin files laid out the way scripts/build-wordpress-plugin.sh does.
 *
 * Run: php tests/wordpress_data_dir_2026_10.php
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
    if (isset($opt['wpconfig'])) {
        define('CASHUPAY_DATA_DIR', $opt['wpconfig']);
    }
    $GLOBALS['opt'] = $opt;
    $GLOBALS['wp_options'] = [];

    function is_multisite(): bool { return !empty($GLOBALS['opt']['multisite']); }
    function get_current_blog_id(): int { return (int)($GLOBALS['opt']['blog'] ?? 1); }
    function is_super_admin(): bool { return !empty($GLOBALS['opt']['super']); }
    function current_user_can(string $cap): bool { return $cap === 'manage_options' && !empty($GLOBALS['opt']['admin']); }
    function site_url(string $path = ''): string { return rtrim($GLOBALS['opt']['site'], '/') . $path; }
    function get_site_url(?int $blog = null, string $path = ''): string {
        if (isset($GLOBALS['opt']['sites'][(string) $blog])) {
            return rtrim($GLOBALS['opt']['sites'][(string) $blog], '/') . $path;
        }
        return rtrim($blog === 1 ? ($GLOBALS['opt']['main_site'] ?? $GLOBALS['opt']['site']) : $GLOBALS['opt']['site'], '/') . $path;
    }
    // The sites of the network at an address ('sites' maps blog id => URL).
    function get_sites(array $args = []): array {
        $ids = [];
        foreach ($GLOBALS['opt']['sites'] ?? [] as $id => $url) {
            $p = parse_url($url);
            if (strtolower($p['host']) === ($args['domain'] ?? '') && rtrim($p['path'] ?? '', '/') . '/' === ($args['path'] ?? '')) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }
    function __(string $s, string $d = ''): string { return $s; }
    function esc_html__(string $s, string $d = ''): string { return htmlspecialchars($s); }
    function esc_html(string $s): string { return htmlspecialchars($s); }
    function get_option(string $k, $d = false) { return $GLOBALS['wp_options'][$k] ?? $d; }
    function update_option(string $k, $v, $autoload = null): bool { $GLOBALS['wp_options'][$k] = $v; return true; }
    function delete_option(string $k): bool { unset($GLOBALS['wp_options'][$k]); return true; }
    function get_transient(string $k) { return false; }
    function set_transient(string $k, $v, int $t = 0): bool { return true; }
    function delete_transient(string $k): bool { return true; }
    function add_action(...$a): void {}
    function add_filter(...$a): void {}
    function did_action(string $a): int { return 0; }
    function add_rewrite_rule(...$a): void {}
    function flush_rewrite_rules(bool $hard = true): void {}
    function wp_next_scheduled(string $h) { return 1; }
    function wp_schedule_event(...$a): bool { return true; }

    require $opt['plugin'] . '/bootstrap.php';
    require $opt['plugin'] . '/activation.php';
    require $opt['plugin'] . '/rewrite-rules.php';

    $out = [
        'dir' => CASHUPAY_DATA_DIR,
        'site_dir' => cashupay_data_dir(),
        'foreign' => $GLOBALS['cashupay_foreign_data_dir'] ?? null,
        'refused' => !empty($GLOBALS['cashupay_refuse_site']),
        'can_manage' => cashupay_current_user_can_manage(),
        'for_site' => defined('CASHUPAY_DATA_DIR_FOR_SITE'),
        'network' => $GLOBALS['cashupay_network_data_dir'] ?? null,
        'marker' => @file_get_contents(cashupay_data_dir() . '/.owner') ?: null,
    ];
    if (!empty($opt['activate']) && !$out['refused']) {
        cashupay_activate_unguarded();
        $out['base_url'] = Config::get('base_url');
        $out['db_dir'] = Database::getDataDir();
        if (!empty($opt['seed'])) {
            Config::set('setup_complete', true);
            Database::query("INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, created_at)
                             VALUES ('store_A', 'A', 'https://mint.example', 'sat', ?, ?)", [$opt['seed'], time()]);
        }
        $row = Database::fetchOne("SELECT seed_phrase FROM stores WHERE id = 'store_A'");
        $out['seed'] = $row['seed_phrase'] ?? null;
    }
    if (!empty($opt['notice'])) {
        require $opt['plugin'] . '/admin-menu.php';
        ob_start();
        cashupay_network_data_dir_notice();
        $out['notice'] = ob_get_clean();
    }
    echo json_encode($out);
    exit(0);
}

// --- parent ----------------------------------------------------------------------
function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$tmp = sys_get_temp_dir() . '/cashupay-wpdir-' . bin2hex(random_bytes(8));
$plugin = $tmp . '/plugin';
mkdir($plugin, 0700, true);
foreach (glob($root . '/wordpress/*.php') as $file) {
    copy($file, $plugin . '/' . basename($file));
}
symlink($root . '/includes', $plugin . '/includes');
symlink($root . '/cashu-wallet-php', $plugin . '/cashu-wallet-php');
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

$hosting = $tmp . '/hosting';
foreach (['siteA', 'siteB', 'siteC', 'net'] as $site) {
    mkdir("$hosting/$site/wp-content", 0700, true);
}

/** Run one simulated request; returns the child's report. */
function wp(array $opt): array {
    global $plugin;
    $opt += ['plugin' => $plugin, 'admin' => true];
    $cmd = [PHP_BINARY, __FILE__, '--child', json_encode($opt)];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

$A = ['abspath' => "$hosting/siteA/", 'site' => 'https://a.example'];
$B = ['abspath' => "$hosting/siteB/", 'site' => 'https://b.example'];

echo "Sibling installs get separate wallets\n";
$a = wp($A + ['activate' => true, 'seed' => 'DUMMY-SEED-A']);
$b = wp($B + ['activate' => true]);
check($a['dir'] !== $b['dir'], 'two ABSPATHs with the same parent resolve to different data folders');
check(dirname($a['dir']) === $hosting && str_starts_with(basename($a['dir']), 'cashupay-data-'),
    'a new install uses a folder named after itself, outside the website');
check($a['db_dir'] === $a['dir'] && $b['db_dir'] === $b['dir'], 'and the database lives there');
check($b['seed'] === null, "site B does not see site A's seed");
check($b['base_url'] === 'https://b.example/cashupay', 'site B pins its own address');
check(str_starts_with((string)file_get_contents($a['dir'] . '/.owner'), "$hosting/siteA/\n"), 'the folder is marked with its owner');
check(wp($A)['dir'] === $a['dir'] && wp($A + ['activate' => true])['seed'] === 'DUMMY-SEED-A', 'site A keeps finding its wallet');
check(wp($A + ['activate' => true])['base_url'] === 'https://a.example/cashupay', "and B's activation did not touch A's address");

echo "An unmarked shared folder from an older version\n";
// What an older version left behind: <parent>/cashupay-data, no marker, site C's wallet.
$hosting2 = $tmp . '/hosting2';
foreach (['siteC', 'siteD'] as $site) {
    mkdir("$hosting2/$site/wp-content", 0700, true);
}
$legacy = "$hosting2/cashupay-data";
$C = ['abspath' => "$hosting2/siteC/", 'site' => 'https://c.example'];
$D = ['abspath' => "$hosting2/siteD/", 'site' => 'https://d.example'];
$c = wp($C + ['wpconfig' => $legacy, 'activate' => true, 'seed' => 'DUMMY-SEED-C']);
check($c['dir'] === $legacy, '(set up: site C has a wallet in the shared folder)');
@unlink($legacy . '/.owner');

$d = wp($D + ['activate' => true]);
check($d['dir'] !== $legacy, 'another site does not adopt it');
check($d['foreign'] === $legacy, 'and is told the folder belongs to another site');
check($d['seed'] === null, "nor sees site C's seed");
check(!file_exists($legacy . '/.owner'), 'nor marks it');

$cHttp = wp(['abspath' => "$hosting2/siteC/", 'site' => 'http://c.example/']);
check($cHttp['dir'] === $legacy && $cHttp['foreign'] === null,
    'the site whose address the database remembers adopts it (http or https, trailing slash or not)');
check(str_starts_with((string)file_get_contents($legacy . '/.owner'), "$hosting2/siteC/\n"), 'and marks it');
check(wp($D)['dir'] !== $legacy, 'once marked, the other site still gets its own folder');

echo "Activation keeps an address that belongs to another site\n";
$dOnC = wp($D + ['wpconfig' => $legacy, 'activate' => true]);
check($dOnC['base_url'] === 'https://c.example/cashupay', "site D activating on site C's folder leaves C's base_url alone");

echo "Multisite: one wallet per site, super admins only\n";
$net = ['abspath' => "$hosting/net/", 'site' => 'https://net.example', 'multisite' => true, 'super' => true];
$n1 = wp($net + ['blog' => 1, 'activate' => true, 'seed' => 'DUMMY-SEED-MAIN']);
$n2 = wp(array_merge($net, ['blog' => 2, 'site' => 'https://two.net.example', 'main_site' => 'https://net.example', 'activate' => true]));
check($n1['dir'] !== $n2['dir'], 'blogs 1 and 2 resolve to different data folders');
check($n2['dir'] === $n1['dir'] . '/site-2', 'blog 2 lives in site-2 inside the base folder');
check($n2['seed'] === null, "blog 2 does not see the main site's seed");

$sub = wp(array_merge($net, ['blog' => 2, 'super' => false]));
check($sub['can_manage'] === false, 'a subsite administrator who is not a super admin fails the CashuPay admin gate');
check($n1['can_manage'] === true, 'a super admin passes it');
check(wp($A)['can_manage'] === true, 'on a single site, manage_options is still enough');

$shared = "$tmp/wpconfig-data";
$w1 = wp($net + ['blog' => 1, 'wpconfig' => $shared, 'activate' => true, 'seed' => 'DUMMY-SEED-MAIN']);
$w2 = wp($net + ['blog' => 2, 'wpconfig' => $shared, 'activate' => true]);
check($w1['site_dir'] === $shared, 'with a wp-config folder, blog 1 uses it as is');
check($w2['site_dir'] === "$shared/site-2", 'blog 2 is given site-2 inside it');
check($w2['refused'] || ($w2['db_dir'] === "$shared/site-2" && $w2['seed'] === null),
    "and either really uses it or CashuPay stays off on blog 2; it never opens blog 1's wallet");

echo "Multisite upgrade: the shared wallet stays with the site it was set up for\n";
// What an older version left on a network whose shop is blog 3: one unmarked
// <parent>/cashupay-data, its database pinned to blog 3's address.
$hosting3 = $tmp . '/hosting3';
mkdir("$hosting3/net/wp-content", 0700, true);
$legacy3 = "$hosting3/cashupay-data";
$sites3 = ['1' => 'https://net3.example', '2' => 'https://two.net3.example', '3' => 'https://shop.net3.example'];
wp(['abspath' => "$hosting3/net/", 'site' => $sites3['3'], 'wpconfig' => $legacy3, 'activate' => true, 'seed' => 'DUMMY-SEED-SHOP']);
@unlink($legacy3 . '/.owner');
$net3 = ['abspath' => "$hosting3/net/", 'multisite' => true, 'super' => true, 'sites' => $sites3];

// The main site is visited first: it must not take the shop's wallet.
$m1 = wp($net3 + ['blog' => 1, 'site' => $sites3['1'], 'activate' => true]);
check($m1['site_dir'] === "$legacy3/site-1", 'the main site, visited first, gets site-1 instead of the shop\'s wallet');
check($m1['seed'] === null, "and does not see the shop's seed");
check($m1['foreign'] === null, 'the shared folder is still recognised as this network\'s');
$m3 = wp($net3 + ['blog' => 3, 'site' => $sites3['3'], 'activate' => true]);
check($m3['site_dir'] === $legacy3 && $m3['db_dir'] === $legacy3, 'blog 3, whose address the wallet remembers, keeps using it');
check($m3['seed'] === 'DUMMY-SEED-SHOP' && $m3['base_url'] === 'https://shop.net3.example/cashupay', 'with its seed and its address');
check(str_ends_with((string) $m3['marker'], "\nblog 3\n"), 'and the folder is marked as blog 3\'s');
check($m3['network'] === null, 'blog 3 is not told about a shared wallet');
$m2 = wp($net3 + ['blog' => 2, 'site' => $sites3['2'], 'notice' => true]);
check($m2['site_dir'] === "$legacy3/site-2" && $m2['network'] === $legacy3, 'blog 2 gets site-2');
check(str_contains($m2['notice'], 'this site now has its own wallet') && str_contains($m2['notice'], $legacy3)
    && str_contains($m2['notice'], 'shop.net3.example'), 'and a super admin there is told where the shared wallet is and whose it is');
check(wp(array_merge($net3, ['blog' => 2, 'site' => $sites3['2'], 'notice' => true, 'super' => false]))['notice'] === '',
    'a subsite administrator is not');
// The marker settles it, also once the database is gone from view.
rename("$legacy3/cashupay.sqlite", "$legacy3/cashupay.sqlite.away");
check(wp($net3 + ['blog' => 3, 'site' => $sites3['3']])['site_dir'] === $legacy3, 'the decision is stable (read from the marker)');
rename("$legacy3/cashupay.sqlite.away", "$legacy3/cashupay.sqlite");

echo "Multisite upgrade with a wp-config folder\n";
$shared4 = "$tmp/wpconfig-data4";
$sites4 = ['1' => 'https://net4.example', '2' => 'https://shop.net4.example', '4' => 'https://four.net4.example'];
wp(['abspath' => "$hosting/net/", 'site' => $sites4['2'], 'wpconfig' => $shared4, 'activate' => true, 'seed' => 'DUMMY-SEED-SHOP4']);
@unlink($shared4 . '/.owner');
$net4 = ['abspath' => "$hosting/net/", 'multisite' => true, 'super' => true, 'sites' => $sites4, 'wpconfig' => $shared4];
$v2 = wp($net4 + ['blog' => 2, 'site' => $sites4['2'], 'activate' => true]);
check($v2['site_dir'] === $shared4 && !$v2['for_site'] && !$v2['refused'], 'blog 2, whose address the wallet remembers, keeps the wp-config folder');
check($v2['db_dir'] === $shared4 && $v2['seed'] === 'DUMMY-SEED-SHOP4', 'and its wallet');
$v4 = wp($net4 + ['blog' => 4, 'site' => $sites4['4'], 'activate' => true, 'notice' => true]);
check($v4['site_dir'] === "$shared4/site-4", 'another blog gets site-4 inside it');
check(!$v4['refused'] && ($v4['db_dir'] === "$shared4/site-4" && $v4['seed'] === null), "and never opens blog 2's wallet");
check(str_contains($v4['notice'], $shared4), 'and is told where the shared wallet is');
$v1 = wp($net4 + ['blog' => 1, 'site' => $sites4['1'], 'activate' => true]);
check($v1['site_dir'] === "$shared4/site-1" && ($v1['refused'] || $v1['seed'] === null), "the main site does not open blog 2's wallet either");

echo "A site moved to a new folder on the same server keeps its wallet\n";
$hosting5 = $tmp . '/hosting5';
foreach (['old', 'new', 'other'] as $site) {
    mkdir("$hosting5/$site/wp-content", 0700, true);
}
$legacy5 = "$hosting5/cashupay-data";
wp(['abspath' => "$hosting5/old/", 'site' => 'https://e.example', 'wpconfig' => $legacy5, 'activate' => true, 'seed' => 'DUMMY-SEED-E']);
check(str_starts_with((string) file_get_contents("$legacy5/.owner"), "$hosting5/old/\n"), '(set up: the folder is marked with the old path)');
$f = wp(['abspath' => "$hosting5/other/", 'site' => 'https://f.example', 'activate' => true]);
check($f['dir'] !== $legacy5 && $f['foreign'] === $legacy5 && $f['seed'] === null, 'a sibling install still does not adopt a folder marked by another path');
check(str_starts_with((string) file_get_contents("$legacy5/.owner"), "$hosting5/old/\n"), 'nor rewrites its marker');
$e = wp(['abspath' => "$hosting5/new/", 'site' => 'https://e.example', 'activate' => true]);
check($e['dir'] === $legacy5 && $e['foreign'] === null && $e['seed'] === 'DUMMY-SEED-E', 'the moved site, whose address the wallet remembers, adopts it');
check(str_starts_with((string) file_get_contents("$legacy5/.owner"), "$hosting5/new/\n"), 'and the marker now names the new path');
check(wp(['abspath' => "$hosting5/other/", 'site' => 'https://f.example'])['dir'] !== $legacy5, 'the sibling still gets its own folder');

echo "wordpress_data_dir_2026_10: OK\n";
