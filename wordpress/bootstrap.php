<?php
/**
 * CashuPay WordPress Bootstrap
 *
 * Sets up constants and data directory for WordPress integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Mark that we're running in WordPress mode
define('CASHUPAY_WORDPRESS', true);

// Plugin directory (contains includes/, assets/, etc.)
define('CASHUPAY_PLUGIN_DIR', __DIR__);

// Which site of a multisite network this request belongs to (1 on a single site).
// Each site gets its own wallet; site 1 keeps the folder a single-site install uses.
define('CASHUPAY_BLOG_ID', (function_exists('is_multisite') && is_multisite() && function_exists('get_current_blog_id'))
    ? max(1, (int) get_current_blog_id())
    : 1);

/**
 * What a data folder's .owner marker says: this WordPress installation (ABSPATH) on
 * the first line, and the site of a network whose wallet is in it on the second.
 */
function cashupay_data_dir_owner(int $blogId): string {
    return ABSPATH . "\nblog " . $blogId . "\n";
}

/**
 * A data folder's .owner marker as [installation, site of the network], or null when
 * it has none. A marker without a second line is site 1's.
 *
 * @return ?array{0: string, 1: int}
 */
function cashupay_data_dir_marker(string $dir): ?array {
    $marker = @file_get_contents($dir . '/.owner');
    if (!is_string($marker) || $marker === '') {
        return null;
    }
    $lines = explode("\n", $marker);
    $blog = preg_match('/^blog (\d+)$/', $lines[1] ?? '', $m) ? max(1, (int) $m[1]) : 1;
    return [$lines[0], $blog];
}

/** The site of this installation a folder's marker names, or null when the marker is missing or another installation's. */
function cashupay_data_dir_marked_blog(string $dir): ?int {
    $marker = cashupay_data_dir_marker($dir);
    return ($marker !== null && $marker[0] === ABSPATH) ? $marker[1] : null;
}

/**
 * Whether two CashuPay base URLs name the same site. Host, port and path only, so a
 * site that moved between http and https, or a URL with a trailing slash, still matches.
 */
function cashupay_same_site_url(string $a, string $b): bool {
    $norm = static function (string $url): ?string {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        return strtolower($parts['host']) . ':' . ($parts['port'] ?? '') . '/' . trim($parts['path'] ?? '', '/');
    };
    $na = $norm($a);
    return $na !== null && $na === $norm($b);
}

/**
 * One setting of the wallet in a data folder, read without touching the Database
 * class (which is for this site's own wallet). Null when there is no such wallet.
 */
