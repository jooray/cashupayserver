<?php
/**
 * CashuPayServer - Centralized URL Helper
 *
 * All URL generation logic in one place. Handles both WordPress
 * and standalone mode without scattered conditionals.
 */

class Urls {
    /** @var string|null Plugin file path for WordPress plugins_url() */
    private static ?string $pluginFile = null;

    /**
     * Initialize with WordPress plugin file path.
     * Call this from wordpress/bootstrap.php.
     */
    public static function init(string $pluginFile): void {
        self::$pluginFile = $pluginFile;
    }

    /**
     * Check if running in WordPress mode
     */
    public static function isWordPress(): bool {
        return defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS;
    }

    /**
     * Get the server URL for e-commerce integration (BTCPay API base).
     * This is the URL e-commerce plugins should use as "BTCPay Server URL".
     */
    public static function server(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay');
        }

        // Standalone: check url_mode config for direct vs router.php URLs
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();

        return $mode === 'direct' ? $base : $base . '/router.php';
    }

    /**
     * Get the admin dashboard URL
     */
    public static function admin(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay-admin/');
        }
        return 'admin.php';
    }

    /**
     * Get the setup wizard URL
     */
    public static function setup(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay-setup/');
        }
        return 'setup.php';
    }

    /**
     * Get the URL for static assets (JS, CSS, etc.)
     *
     * @param string $subpath Path within assets/ directory (e.g., 'js/', 'css/')
     * @return string Base URL for assets
     */
    public static function assets(string $subpath = ''): string {
        if (self::isWordPress()) {
            // Use the plugin file if set, otherwise fallback to constant
            $pluginFile = self::$pluginFile ?? (defined('CASHUPAY_PLUGIN_DIR') ? CASHUPAY_PLUGIN_DIR . '/cashupay.php' : __FILE__);
            return plugins_url('assets/' . $subpath, $pluginFile);
        }
        return 'assets/' . $subpath;
    }

    /**
     * Get the API base URL (same as server URL for API calls)
     */
    public static function api(): string {
        return rtrim(self::server(), '/');
    }

    /**
     * Get the payment page URL
     *
     * @param string $invoiceId Invoice ID (optional)
     * @return string Payment page URL
     */
    public static function payment(string $invoiceId = ''): string {
        if (self::isWordPress()) {
            $url = site_url('/cashupay/payment/');
            return $invoiceId ? $url . $invoiceId : $url;
        }

        $base = rtrim(Config::getBaseUrl(), '/') . '/payment.php';
        return $invoiceId ? $base . '?id=' . urlencode($invoiceId) : $base;
    }

    /**
     * Get the API key authorization (pairing) URL
     *
     * @param array $params Query parameters for the authorization request
     * @return string Full pairing URL with parameters
     */
    public static function pairing(array $params = []): string {
        $serverUrl = rtrim(self::server(), '/');
        $url = $serverUrl . '/api-keys/authorize';

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Build the standard BTCPay pairing URL with test permissions
     */
    public static function pairingTest(): string {
        return self::pairing([
            'applicationName' => 'Test Connection',
            'permissions' => [
                'btcpay.store.canviewinvoices',
                'btcpay.store.cancreateinvoice',
                'btcpay.store.webhooks.canmodifywebhooks',
            ],
            'strict' => 'true',
        ]);
    }

    /**
     * Get the cron/background task URL
     */
    public static function cron(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay/cron');
        }
        return rtrim(Config::getBaseUrl(), '/') . '/cron.php';
    }

    /**
     * Get the receive endpoint URL (NUT-18 payment requests)
     */
    public static function receive(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay/receive');
        }
        return rtrim(Config::getBaseUrl(), '/') . '/receive.php';
    }

    /**
     * Get all URLs as JSON for JavaScript consumption
     */
    public static function toJson(): string {
        return json_encode([
            'server' => self::server(),
            'admin' => self::admin(),
            'setup' => self::setup(),
            'assets' => self::assets(),
            'assetsJs' => self::assets('js/'),
            'api' => self::api(),
            'pairing' => self::pairing(),
            'pairingTest' => self::pairingTest(),
            'cron' => self::cron(),
            'receive' => self::receive(),
            'isWordPress' => self::isWordPress(),
        ]);
    }

    /**
     * Get site base URL (for security tests and redirects)
     * In WordPress, uses site_url(). In standalone, uses Config::getBaseUrl().
     */
    public static function siteBase(): string {
        if (self::isWordPress()) {
            return rtrim(site_url(), '/');
        }
        return rtrim(Config::getBaseUrl(), '/');
    }
}
