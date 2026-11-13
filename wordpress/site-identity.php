<?php
/**
 * CashuPay WordPress: which data folder and which gateway address belong to this site.
 *
 * Function definitions only, no side effects, so both bootstrap.php and uninstall.php
 * (which runs without the bootstrap) can load it.
 */

if (!defined('ABSPATH')) {
    exit;
}

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
 * Whether a data folder holds this site's wallet (blog $blogId of this installation).
 *
 * Its .owner marker decides when it names this installation; without one (a folder from
 * an older version), or with one naming another path (a site moved to a new folder, or
 * a staging copy of production), the address its database remembers must be this site's.
 * A staging clone that shares production's folder must never treat production's wallet
 * as its own (audit 2026-11).
 */
function cashupay_data_dir_belongs_to_site(string $dir, int $blogId, string $siteCashupayUrl): bool {
    $marker = cashupay_data_dir_marker($dir);
    if ($marker !== null && $marker[0] === ABSPATH) {
        return $marker[1] === $blogId;
    }
    $url = cashupay_data_dir_setting($dir, 'base_url');
    return is_string($url) && $url !== '' && cashupay_same_site_url($url, $siteCashupayUrl);
}

/**
 * Host (lower case), port and path of a URL, ignoring the scheme, a default port and a
 * trailing slash. Null when the URL has no host.
 */
function cashupay_normalize_gateway_url(string $url): ?string {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port === 80 || $port === 443) {
        // The scheme is ignored, so its default port is too (https://host:443 = host).
        $port = null;
    }
    return strtolower($parts['host']) . ($port !== null ? ':' . $port : '')
        . '/' . trim($parts['path'] ?? '', '/');
}

/**
 * Whether the BTCPay gateway's server address is this site's CashuPay: same host and
 * port, path exactly /cashupay (a trailing slash allowed), http and https alike, so a
 * site that moved to https still recognises its own address. /cashupay-copy or another
 * folder is not ours.
 */
function cashupay_gateway_url_is_ours(string $url, ?string $ours = null): bool {
    $a = cashupay_normalize_gateway_url($url);
    $b = cashupay_normalize_gateway_url($ours ?? site_url('/cashupay'));
    return $a !== null && $a === $b;
}
