<?php
/**
 * CashuPay WordPress Uninstall
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Only cleans up non-critical data; ecash tokens are preserved
 * unless the user explicitly removes the data directory.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Reset BTCPay plugin options only if they point to CashuPay. Compared on host and
// path only, so a site that moved between http and https, or a URL saved with a
// trailing slash, still matches.
function cashupay_uninstall_normalize_url(string $url): string {
    $parts = wp_parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $host . rtrim($parts['path'] ?? '', '/');
}

/**
 * Folders this site's wallet may live in, in the same order bootstrap.php tries them.
 *
 * The uninstall routine runs without the plugin's bootstrap, and must still be able to
 * find the database that holds the credentials it is about to revoke.
 *
 * @return string[]
 */
function cashupay_uninstall_data_dirs(): array {
    $dirs = [];
    if (defined('CASHUPAY_DATA_DIR') && CASHUPAY_DATA_DIR !== '') {
        $dirs[] = rtrim((string) CASHUPAY_DATA_DIR, '/');
    }
    $parent = dirname(ABSPATH);
    $dirs[] = $parent . '/cashupay-data';
    $dirs[] = $parent . '/cashupay-data-' . substr(hash('sha256', ABSPATH), 0, 12);
    if (defined('WP_CONTENT_DIR')) {
        $dirs[] = rtrim(WP_CONTENT_DIR, '/') . '/cashupay-data';
    }
    $blogId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
    foreach ($dirs as $dir) {
        $dirs[] = $dir . '/site-' . $blogId;
    }
    return array_values(array_unique(array_filter($dirs, 'is_string')));
}

/**
 * Revoke the API key and webhook this plugin created, and remove their options.
 *
 * The options are deleted only for credentials the local database recognises as ours,
 * so a merchant who points the BTCPay plugin at their own BTCPay Server keeps that
 * configuration. When the database cannot be opened the URL check decides, as before.
 */
function cashupay_uninstall_cleanup_credentials(): void {
    $urlPointsHere = (function (): bool {
        $btcpay = cashupay_uninstall_normalize_url((string) get_option('btcpay_gf_url', ''));
        $ours = cashupay_uninstall_normalize_url(site_url('/cashupay'));
        return $btcpay !== '' && $ours !== ''
            && ($btcpay === $ours || strpos($btcpay, $ours . '/') === 0);
    })();

    $apiKey = (string) get_option('btcpay_gf_api_key', '');
    $webhook = get_option('btcpay_gf_webhook', null);
    $keyHash = $apiKey !== '' ? hash('sha256', $apiKey) : '';
    $webhookId = is_array($webhook) && !empty($webhook['id']) ? (string) $webhook['id'] : '';

    $keyFound = false;
    $webhookFound = false;
    foreach (cashupay_uninstall_data_dirs() as $dir) {
        $dbPath = $dir . '/cashupay.sqlite';
        if (!is_file($dbPath)) {
            continue;
        }
        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            if ($keyHash !== '') {
                $stmt = $pdo->prepare('SELECT id FROM api_keys WHERE key_hash = ?');
                $stmt->execute([$keyHash]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
                    $keyFound = true;
                }
            }
            if ($webhookId !== '') {
                $stmt = $pdo->prepare('SELECT id FROM webhooks WHERE id = ?');
                $stmt->execute([$webhookId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    // Keep the row's history but stop it from signing anything.
                    $pdo->prepare("UPDATE webhooks SET enabled = 0 WHERE id = ?")->execute([$id]);
                    $webhookFound = true;
                }
            }
            $pdo = null;
        } catch (Throwable $e) {
            // A database this user cannot open is not a reason to keep the option.
            error_log('CashuPayServer uninstall: could not check ' . $dbPath . ': ' . $e->getMessage());
        }
    }

    // Only remove the credential options we can attribute to this plugin. A merchant
    // who later pointed the gateway at their own BTCPay Server has a stale webhook
    // option but live API-key and store options that must be left alone.
    if ($keyFound || $urlPointsHere) {
        delete_option('btcpay_gf_api_key');
    }
    if ($webhookFound || $urlPointsHere) {
        delete_option('btcpay_gf_webhook');
    }
    if ($urlPointsHere) {
        delete_option('btcpay_gf_url');
        delete_option('btcpay_gf_store_id');
    } elseif ($keyFound) {
        // The URL belongs to a real BTCPay Server, but the key was ours.
        delete_option('btcpay_gf_store_id');
    }
}

function cashupay_uninstall_site(): void {
    cashupay_uninstall_cleanup_credentials();

    delete_option('cashupay_activation_error');
    delete_option('cashupay_data_protection_version');
    delete_transient('cashupay_data_exposure');
    delete_transient('cashupay_rewrite_flush_retry');

    // Unschedule cron
    $timestamp = wp_next_scheduled('cashupay_poll_quotes');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cashupay_poll_quotes');
    }
}

if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites') && function_exists('switch_to_blog')) {
    // Options and wallets are per site, and a network delete runs this file once.
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        cashupay_uninstall_site();
        restore_current_blog();
    }
} else {
    cashupay_uninstall_site();
}

// Flush rewrite rules
delete_option('cashupay_rewrite_rules_version');
flush_rewrite_rules();

// Note: Data directory is NOT removed on uninstall.
// It contains ecash tokens (real value). Users must manually
// export their seed phrases and delete the directory if desired.
// The directory location is shown in the plugin's admin interface.