function cashupay_data_dir_setting(string $dir, string $key): mixed {
    $db = $dir . '/cashupay.sqlite';
    if (!is_file($db) || !class_exists('PDO')) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        $stmt = $pdo->prepare('SELECT value FROM config WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_string($value)) {
        return null;
    }
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

/**
 * Which site of this installation the wallet in a folder was set up for: its database
 * remembers that site's address. 1 on a single site; on a network, the main site or
 * the site of the network at that address, as long as that site has no folder of its
 * own inside this one yet. Null for an empty folder or another site's wallet.
 *
 * @param int[] $hints sites to try besides the main site and this one
 */
function cashupay_data_dir_site_of(string $dir, array $hints = []): ?int {
    $url = cashupay_data_dir_setting($dir, 'base_url');
    if (!is_string($url) || $url === '') {
        return null;
    }
    if (!(function_exists('is_multisite') && is_multisite() && function_exists('get_site_url'))) {
        return cashupay_same_site_url($url, site_url('/cashupay')) ? 1 : null;
    }
    $candidates = array_merge([1, CASHUPAY_BLOG_ID], $hints);
    // The site at that address, whichever site this request is for, so that every site
    // of the network comes to the same answer.
    $parts = parse_url($url);
    if (function_exists('get_sites') && is_array($parts) && !empty($parts['host'])) {
        $path = preg_replace('#/cashupay/?$#', '', '/' . trim($parts['path'] ?? '', '/'));
        $candidates = array_merge($candidates, array_map('intval', (array) get_sites([
            'domain' => strtolower($parts['host']),
            'path' => rtrim((string) $path, '/') . '/',
            'fields' => 'ids',
            'number' => 5,
        ])));
    }
    foreach (array_unique($candidates) as $blog) {
        if ($blog > 1 && is_dir($dir . '/site-' . $blog)) {
            continue;
        }
        if (cashupay_same_site_url($url, get_site_url($blog, '/cashupay'))) {
            return $blog;
        }
    }
    return null;
}

/**
 * The data folder of this WordPress installation, and the shared folder it did not
 * take because another site owns it (null when there is none).
 *
 * Older versions put every installation in the same parent directory into one
 * dirname(ABSPATH)/cashupay-data, so sibling sites (cPanel addon domains, Plesk
 * vhosts) opened each other's wallet. Now that folder is used only when its .owner
 * marker names this installation or, for an install upgraded from such a version or
 * moved to a new folder, when its database remembers this site's address (it is then
 * marked again). Everything else gets a folder named after this installation.
 *
 * @return array{0: string, 1: ?string}
 */
function cashupay_resolve_data_dir(): array {
    $parent = dirname(ABSPATH);
    $legacy = $parent . '/cashupay-data';
    $own = $parent . '/cashupay-data-' . substr(hash('sha256', ABSPATH), 0, 12);
    $content = WP_CONTENT_DIR . '/cashupay-data';
    $foreign = null;

    if (is_dir($legacy)) {
        $marker = cashupay_data_dir_marker($legacy);
        if ($marker !== null && $marker[0] === ABSPATH) {
            return [$legacy, null];
        }
        // Once this install has its own folder the question is settled; no need to
        // open the other site's database on every request.
        if (!is_dir($own)) {
            $blog = cashupay_data_dir_site_of($legacy, $marker !== null ? [$marker[1]] : []);
            if ($blog !== null) {
                @file_put_contents($legacy . '/.owner', cashupay_data_dir_owner($blog));
                return [$legacy, null];
            }
        }
        $foreign = $legacy;
    }
    if (is_dir($own)) {
        return [$own, $foreign];
    }
    if (is_dir($content)) {
        return [$content, $foreign];
    }
    return [is_writable($parent) ? $own : $content, $foreign];
}

/**
 * This site's wallet folder inside the folder of the installation (or the network).
 *
 * On a network the base folder holds one site's wallet and every other site keeps its
 * own in site-<id> inside it. Older versions gave every site the base folder, so after
 * an upgrade it stays with the site whose address its database remembers, which may
 * be a site other than the main one; the marker then records that site. Otherwise it
 * is the main site's.
 */
function cashupay_site_data_dir(string $base): string {
    if (!(function_exists('is_multisite') && is_multisite())) {
        return $base;
    }
    $owner = cashupay_data_dir_marked_blog($base);
    if ($owner === null) {
        $marker = cashupay_data_dir_marker($base);
        $owner = cashupay_data_dir_site_of($base, $marker !== null ? [$marker[1]] : []);
        if ($owner !== null) {
            @file_put_contents($base . '/.owner', cashupay_data_dir_owner($owner));
        }
    }
    return CASHUPAY_BLOG_ID === ($owner ?? 1) ? $base : $base . '/site-' . CASHUPAY_BLOG_ID;
}

// Determine data directory with priority:
// 1. User-defined constant in wp-config.php
// 2. This installation's folder above ABSPATH (outside web root)
// 3. Fallback: wp-content/cashupay-data
// On a multisite network, sites other than the one whose wallet is in that folder use
// site-<id> inside it (cashupay_site_data_dir()).
$GLOBALS['cashupay_foreign_data_dir'] = null;
// The network's base folder, when this site does not use it (admin-menu.php tells a
// super admin where the wallet the sites used to share is).
$GLOBALS['cashupay_network_data_dir'] = null;
if (!defined('CASHUPAY_DATA_DIR')) {
    [$cashupay_base_dir, $GLOBALS['cashupay_foreign_data_dir']] = cashupay_resolve_data_dir();
    define('CASHUPAY_DATA_DIR', cashupay_site_data_dir($cashupay_base_dir));
    if (CASHUPAY_DATA_DIR !== $cashupay_base_dir) {
        $GLOBALS['cashupay_network_data_dir'] = $cashupay_base_dir;
    }
    unset($cashupay_base_dir);
} elseif (function_exists('is_multisite') && is_multisite()
    && ($cashupay_site_dir = cashupay_site_data_dir(rtrim(CASHUPAY_DATA_DIR, '/'))) !== rtrim(CASHUPAY_DATA_DIR, '/')) {
    // wp-config.php is shared by every site of the network, so its folder is the
    // network's base and this site's wallet lives in site-<id> inside it. The constant
    // cannot be redefined; Database::getDataDir() reads this one first.
    define('CASHUPAY_DATA_DIR_FOR_SITE', $cashupay_site_dir);
    $GLOBALS['cashupay_network_data_dir'] = rtrim(CASHUPAY_DATA_DIR, '/');
    unset($cashupay_site_dir);
    require_once __DIR__ . '/includes/database.php';
    if (Database::getDataDir() !== CASHUPAY_DATA_DIR_FOR_SITE) {
        // A Database that does not know about it would open another site's wallet.
        // Switch CashuPay off on this site instead (the notice in admin-menu.php says why).
        $GLOBALS['cashupay_refuse_site'] = true;
        $GLOBALS['cashupay_startup_error'] = 'This site of the network cannot get its own CashuPay wallet folder, '
            . 'because the CASHUPAY_DATA_DIR line in wp-config.php applies to every site. '
            . 'CashuPay is off on this site so that it cannot open another site\'s wallet. '
            . 'Use CashuPay on the site of the network that already has it, or update the CashuPay plugin.';
    }
}

/** The data folder this site's wallet lives in (see above). */
function cashupay_data_dir(): string {
    return defined('CASHUPAY_DATA_DIR_FOR_SITE') ? CASHUPAY_DATA_DIR_FOR_SITE : CASHUPAY_DATA_DIR;
}

/**
 * Who may open CashuPay's admin and setup pages. On a multisite network only a super
 * admin: a subsite administrator is not trusted with the network's money.
 */
function cashupay_current_user_can_manage(): bool {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        return false;
    }
    if (function_exists('is_multisite') && is_multisite()) {
        return function_exists('is_super_admin') && is_super_admin();
    }
    return true;
}

// Initialize centralized URL helper
// In deployed plugin, __DIR__ is the plugin root containing includes/
require_once __DIR__ . '/includes/urls.php';
Urls::init(__DIR__ . '/admin.php');
